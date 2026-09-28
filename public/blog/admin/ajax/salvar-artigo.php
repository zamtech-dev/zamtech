<?php
require_once __DIR__ . '/../_auth.php';
require_once dirname(__DIR__, 1) . '/_render.php';

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

$id = isset($dados['id']) ? (int) $dados['id'] : 0;
$titulo = trim((string) ($dados['titulo'] ?? ''));
$blocosRecebidos = is_array($dados['blocks'] ?? null) ? $dados['blocks'] : [];
$statusPedido = ($dados['status'] ?? 'rascunho') === 'publicado' ? 'publicado' : 'rascunho';
$categoriaRecebida = trim((string) ($dados['categoria'] ?? ''));
$imagemCapa = trim((string) ($dados['imagem_capa'] ?? ''));
$imagemCapaAlt = trim((string) ($dados['imagem_capa_alt'] ?? ''));
$resumo = trim((string) ($dados['resumo'] ?? ''));
$metaTitulo = trim((string) ($dados['meta_titulo'] ?? ''));
$metaDescricao = trim((string) ($dados['meta_descricao'] ?? ''));

if ($titulo === '') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'O artigo precisa de um título.']);
    exit;
}

if (empty($blocosRecebidos)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'O artigo está vazio. Escreva algo antes de salvar.']);
    exit;
}

// Só aceita blocos com um "type" que a gente realmente sabe renderizar —
// qualquer coisa fora disso é ignorada silenciosamente, não quebra o save.
$tiposValidos = ['paragraph', 'heading', 'quote', 'delimiter', 'image', 'embed'];
$blocosLimpos = [];
foreach ($blocosRecebidos as $bloco) {
    if (is_array($bloco) && in_array($bloco['type'] ?? '', $tiposValidos, true)) {
        $blocosLimpos[] = $bloco;
    }
}

if (empty($blocosLimpos)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'O artigo está vazio. Escreva algo antes de salvar.']);
    exit;
}

// Se for publicar, exige que toda imagem do artigo (capa + blocos) tenha
// alt preenchido — SEO e acessibilidade não são opcionais na hora de ir
// ao ar.
if ($statusPedido === 'publicado') {
    if ($imagemCapa !== '' && $imagemCapaAlt === '') {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Escreva o texto alternativo da imagem de capa antes de publicar.']);
        exit;
    }
    foreach ($blocosLimpos as $bloco) {
        if ($bloco['type'] === 'image' && trim((string) ($bloco['alt'] ?? '')) === '') {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Tem uma imagem no artigo sem texto alternativo (alt). Preencha antes de publicar.']);
            exit;
        }
    }
}

$conteudoHtml = renderizarBlocosParaHtml($blocosLimpos);
$conteudoJson = json_encode($blocosLimpos, JSON_UNESCAPED_UNICODE);

if ($resumo === '') {
    $resumo = mb_substr(trim(strip_tags($conteudoHtml)), 0, 300);
}
if ($metaTitulo === '') {
    $metaTitulo = mb_substr($titulo, 0, 70);
}
if ($metaDescricao === '') {
    $metaDescricao = mb_substr($resumo, 0, 160);
}

$conn = conectarBanco();
$autorId = (int) $_SESSION['blog_admin_id'];

// só aceita uma categoria que realmente existe na tabela blog_categorias —
// qualquer outra coisa vira "sem categoria", não confia em texto livre
// vindo do JS.
$categoria = in_array($categoriaRecebida, listarCategorias($conn), true) ? $categoriaRecebida : null;

if ($id > 0) {
    // edição — busca o artigo atual pra saber o slug e se já foi publicado antes
    $stmt = $conn->prepare('SELECT slug, status, publicado_em FROM blog_artigos WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $atual = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$atual) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Artigo não encontrado.']);
        exit;
    }

    $slug = $atual['slug'];
    $publicadoEm = $atual['publicado_em'];
    if ($statusPedido === 'publicado' && $atual['status'] !== 'publicado') {
        $publicadoEm = date('Y-m-d H:i:s');
    }

    $stmt = $conn->prepare(
        'UPDATE blog_artigos SET
            titulo = ?, categoria = ?, resumo = ?, conteudo_json = ?, conteudo_html = ?,
            imagem_capa = ?, imagem_capa_alt = ?, meta_titulo = ?, meta_descricao = ?,
            status = ?, publicado_em = ?
         WHERE id = ?'
    );
    $stmt->bind_param(
        'sssssssssssi',
        $titulo,
        $categoria,
        $resumo,
        $conteudoJson,
        $conteudoHtml,
        $imagemCapa,
        $imagemCapaAlt,
        $metaTitulo,
        $metaDescricao,
        $statusPedido,
        $publicadoEm,
        $id
    );
    $stmt->execute();
    $stmt->close();
} else {
    $slugBase = gerarSlug($titulo);
    $slug = gerarSlugUnico($conn, $slugBase);
    $publicadoEm = $statusPedido === 'publicado' ? date('Y-m-d H:i:s') : null;

    $stmt = $conn->prepare(
        'INSERT INTO blog_artigos
            (autor_id, titulo, slug, categoria, resumo, conteudo_json, conteudo_html,
             imagem_capa, imagem_capa_alt, meta_titulo, meta_descricao, status, publicado_em)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'issssssssssss',
        $autorId,
        $titulo,
        $slug,
        $categoria,
        $resumo,
        $conteudoJson,
        $conteudoHtml,
        $imagemCapa,
        $imagemCapaAlt,
        $metaTitulo,
        $metaDescricao,
        $statusPedido,
        $publicadoEm
    );
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
}

$conn->close();

echo json_encode([
    'sucesso' => true,
    'id' => $id,
    'slug' => $slug,
    'status' => $statusPedido,
    'url' => $statusPedido === 'publicado' ? SITE_URL . '/blog/' . $slug : null,
]);
