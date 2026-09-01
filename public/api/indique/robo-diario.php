<?php
// Robô diário do Indique e Ganhe.
//
// Ninguém abre isso pelo navegador — ele mora aqui só porque o esquema de
// deploy (git push -> FTP) exige que todo PHP fique dentro de public/.
// Quem chama esse arquivo é a Tarefa Cron do cPanel, uma vez por dia.
//
// O que ele faz: pra cada indicado ainda não validado, pergunta pro SGP
// "esse contrato já ativou? a 1ª fatura já foi paga?". Quando os dois
// forem sim, ele cria o desconto do indicador — mas só como
// "pendente_aprovacao". Nenhuma fatura de verdade é tocada aqui; isso só
// acontece depois que alguém do financeiro aprovar pelo link do email.
require_once __DIR__ . '/_config.php';

// Trava de segurança: só roda via linha de comando (o jeito que a Tarefa
// Cron chama) OU, pra dar pra testar manualmente sem Terminal no cPanel,
// via navegador com a chave secreta certa na URL. Sem a chave certa, barra
// na hora — isso não é uma página pública, é um robô de fundo.
//
// IMPORTANTE: depois de testar, troque essa chave por outra qualquer (ou
// apague esse bloco todo) — ela fica exposta em texto no código.
define('ROBO_CHAVE_TESTE', '920c729a5e148cdaeb8349d233306b2b');

if (php_sapi_name() !== 'cli') {
    $chaveInformada = $_GET['chave'] ?? '';
    if (!hash_equals(ROBO_CHAVE_TESTE, $chaveInformada)) {
        http_response_code(403);
        exit('Acesso negado.');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

$conn = conectarBanco();

$stmt = $conn->prepare(
    "SELECT id, indicacao_codigo, indicado_cpfcnpj, indicado_nome, status
     FROM indicados
     WHERE status IN ('pre_cadastrado', 'contrato_ativo')"
);
$stmt->execute();
$pendentes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo 'Robô Indique e Ganhe - ' . date('Y-m-d H:i:s') . ' - ' . count($pendentes) . " indicado(s) pendente(s)\n";

foreach ($pendentes as $indicado) {
    processarIndicado($conn, $indicado);
}

$conn->close();
echo "Fim.\n";

/**
 * Confere o progresso de um indicado no SGP e avança o status dele
 * conforme o que encontrar.
 */
function processarIndicado(mysqli $conn, array $indicado): void
{
    $cpf = $indicado['indicado_cpfcnpj'];
    $dadosCliente = chamarSGP('/api/ura/clientes/', ['cpfcnpj' => $cpf]);

    if ($dadosCliente === null) {
        echo "  [{$cpf}] SGP fora do ar agora, tenta de novo amanhã.\n";
        return;
    }

    $clientes = $dadosCliente['clientes'] ?? [];
    if (empty($clientes)) {
        echo "  [{$cpf}] Ainda não apareceu como cliente no SGP (pré-cadastro não convertido ainda).\n";
        return;
    }

    $cliente = $clientes[0];

    $contratoAtivo = null;
    foreach (($cliente['contratos'] ?? []) as $contrato) {
        if (strtolower(trim($contrato['status'] ?? '')) === 'ativo') {
            $contratoAtivo = $contrato;
            break;
        }
    }

    if ($contratoAtivo === null) {
        echo "  [{$cpf}] Ainda sem contrato ativo.\n";
        return;
    }

    if ($indicado['status'] === 'pre_cadastrado') {
        atualizarStatusIndicado($conn, (int) $indicado['id'], 'contrato_ativo');
    }

    $temFaturaPaga = false;
    foreach (($cliente['titulos'] ?? []) as $titulo) {
        if (in_array(strtolower(trim($titulo['status'] ?? '')), ['pago', 'liquidado', 'baixado'], true)) {
            $temFaturaPaga = true;
            break;
        }
    }

    if (!$temFaturaPaga) {
        echo "  [{$cpf}] Contrato ativo, mas a 1ª fatura ainda não foi paga.\n";
        return;
    }

    atualizarStatusIndicado($conn, (int) $indicado['id'], 'valida', true);
    echo "  [{$cpf}] Validado! Contrato ativo e 1ª fatura paga.\n";

    criarDescontoSeNecessario($conn, $indicado);
}

function atualizarStatusIndicado(mysqli $conn, int $id, string $status, bool $comDataValidacao = false): void
{
    if ($comDataValidacao) {
        $stmt = $conn->prepare('UPDATE indicados SET status = ?, data_validacao = NOW() WHERE id = ?');
    } else {
        $stmt = $conn->prepare('UPDATE indicados SET status = ? WHERE id = ?');
    }
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Cria o registro de desconto pro indicador — só se ainda não existir um
 * (o robô roda todo dia, não pode duplicar) e só se o indicador ainda
 * estiver elegível (link não bloqueado, contrato dele ainda ativo — se ele
 * cancelou nesse meio tempo, perde o direito, conforme o regulamento).
 */
function criarDescontoSeNecessario(mysqli $conn, array $indicado): void
{
    $indicadoId = (int) $indicado['id'];

    $stmt = $conn->prepare('SELECT id FROM descontos WHERE indicado_id = ? LIMIT 1');
    $stmt->bind_param('i', $indicadoId);
    $stmt->execute();
    $jaExiste = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($jaExiste) {
        return;
    }

    $stmt = $conn->prepare('SELECT indicador_cpfcnpj, indicador_nome, status FROM indicacoes WHERE codigo = ? LIMIT 1');
    $stmt->bind_param('s', $indicado['indicacao_codigo']);
    $stmt->execute();
    $indicacao = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$indicacao || $indicacao['status'] !== 'ativo') {
        echo "  -> Link do indicador está bloqueado, desconto não gerado.\n";
        return;
    }

    $dadosIndicador = chamarSGP('/api/ura/clientes/', ['cpfcnpj' => $indicacao['indicador_cpfcnpj']]);
    $indicadorAindaAtivo = false;
    foreach (($dadosIndicador['clientes'][0]['contratos'] ?? []) as $contrato) {
        if (strtolower(trim($contrato['status'] ?? '')) === 'ativo') {
            $indicadorAindaAtivo = true;
            break;
        }
    }

    if (!$indicadorAindaAtivo) {
        echo "  -> Indicador não tem mais contrato ativo (cancelou), perdeu o direito ao desconto.\n";
        return;
    }

    $token = gerarTokenAprovacao($conn);

    $stmt = $conn->prepare(
        'INSERT INTO descontos (indicador_cpfcnpj, indicado_id, percentual, status, token_aprovacao)
         VALUES (?, ?, 50.00, "pendente_aprovacao", ?)'
    );
    $indicadorCpf = $indicacao['indicador_cpfcnpj'];
    $stmt->bind_param('sis', $indicadorCpf, $indicadoId, $token);
    $stmt->execute();
    $stmt->close();

    enviarEmailAprovacao($token, $indicacao['indicador_nome'], $indicadorCpf, $indicado['indicado_nome'], 50.00);
    echo "  -> Desconto criado e email de aprovação enviado pro financeiro.\n";
}
