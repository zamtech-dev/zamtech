<?php
require_once __DIR__ . '/_auth.php';

if (estaLogado()) {
    header('Location: /blog/admin/');
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $senha = (string) ($_POST['senha'] ?? '');
    $ip = pegarIpVisitante();

    $conn = conectarBanco();

    if (contarTentativasRecentes($conn, $ip) >= LOGIN_MAX_TENTATIVAS) {
        $erro = 'Muitas tentativas erradas. Espere ' . LOGIN_JANELA_MINUTOS . ' minutos e tente de novo.';
    } elseif ($email === '' || $senha === '') {
        $erro = 'Preencha email e senha.';
    } else {
        $stmt = $conn->prepare('SELECT id, nome, senha_hash FROM blog_admin_usuarios WHERE email = ? AND ativo = 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $usuario = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($usuario && password_verify($senha, $usuario['senha_hash'])) {
            registrarTentativaLogin($conn, $ip, $email, true);

            session_regenerate_id(true);
            $_SESSION['blog_admin_id'] = (int) $usuario['id'];
            $_SESSION['blog_admin_nome'] = $usuario['nome'];

            header('Location: /blog/admin/');
            exit;
        }

        registrarTentativaLogin($conn, $ip, $email, false);
        $erro = 'Email ou senha incorretos.';
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Entrar — Blog Zamtech</title>
    <meta name="robots" content="noindex, nofollow" />
    <link rel="stylesheet" href="/blog/admin/assets/admin.css" />
</head>
<body>
    <div class="login-container">
        <div class="card form-card">
            <h1>Blog Zamtech</h1>
            <p class="subtitulo">Entre com sua conta de administrador</p>

            <?php if ($erro !== ''): ?>
                <div class="alerta alerta-erro"><?= htmlspecialchars($erro, ENT_QUOTES) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <div class="campo">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>" />
                </div>
                <div class="campo">
                    <label for="senha">Senha</label>
                    <input type="password" id="senha" name="senha" required />
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%; margin-top: 8px;">Entrar</button>
            </form>
        </div>
    </div>
</body>
</html>
