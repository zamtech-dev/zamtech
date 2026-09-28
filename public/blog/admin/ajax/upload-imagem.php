<?php
// Recebe uma imagem já cortada/redimensionada pelo navegador (enviada como
// PNG ou JPEG em multipart/form-data), converte pra WebP no servidor via
// GD, e salva em public/assets/img/blog. Exige um texto alternativo (alt)
// não vazio — sem isso a imagem não sobe, por causa do SEO/acessibilidade.

require_once __DIR__ . '/../_auth.php';

header('Content-Type: application/json');

if (!estaLogado()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Sessão expirada, faça login de novo.']);
    exit;
}

validarCsrfOuMorrer((string) ($_POST['csrf'] ?? ''));

$alt = trim((string) ($_POST['alt'] ?? ''));
if ($alt === '') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Escreva o texto alternativo (alt) da imagem antes de enviar.']);
    exit;
}

if (empty($_FILES['imagem']) || $_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Nenhuma imagem recebida.']);
    exit;
}

$arquivo = $_FILES['imagem'];

if ($arquivo['size'] > 12 * 1024 * 1024) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Imagem muito grande (máximo 12MB).']);
    exit;
}

$infoImagem = @getimagesize($arquivo['tmp_name']);
if (!$infoImagem) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Arquivo não é uma imagem válida.']);
    exit;
}

$tipoMime = $infoImagem['mime'];
$imagemOrigem = null;

switch ($tipoMime) {
    case 'image/jpeg':
        $imagemOrigem = @imagecreatefromjpeg($arquivo['tmp_name']);
        break;
    case 'image/png':
        $imagemOrigem = @imagecreatefrompng($arquivo['tmp_name']);
        break;
    case 'image/webp':
        $imagemOrigem = @imagecreatefromwebp($arquivo['tmp_name']);
        break;
    default:
        echo json_encode(['sucesso' => false, 'mensagem' => 'Formato não aceito. Envie JPEG, PNG ou WebP.']);
        exit;
}

if (!$imagemOrigem) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Não consegui processar essa imagem.']);
    exit;
}

// PNG pode ter transparência — preserva ao converter pra WebP.
imagepalettetotruecolor($imagemOrigem);
imagealphablending($imagemOrigem, true);
imagesavealpha($imagemOrigem, true);

if (!is_dir(BLOG_UPLOAD_DIR)) {
    mkdir(BLOG_UPLOAD_DIR, 0755, true);
}

$nomeArquivo = date('Y-m') . '/' . bin2hex(random_bytes(8)) . '.webp';
$caminhoCompleto = BLOG_UPLOAD_DIR . '/' . $nomeArquivo;
$pastaDestino = dirname($caminhoCompleto);
if (!is_dir($pastaDestino)) {
    mkdir($pastaDestino, 0755, true);
}

$salvou = imagewebp($imagemOrigem, $caminhoCompleto, 82);
imagedestroy($imagemOrigem);

if (!$salvou) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Falha ao salvar a imagem convertida.']);
    exit;
}

echo json_encode([
    'sucesso' => true,
    'url' => BLOG_UPLOAD_URL . '/' . $nomeArquivo,
    'alt' => $alt,
]);
