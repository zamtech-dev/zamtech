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

// Consulta o cliente na SGP via cURL
$curl = curl_init();
curl_setopt_array($curl, array(
  CURLOPT_URL => $sgp_url . '/api/ura/clientes/',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_POSTFIELDS => json_encode([
    'app' => $sgp_app,
    'token' => $sgp_token,
    'cpfcnpj' => $cpf_cnpj
  ]),
  CURLOPT_HTTPHEADER => array(
    'Content-Type: application/json'
  ),
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
            $status = isset($t['status']) ? strtolower($t['status']) : '';
            $dataPagamento = isset($t['dataPagamento']) ? $t['dataPagamento'] : '';
            
            if ($status === 'aberto' || $status === 'abertos' || empty($dataPagamento)) {
                $diasAtraso = isset($t['diasAtraso']) ? $t['diasAtraso'] : 0;
                $todasFaturas[] = [
                    'periodo' => $diasAtraso > 0 ? 'Em Atraso' : 'Atual',
                    'vencimento' => isset($t['dataVencimento']) ? $t['dataVencimento'] : '',
                    'valor' => isset($t['valorCorrigido']) ? $t['valorCorrigido'] : (isset($t['valor']) ? $t['valor'] : 0),
                    'linha_digitavel' => isset($t['codigoBarras']) ? $t['codigoBarras'] : (isset($t['linhaDigitavel']) ? $t['linhaDigitavel'] : ''),
                    'pix_copia_cola' => isset($t['codigoPix']) ? $t['codigoPix'] : '',
                    'link_pdf' => isset($t['link']) ? $t['link'] : (isset($t['link_cobranca']) ? $t['link_cobranca'] : '#')
                ];
            }
        }
    }
}

if (empty($todasFaturas)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Nenhuma fatura em aberto localizada.']);
    exit;
}

// Ordena as faturas por data de vencimento (da mais recente para a mais antiga)
usort($todasFaturas, function($a, $b) {
    return strtotime($b['vencimento']) - strtotime($a['vencimento']);
});

// Limita para exibir apenas as 2 faturas mais relevantes (atual e mês anterior)
$todasFaturas = array_slice($todasFaturas, 0, 2);

echo json_encode(['sucesso' => true, 'faturas' => $todasFaturas]);
?>