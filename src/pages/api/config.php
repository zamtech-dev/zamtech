<?php
// config.php - Centralização de Conexão IXC para Zamtech Fibra Óptica

if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

// Requer a classe original do Webservice da IXC
require_once __DIR__ . '/WebserviceClient.php';

// Credenciais da IXC da Zamtech
$host = 'https://192.168.29.15/webservice/v1'; // Ajuste se necessário para o seu IP/domínio de produção
$token = 'TOKEN'; // Substitua pelo seu token real da IXC
$selfSigned = true;

try {
    $api = new IXCsoft\WebserviceClient($host, $token, $selfSigned);
} catch (Exception $e) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao instanciar o cliente da API IXC.'
    ]);
    exit;
}