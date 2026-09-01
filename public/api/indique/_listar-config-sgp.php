<?php
// Script de consulta, só pra descobrir os IDs de "portador" e "plano de
// contas" que a Zamtech usa no SGP — precisa disso pra gerar a fatura
// avulsa do desconto certinho. Não mexe em nada, só lê e mostra.
//
// Depois de descobrir os IDs certos, esse arquivo pode ser apagado (ou eu
// apago quando a gente fechar a etapa de aplicar desconto).
require_once __DIR__ . '/_config.php';

if (php_sapi_name() !== 'cli') {
    $chaveInformada = $_GET['chave'] ?? '';
    if (!hash_equals(ROBO_CHAVE_TESTE, $chaveInformada)) {
        http_response_code(403);
        exit('Acesso negado.');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

// Manda cada pedaço pro navegador assim que fica pronto, em vez de guardar
// tudo pro final — se travar no meio, pelo menos mostra até onde chegou.
function imprimir(string $texto): void
{
    echo $texto;
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

/**
 * Igual chamarSGP(), mas via GET (app/token na query string) — alguns
 * endpoints de listagem simples do SGP só aceitam GET, apesar da doc
 * mostrar exemplo em POST.
 */
function chamarSGPviaGET(string $endpoint, array $params): ?array
{
    $query = http_build_query(array_merge(['app' => SGP_APP, 'token' => SGP_TOKEN], $params));
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => SGP_URL . $endpoint . '?' . $query,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'GET',
    ]);
    $response = curl_exec($curl);
    $erroCurl = curl_error($curl);
    curl_close($curl);

    if ($erroCurl) {
        return null;
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function listarSecao(string $titulo, string $endpoint): void
{
    imprimir("=== {$titulo} ===\n");

    try {
        $resultado = chamarSGP($endpoint, []);
        $comoTexto = $resultado === null ? '' : json_encode($resultado);
        if (stripos($comoTexto, 'não é permitido') !== false || stripos($comoTexto, 'not allowed') !== false) {
            imprimir("(POST não permitido nesse endpoint, tentando via GET...)\n");
            $resultado = chamarSGPviaGET($endpoint, []);
        }
    } catch (\Throwable $e) {
        imprimir("ERRO ao consultar: " . $e->getMessage() . "\n\n");
        return;
    }

    if ($resultado === null) {
        imprimir("Não consegui consultar (SGP fora do ar, token sem permissão, ou demorou demais).\n\n");
        return;
    }

    if (!is_array($resultado) || empty($resultado)) {
        imprimir("Consultei certinho, mas veio vazio ou num formato inesperado. Resposta crua:\n");
        imprimir(var_export($resultado, true) . "\n\n");
        return;
    }

    foreach ($resultado as $item) {
        if (!is_array($item)) {
            imprimir("  (item inesperado) " . var_export($item, true) . "\n");
            continue;
        }
        $partes = [];
        foreach ($item as $chave => $valor) {
            $partes[] = "{$chave}={$valor}";
        }
        imprimir("  " . implode('  |  ', $partes) . "\n");
    }
    imprimir("\n");
}

try {
    listarSecao('Portadores (Financeiro > Portadores no SGP)', '/api/ura/portador/');
    listarSecao('Planos de Contas (Financeiro > Plano de Contas no SGP)', '/api/ura/planoscontas/');
    imprimir("Fim. Me manda esse resultado.\n");
} catch (\Throwable $e) {
    imprimir("ERRO GERAL: " . $e->getMessage() . "\n");
    imprimir($e->getFile() . ':' . $e->getLine() . "\n");
}
