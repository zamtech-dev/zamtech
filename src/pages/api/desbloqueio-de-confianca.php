<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Proteção de IP e Conexão Centralizada
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.']);
    exit;
}

// Recebe os parâmetros enviados pelo Front-end (Astro)
$contrato_id = $_POST['contrato'] ?? null;
$cpf_cliente = $_POST['cpf'] ?? null;

if (!$contrato_id || !$cpf_cliente) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Informe o CPF e o ID do contrato.']);
    exit;
}

try {
    // Parâmetros exigidos pela API da IXC para desbloqueio de confiança
    $params = array(
        'id' => $contrato_id 
    );

    $api->get('desbloqueio_confianca', $params);
    $retorno = $api->getRespostaConteudo(true); // Retorna array

    if ($retorno) {
        echo json_encode([
            'sucesso' => true, 
            'mensagem' => 'Desbloqueio de confiança efetuado com sucesso!'
        ]);
    } else {
        echo json_encode([
            'sucesso' => false, 
            'mensagem' => 'Não foi possível realizar o desbloqueio automático. Verifique os dados informados.'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false, 
        'mensagem' => 'Erro interno ao processar a solicitação com a IXC.'
    ]);
}