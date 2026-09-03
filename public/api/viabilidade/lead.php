<?php
// Guarda o contato de quem pesquisou um endereço sem cobertura (ou sem
// vaga na CTO mais próxima) — pra Carol ter uma lista de gente interessada
// e usar isso pra priorizar expansão de rede, ou avisar quando abrir vaga.
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
if (!is_array($input)) {
    $input = [];
}

$nome = isset($input['nome']) ? trim($input['nome']) : '';
$contato = isset($input['contato']) ? trim($input['contato']) : '';
$endereco = isset($input['endereco']) ? trim($input['endereco']) : '';
$motivo = isset($input['motivo']) && in_array($input['motivo'], ['sem_cobertura', 'sem_vaga'], true)
    ? $input['motivo']
    : 'sem_cobertura';

if ($nome === '' || $contato === '') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Preencha nome e telefone (ou email) pra gente conseguir te avisar.']);
    exit;
}

if ($endereco === '') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Endereço não informado.']);
    exit;
}

$linha = json_encode([
    'data' => date('Y-m-d H:i:s'),
    'nome' => $nome,
    'contato' => $contato,
    'endereco' => $endereco,
    'motivo' => $motivo,
], JSON_UNESCAPED_UNICODE);

@file_put_contents(VIABILIDADE_LEADS_ARQUIVO, $linha . "\n", FILE_APPEND | LOCK_EX);

$rotuloMotivo = $motivo === 'sem_vaga' ? 'Rede chega perto, mas sem porta livre' : 'Região ainda sem rede';

$assunto = 'Lead de viabilidade — ' . $nome;
$corpo = "Olá!\n\n"
    . "Alguém pesquisou viabilidade no site (zamtech.com.br) e não conseguimos atender agora:\n\n"
    . "Nome: {$nome}\n"
    . "Contato: {$contato}\n"
    . "Endereço pesquisado: {$endereco}\n"
    . "Motivo: {$rotuloMotivo}\n\n"
    . "— Site Zamtech, Consulta de Viabilidade";

enviarEmailViaSmtp(EMAIL_FINANCEIRO, $assunto, $corpo);

echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Prontinho! Anotamos seu contato e te avisamos assim que tiver novidade.',
]);
