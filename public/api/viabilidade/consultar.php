<?php
// Recebe CEP + número digitados no site e responde se a Zamtech provavelmente
// atende ali. Fluxo: CEP -> vira endereço completo (rua, bairro, cidade) via
// ViaCEP -> vira um ponto no mapa (geocodificação via OpenStreetMap) ->
// compara com a CTO (caixinha de fibra) mais próxima cadastrada no SGP ->
// responde com base na distância e nas portas livres.
//
// Por que CEP + número, e não um campo de endereço livre: o CEP no Brasil já
// aponta praticamente pra rua exata, então não tem risco de confundir (por
// exemplo) uma "Rua Mourisco" com outra rua de mesmo nome em outra cidade —
// problema real que a gente teve testando com endereço digitado na mão.
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

$cep = isset($input['cep']) ? preg_replace('/\D/', '', $input['cep']) : '';
$numero = isset($input['numero']) ? trim($input['numero']) : '';

if (strlen($cep) !== 8) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Digite um CEP válido (8 números).',
    ]);
    exit;
}

if ($numero === '') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Digite o número da casa ou prédio.',
    ]);
    exit;
}

$enderecoCep = consultarCep($cep);

if ($enderecoCep === null) {
    echo json_encode([
        'sucesso' => true,
        'atende' => false,
        'motivo' => 'cep_nao_encontrado',
        'mensagem' => 'Não encontrei esse CEP. Confere se digitou certinho, ou fala direto com a gente pelo WhatsApp.',
    ]);
    exit;
}

// Monta o endereço completo pra geocodificar. Se o CEP não tiver logradouro
// (acontece com CEPs "gerais", de cidade pequena) usa só bairro/cidade — bem
// menos preciso, mas ainda funciona.
$partesEndereco = array_filter([
    $enderecoCep['logradouro'] !== '' ? $enderecoCep['logradouro'] . ', ' . $numero : null,
    $enderecoCep['bairro'],
    $enderecoCep['cidade'] . ' - ' . $enderecoCep['uf'],
]);
$enderecoCompleto = implode(', ', $partesEndereco) . ', Brasil';
$enderecoExibicao = trim(
    ($enderecoCep['logradouro'] !== '' ? $enderecoCep['logradouro'] . ', ' . $numero . ' - ' : '')
    . $enderecoCep['bairro'] . ', ' . $enderecoCep['cidade'] . '/' . $enderecoCep['uf']
);

// Devolvido junto com toda resposta que tem endereço resolvido — pra quem
// chama (ex: /contratar) poder pré-preencher o formulário de pré-cadastro
// sem precisar consultar o CEP de novo.
$enderecoPartes = [
    'cep' => substr($cep, 0, 5) . '-' . substr($cep, 5),
    'logradouro' => $enderecoCep['logradouro'],
    'numero' => $numero,
    'bairro' => $enderecoCep['bairro'],
    'cidade' => $enderecoCep['cidade'],
    'uf' => $enderecoCep['uf'],
];

$geo = geocodificarEndereco($enderecoCompleto);

if ($geo === null) {
    echo json_encode([
        'sucesso' => true,
        'atende' => false,
        'motivo' => 'endereco_nao_encontrado',
        'mensagem' => 'Achei o CEP, mas não consegui localizar esse endereço no mapa. Fala com a gente pelo WhatsApp que a gente confere na mão.',
        'endereco_encontrado' => $enderecoExibicao,
        'endereco_partes' => $enderecoPartes,
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
        'endereco_encontrado' => $enderecoExibicao,
        'endereco_partes' => $enderecoPartes,
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
        'mensagem' => 'Boa notícia! A gente provavelmente já tem rede de fibra aí pertinho. 🎉',
        'endereco_encontrado' => $enderecoExibicao,
        'endereco_partes' => $enderecoPartes,
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
        'endereco_encontrado' => $enderecoExibicao,
        'endereco_partes' => $enderecoPartes,
        'distancia_m' => $distanciaM,
    ]);
    exit;
}

echo json_encode([
    'sucesso' => true,
    'atende' => false,
    'motivo' => 'sem_cobertura',
    'mensagem' => 'Ainda não chegamos nessa região, mas estamos sempre expandindo a rede. Deixa seu contato que a gente avisa quando chegar aí.',
    'endereco_encontrado' => $enderecoExibicao,
    'endereco_partes' => $enderecoPartes,
    'distancia_m' => $distanciaM,
]);
