<?php
require_once __DIR__ . '/admin/_config.php';

$porPagina = 9;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$offset = ($pagina - 1) * $porPagina;

$busca = trim((string) ($_GET['busca'] ?? ''));
$categoriaFiltroBruta = trim((string) ($_GET['categoria'] ?? ''));
$ordenar = ($_GET['ordenar'] ?? '') === 'populares' ? 'populares' : 'recentes';

/**
 * Faz bind_param com uma quantidade variável de parâmetros (a busca e o
 * filtro de categoria são opcionais, então o número de "?" muda) e já
 * executa a query.
 */
function bindEExecutar(mysqli_stmt $stmt, string $tipos, array $parametros): void
{
    if ($tipos === '') {
        $stmt->execute();
        return;
    }
    $referencias = [$tipos];
    foreach ($parametros as $chave => $valor) {
        $referencias[] = &$parametros[$chave];
    }
    call_user_func_array([$stmt, 'bind_param'], $referencias);
    $stmt->execute();
}

/**
 * Monta um link de /blog preservando busca, categoria e ordenação atuais,
 * sobrescrevendo só o que for passado em $sobrescreve. Usar null pra tirar
 * um parâmetro da URL (ex: ao trocar de filtro, a página volta pra 1).
 */
function linkComFiltros(array $sobrescreve = []): string
{
    global $busca, $categoriaFiltro, $ordenar;
    $params = [
        'busca' => $busca !== '' ? $busca : null,
        'categoria' => $categoriaFiltro !== '' ? $categoriaFiltro : null,
        'ordenar' => $ordenar !== 'recentes' ? $ordenar : null,
    ];
    $params = array_merge($params, $sobrescreve);
    $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
    $query = http_build_query($params);
    return $query === '' ? '/blog' : ('/blog?' . $query);
}

/**
 * Ícone (dos assets do próprio site) pra identificar cada categoria
 * visualmente. Se um dia criar uma categoria nova pelo painel e esquecer
 * de mapear aqui, cai num ícone genérico — não quebra nada.
 */
function iconeCategoria(string $categoria): string
{
    $mapa = [
        'Residencial' => '/assets/icons/others-icons/casa-house.svg',
        'Empresarial' => '/assets/icons/others-icons/empresarial.svg',
        'Dicas' => '/assets/icons/tech-icon.svg',
        'Novidades' => '/assets/icons/star.svg',
    ];
    return $mapa[$categoria] ?? '/assets/icons/others-icons/casa-house.svg';
}

function formatarDataBr(?string $data): string
{
    if (!$data) {
        return '';
    }
    $meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $ts = strtotime($data);
    return (int) date('d', $ts) . ' de ' . $meses[(int) date('n', $ts) - 1] . ' de ' . date('Y', $ts);
}

$conn = conectarBanco();

// só aceita uma categoria que realmente existe na tabela blog_categorias —
// qualquer outra coisa na URL vira "sem filtro", não confia em texto livre.
$categoriaFiltro = in_array($categoriaFiltroBruta, listarCategorias($conn), true) ? $categoriaFiltroBruta : '';

// --- filtro (busca + categoria), reaproveitado na contagem e na listagem ---
$condicoes = ["status = 'publicado'"];
$parametros = [];
$tipos = '';

if ($busca !== '') {
    $condicoes[] = '(titulo LIKE ? OR resumo LIKE ?)';
    $comCuringa = '%' . $busca . '%';
    $parametros[] = $comCuringa;
    $parametros[] = $comCuringa;
    $tipos .= 'ss';
}
if ($categoriaFiltro !== '') {
    $condicoes[] = 'categoria = ?';
    $parametros[] = $categoriaFiltro;
    $tipos .= 's';
}
$whereSql = implode(' AND ', $condicoes);
$orderSql = $ordenar === 'populares' ? 'visualizacoes DESC, publicado_em DESC' : 'publicado_em DESC';

$stmtTotal = $conn->prepare("SELECT COUNT(*) AS total FROM blog_artigos WHERE {$whereSql}");
bindEExecutar($stmtTotal, $tipos, $parametros);
$total = (int) ($stmtTotal->get_result()->fetch_assoc()['total'] ?? 0);
$stmtTotal->close();
$totalPaginas = max(1, (int) ceil($total / $porPagina));

