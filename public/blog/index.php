<?php
require_once __DIR__ . '/admin/_config.php';

$porPagina = 9;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$offset = ($pagina - 1) * $porPagina;

$conn = conectarBanco();

$totalResultado = $conn->query("SELECT COUNT(*) AS total FROM blog_artigos WHERE status = 'publicado'");
$total = (int) ($totalResultado->fetch_assoc()['total'] ?? 0);
$totalPaginas = max(1, (int) ceil($total / $porPagina));

$stmt = $conn->prepare(
    "SELECT titulo, slug, resumo, imagem_capa, imagem_capa_alt, publicado_em
     FROM blog_artigos
     WHERE status = 'publicado'
     ORDER BY publicado_em DESC
     LIMIT ? OFFSET ?"
);
$stmt->bind_param('ii', $porPagina, $offset);
$stmt->execute();
$artigos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

function formatarDataBr(?string $data): string
{
    if (!$data) {
        return '';
    }
    $meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $ts = strtotime($data);
    return (int) date('d', $ts) . ' de ' . $meses[(int) date('n', $ts) - 1] . ' de ' . date('Y', $ts);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Blog Zamtech — Notícias, dicas e novidades sobre internet fibra</title>
    <meta name="description" content="Fique por dentro de dicas de internet, novidades da Zamtech e conteúdos sobre fibra óptica, Wi-Fi e conectividade." />
    <link rel="canonical" href="<?= SITE_URL ?>/blog" />

    <meta property="og:type" content="website" />
    <meta property="og:title" content="Blog Zamtech" />
    <meta property="og:description" content="Notícias, dicas e novidades sobre internet fibra óptica." />
    <meta property="og:url" content="<?= SITE_URL ?>/blog" />
    <meta property="og:image" content="<?= SITE_URL ?>/assets/icons/logo-zamtech.svg" />
    <meta name="twitter:card" content="summary_large_image" />

    <link rel="shortcut icon" href="/assets/icons/website-global-icons/icon-zamtech.svg" type="image/x-icon" />
    <link rel="apple-touch-icon" href="/assets/icons/website-global-icons/icon-zamtech.svg" />
    <link rel="stylesheet" href="/blog/assets/blog.css" />

    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Blog',
        'name' => 'Blog Zamtech',
        'url' => SITE_URL . '/blog',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    </script>
</head>
<body class="margin-compensa">
    <?php require __DIR__ . '/_header.php'; ?>

    <main class="blog-listagem section container">
        <h1 class="blog-titulo-pagina">Blog Zamtech</h1>
        <p class="blog-subtitulo-pagina">Dicas, novidades e conteúdo sobre internet fibra óptica</p>

        <?php if (empty($artigos)): ?>
            <div class="blog-vazio">
                <p>Ainda não publicamos nenhum artigo por aqui. Volte em breve!</p>
            </div>
        <?php else: ?>
            <div class="blog-grid">
                <?php foreach ($artigos as $artigo): ?>
                    <a href="/blog/<?= htmlspecialchars($artigo['slug'], ENT_QUOTES) ?>" class="blog-card">
                        <?php if ($artigo['imagem_capa']): ?>
                            <img
                                src="<?= htmlspecialchars($artigo['imagem_capa'], ENT_QUOTES) ?>"
                                alt="<?= htmlspecialchars($artigo['imagem_capa_alt'] ?: '', ENT_QUOTES) ?>"
                                class="blog-card-imagem"
                                loading="lazy"
                            />
                        <?php endif; ?>
                        <div class="blog-card-corpo">
                            <span class="blog-card-data"><?= formatarDataBr($artigo['publicado_em']) ?></span>
                            <h2 class="blog-card-titulo"><?= htmlspecialchars($artigo['titulo'], ENT_QUOTES) ?></h2>
                            <p class="blog-card-resumo"><?= htmlspecialchars($artigo['resumo'], ENT_QUOTES) ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <nav class="blog-paginacao">
                    <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                        <a href="?pagina=<?= $p ?>" class="<?= $p === $pagina ? 'ativa' : '' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <?php require __DIR__ . '/_footer.php'; ?>
    <?php require __DIR__ . '/_lgpd.php'; ?>
    <?php require __DIR__ . '/_whatsapp.php'; ?>
</body>
</html>
