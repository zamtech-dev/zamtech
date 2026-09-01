<?php
require_once __DIR__ . '/_config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método inválido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$nome = isset($input['nome']) ? trim($input['nome']) : '';
$cpf = isset($input['cpf']) ? preg_replace('/\D/', '', $input['cpf']) : '';

if ($nome === '' || $cpf === '') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Preencha nome e CPF.']);
    exit;
}

if (!validarCPF($cpf)) {
    // Isso também barra CNPJ (14 dígitos nunca passa na validação de CPF),
    // então já garante "só Pessoa Física" sem depender de campo nenhum do SGP.
    echo json_encode(['sucesso' => false, 'mensagem' => 'CPF inválido.']);
    exit;
}

// Tudo que mexe em banco/rede fica protegido: se algo quebrar (tabela que
// ainda não existe, SGP fora do ar, etc.) o cliente recebe um JSON limpo
// em vez de uma tela de erro do PHP quebrando o front-end.
try {
    $conn = conectarBanco();

    // Se esse CPF já tem um link ativo, devolve o mesmo em vez de criar outro.
    $stmt = $conn->prepare('SELECT codigo FROM indicacoes WHERE indicador_cpfcnpj = ? AND status = "ativo" LIMIT 1');
    $stmt->bind_param('s', $cpf);
    $stmt->execute();
    $existente = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existente) {
        echo json_encode([
            'sucesso' => true,
            'link' => 'https://zamtech.com.br/assinar?ref=' . $existente['codigo'],
            'ja_existia' => true,
        ]);
        exit;
    }

    // Consulta o cliente no SGP pelo CPF (mesmo endpoint que a 2ª via já usa).
    $dadosCliente = chamarSGP('/api/ura/clientes/', ['cpfcnpj' => $cpf]);

    if ($dadosCliente === null) {
        http_response_code(502);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Não consegui confirmar seus dados agora. Tente novamente em instantes ou fale com a gente pelo WhatsApp.',
            'permitir_whatsapp' => true,
        ]);
        exit;
    }

    $clientes = $dadosCliente['clientes'] ?? [];

    if (empty($clientes)) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Não encontramos um contrato ativo com esse CPF na Zamtech.',
            'permitir_whatsapp' => true,
        ]);
        exit;
    }

    $cliente = $clientes[0];

    // Precisa ter pelo menos um contrato Ativo.
    $contratoAtivo = null;
    foreach (($cliente['contratos'] ?? []) as $contrato) {
        $status = strtolower(trim($contrato['status'] ?? ''));
        if ($status === 'ativo') {
            $contratoAtivo = $contrato;
            break;
        }
    }

    if ($contratoAtivo === null) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Seu contrato precisa estar ativo pra gerar o link de indicação.',
            'permitir_whatsapp' => true,
        ]);
        exit;
    }

    // Precisa ter pelo menos uma fatura já paga (comprova que passou da 1ª fatura).
    $temFaturaPaga = false;
    foreach (($cliente['titulos'] ?? []) as $titulo) {
        $status = strtolower(trim($titulo['status'] ?? ''));
        if (in_array($status, ['pago', 'liquidado', 'baixado'], true)) {
            $temFaturaPaga = true;
            break;
        }
    }

    if (!$temFaturaPaga) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Você só pode gerar seu link depois que a 1ª fatura do seu contrato for paga.',
            'permitir_whatsapp' => true,
        ]);
        exit;
    }

    // Elegível! Gera o código e salva.
    $codigo = gerarCodigoIndicacao($conn);
    $contratoId = (int) ($contratoAtivo['contrato'] ?? 0);

    $stmt = $conn->prepare(
        'INSERT INTO indicacoes (codigo, indicador_cpfcnpj, indicador_nome, indicador_contrato_id) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('sssi', $codigo, $cpf, $nome, $contratoId);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode([
        'sucesso' => true,
        'link' => 'https://zamtech.com.br/assinar?ref=' . $codigo,
        'ja_existia' => false,
    ]);
} catch (\Throwable $e) {
    error_log('Indique e Ganhe - gerar-link.php falhou: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Deu um erro aqui do nosso lado. Tenta de novo em instantes ou fala com a gente pelo WhatsApp.',
        'permitir_whatsapp' => true,
    ]);
}
