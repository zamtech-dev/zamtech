<?php
require_once __DIR__ . '/admin/_config.php';
require_once __DIR__ . '/_render.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '') {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$conn = conectarBanco();
$stmt = $conn->prepare(
    "SELECT id, titulo, slug, resumo, conteudo_json, conteudo_html, imagem_capa, imagem_capa_alt,
            meta_titulo, meta_descricao, criado_em, atualizado_em, publicado_em, visualizacoes
     FROM blog_artigos
     WHERE slug = ? AND status = 'publicado'"
);
$stmt->bind_param('s', $slug);
$stmt->execute();
$artigo = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$artigo) {
    http_response_code(404);
    $conn->close();
    require __DIR__ . '/404.php';
    exit;
}

// conta visualização (simples, sem exigir JS nem sessão)
$stmtView = $conn->prepare('UPDATE blog_artigos SET visualizacoes = visualizacoes + 1 WHERE id = ?');
$stmtView->bind_param('i', $artigo['id']);
$stmtView->execute();
$stmtView->close();
$conn->close();

$blocos = json_decode($artigo['conteudo_json'], true) ?: [];
$tempoLeitura = calcularTempoLeitura($artigo['conteudo_html']);
$usaInstagram = artigoUsaEmbedSocial($blocos, 'instagram');
$usaTwitter = artigoUsaEmbedSocial($blocos, 'twitter');

$urlCanonica = SITE_URL . '/blog/' . $artigo['slug'];
$tituloSeo = $artigo['meta_titulo'] ?: $artigo['titulo'];
$descricaoSeo = $artigo['meta_descricao'] ?: $artigo['resumo'];
$imagemSeo = $artigo['imagem_capa'] ? SITE_URL . $artigo['imagem_capa'] : SITE_URL . '/assets/icons/logo-zamtech.svg';

function formatarDataIso(?string $data): string
{
    return $data ? date('c', strtotime($data)) : '';
}

function formatarDataLeitura(?string $data): string
{
    if (!$data) {
        return '';
    }
    $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $ts = strtotime($data);
    return (int) date('d', $ts) . ' de ' . $meses[(int) date('n', $ts) - 1] . ' de ' . date('Y', $ts);
}

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BlogPosting',
    'headline' => $artigo['titulo'],
    'description' => $descricaoSeo,
    'datePublished' => formatarDataIso($artigo['publicado_em']),
    'dateModified' => formatarDataIso($artigo['atualizado_em']),
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $urlCanonica],
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'Zamtech',
        'logo' => ['@type' => 'ImageObject', 'url' => SITE_URL . '/assets/icons/logo-zamtech.svg'],
    ],
    'author' => ['@type' => 'Organization', 'name' => 'Zamtech'],
];
if ($artigo['imagem_capa']) {
    $jsonLd['image'] = [$imagemSeo];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <!-- fonte via <link> (não mais @import no CSS) — evita o atraso na
         primeira pintura que causava o flash cinza rápido ao abrir a página -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@500;700;900&family=Red+Hat+Text:wght@400;500;600&display=swap" />
    <title><?= htmlspecialchars($tituloSeo, ENT_QUOTES) ?> — Blog Zamtech</title>
    <meta name="description" content="<?= htmlspecialchars($descricaoSeo, ENT_QUOTES) ?>" />
    <link rel="canonical" href="<?= htmlspecialchars($urlCanonica, ENT_QUOTES) ?>" />

    <meta property="og:type" content="article" />
    <meta property="og:title" content="<?= htmlspecialchars($tituloSeo, ENT_QUOTES) ?>" />
    <meta property="og:description" content="<?= htmlspecialchars($descricaoSeo, ENT_QUOTES) ?>" />
    <meta property="og:url" content="<?= htmlspecialchars($urlCanonica, ENT_QUOTES) ?>" />
    <meta property="og:image" content="<?= htmlspecialchars($imagemSeo, ENT_QUOTES) ?>" />
    <meta property="og:image:secure_url" content="<?= htmlspecialchars($imagemSeo, ENT_QUOTES) ?>" />
    <meta property="og:image:type" content="<?= $artigo['imagem_capa'] ? 'image/webp' : 'image/svg+xml' ?>" />
    <meta property="article:published_time" content="<?= formatarDataIso($artigo['publicado_em']) ?>" />
    <meta property="article:modified_time" content="<?= formatarDataIso($artigo['atualizado_em']) ?>" />

    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="<?= htmlspecialchars($tituloSeo, ENT_QUOTES) ?>" />
    <meta name="twitter:description" content="<?= htmlspecialchars($descricaoSeo, ENT_QUOTES) ?>" />
    <meta name="twitter:image" content="<?= htmlspecialchars($imagemSeo, ENT_QUOTES) ?>" />

    <link rel="shortcut icon" href="/assets/icons/website-global-icons/icon-zamtech.svg" type="image/x-icon" />
    <link rel="apple-touch-icon" href="/assets/icons/website-global-icons/icon-zamtech.svg" />
    <link rel="stylesheet" href="/blog/assets/blog.css" />

    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body class="margin-compensa">
    <?php require __DIR__ . '/_header.php'; ?>

    <main class="artigo-pagina section container">
        <article>
            <header class="artigo-cabecalho">
                <h1><?= htmlspecialchars($artigo['titulo'], ENT_QUOTES) ?></h1>
                <p class="artigo-meta">
                    <?= formatarDataLeitura($artigo['publicado_em']) ?> &middot; <?= $tempoLeitura ?> min de leitura
                </p>
            </header>

            <?php if ($artigo['imagem_capa']): ?>
                <img
                    src="<?= htmlspecialchars($artigo['imagem_capa'], ENT_QUOTES) ?>"
                    alt="<?= htmlspecialchars($artigo['imagem_capa_alt'] ?: '', ENT_QUOTES) ?>"
                    class="artigo-imagem-capa"
                />
            <?php endif; ?>

            <div class="artigo-corpo">
                <?= $artigo['conteudo_html'] ?>
            </div>
        </article>

        <div class="artigo-voltar">
            <a href="/blog" class="btn-voltar-blog">&larr; Ver todos os artigos</a>
        </div>
    </main>

    <?php require __DIR__ . '/_footer.php'; ?>
    <?php require __DIR__ . '/_lgpd.php'; ?>
    <?php require __DIR__ . '/_whatsapp.php'; ?>

    <?php if ($usaInstagram): ?>
        <script async src="https://www.instagram.com/embed.js"></script>
    <?php endif; ?>
    <?php if ($usaTwitter): ?>
        <script async src="https://platform.twitter.com/widgets.js"></script>
    <?php endif; ?>
</body>
</html>
