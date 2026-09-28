<?php
require_once __DIR__ . '/../_auth.php';

header('Content-Type: application/json');

if (!estaLogado()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Sessão expirada, faça login de novo.']);
    exit;
}

$dados = json_decode(file_get_contents('php://input'), true) ?? [];
validarCsrfOuMorrer((string) ($dados['csrf'] ?? ''));

$id = (int) ($dados['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Artigo inválido.']);
    exit;
}

$conn = conectarBanco();
$stmt = $conn->prepare('DELETE FROM blog_artigos WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$apagou = $stmt->affected_rows > 0;
$stmt->close();
$conn->close();

echo json_encode(['sucesso' => $apagou, 'mensagem' => $apagou ? '' : 'Artigo não encontrado.']);
