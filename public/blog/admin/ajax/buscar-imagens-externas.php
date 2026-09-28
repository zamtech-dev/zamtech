<?php
// Busca fotos no Unsplash ou no Pexels (via API deles) pra alimentar a
// aba correspondente no modal de imagem do editor. Só devolve o que a
// gente precisa mostrar (miniatura, foto em tamanho grande, crédito do
// fotógrafo) — a chave de API nunca sai daqui pro navegador.

require_once __DIR__ . '/../_auth.php';

header('Content-Type: application/json');

if (!estaLogado()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Sessão expirada.']);
    exit;
}

$fonte = ($_GET['fonte'] ?? '') === 'pexels' ? 'pexels' : 'unsplash';
$termo = trim((string) ($_GET['q'] ?? ''));

if ($termo === '') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Digite alguma coisa pra buscar.']);
    exit;
}

/**
 * Faz uma chamada GET simples numa API externa e devolve o corpo já
 * decodificado, ou null se der qualquer problema (timeout, erro HTTP,
 * resposta que não é JSON válido).
 */
function chamarApiExterna(string $url, array $headers): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resposta = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resposta === false || $codigo !== 200) {
        return null;
    }

    $dados = json_decode($resposta, true);
    return is_array($dados) ? $dados : null;
}

if ($fonte === 'unsplash') {
    if (UNSPLASH_ACCESS_KEY === '') {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Unsplash ainda não configurado nesse servidor (falta a chave de API).']);
        exit;
    }

    $url = 'https://api.unsplash.com/search/photos?per_page=24&query=' . urlencode($termo);
    $dados = chamarApiExterna($url, ['Authorization: Client-ID ' . UNSPLASH_ACCESS_KEY]);

    if ($dados === null) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Não consegui falar com o Unsplash agora. Tenta de novo em instantes.']);
        exit;
    }

    $imagens = array_map(static function (array $foto): array {
        return [
            'miniatura' => $foto['urls']['small'] ?? '',
            'completa' => $foto['urls']['regular'] ?? ($foto['urls']['full'] ?? ''),
            'credito' => 'Foto de ' . ($foto['user']['name'] ?? 'Unsplash') . ' · Unsplash',
            'download_location' => $foto['links']['download_location'] ?? null,
        ];
    }, $dados['results'] ?? []);

    echo json_encode(['sucesso' => true, 'imagens' => $imagens]);
    exit;
}

// --- Pexels ---
if (PEXELS_API_KEY === '') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Pexels ainda não configurado nesse servidor (falta a chave de API).']);
    exit;
}

$url = 'https://api.pexels.com/v1/search?per_page=24&query=' . urlencode($termo);
$dados = chamarApiExterna($url, ['Authorization: ' . PEXELS_API_KEY]);

if ($dados === null) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Não consegui falar com o Pexels agora. Tenta de novo em instantes.']);
    exit;
}

$imagens = array_map(static function (array $foto): array {
    return [
        'miniatura' => $foto['src']['medium'] ?? '',
        'completa' => $foto['src']['large2x'] ?? ($foto['src']['large'] ?? ''),
        'credito' => 'Foto de ' . ($foto['photographer'] ?? 'Pexels') . ' · Pexels',
        'download_location' => null,
    ];
}, $dados['photos'] ?? []);

echo json_encode(['sucesso' => true, 'imagens' => $imagens]);
