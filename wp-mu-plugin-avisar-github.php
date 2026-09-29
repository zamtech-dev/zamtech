<?php
/**
 * Plugin Name: Zamtech — Avisar o GitHub pra reconstruir o site
 * Description: Toda vez que um artigo é publicado, atualizado ou despublicado,
 *              avisa o GitHub Actions pra ele reconstruir e reenviar o site
 *              (porque o blog agora é estático — gerado no build, não lido
 *              do banco a cada visita).
 *
 * COMO INSTALAR:
 *   1. Cole este arquivo em wp-content/mu-plugins/ (crie a pasta "mu-plugins"
 *      se ela não existir ainda). "mu" = "must-use": ativa sozinho, não
 *      precisa ir em Plugins > Ativar.
 *   2. Abra o wp-config.php do WordPress e adicione estas duas linhas ANTES
 *      da linha que diz "That's all, stop editing! Happy publishing.":
 *
 *          define('ZAMTECH_GITHUB_TOKEN', 'cole_aqui_o_token_do_github');
 *          define('ZAMTECH_GITHUB_REPO', 'usuario/nome-do-repositorio');
 *
 *      O token é um "Personal Access Token" do GitHub (Settings > Developer
 *      settings > Fine-grained tokens), com permissão "Contents: Read and
 *      write" + "Actions: Read and write" só neste repositório.
 *
 * Se essas constantes não estiverem definidas, o plugin simplesmente não faz
 * nada (não dá erro, só não dispara o rebuild) — assim dá pra colar o arquivo
 * antes mesmo de ter o token em mãos.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Avisa o GitHub que algo mudou, pra ele rodar o deploy.yml de novo.
 * Silencioso em caso de erro (não trava a publicação do artigo por causa
 * disso) — só registra um aviso no log de erros do PHP.
 */
function zamtech_avisar_github_rebuild(): void
{
    if (!defined('ZAMTECH_GITHUB_TOKEN') || !defined('ZAMTECH_GITHUB_REPO')) {
        return;
    }

    $resposta = wp_remote_post(
        'https://api.github.com/repos/' . ZAMTECH_GITHUB_REPO . '/dispatches',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . ZAMTECH_GITHUB_TOKEN,
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Zamtech-WordPress',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode(['event_type' => 'wp-publish']),
            'timeout' => 10,
        ]
    );

    if (is_wp_error($resposta)) {
        error_log('[zamtech] Falha ao avisar o GitHub pra reconstruir o site: ' . $resposta->get_error_message());
        return;
    }

    $codigo = wp_remote_retrieve_response_code($resposta);
    if ($codigo !== 204) {
        error_log('[zamtech] GitHub respondeu ' . $codigo . ' ao pedir o rebuild: ' . wp_remote_retrieve_body($resposta));
    }
}

/**
 * Dispara em qualquer mudança de status de um artigo (post_type "post"):
 * publicar, editar um já publicado (o WordPress chama esse hook de novo
 * mesmo sem trocar de status), ou despublicar — nos três casos o site
 * estático precisa ser refeito.
 */
add_action('transition_post_status', function (string $novoStatus, string $statusAntigo, WP_Post $post): void {
    if ($post->post_type !== 'post') {
        return;
    }
    if ($novoStatus === 'publish' || $statusAntigo === 'publish') {
        zamtech_avisar_github_rebuild();
    }
}, 10, 3);

// Mandar pra lixeira ou apagar de vez também precisa reconstruir o site
// (senão a página antiga do artigo continua no ar até o próximo deploy).
add_action('wp_trash_post', 'zamtech_avisar_github_rebuild');
add_action('after_delete_post', 'zamtech_avisar_github_rebuild');
