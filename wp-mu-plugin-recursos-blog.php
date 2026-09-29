<?php
/**
 * Plugin Name: Zamtech — Curtidas e contagem de comentários na API
 * Description: Adiciona dois recursos que o WordPress não expõe sozinho na
 *              API: (1) um contador de "curtidas" por artigo, guardado como
 *              campo do próprio post; (2) o número de comentários de cada
 *              artigo, pra poder ordenar "mais comentados" no site.
 *              Comentário em si (ler e enviar) já é nativo do WordPress —
 *              não precisa de nada extra pra isso, só deixar habilitado em
 *              Configurações > Discussão.
 *
 * COMO INSTALAR: igual ao outro arquivo — cole em wp-content/mu-plugins/
 * (mesma pasta do wp-mu-plugin-avisar-github.php). Não precisa mexer no
 * wp-config.php pra este aqui, ele não usa nenhum token.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * O contador de curtidas é guardado com update_post_meta() comum (função de
 * PHP puro, não passa pela API) — de propósito NÃO usamos register_post_meta
 * aqui. Se a gente registrasse como campo de "meta" da API, o editor de
 * blocos (Gutenberg) passa a devolver esse campo pra cada post carregado E
 * reenviar ele de volta a cada "Publicar/Atualizar" — e como só a rota
 * customizada lá embaixo pode alterar o valor, o WordPress rejeitava essa
 * tentativa de escrita no meio do salvamento do post, quebrando a resposta
 * da tela de editar artigo ("Falha ao publicar. A resposta não é um JSON
 * válido."). Expondo como campo de LEITURA (register_rest_field, igual ao
 * de comentários logo abaixo) a gente mostra o número pro site sem abrir
 * esse caminho de escrita.
 */
add_action('rest_api_init', function (): void {

    register_rest_field('post', 'zamtech_curtidas', [
        'get_callback' => function (array $post) {
            $id = isset($post['id']) ? (int) $post['id'] : 0;
            return $id ? (int) get_post_meta($id, 'zamtech_curtidas', true) : 0;
        },
    ]);

    // Expõe o número de comentários de cada post na API (o WordPress não
    // manda esse número por padrão em /wp/v2/posts).
    register_rest_field('post', 'zamtech_total_comentarios', [
        'get_callback' => function (array $post) {
            $id = isset($post['id']) ? (int) $post['id'] : 0;
            return $id ? (int) get_comments_number($id) : 0;
        },
    ]);

    // Rota que o botão de curtir do site chama: POST
    // /wp-json/zamtech/v1/curtir/123 → soma +1 na curtida do artigo 123 e
    // devolve o total atualizado. Qualquer visitante pode chamar (não tem
    // login no site público) — o controle de "já curtiu" fica só no
    // navegador da própria pessoa (localStorage), não é à prova de trapaça,
    // mas é suficiente pro que isso precisa ser.
    register_rest_route('zamtech/v1', '/curtir/(?P<id>\d+)', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $req) {
            $id = (int) $req['id'];

            if (get_post_type($id) !== 'post' || get_post_status($id) !== 'publish') {
                return new WP_Error('post_invalido', 'Artigo não encontrado.', ['status' => 404]);
            }

            $atual = (int) get_post_meta($id, 'zamtech_curtidas', true);
            $novo = $atual + 1;
            update_post_meta($id, 'zamtech_curtidas', $novo);

            return ['curtidas' => $novo];
        },
    ]);
});
