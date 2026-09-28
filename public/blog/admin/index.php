<?php
require_once __DIR__ . '/_auth.php';
exigirLogin();

$conn = conectarBanco();

$filtro = $_GET['status'] ?? 'todos';
$where = '';
if ($filtro === 'rascunho' || $filtro === 'publicado') {
    $where = "WHERE status = '" . $conn->real_escape_string($filtro) . "'";
}

$resultado = $conn->query(
    "SELECT id, titulo, slug, categoria, status, imagem_capa, criado_em, publicado_em
     FROM blog_artigos
     {$where}
     ORDER BY atualizado_em DESC"
);

$artigos = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
$conn->close();

function formatarData(?string $data): string
{
    if (!$data) {
        return '';
    }
    return date('d/m/Y H:i', strtotime($data));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Painel do Blog — Zamtech</title>
    <meta name="robots" content="noindex, nofollow" />
    <link rel="stylesheet" href="/blog/admin/assets/admin.css" />
</head>
<body>
    <div class="admin-shell">
        <header class="admin-topbar">
            <div class="admin-topbar-brand">
                <img src="/assets/icons/logo-zamtech.svg" alt="Zamtech" onerror="this.style.display='none'" />
                <span>Blog</span>
            </div>
            <nav class="admin-topbar-nav">
                <a href="/blog/admin/" class="ativo">Artigos</a>
                <a href="/blog/admin/editor.php">Novo artigo</a>
                <a href="/blog/admin/configuracoes.php">Configurações</a>
                <a href="/blog" target="_blank">Ver blog</a>
                <a href="/blog/admin/logout.php" class="btn-sair">Sair</a>
            </nav>
        </header>

        <main class="admin-content">
            <div class="admin-page-header">
                <h1>Seus artigos</h1>
                <a href="/blog/admin/editor.php" class="btn btn-primary">+ Novo artigo</a>
            </div>

            <div style="margin-bottom: 16px; display:flex; gap:8px;">
                <a href="?status=todos" class="btn btn-sm <?= $filtro === 'todos' ? 'btn-primary' : 'btn-outline' ?>">Todos</a>
                <a href="?status=publicado" class="btn btn-sm <?= $filtro === 'publicado' ? 'btn-primary' : 'btn-outline' ?>">Publicados</a>
                <a href="?status=rascunho" class="btn btn-sm <?= $filtro === 'rascunho' ? 'btn-primary' : 'btn-outline' ?>">Rascunhos</a>
            </div>

            <div class="card">
                <?php if (empty($artigos)): ?>
                    <div class="estado-vazio">
                        <p>Nenhum artigo por aqui ainda.</p>
                    </div>
                <?php else: ?>
                    <div class="lista-artigos">
                        <?php foreach ($artigos as $artigo): ?>
                            <div class="item-artigo">
                                <img
                                    class="item-artigo-capa"
                                    src="<?= htmlspecialchars($artigo['imagem_capa'] ?: '/assets/icons/logo-zamtech.svg', ENT_QUOTES) ?>"
                                    alt=""
                                />
                                <div class="item-artigo-info">
                                    <div class="item-artigo-titulo"><?= htmlspecialchars($artigo['titulo'], ENT_QUOTES) ?></div>
                                    <div class="item-artigo-meta">
                                        <span class="badge <?= $artigo['status'] === 'publicado' ? 'badge-publicado' : 'badge-rascunho' ?>">
                                            <?= $artigo['status'] === 'publicado' ? 'Publicado' : 'Rascunho' ?>
                                        </span>
                                        <?php if ($artigo['categoria']): ?>
                                            <span class="badge badge-categoria"><?= htmlspecialchars($artigo['categoria'], ENT_QUOTES) ?></span>
                                        <?php endif; ?>
                                        <span>
                                            <?= $artigo['status'] === 'publicado'
                                                ? 'em ' . formatarData($artigo['publicado_em'])
                                                : 'criado em ' . formatarData($artigo['criado_em']) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="item-artigo-acoes">
                                    <?php if ($artigo['status'] === 'publicado'): ?>
                                        <a href="/blog/<?= htmlspecialchars($artigo['slug'], ENT_QUOTES) ?>" target="_blank" class="btn btn-sm btn-outline">Ver</a>
                                    <?php endif; ?>
                                    <a href="/blog/admin/editor.php?id=<?= (int) $artigo['id'] ?>" class="btn btn-sm btn-outline">Editar</a>
                                    <button type="button" class="btn btn-sm btn-danger-outline" data-excluir="<?= (int) $artigo['id'] ?>">Excluir</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
    document.querySelectorAll('[data-excluir]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var id = botao.getAttribute('data-excluir');
            if (!confirm('Excluir este artigo? Essa ação não pode ser desfeita.')) {
                return;
            }
            fetch('/blog/admin/ajax/excluir-artigo.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ id: id, csrf: '<?= pegarTokenCsrf() ?>' }),
            })
                .then(function (r) { return r.json(); })
                .then(function (dados) {
                    if (dados.sucesso) {
                        location.reload();
                    } else {
                        alert(dados.mensagem || 'Não deu pra excluir.');
                    }
                })
                .catch(function () { alert('Erro de conexão. Tente de novo.'); });
        });
    });
    </script>
</body>
</html>
