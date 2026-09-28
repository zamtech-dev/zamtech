<?php
// Baixa uma foto escolhida no Unsplash/Pexels e salva ela no NOSSO
// servidor (convertida pra WebP, igual ao upload manual) — nunca faz
// hotlink direto no site externo, porque aí a imagem podia sumir do
// artigo se a conta de lá mudasse ou o link expirasse.

require_once __DIR__ . '/../_auth.php';

header('Content-Type: application/json');

if (!estaLogado()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Sessão expirada, faça login de novo.']);
    exit;
}

$dados = json_decode(file_get_contents('php://input'), true);
if (!is_array($dados)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Dados inválidos.']);
    exit;
}

validarCsrfOuMorrer((string) ($dados['csrf'] ?? ''));

$alt = trim((string) ($dados['alt'] ?? ''));
$urlOrigem = trim((string) ($dados['url'] ?? ''));
$downloadLocation = trim((string) ($dados['download_location'] ?? ''));

if ($alt === '') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Escreva o texto alternativo (alt) da imagem antes de enviar.']);
    exit;
}

if ($urlOrigem === '' || !filter_var($urlOrigem, FILTER_VALIDATE_URL)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Imagem inválida.']);
    exit;
}

// só aceita baixar de domínios que a gente conhece (Unsplash/Pexels) — isso
// aqui não pode virar um "baixador de qualquer URL da internet".
$hostOrigem = parse_url($urlOrigem, PHP_URL_HOST) ?? '';
$hostsPermitidos = ['images.unsplash.com', 'images.pexels.com'];
$hostPermitido = false;
foreach ($hostsPermitidos as $hostOk) {
    if ($hostOrigem === $hostOk || str_ends_with($hostOrigem, '.' . $hostOk)) {
        $hostPermitido = true;
        break;
    }
}
if (!$hostPermitido) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Essa imagem não vem de uma fonte permitida.']);
    exit;
}

$ch = curl_init($urlOrigem);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
]);
$binario = curl_exec($ch);
$codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($binario === false || $codigo !== 200) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Não consegui baixar essa imagem agora. Tenta de novo.']);
    exit;
}

if (strlen($binario) > 20 * 1024 * 1024) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Imagem grande demais.']);
    exit;
}

$infoImagem = @getimagesizefromstring($binario);
if (!$infoImagem) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Isso não é uma imagem válida.']);
    exit;
}

$imagemOrigem = @imagecreatefromstring($binario);
if (!$imagemOrigem) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Não consegui processar essa imagem.']);
    exit;
}

$url = salvarImagemComoWebp($imagemOrigem);

if (!$url) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Falha ao salvar a imagem convertida.']);
    exit;
}

// O Unsplash pede (nos termos da API deles) pra avisar que a foto foi
// efetivamente usada, não só exibida na busca — dispara isso em segundo
// plano, sem travar a resposta pro usuário se falhar.
if ($downloadLocation !== '' && str_contains($downloadLocation, 'unsplash.com') && UNSPLASH_ACCESS_KEY !== '') {
    $separador = str_contains($downloadLocation, '?') ? '&' : '?';
    $chPing = curl_init($downloadLocation . $separador . 'client_id=' . UNSPLASH_ACCESS_KEY);
    curl_setopt_array($chPing, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    @curl_exec($chPing);
    curl_close($chPing);
}

echo json_encode([
    'sucesso' => true,
    'url' => $url,
    'alt' => $alt,
]);
