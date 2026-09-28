<?php
require_once __DIR__ . '/_auth.php';
exigirLogin();

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senhaAtual = (string) ($_POST['senha_atual'] ?? '');
    $senhaNova = (string) ($_POST['senha_nova'] ?? '');
    $senhaConfirma = (string) ($_POST['senha_confirma'] ?? '');

    $conn = conectarBanco();
    $id = (int) $_SESSION['blog_admin_id'];

    $stmt = $conn->prepare('SELECT senha_hash FROM blog_admin_usuarios WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $usuario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$usuario || !password_verify($senhaAtual, $usuario['senha_hash'])) {
        $erro = 'Senha atual incorreta.';
    } elseif (strlen($senhaNova) < 8) {
        $erro = 'A nova senha precisa ter pelo menos 8 caracteres.';
    } elseif ($senhaNova !== $senhaConfirma) {
        $erro = 'A confirmação não bate com a nova senha.';
    } else {
        $novoHash = password_hash($senhaNova, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $conn->prepare('UPDATE blog_admin_usuarios SET senha_hash = ? WHERE id = ?');
        $stmt->bind_param('si', $novoHash, $id);
        $stmt->execute();
        $stmt->close();
        $sucesso = 'Senha alterada com sucesso.';
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Configurações — Blog Zamtech</title>
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
                <a href="/blog/admin/">Artigos</a>
                <a href="/blog/admin/editor.php">Novo artigo</a>
                <a href="/blog/admin/configuracoes.php" class="ativo">Configurações</a>
                <a href="/blog" target="_blank">Ver blog</a>
                <a href="/blog/admin/logout.php" class="btn-sair">Sair</a>
            </nav>
        </header>

        <main class="admin-content">
            <div class="card form-card">
                <h1>Trocar senha</h1>
                <p class="subtitulo">Use uma senha forte que só você conhece</p>

                <?php if ($erro !== ''): ?>
                    <div class="alerta alerta-erro"><?= htmlspecialchars($erro, ENT_QUOTES) ?></div>
                <?php endif; ?>
                <?php if ($sucesso !== ''): ?>
                    <div class="alerta alerta-sucesso"><?= htmlspecialchars($sucesso, ENT_QUOTES) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="campo">
                        <label for="senha_atual">Senha atual</label>
                        <input type="password" id="senha_atual" name="senha_atual" required />
                    </div>
                    <div class="campo">
                        <label for="senha_nova">Nova senha</label>
                        <input type="password" id="senha_nova" name="senha_nova" required minlength="8" />
                    </div>
                    <div class="campo">
                        <label for="senha_confirma">Confirme a nova senha</label>
                        <input type="password" id="senha_confirma" name="senha_confirma" required minlength="8" />
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%; margin-top: 8px;">Salvar nova senha</button>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
