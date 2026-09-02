<?php
// Script SÓ PARA TESTE: apaga um registro de "indicado" (pré-cadastro) que
// ficou preso, junto com qualquer desconto ligado a ele. Serve pra limpar
// CPFs de teste que nunca viraram cliente de verdade no SGP (por isso o
// robô fica repetindo "ainda não apareceu como cliente" todo dia).
//
// Só apaga da NOSSA tabela (indicados/descontos) — não toca em nada no SGP.
require_once __DIR__ . '/_config.php';

$chaveInformada = $_GET['chave'] ?? '';
if (!hash_equals(ROBO_CHAVE_TESTE, $chaveInformada)) {
    http_response_code(403);
    exit('Acesso negado.');
}
header('Content-Type: text/plain; charset=UTF-8');

$cpf = preg_replace('/\D/', '', $_GET['cpf'] ?? '');
if ($cpf === '') {
    exit("Passa o CPF na URL, assim: ?chave=...&cpf=12345678900\n");
}

$conn = conectarBanco();

$stmt = $conn->prepare('SELECT id, indicado_nome, status FROM indicados WHERE indicado_cpfcnpj = ?');
$stmt->bind_param('s', $cpf);
$stmt->execute();
$indicados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($indicados)) {
    $conn->close();
    exit("Não achei nenhum indicado com esse CPF. Nada pra apagar.\n");
}

foreach ($indicados as $indicado) {
    $indicadoId = (int) $indicado['id'];

    $stmt = $conn->prepare('DELETE FROM descontos WHERE indicado_id = ?');
    $stmt->bind_param('i', $indicadoId);
    $stmt->execute();
    $descontosApagados = $stmt->affected_rows;
    $stmt->close();

    $stmt = $conn->prepare('DELETE FROM indicados WHERE id = ?');
    $stmt->bind_param('i', $indicadoId);
    $stmt->execute();
    $stmt->close();

    echo "Apaguei o indicado '{$indicado['indicado_nome']}' (status era '{$indicado['status']}') e {$descontosApagados} desconto(s) ligado(s) a ele.\n";
}

$conn->close();
echo "\nPronto — esse CPF não vai mais aparecer preso na lista do robô. Pode preencher o /assinar de novo com ele quando quiser.\n";
