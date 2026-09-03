<?php
// Configuração da Consulta de Viabilidade (site -> SGP).
// Feature independente das outras (Indique e Ganhe / Pré-Cadastro) — por
// isso tem seu próprio _config.php, mesmo repetindo algumas constantes que
// já existem nos outros. Assim uma não depende da outra pra funcionar ou
// pra ser desligada/alterada no futuro.
//
// Como funciona, resumido: a pessoa digita o endereço dela no site. A gente
// transforma esse endereço num ponto de latitude/longitude (geocodificação,
// via OpenStreetMap/Nominatim — de graça, sem chave de API). Depois busca
// todas as CTOs (as caixinhas de fibra) cadastradas no SGP, que já têm
// latitude/longitude e quantidade de portas livres. Calcula a distância até
// a CTO mais próxima e responde se "provavelmente atende" ou não.
//
// Importante: isso é uma ESTIMATIVA (distância em linha reta, não o
// caminho real do cabo). Por isso a mensagem pro cliente sempre deixa claro
// que é uma resposta provável, e que o time comercial confirma de verdade.

// --- SGP (mesma credencial já usada em /api/pre-cadastro e /api/indique) ---
define('SGP_URL', 'https://zamtech.sgp.tsmx.app');
define('SGP_APP', 'segunda-via-website');
define('SGP_TOKEN', '4ab8d0da-ed7d-4e91-b717-9d4a98625458');

// --- Geocodificação (OpenStreetMap / Nominatim) ---
// De graça, mas exige um User-Agent identificando o site (política deles) e
// tem limite de uso justo (não é feito pra volume alto). Se um dia o
// volume de busca crescer muito ou a precisão não for boa o suficiente,
// dá pra trocar por Google Maps Geocoding API aqui.
define('NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search');
define('NOMINATIM_USER_AGENT', 'ZamtechViabilidade/1.0 (comercial@zamtech.com.br)');

// --- Regra de "atende ou não" ---
// Raio, em metros, que a gente considera "tem rede aí perto". Baseado nos
// dados reais das CTOs da Zamtech: onde tem cobertura, as CTOs ficam bem
// próximas umas das outras (a maioria a menos de 75m uma da outra), então
// 200m é uma margem confortável sem estourar pra fora da área real.
define('VIABILIDADE_RAIO_METROS', 200);

// --- Cache da lista de CTOs ---
// A lista de CTOs no SGP não muda a cada minuto, e são ~800+ registros —
// então guarda um cache local em arquivo (formato PHP, não é servido como
// texto se alguém tentar abrir a URL direto) e só busca de novo no SGP
// depois que esse tempo passar. Reduz carga no SGP e deixa a busca rápida
// pro cliente.
define('VIABILIDADE_CACHE_ARQUIVO', __DIR__ . '/_cache_ctos.php');
define('VIABILIDADE_CACHE_MINUTOS', 30);

// --- Aviso de lead novo (região sem cobertura/sem vaga) pra Carol (comercial) ---
define('EMAIL_FINANCEIRO', 'zamtechcomercial@gmail.com');
define('VIABILIDADE_LEADS_ARQUIVO', __DIR__ . '/_leads.log');

// --- Envio de email via SMTP autenticado ---
// Mesmo esquema (e mesmo motivo) do /api/pre-cadastro/_config.php: o mail()
// nativo da Hostgator não passa no SPF do domínio, então manda-se
// autenticado direto pelo servidor de email oficial da Zamtech (Dynu).
define('SMTP_HOST', 'zamtech-com-br-smtp.dynu.com');
define('SMTP_PORTA', 587);
define('SMTP_USUARIO', 'comercial@zamtech.com.br');
define('SMTP_SENHA', '@Zamtech33');
define('SMTP_NOME_REMETENTE', 'Zamtech Viabilidade');

/**
 * Chama um endpoint GET da API do SGP (app + token via query string).
 * Retorna o array decodificado, ou null se a chamada falhou.
 * (Diferente do chamarSGP() dos outros _config.php, que é sempre POST com
 * corpo JSON — o endpoint de CTOs da API FTTH do SGP só aceita GET.)
 */
