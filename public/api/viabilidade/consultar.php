<?php
// Recebe um endereço digitado no site e responde se a Zamtech provavelmente
// atende ali. Fluxo: endereço -> vira um ponto no mapa (geocodificação via
// OpenStreetMap) -> compara com a CTO (caixinha de fibra) mais próxima
// cadastrada no SGP -> responde com base na distância e nas portas livres.
//
// Isso é uma ESTIMATIVA, não uma confirmação técnica de verdade — por isso
// nunca promete, só indica "provável". A confirmação de verdade é sempre
// feita pelo time comercial.
require_once __DIR__ . '/_config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método inválido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$endereco = isset($input['endereco']) ? trim($input['endereco']) : '';

if ($endereco === '' || mb_strlen($endereco) < 6) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Digite um endereço completo (rua, número, bairro e cidade) pra eu conseguir localizar.',
    ]);
    exit;
}

// Melhora a geocodificação: se a pessoa não mencionou UF/ES, ajuda o
// Nominatim a não confundir com uma rua de mesmo nome em outro estado.
$enderecoBusca = $endereco;
if (stripos($endereco, 'ES') === false && stripos($endereco, 'Espírito Santo') === false) {
    $enderecoBusca .= ', ES, Brasil';
}

$geo = geocodificarEndereco($enderecoBusca);

if ($geo === null) {
    echo json_encode([
        'sucesso' => true,
        'atende' => false,
        'motivo' => 'endereco_nao_encontrado',
        'mensagem' => 'Não consegui localizar esse endereço. Tenta escrever com rua, número, bairro e cidade — ou fala direto com a gente pelo WhatsApp.',
    ]);
    exit;
}

$ctos = buscarCTOs();

if ($ctos === null) {
    http_response_code(502);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Não consegui consultar nossa cobertura agora. Tenta de novo em instantes ou fala com a gente pelo WhatsApp.',
    ]);
    exit;
}

$maisProxima = ctoMaisProxima($ctos, $geo['lat'], $geo['lon']);

if ($maisProxima === null) {
    // Não tem nenhuma CTO com coordenada cadastrada — situação de dado
    // faltando, não de "sem cobertura". Trata como sem cobertura mesmo
    // assim (mais seguro que afirmar que atende sem checar nada).
    echo json_encode([
        'sucesso' => true,
        'atende' => false,
        'motivo' => 'sem_cobertura',
        'mensagem' => 'Ainda não temos rede identificada nessa região.',
        'endereco_encontrado' => $geo['endereco_encontrado'],
    ]);
    exit;
}

$distanciaM = round($maisProxima['distancia_m']);
$dentroDoRaio = $maisProxima['distancia_m'] <= VIABILIDADE_RAIO_METROS;
$portasDisponiveis = (int) ($maisProxima['cto']['portas_disponiveis'] ?? 0);

if ($dentroDoRaio && $portasDisponiveis > 0) {
    echo json_encode([
        'sucesso' => true,
        'atende' => true,
        'motivo' => 'atende',
        'mensagem' => 'Boa notícia! A gente provavelmente já tem rede de fibra aí pertinho. 🎉 Faz seu pré-cadastro que nosso time comercial confirma tudo certinho e agenda a instalação.',
        'endereco_encontrado' => $geo['endereco_encontrado'],
        'distancia_m' => $distanciaM,
    ]);
    exit;
}

if ($dentroDoRaio && $portasDisponiveis <= 0) {
    echo json_encode([
        'sucesso' => true,
        'atende' => false,
        'motivo' => 'sem_vaga',
        'mensagem' => 'Já temos rede de fibra bem perto daí, mas a caixinha mais próxima está com todas as portas ocupadas no momento. Deixa seu contato que a gente avisa assim que abrir vaga.',
        'endereco_encontrado' => $geo['endereco_encontrado'],
        'distancia_m' => $distanciaM,
    ]);
    exit;
}

echo json_encode([
    'sucesso' => true,
    'atende' => false,
    'motivo' => 'sem_cobertura',
    'mensagem' => 'Ainda não chegamos nessa região, mas estamos sempre expandindo a rede. Deixa seu contato que a gente avisa quando chegar aí.',
    'endereco_encontrado' => $geo['endereco_encontrado'],
    'distancia_m' => $distanciaM,
]);
