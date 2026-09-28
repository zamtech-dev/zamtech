<?php
// Gera o sitemap XML dos artigos publicados, sempre atualizado (não é um
// arquivo estático) — é a peça central do SEO do blog, já que o "ping"
// direto pro Google não existe mais e a Indexing API não serve pra isso
// (ela só vale pra vaga de emprego e evento ao vivo). O Google lê esse
// arquivo periodicamente e assim descobre e revisita os artigos.

require_once __DIR__ . '/admin/_config.php';

$conn = conectarBanco();
$resultado = $conn->query(
    "SELECT slug, atualizado_em FROM blog_artigos WHERE status = 'publicado' ORDER BY publicado_em DESC"
);
$artigos = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
$conn->close();

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc><?= SITE_URL ?>/blog</loc>
        <changefreq>daily</changefreq>
        <priority>0.8</priority>
    </url>
    <?php foreach ($artigos as $artigo): ?>
    <url>
        <loc><?= SITE_URL ?>/blog/<?= htmlspecialchars($artigo['slug'], ENT_QUOTES | ENT_XML1) ?></loc>
        <lastmod><?= date('c', strtotime($artigo['atualizado_em'])) ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>
    <?php endforeach; ?>
</urlset>
