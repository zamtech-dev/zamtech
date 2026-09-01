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

echo "=== Portadores (Financeiro > Portadores no SGP) ===\n";
$portadores = chamarSGP('/api/ura/portador/', []);
if ($portadores === null) {
    echo "Não consegui consultar (SGP fora do ar ou token sem permissão).\n";
} else {
    foreach ($portadores as $p) {
        echo "  id={$p['id']}  |  {$p['descricao']}  |  {$p['codigo_banco']}\n";
    }
}

echo "\n=== Planos de Contas (Financeiro > Plano de Contas no SGP) ===\n";
$planos = chamarSGP('/api/ura/planoscontas/', []);
if ($planos === null) {
    echo "Não consegui consultar (SGP fora do ar ou token sem permissão).\n";
} else {
    foreach ($planos as $pc) {
        echo "  id={$pc['id']}  |  {$pc['codigo']}  |  {$pc['descricao']}\n";
    }
}

echo "\nFim. Me manda esse resultado.\n";