function chamarSgpGet(string $endpoint, array $paramsExtras = []): ?array
{
    $params = array_merge([
        'app' => SGP_APP,
        'token' => SGP_TOKEN,
    ], $paramsExtras);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => SGP_URL . $endpoint . '?' . http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'GET',
    ]);

    $response = curl_exec($curl);
    $erroCurl = curl_error($curl);
    curl_close($curl);

    if ($erroCurl) {
        error_log('Viabilidade - erro ao chamar SGP (' . $endpoint . '): ' . $erroCurl);
        return null;
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

/**
 * Busca a lista de CTOs — do cache local se ainda estiver "fresco", ou do
 * SGP direto (e atualiza o cache) se não tiver ou estiver velho demais.
 * Retorna null só se o cache não existir/estiver velho E a chamada ao SGP
 * falhar (ou seja: sempre tenta usar cache velho como fallback, melhor que
 * nada).
 */
function buscarCTOs(): ?array
{
    $cacheValido = is_file(VIABILIDADE_CACHE_ARQUIVO)
        && (time() - filemtime(VIABILIDADE_CACHE_ARQUIVO)) < (VIABILIDADE_CACHE_MINUTOS * 60);

    if ($cacheValido) {
        $ctos = include VIABILIDADE_CACHE_ARQUIVO;
        if (is_array($ctos)) {
            return $ctos;
        }
    }

    $ctos = chamarSgpGet('/api/fttx/splitter/all/', ['no_busy_ports' => '1']);

    if (is_array($ctos)) {
        $conteudo = "<?php\n// Cache gerado automaticamente em " . date('Y-m-d H:i:s') . " — não editar na mão.\nreturn " . var_export($ctos, true) . ";\n";
        @file_put_contents(VIABILIDADE_CACHE_ARQUIVO, $conteudo);
        return $ctos;
    }

    // SGP falhou agora — se tiver um cache velho (mesmo vencido), usa ele
    // em vez de deixar a busca inteira quebrada.
    if (is_file(VIABILIDADE_CACHE_ARQUIVO)) {
        $ctosVelhos = include VIABILIDADE_CACHE_ARQUIVO;
        if (is_array($ctosVelhos)) {
            return $ctosVelhos;
        }
    }

    return null;
}

/**
 * Geocodifica um endereço em texto livre pra latitude/longitude, usando o
 * Nominatim (OpenStreetMap). Retorna ['lat' => float, 'lon' => float,
 * 'endereco_encontrado' => string] ou null se não achou nada.
 */
function geocodificarEndereco(string $endereco): ?array
{
    $params = [
        'format' => 'json',
        'countrycodes' => 'br',
        'limit' => '1',
        'addressdetails' => '0',
        'q' => $endereco,
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => NOMINATIM_URL . '?' . http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['User-Agent: ' . NOMINATIM_USER_AGENT],
    ]);

    $response = curl_exec($curl);
    $erroCurl = curl_error($curl);
    curl_close($curl);

    if ($erroCurl) {
        error_log('Viabilidade - erro ao geocodificar endereço: ' . $erroCurl);
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || count($data) === 0) {
        return null;
    }

    $primeiro = $data[0];
    if (!isset($primeiro['lat'], $primeiro['lon'])) {
        return null;
    }

    return [
        'lat' => (float) $primeiro['lat'],
        'lon' => (float) $primeiro['lon'],
        'endereco_encontrado' => $primeiro['display_name'] ?? $endereco,
    ];
}

/**
 * Distância em metros entre dois pontos de latitude/longitude (fórmula de
 * Haversine — "linha reta" na superfície da Terra, não é o caminho real do
 * cabo de fibra).
 */
function distanciaMetros(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $raioTerra = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $raioTerra * asin(min(1, sqrt($a)));
}

/**
 * Acha a CTO mais próxima de um ponto, entre as CTOs que têm coordenada
 * válida. Retorna ['cto' => array, 'distancia_m' => float] ou null se a
 * lista estiver vazia / nenhuma CTO tiver coordenada.
 */
