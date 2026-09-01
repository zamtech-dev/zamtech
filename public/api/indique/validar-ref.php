<?php
// Confere se um código de indicação existe e está ativo, ANTES da pessoa
// preencher o formulário inteiro em /assinar. Não mexe em SGP nem em nada
// sensível — é só uma consulta rápida na nossa própria tabela, pra dar
// feedback imediato ("esse link não existe/expirou") sem fazer a pessoa
// perder tempo digitando endereço pra nada.
require_once __DIR__ . '/_config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['valido' => false, 'mensagem' => 'Método inválido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$ref = isset($input['ref']) ? trim($input['ref']) : '';

if ($ref === '') {
    echo json_encode(['valido' => false, 'mensagem' => 'Link de indicação inválido.']);
    exit;
}

try {
    $conn = conectarBanco();

    $stmt = $conn->prepare('SELECT codigo, indicador_nome FROM indicacoes WHERE codigo = ? AND status = "ativo" LIMIT 1');
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $indicacao = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$indicacao) {
        echo json_encode([
            'valido' => false,
            'mensagem' => 'Esse link de indicação não é válido ou já expirou. Peça um novo link de quem te indicou.',
        ]);
        exit;
    }

    echo json_encode(['valido' => true]);
} catch (\Throwable $e) {
    error_log('Indique e Ganhe - validar-ref.php falhou: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'valido' => false,
        'mensagem' => 'Não consegui confirmar o link agora. Tenta recarregar a página em instantes.',
    ]);
}
