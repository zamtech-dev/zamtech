<?php
// Lista as imagens já enviadas pro blog, pra alimentar o "banco de
// imagens" do editor (reaproveitar uma imagem já usada antes, sem ter
// que enviar de novo).

require_once __DIR__ . '/../_auth.php';

header('Content-Type: application/json');

if (!estaLogado()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Sessão expirada.']);
    exit;
}

$imagens = [];

if (is_dir(BLOG_UPLOAD_DIR)) {
    $arquivos = glob(BLOG_UPLOAD_DIR . '/*/*.webp') ?: [];
    // mais recente primeiro
    usort($arquivos, function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });

    foreach (array_slice($arquivos, 0, 60) as $caminho) {
        $relativo = str_replace(BLOG_UPLOAD_DIR, '', $caminho);
        $imagens[] = [
            'url' => BLOG_UPLOAD_URL . str_replace('\\', '/', $relativo),
        ];
    }
}

echo json_encode(['sucesso' => true, 'imagens' => $imagens]);
