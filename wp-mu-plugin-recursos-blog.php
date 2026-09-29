<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function (): void {

    register_rest_field('post', 'zamtech_curtidas', [
        'get_callback' => function (array $post) {
            $id = isset($post['id']) ? (int) $post['id'] : 0;
            return $id ? (int) get_post_meta($id, 'zamtech_curtidas', true) : 0;
        },
    ]);

    register_rest_field('post', 'zamtech_total_comentarios', [
        'get_callback' => function (array $post) {
            $id = isset($post['id']) ? (int) $post['id'] : 0;
            return $id ? (int) get_comments_number($id) : 0;
        },
    ]);

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