$stmt = $conn->prepare(
    "SELECT titulo, slug, categoria, resumo, imagem_capa, imagem_capa_alt, publicado_em
     FROM blog_artigos
     WHERE {$whereSql}
     ORDER BY {$orderSql}
     LIMIT ? OFFSET ?"
);
$parametrosListagem = $parametros;
$parametrosListagem[] = $porPagina;
$parametrosListagem[] = $offset;
bindEExecutar($stmt, $tipos . 'ii', $parametrosListagem);
$artigos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- widgets da barra lateral: sempre olham pro blog inteiro, sem o filtro atual da busca ---
$categorias = $conn->query(
    "SELECT categoria, COUNT(*) AS total
     FROM blog_artigos
     WHERE status = 'publicado' AND categoria IS NOT NULL AND categoria <> ''
     GROUP BY categoria
     ORDER BY categoria"
)->fetch_all(MYSQLI_ASSOC);

$populares = $conn->query(
    "SELECT titulo, slug, imagem_capa, imagem_capa_alt
     FROM blog_artigos
     WHERE status = 'publicado'
     ORDER BY visualizacoes DESC, publicado_em DESC
     LIMIT 3"
)->fetch_all(MYSQLI_ASSOC);

$recentes = $conn->query(
    "SELECT titulo, slug, publicado_em
     FROM blog_artigos
     WHERE status = 'publicado'
     ORDER BY publicado_em DESC
     LIMIT 3"
)->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <!-- preconnect + <link> pra fonte do Google em vez de @import dentro do
         CSS: o @import obrigava o navegador a esperar blog.css inteiro
         chegar pra só então descobrir que precisava buscar a fonte em outro
         site, atrasando a primeira pintura da página — esse atraso é o
         "flash cinza" rápido que aparece ao abrir o blog. -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@500;700;900&family=Red+Hat+Text:wght@400;500;600&display=swap" />
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

        <div class="blog-layout">
            <!-- Barra lateral esquerda -->
            <aside class="blog-sidebar">

                <!-- 1. Barra de pesquisa -->
                <div class="sidebar-card">
                    <h2 class="sidebar-card-titulo">Pesquisar</h2>
                    <form method="get" action="/blog" class="busca-form">
                        <?php if ($categoriaFiltro !== ''): ?>
                            <input type="hidden" name="categoria" value="<?= htmlspecialchars($categoriaFiltro, ENT_QUOTES) ?>" />
                        <?php endif; ?>
                        <?php if ($ordenar !== 'recentes'): ?>
                            <input type="hidden" name="ordenar" value="<?= htmlspecialchars($ordenar, ENT_QUOTES) ?>" />
                        <?php endif; ?>
                        <div class="busca-campo">
                            <input
                                type="search"
                                name="busca"
                                value="<?= htmlspecialchars($busca, ENT_QUOTES) ?>"
                                placeholder="Buscar artigo..."
                                class="busca-input"
                                aria-label="Buscar artigo no blog"
                            />
                            <button type="submit" class="busca-botao" aria-label="Buscar">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                                    <circle cx="11" cy="11" r="7"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                            </button>
                        </div>
                    </form>
                    <?php if ($busca !== ''): ?>
                        <a href="<?= htmlspecialchars(linkComFiltros(['busca' => null, 'pagina' => null]), ENT_QUOTES) ?>" class="busca-limpar">Limpar busca &times;</a>
                    <?php endif; ?>
                </div>

                <!-- 2. Filtro de pesquisa (ordenação) -->
                <div class="sidebar-card">
                    <h2 class="sidebar-card-titulo">Ordenar por</h2>
                    <div class="filtro-tabs">
                        <a href="<?= htmlspecialchars(linkComFiltros(['ordenar' => null, 'pagina' => null]), ENT_QUOTES) ?>" class="filtro-tab <?= $ordenar === 'recentes' ? 'ativo' : '' ?>">Recentes</a>
                        <a href="<?= htmlspecialchars(linkComFiltros(['ordenar' => 'populares', 'pagina' => null]), ENT_QUOTES) ?>" class="filtro-tab <?= $ordenar === 'populares' ? 'ativo' : '' ?>">Mais lidos</a>
                    </div>
                </div>

                <!-- 3. Categoria -->
                <div class="sidebar-card">
                    <h2 class="sidebar-card-titulo">Categorias</h2>
                    <ul class="categoria-lista">
                        <li>
                            <a href="<?= htmlspecialchars(linkComFiltros(['categoria' => null, 'pagina' => null]), ENT_QUOTES) ?>" class="<?= $categoriaFiltro === '' ? 'ativo' : '' ?>">
                                Todas
                            </a>
                        </li>
                        <?php foreach ($categorias as $cat): ?>
                            <li>
                                <a href="<?= htmlspecialchars(linkComFiltros(['categoria' => $cat['categoria'], 'pagina' => null]), ENT_QUOTES) ?>" class="<?= $categoriaFiltro === $cat['categoria'] ? 'ativo' : '' ?>">
                                    <span class="categoria-nome">
                                        <img src="<?= iconeCategoria($cat['categoria']) ?>" alt="" class="categoria-icone" />
                                        <?= htmlspecialchars($cat['categoria'], ENT_QUOTES) ?>
                                    </span>
                                    <span class="categoria-contagem"><?= (int) $cat['total'] ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- 4. Quem é a Zamtech -->
                <div class="sidebar-card sidebar-card-destaque sidebar-card-com-imagem">
                    <div class="sidebar-card-banner">
                        <img src="/assets/img/sobre-imgs/01-conheca-a-zamtech-fibra-optica.webp" alt="" loading="lazy" />
                        <span class="sidebar-card-icone-badge">
                            <img src="/assets/icons/sobre.svg" alt="" />
                        </span>
                    </div>
                    <div class="sidebar-card-corpo">
                        <h2 class="sidebar-card-titulo">Quem é a Zamtech?</h2>
                        <p>Internet fibra óptica de verdade, com suporte que responde e planos pensados pra sua casa ou pra sua empresa.</p>
                        <a href="/sobre" class="sidebar-card-link">Conhecer a Zamtech &rarr;</a>
                    </div>
                </div>

                <!-- Conheça produtos residenciais -->
                <div class="sidebar-card sidebar-card-com-imagem">
                    <div class="sidebar-card-banner">
                        <img src="/assets/img/backgrounds/wallpaper-planos-residenciais.webp" alt="" loading="lazy" />
                        <span class="sidebar-card-icone-badge">
                            <img src="/assets/icons/others-icons/casa-house.svg" alt="" />
                        </span>
                    </div>
                    <div class="sidebar-card-corpo">
                        <h2 class="sidebar-card-titulo">Pra sua casa</h2>
                        <p>Planos de internet residencial com Wi-Fi de verdade em todo cômodo.</p>
                        <a href="/planos-residenciais" class="sidebar-card-link">Ver planos residenciais &rarr;</a>
                    </div>
                </div>

                <!-- Conheça produtos empresariais -->
                <div class="sidebar-card sidebar-card-escura sidebar-card-com-imagem">
                    <div class="sidebar-card-banner">
                        <img src="/assets/img/backgrounds/background-planos-empresariais.jpg" alt="" loading="lazy" />
                        <span class="sidebar-card-icone-badge">
                            <img src="/assets/icons/others-icons/empresarial.svg" alt="" />
                        </span>
                    </div>
                    <div class="sidebar-card-corpo">
                        <h2 class="sidebar-card-titulo">Pra sua empresa</h2>
                        <p>Link dedicado, estabilidade e suporte prioritário pro seu negócio não parar.</p>
                        <a href="/planos-empresariais" class="sidebar-card-link">Ver planos empresariais &rarr;</a>
                    </div>
                </div>

                <!-- Botão indique e ganhe (mesmo padrão de emoji que o menu real do site já usa aqui) -->
                <div class="sidebar-card sidebar-card-indique">
                    <h2 class="sidebar-card-titulo">🎁 Indique e Ganhe</h2>
                    <p>Indique a Zamtech pra um amigo e ganhe desconto na sua fatura quando ele contratar.</p>
                    <a href="/indique" class="btn-indique">Quero indicar</a>
                </div>

                <!-- Artigos populares -->
                <div class="sidebar-card">
                    <h2 class="sidebar-card-titulo sidebar-card-titulo-com-icone">
                        <img src="/assets/icons/star.svg" alt="" class="titulo-icone" />
                        Artigos populares
                    </h2>
                    <?php if (empty($populares)): ?>
                        <p class="sidebar-vazio">Ainda não tem artigo publicado.</p>
                    <?php else: ?>
                        <ul class="sidebar-lista-artigos">
                            <?php foreach ($populares as $pop): ?>
                                <li>
                                    <a href="/blog/<?= htmlspecialchars($pop['slug'], ENT_QUOTES) ?>">
                                        <?php if ($pop['imagem_capa']): ?>
                                            <img src="<?= htmlspecialchars($pop['imagem_capa'], ENT_QUOTES) ?>" alt="" loading="lazy" />
                                        <?php else: ?>
                                            <span class="sidebar-lista-sem-imagem" aria-hidden="true"></span>
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($pop['titulo'], ENT_QUOTES) ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Artigos recentes -->
                <div class="sidebar-card">
                    <h2 class="sidebar-card-titulo sidebar-card-titulo-com-icone">
                        <img src="/assets/icons/others-icons/relogio-disponibilidade.svg" alt="" class="titulo-icone" />
                        Artigos recentes
                    </h2>
                    <?php if (empty($recentes)): ?>
                        <p class="sidebar-vazio">Ainda não tem artigo publicado.</p>
                    <?php else: ?>
                        <ul class="sidebar-lista-artigos sidebar-lista-simples">
                            <?php foreach ($recentes as $rec): ?>
                                <li>
                                    <a href="/blog/<?= htmlspecialchars($rec['slug'], ENT_QUOTES) ?>">
                                        <span><?= htmlspecialchars($rec['titulo'], ENT_QUOTES) ?></span>
                                        <span class="sidebar-lista-data"><?= formatarDataBr($rec['publicado_em']) ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </aside>

            <!-- Conteúdo principal: resultado da busca/filtro + grid de artigos -->
            <div class="blog-conteudo">
                <?php if ($busca !== '' || $categoriaFiltro !== ''): ?>
                    <p class="blog-resultado-info">
                        <?= $total ?> artigo<?= $total === 1 ? '' : 's' ?> encontrado<?= $total === 1 ? '' : 's' ?>
                        <?php if ($busca !== ''): ?> pra "<strong><?= htmlspecialchars($busca, ENT_QUOTES) ?></strong>"<?php endif; ?>
                        <?php if ($categoriaFiltro !== ''): ?> em <strong><?= htmlspecialchars($categoriaFiltro, ENT_QUOTES) ?></strong><?php endif; ?>
                        &middot; <a href="/blog">limpar filtros</a>
                    </p>
                <?php endif; ?>

                <?php if (empty($artigos)): ?>
                    <div class="blog-vazio">
                        <p>
                            <?= ($busca !== '' || $categoriaFiltro !== '')
                                ? 'Nenhum artigo encontrado com esse filtro.'
                                : 'Ainda não publicamos nenhum artigo por aqui. Volte em breve!' ?>
                        </p>
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
                                    <div class="blog-card-topo">
                                        <span class="blog-card-data"><?= formatarDataBr($artigo['publicado_em']) ?></span>
                                        <?php if ($artigo['categoria']): ?>
                                            <span class="blog-card-categoria">
                                                <img src="<?= iconeCategoria($artigo['categoria']) ?>" alt="" class="blog-card-categoria-icone" />
                                                <?= htmlspecialchars($artigo['categoria'], ENT_QUOTES) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <h2 class="blog-card-titulo"><?= htmlspecialchars($artigo['titulo'], ENT_QUOTES) ?></h2>
                                    <p class="blog-card-resumo"><?= htmlspecialchars($artigo['resumo'], ENT_QUOTES) ?></p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalPaginas > 1): ?>
                        <nav class="blog-paginacao">
                            <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                                <a href="<?= htmlspecialchars(linkComFiltros(['pagina' => $p]), ENT_QUOTES) ?>" class="<?= $p === $pagina ? 'ativa' : '' ?>"><?= $p ?></a>
                            <?php endfor; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php require __DIR__ . '/_footer.php'; ?>
    <?php require __DIR__ . '/_lgpd.php'; ?>
    <?php require __DIR__ . '/_whatsapp.php'; ?>
</body>
</html>
