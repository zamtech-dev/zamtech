<?php
// Controle de sessão do admin: login obrigatório, rate limiting contra
// força bruta, e um token CSRF simples pra proteger os formulários/AJAX.

require_once __DIR__ . '/_config.php';

session_name(BLOG_SESSION_NAME);
session_start();

/**
 * Pega o IP real do visitante (considera proxy/CDN se um dia existir).
 */
function pegarIpVisitante(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Quantas tentativas de login (falhas) esse IP fez na janela de tempo.
 */
function contarTentativasRecentes(mysqli $conn, string $ip): int
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM blog_login_tentativas
         WHERE ip = ? AND sucesso = 0 AND criado_em > (NOW() - INTERVAL ? MINUTE)'
    );
    $janela = LOGIN_JANELA_MINUTOS;
    $stmt->bind_param('si', $ip, $janela);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($linha['total'] ?? 0);
}

/**
 * Registra uma tentativa de login (sucesso ou falha) pra fins de auditoria
 * e de bloqueio por força bruta.
 */
function registrarTentativaLogin(mysqli $conn, string $ip, string $email, bool $sucesso): void
{
    $stmt = $conn->prepare(
        'INSERT INTO blog_login_tentativas (ip, email_tentado, sucesso) VALUES (?, ?, ?)'
    );
    $sucessoInt = $sucesso ? 1 : 0;
    $stmt->bind_param('ssi', $ip, $email, $sucessoInt);
    $stmt->execute();
    $stmt->close();
}

/**
 * True se o usuário está logado no admin do blog.
 */
function estaLogado(): bool
{
    return !empty($_SESSION['blog_admin_id']);
}

/**
 * Corta a execução e manda pro login se não estiver autenticado.
 * Chame isso no topo de toda página do admin que exige login.
 */
function exigirLogin(): void
{
    if (!estaLogado()) {
        header('Location: /blog/admin/login.php');
        exit;
    }
}

/**
 * Gera (ou reaproveita) o token CSRF da sessão atual.
 */
function pegarTokenCsrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Confere se o token CSRF enviado bate com o da sessão. Em caso de
 * chamada AJAX que falhar, já responde 403 em JSON e encerra.
 */
function validarCsrfOuMorrer(string $tokenRecebido): void
{
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $tokenRecebido)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['sucesso' => false, 'mensagem' => 'Sessão expirada, recarregue a página e tente de novo.']);
        exit;
    }
}
