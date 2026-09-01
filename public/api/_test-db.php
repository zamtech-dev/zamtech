<?php
header('Content-Type: application/json');

$host = 'localhost';
$db   = 'ztclas09_indique';
$user = 'ztclas09_indique';
$pass = '%rR3Zw9Q_MMmZC3s';

$conn = @new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    echo json_encode([
        'sucesso' => false,
        'erro' => $conn->connect_error,
        'testado' => ['host' => $host, 'db' => $db, 'user' => $user]
    ]);
    exit;
}

echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Conectou certinho no banco ' . $db,
    'mysql_version' => $conn->server_info
]);

$conn->close();