function ctoMaisProxima(array $ctos, float $lat, float $lon): ?array
{
    $maisProxima = null;
    $menorDistancia = null;

    foreach ($ctos as $cto) {
        $mapLl = $cto['map_ll'] ?? null;
        if (!$mapLl || strpos($mapLl, ',') === false) {
            continue;
        }

        [$ctoLat, $ctoLon] = array_map('trim', explode(',', $mapLl, 2));
        if (!is_numeric($ctoLat) || !is_numeric($ctoLon)) {
            continue;
        }

        $distancia = distanciaMetros($lat, $lon, (float) $ctoLat, (float) $ctoLon);
        if ($menorDistancia === null || $distancia < $menorDistancia) {
            $menorDistancia = $distancia;
            $maisProxima = $cto;
        }
    }

    if ($maisProxima === null) {
        return null;
    }

    return ['cto' => $maisProxima, 'distancia_m' => $menorDistancia];
}

/**
 * Manda um email "de verdade", autenticado via SMTP — mesma implementação
 * (linha por linha do protocolo, sem biblioteca externa) do
 * /api/pre-cadastro/_config.php, já que a Hostgator não tem Composer/Terminal.
 */
function enviarEmailViaSmtp(string $destinatario, string $assunto, string $corpo): bool
{
    if (SMTP_HOST === '' || SMTP_SENHA === '') {
        error_log('Viabilidade - SMTP: falta configurar SMTP_HOST e/ou SMTP_SENHA em _config.php.');
        return false;
    }

    $protocolo = (SMTP_PORTA === 465) ? 'ssl' : 'tcp';
    $conexao = @stream_socket_client($protocolo . '://' . SMTP_HOST . ':' . SMTP_PORTA, $erroNum, $erroMsg, 15);
    if (!$conexao) {
        error_log("Viabilidade - SMTP: não consegui conectar ({$erroMsg})");
        return false;
    }

    $ler = function () use ($conexao): string {
        $resposta = '';
        while ($linha = fgets($conexao, 515)) {
            $resposta .= $linha;
            if (isset($linha[3]) && $linha[3] === ' ') {
                break;
            }
        }
        return $resposta;
    };
    $mandar = function (string $comando) use ($conexao): void {
        fwrite($conexao, $comando . "\r\n");
    };

    $ler(); // saudação inicial do servidor (220)
    $mandar('EHLO zamtech.com.br');
    $ler();

    if (SMTP_PORTA !== 465) {
        $mandar('STARTTLS');
        if (strpos($ler(), '220') !== 0) {
            fclose($conexao);
            error_log('Viabilidade - SMTP: STARTTLS recusado.');
            return false;
        }

        if (!stream_socket_enable_crypto($conexao, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($conexao);
            error_log('Viabilidade - SMTP: falha ao iniciar TLS.');
            return false;
        }

        $mandar('EHLO zamtech.com.br');
        $ler();
    }

    $mandar('AUTH LOGIN');
    $ler();
    $mandar(base64_encode(SMTP_USUARIO));
    $ler();
    $mandar(base64_encode(SMTP_SENHA));
    if (strpos($ler(), '235') !== 0) {
        fclose($conexao);
        error_log('Viabilidade - SMTP: autenticação recusada (confere SMTP_USUARIO e SMTP_SENHA em _config.php).');
        return false;
    }

    $mandar('MAIL FROM:<' . SMTP_USUARIO . '>');
    $ler();
    $mandar('RCPT TO:<' . $destinatario . '>');
    $ler();
    $mandar('DATA');
    $ler();

    $cabecalhos = 'From: ' . SMTP_NOME_REMETENTE . ' <' . SMTP_USUARIO . ">\r\n"
        . "To: <{$destinatario}>\r\n"
        . "Subject: =?UTF-8?B?" . base64_encode($assunto) . "?=\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    $corpoEscapado = preg_replace('/^\./m', '..', $corpo);

    $mandar($cabecalhos . "\r\n" . $corpoEscapado . "\r\n.");
    $respostaEnvio = $ler();

    $mandar('QUIT');
    fclose($conexao);

    return strpos($respostaEnvio, '250') === 0;
}
