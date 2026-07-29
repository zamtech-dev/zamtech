<?php
// rate_limit.php - Controle de Tentativas por IP

$max_tentativas = 10;      // Limite de requisições permitidas na janela de tempo
$tempo_janela = 60;        // Janela em segundos
$pasta_temp = sys_get_temp_dir() . '/zamtech_rate_limit/';

if (!file_exists($pasta_temp)) {
    mkdir($pasta_temp, 0755, true);
}

$ip_cliente = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$arquivo_controle = $pasta_temp . md5($ip_cliente) . '.json';

$agora = time();
$dados = ['tentativas' => 1, 'inicio' => $agora];

if (file_exists($arquivo_controle)) {
    $conteudo = json_decode(file_get_contents($arquivo_controle), true);
    if ($conteudo) {
        if (($agora - $conteudo['inicio']) < $tempo_janela) {
            if ($conteudo['tentativas'] >= $max_tentativas) {
                header('Content-Type: application/json; charset=UTF-8');
                http_response_code(429);
                echo json_encode([
                    'sucesso' => false, 
                    'mensagem' => 'Muitas requisições originadas deste endereço. Aguarde 1 minuto.'
                ]);
                exit;
            }
            $dados['tentativas'] = $conteudo['tentativas'] + 1;
            $dados['inicio'] = $conteudo['inicio'];
        }
    }
}

file_put_contents($arquivo_controle, json_encode($dados));