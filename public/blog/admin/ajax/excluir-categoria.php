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
    echo json_encode(['sucesso' => false, 'mensagem' => 'Categoria inválida.']);
    exit;
}

$conn = conectarBanco();

$stmt = $conn->prepare('SELECT nome FROM blog_categorias WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$categoria = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$categoria) {
    $conn->close();
    echo json_encode(['sucesso' => false, 'mensagem' => 'Categoria não encontrada.']);
    exit;
}

// tira a categoria dos artigos que usavam ela — não apaga os artigos,
// só deixa eles "sem categoria" (igual escolher "Sem categoria" no editor).
$stmtLimpa = $conn->prepare('UPDATE blog_artigos SET categoria = NULL WHERE categoria = ?');
$stmtLimpa->bind_param('s', $categoria['nome']);
$stmtLimpa->execute();
$stmtLimpa->close();

$stmtApaga = $conn->prepare('DELETE FROM blog_categorias WHERE id = ?');
$stmtApaga->bind_param('i', $id);
$stmtApaga->execute();
$apagou = $stmtApaga->affected_rows > 0;
$stmtApaga->close();
$conn->close();

echo json_encode(['sucesso' => $apagou, 'mensagem' => $apagou ? '' : 'Categoria não encontrada.']);
