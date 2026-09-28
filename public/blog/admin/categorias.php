<?php
require_once __DIR__ . '/_auth.php';
exigirLogin();

$conn = conectarBanco();
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar') {
    validarCsrfOuMorrer((string) ($_POST['csrf'] ?? ''));

    $nome = trim((string) ($_POST['nome'] ?? ''));
    if ($nome === '') {
        $erro = 'Escreva um nome pra categoria.';
    } elseif (mb_strlen($nome) > 60) {
        $erro = 'Nome muito longo (máximo 60 caracteres).';
    } else {
        $stmt = $conn->prepare('INSERT IGNORE INTO blog_categorias (nome) VALUES (?)');
        $stmt->bind_param('s', $nome);
        $stmt->execute();
        if ($stmt->affected_rows === 0) {
            $erro = 'Essa categoria já existe.';
        }
        $stmt->close();
    }
}

$categorias = $conn->query(
    "SELECT c.id, c.nome, COUNT(a.id) AS total_artigos
     FROM blog_categorias c
     LEFT JOIN blog_artigos a ON a.categoria = c.nome
     GROUP BY c.id, c.nome
     ORDER BY c.nome"
)->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Categorias — Blog Zamtech</title>
    <meta name="robots" content="noindex, nofollow" />
    <link rel="shortcut icon" href="/assets/icons/website-global-icons/icon-zamtech.svg" type="image/x-icon" />
    <link rel="apple-touch-icon" href="/assets/icons/website-global-icons/icon-zamtech.svg" />
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
                <a href="/blog/admin/">Artigos</a>
                <a href="/blog/admin/editor.php">Novo artigo</a>
                <a href="/blog/admin/categorias.php" class="ativo">Categorias</a>
                <a href="/blog/admin/configuracoes.php">Configurações</a>
                <a href="/blog" target="_blank">Ver blog</a>
                <a href="/blog/admin/logout.php" class="btn-sair">Sair</a>
            </nav>
        </header>

        <main class="admin-content">
            <div class="admin-page-header">
                <h1>Categorias</h1>
            </div>

            <div class="card" style="padding: 24px; margin-bottom: 24px;">
                <h3 style="margin-bottom: 12px;">Nova categoria</h3>
                <?php if ($erro !== ''): ?>
                    <div class="alerta alerta-erro"><?= htmlspecialchars($erro, ENT_QUOTES) ?></div>
                <?php endif; ?>
                <form method="POST" class="form-categoria-nova">
                    <input type="hidden" name="acao" value="criar" />
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(pegarTokenCsrf(), ENT_QUOTES) ?>" />
                    <input type="text" name="nome" placeholder="Ex: Promoções" maxlength="60" required />
                    <button type="submit" class="btn btn-primary">+ Adicionar</button>
                </form>
            </div>

            <div class="card">
                <?php if (empty($categorias)): ?>
                    <div class="estado-vazio">
                        <p>Nenhuma categoria cadastrada ainda.</p>
                    </div>
                <?php else: ?>
                    <div class="lista-artigos">
                        <?php foreach ($categorias as $cat): ?>
                            <div class="item-artigo item-categoria">
                                <div class="item-artigo-info">
                                    <div class="item-artigo-titulo"><?= htmlspecialchars($cat['nome'], ENT_QUOTES) ?></div>
                                    <div class="item-artigo-meta">
                                        <span><?= (int) $cat['total_artigos'] ?> artigo<?= (int) $cat['total_artigos'] === 1 ? '' : 's' ?></span>
                                    </div>
                                </div>
                                <div class="item-artigo-acoes">
                                    <button type="button" class="btn btn-sm btn-danger-outline" data-excluir-categoria="<?= (int) $cat['id'] ?>" data-nome-categoria="<?= htmlspecialchars($cat['nome'], ENT_QUOTES) ?>">Excluir</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
    document.querySelectorAll('[data-excluir-categoria]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var id = botao.getAttribute('data-excluir-categoria');
            var nome = botao.getAttribute('data-nome-categoria');
            if (!confirm('Excluir a categoria "' + nome + '"? Os artigos que usam ela ficam sem categoria (não são excluídos).')) {
                return;
            }
            fetch('/blog/admin/ajax/excluir-categoria.php', {
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
