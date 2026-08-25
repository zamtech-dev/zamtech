<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método inválido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$cpf_cnpj = isset($input['cpf_cnpj']) ? preg_replace('/\D/', '', $input['cpf_cnpj']) : '';

if (empty($cpf_cnpj)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Informe o CPF ou CNPJ.']);
    exit;
}

$sgp_url = 'https://zamtech.sgp.tsmx.app';
$sgp_app = 'segunda-via-website';
$sgp_token = '4ab8d0da-ed7d-4e91-b717-9d4a98625458';

// 1. Localiza o cliente pelo CPF
$curl = curl_init();
curl_setopt_array($curl, array(
  CURLOPT_URL => $sgp_url . '/api/ura/clientes/',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_POSTFIELDS => json_encode([
    'app' => $sgp_app,
    'token' => $sgp_token,
    'cpfcnpj' => $cpf_cnpj
  ]),
  CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
));

$response = curl_exec($curl);
curl_close($curl);

$dataCliente = json_decode($response, true);

if (!isset($dataCliente['clientes']) || empty($dataCliente['clientes'])) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Nenhum cadastro encontrado para este CPF/CNPJ.']);
    exit;
}

$todasFaturas = [];

foreach ($dataCliente['clientes'] as $cliente) {
    if (isset($cliente['titulos']) && is_array($cliente['titulos'])) {
        foreach ($cliente['titulos'] as $t) {
            $status = isset($t['status']) ? trim(strtolower($t['status'])) : '';
            $dataPagamento = isset($t['dataPagamento']) ? trim($t['dataPagamento']) : '';
            $dataCancelamento = isset($t['dataCancelamento']) ? trim($t['dataCancelamento']) : '';

            // Regra estrita: apenas o que estiver realmente ABERTO no SGP
            $estaAberto = ($status === 'aberto' || $status === 'abertos' || $status === 'a vencer' || $status === 'vencido');
            $naoPago = empty($dataPagamento) || $dataPagamento === '0000-00-00' || $dataPagamento === '0000-00-00 00:00:00';
            $naoCancelado = empty($dataCancelamento) || $dataCancelamento === '0000-00-00' || $dataCancelamento === '0000-00-00 00:00:00';
            $naoLiquidado = !in_array($status, ['liquidado', 'pago', 'cancelado', 'baixado', 'isento']);

            if ($estaAberto && $naoPago && $naoCancelado && $naoLiquidado) {
                $diasAtraso = isset($t['diasAtraso']) ? intval($t['diasAtraso']) : 0;
                
                // Se a data de vencimento já passou da data de hoje, marca como atraso
                $dataVenc = isset($t['dataVencimento']) ? $t['dataVencimento'] : '';
                $hoje = date('Y-m-d');
                $isAtrasado = ($diasAtraso > 0) || ($dataVenc && $dataVenc < $hoje);

                $todasFaturas[] = [
                    'id' => isset($t['id']) ? $t['id'] : '',
                    'periodo' => $isAtrasado ? 'Em Atraso' : 'Atual',
                    'vencimento' => $dataVenc,
                    'valor' => isset($t['valorCorrigido']) && floatval($t['valorCorrigido']) > 0 ? $t['valorCorrigido'] : (isset($t['valor']) ? $t['valor'] : 0),
                    'linha_digitavel' => isset($t['codigoBarras']) && !empty($t['codigoBarras']) ? $t['codigoBarras'] : (isset($t['linhaDigitavel']) ? $t['linhaDigitavel'] : ''),
                    'pix_copia_cola' => isset($t['codigoPix']) ? $t['codigoPix'] : '',
                    'link_pdf' => isset($t['link']) && !empty($t['link']) ? $t['link'] : (isset($t['link_cobranca']) ? $t['link_cobranca'] : '#')
                ];
            }
        }
    }
}

if (empty($todasFaturas)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Parabéns! Não existem faturas pendentes em aberto para este CPF/CNPJ.']);
    exit;
}

// Ordena as faturas pela data de vencimento (a mais antiga/vencida primeiro)
usort($todasFaturas, function($a, $b) {
    return strtotime($a['vencimento']) - strtotime($b['vencimento']);
});

echo json_encode(['sucesso' => true, 'faturas' => $todasFaturas]);
?>