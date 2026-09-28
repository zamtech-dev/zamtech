<?php
// Configuração central do Blog Zamtech (banco, sessão, caminhos).
// Segue o mesmo padrão dos outros módulos (indique, viabilidade): fica
// dentro de public/ mas nunca imprime nada, então acessar a URL direto
// não vaza nenhuma informação sensível.

// --- Banco de dados do Blog ---
// Troque estes 3 valores pelos que o cPanel te der depois de criar o banco
// (veja o passo a passo que te mandei). DB_HOST quase sempre é "localhost".
define('DB_HOST', 'localhost');
define('DB_NAME', 'ztclas09_blog');
define('DB_USER', 'ztclas09_blog');
define('DB_PASS', 'TROQUE_PELA_SENHA_DO_BANCO');

// --- URL base do site (usada pra montar links absolutos, sitemap, etc.) ---
define('SITE_URL', 'https://zamtech.com.br');

// --- Pasta onde as imagens do blog ficam salvas (fisicamente e por URL) ---
define('BLOG_UPLOAD_DIR', dirname(__DIR__, 2) . '/assets/img/blog');
define('BLOG_UPLOAD_URL', '/assets/img/blog');

// --- Nome do cookie de sessão do admin (separado do resto do site) ---
define('BLOG_SESSION_NAME', 'zamtech_blog_admin');

// --- Categorias do blog (lista única, usada no editor e na página pública).
// Pra adicionar/remover uma categoria, mexe só aqui. ---
define('CATEGORIAS_BLOG', ['Residencial', 'Empresarial', 'Dicas', 'Novidades']);

// --- Limite de tentativas de login (rate limiting) ---
define('LOGIN_MAX_TENTATIVAS', 5);
define('LOGIN_JANELA_MINUTOS', 15);

/**
 * Abre a conexão com o banco. Se der erro, encerra a requisição.
 * Em página HTML normal mostra uma mensagem simples; em chamada AJAX
 * (identificada pelo cabeçalho X-Requested-With) devolve JSON.
 */
function conectarBanco(): mysqli
{
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        error_log('Blog Zamtech - erro de conexão com banco: ' . $conn->connect_error);

        $ehAjax = (
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        );

        http_response_code(500);
        if ($ehAjax) {
            header('Content-Type: application/json');
            echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno ao acessar o banco.']);
        } else {
            echo 'Erro interno. Tente novamente em instantes.';
        }
        exit;
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}

/**
 * Gera um slug (URL amigável) a partir de um título: minúsculo, sem
 * acento, espaços viram hífen, sem caracteres especiais.
 */
function gerarSlug(string $texto): string
{
    $texto = mb_strtolower(trim($texto), 'UTF-8');
    $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto);
    $texto = trim($texto, '-');
    $texto = preg_replace('/-+/', '-', $texto);
    return $texto === '' ? 'artigo' : $texto;
}

/**
 * Garante que o slug é único na tabela blog_artigos, acrescentando
 * -2, -3, etc. se precisar. $idIgnorar é usado ao editar um artigo (pra
 * não bater com o próprio registro).
 */
function gerarSlugUnico(mysqli $conn, string $slugBase, ?int $idIgnorar = null): string
{
    $slug = $slugBase;
    $contador = 2;

    while (true) {
        $stmt = $conn->prepare('SELECT id FROM blog_artigos WHERE slug = ? AND id != ?');
        $idComparar = $idIgnorar ?? 0;
        $stmt->bind_param('si', $slug, $idComparar);
        $stmt->execute();
        $existe = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$existe) {
            return $slug;
        }

        $slug = $slugBase . '-' . $contador;
        $contador++;
    }
}
