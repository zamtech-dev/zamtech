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
// A chave (ROBO_CHAVE_TESTE) agora mora no _config.php, compartilhada com
// outros scripts de fundo.
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

echo "\n";
aplicarDescontosAprovados($conn);

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

/**
 * Procura um valor entre vários nomes de campo possíveis num item vindo do
 * SGP. A doc às vezes não deixa 100% claro qual é o nome exato do campo,
 * então em vez de arriscar um KeyError (ou pior, ler o campo errado
 * silenciosamente), a gente tenta cada opção em ordem e devolve null se
 * nenhuma existir — quem chama decide o que fazer com null.
 */
function pegarCampo(array $item, array $possiveisChaves)
{
    foreach ($possiveisChaves as $chave) {
        if (isset($item[$chave]) && $item[$chave] !== '') {
            return $item[$chave];
        }
    }
    return null;
}

/**
 * Etapa final do Indique e Ganhe: pega cada desconto já aprovado pelo
 * financeiro e aplica de verdade na fatura do indicador — cancela a
 * próxima fatura em aberto e cria uma nova só no valor com desconto, do
 * mesmo jeito que a Raiane fazia manualmente no IXC.
 *
 * Enquanto DESCONTO_APLICACAO_ATIVA (em _config.php) estiver false, essa
 * função só MOSTRA o que faria, sem cancelar nem criar nada de verdade.
 */
function aplicarDescontosAprovados(mysqli $conn): void
{
    echo "Aplicando descontos aprovados" . (DESCONTO_APLICACAO_ATIVA ? '' : ' (modo SIMULAÇÃO, nada real é alterado)') . "...\n";

    $stmt = $conn->prepare(
        "SELECT id, indicador_cpfcnpj, indicado_id, percentual
         FROM descontos
         WHERE status = 'aprovado'
         ORDER BY indicador_cpfcnpj, id"
    );
    $stmt->execute();
    $aprovados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($aprovados)) {
        echo "  Nenhum desconto aprovado esperando aplicação.\n";
        return;
    }

    // Agrupa por indicador, pra pegar o caso raro de 2+ descontos aprovados
    // ao mesmo tempo pro mesmo indicador (acúmulo sem precedente real).
    $porIndicador = [];
    foreach ($aprovados as $desconto) {
        $porIndicador[$desconto['indicador_cpfcnpj']][] = $desconto;
    }

    foreach ($porIndicador as $indicadorCpf => $descontosDoIndicador) {
        if (count($descontosDoIndicador) > 1) {
            $quantidade = count($descontosDoIndicador);
            echo "  [{$indicadorCpf}] {$quantidade} descontos aprovados ao mesmo tempo — caso sem precedente, não vou calcular sozinho. Avisando financeiro.\n";
            $stmt = $conn->prepare('SELECT indicador_nome FROM indicacoes WHERE indicador_cpfcnpj = ? LIMIT 1');
            $stmt->bind_param('s', $indicadorCpf);
            $stmt->execute();
            $nomeRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            enviarEmailAlertaAcumulo($indicadorCpf, $nomeRow['indicador_nome'] ?? $indicadorCpf, count($descontosDoIndicador));
            continue;
        }

        aplicarUmDesconto($conn, $descontosDoIndicador[0]);
    }
}

/**
 * Aplica um único desconto aprovado (o caso normal: 1 indicador, 1
 * desconto de 50% esperando).
 */
function aplicarUmDesconto(mysqli $conn, array $desconto): void
{
    $indicadorCpf = $desconto['indicador_cpfcnpj'];
    $percentual = (float) $desconto['percentual'];

    $dadosIndicador = chamarSGP('/api/ura/clientes/', ['cpfcnpj' => $indicadorCpf]);
    if ($dadosIndicador === null) {
        echo "  [{$indicadorCpf}] SGP fora do ar agora, tenta de novo amanhã.\n";
        return;
    }

    $cliente = $dadosIndicador['clientes'][0] ?? null;
    if ($cliente === null) {
        echo "  [{$indicadorCpf}] Não achei esse cliente no SGP, tenta de novo amanhã.\n";
        return;
    }

    $contratoAtivo = null;
    foreach (($cliente['contratos'] ?? []) as $contrato) {
        if (strtolower(trim($contrato['status'] ?? '')) === 'ativo') {
            $contratoAtivo = $contrato;
            break;
        }
    }

    if ($contratoAtivo === null) {
        echo "  [{$indicadorCpf}] Não tem mais contrato ativo (cancelou) — perdeu o direito, não aplico o desconto.\n";
        return;
    }

    $contratoId = pegarCampo($contratoAtivo, ['id', 'contratoId', 'contrato_id', 'contrato']);
    if ($contratoId === null) {
        echo "  [{$indicadorCpf}] Achei o contrato ativo mas não consegui identificar o ID dele nos dados do SGP — preciso olhar isso com calma antes de continuar. Não vou arriscar. Dados brutos do contrato:\n";
        echo '  ' . var_export($contratoAtivo, true) . "\n";
        return;
    }

    // Precisa do nome do indicado mais cedo agora, porque tanto o caminho
    // normal quanto o caminho "cancelou por atraso" usam ele.
    $stmt = $conn->prepare('SELECT indicado_nome FROM indicados WHERE id = ? LIMIT 1');
    $indicadoIdBusca = (int) $desconto['indicado_id'];
    $stmt->bind_param('i', $indicadoIdBusca);
    $stmt->execute();
    $indicadoRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $indicadoNome = $indicadoRow['indicado_nome'] ?? ('indicado #' . $desconto['indicado_id']);

    // Fatura "cancelável" = ainda não paga e ainda não cancelada. Pega a de
    // vencimento mais próximo (a "próxima fatura", no sentido que a Raiane
    // usava no IXC).
    $candidatas = [];
    foreach (($cliente['titulos'] ?? []) as $titulo) {
        $status = strtolower(trim($titulo['status'] ?? ''));
        if (!in_array($status, ['pago', 'liquidado', 'baixado', 'cancelado'], true)) {
            $candidatas[] = $titulo;
        }
    }

    if (empty($candidatas)) {
        echo "  [{$indicadorCpf}] Ainda não tem fatura em aberto pra aplicar o desconto. Espero e tento de novo amanhã.\n";
        return;
    }

    // Regra do regulamento: quem está em atraso não participa e perde o
    // benefício desse ciclo (não é só "espera", é cancelado mesmo).
    // "Em atraso" = alguma fatura em aberto com vencimento já passado.
    $hoje = date('Y-m-d');
    foreach ($candidatas as $tituloAberto) {
        $vencimentoAberto = pegarCampo($tituloAberto, ['vencimento', 'dataVencimento', 'data_vencimento', 'dt_vencimento']);
        if ($vencimentoAberto !== null && (string) $vencimentoAberto < $hoje) {
            echo "  [{$indicadorCpf}] Tem fatura vencida em {$vencimentoAberto} — pelo regulamento, quem está em atraso não participa. Cancelando esse desconto.\n";

            $motivo = 'Indicador com fatura em atraso no momento da aplicação do desconto - bonificação cancelada conforme regulamento.';
            $descontoIdCancelar = (int) $desconto['id'];
            $stmt = $conn->prepare("UPDATE descontos SET status = 'rejeitado', motivo_rejeicao = ? WHERE id = ?");
            $stmt->bind_param('si', $motivo, $descontoIdCancelar);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('SELECT indicador_nome FROM indicacoes WHERE indicador_cpfcnpj = ? LIMIT 1');
            $stmt->bind_param('s', $indicadorCpf);
            $stmt->execute();
            $nomeIndicadorRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            enviarEmailDescontoCanceladoAtraso(
                $indicadorCpf,
                $nomeIndicadorRow['indicador_nome'] ?? $indicadorCpf,
                $indicadoNome,
                $percentual,
                (string) $vencimentoAberto
            );
            return;
        }
    }

    usort($candidatas, function ($a, $b) {
        $vA = pegarCampo($a, ['vencimento', 'dataVencimento', 'data_vencimento', 'dt_vencimento']) ?? '9999-12-31';
        $vB = pegarCampo($b, ['vencimento', 'dataVencimento', 'data_vencimento', 'dt_vencimento']) ?? '9999-12-31';
        return strcmp((string) $vA, (string) $vB);
    });
    $faturaAlvo = $candidatas[0];

    $faturaId = pegarCampo($faturaAlvo, ['id', 'tituloId', 'titulo_id', 'fatura_id']);
    $valorFatura = pegarCampo($faturaAlvo, ['valor', 'valorTotal', 'valor_total', 'valor_fatura', 'valor_cobranca']);
    $vencimento = pegarCampo($faturaAlvo, ['vencimento', 'dataVencimento', 'data_vencimento', 'dt_vencimento']);

    if ($faturaId === null || $valorFatura === null || $vencimento === null) {
        echo "  [{$indicadorCpf}] Achei uma fatura em aberto, mas não bati o pé em algum campo dela (id/valor/vencimento) nos dados do SGP — não vou arriscar cancelar no escuro. Dados brutos da fatura:\n";
        echo '  ' . var_export($faturaAlvo, true) . "\n";
        return;
    }

    $valorFatura = (float) $valorFatura;
    $valorDesconto = round($valorFatura * ($percentual / 100), 2);
    $valorPago = round($valorFatura - $valorDesconto, 2);

    $motivoCancelamento = "Desconto Indique e Ganhe ({$percentual}%) - indicação de {$indicadoNome} validada";
    $observacaoAvulso = "Indique e Ganhe: {$percentual}% de desconto sobre fatura original de R$ " . number_format($valorFatura, 2, ',', '.')
        . " (indicação de {$indicadoNome} validada). Fatura original #{$faturaId} cancelada.";

    echo "  [{$indicadorCpf}] Fatura #{$faturaId}, vencimento {$vencimento}, valor R$ " . number_format($valorFatura, 2, ',', '.')
        . " -> com {$percentual}% de desconto, indicador paga R$ " . number_format($valorPago, 2, ',', '.') . ".\n";

    if (!DESCONTO_APLICACAO_ATIVA) {
        echo "  [{$indicadorCpf}] SIMULAÇÃO: cancelaria a fatura #{$faturaId} e criaria uma avulsa de R$ " . number_format($valorPago, 2, ',', '.')
            . " vencendo em {$vencimento}, contrato #{$contratoId}, portador " . SGP_PORTADOR_DESCONTO . ", plano de contas " . SGP_PLANO_CONTAS_DESCONTO . ". Nada foi alterado de verdade.\n";
        return;
    }

    $respostaCancelar = chamarSGP("/api/banco/titulo/{$faturaId}/cancelar/", ['motivo' => $motivoCancelamento]);
    if ($respostaCancelar === null) {
        echo "  [{$indicadorCpf}] Não consegui cancelar a fatura #{$faturaId} agora (SGP não respondeu certo). Tenta de novo amanhã, nada foi alterado.\n";
        return;
    }

    // Não confio só no texto da resposta do cancelamento (já vimos na
    // prática que o SGP pode responder "de boa", sem palavra de erro
    // nenhuma, e mesmo assim não cancelar nada de verdade). Em vez disso,
    // busco os dados do cliente DE NOVO no SGP e confiro se essa fatura
    // realmente virou "cancelado". Só sigo pra criar a avulsa depois dessa
    // confirmação — criar a avulsa sem ter certeza que a original foi
    // cancelada dobraria a cobrança do cliente.
    $dadosConferencia = chamarSGP('/api/ura/clientes/', ['cpfcnpj' => $indicadorCpf]);
    $statusAposCancelar = null;
    foreach (($dadosConferencia['clientes'][0]['titulos'] ?? []) as $tituloConferencia) {
        $idConferencia = pegarCampo($tituloConferencia, ['id', 'tituloId', 'titulo_id', 'fatura_id']);
        if ($idConferencia !== null && (string) $idConferencia === (string) $faturaId) {
            $statusAposCancelar = strtolower(trim($tituloConferencia['status'] ?? ''));
            break;
        }
    }

    if ($statusAposCancelar !== 'cancelado') {
        $mensagem = "Tentei cancelar a fatura #{$faturaId} do indicador {$indicadorCpf} (indicação de {$indicadoNome}), mas conferindo de novo no SGP ela continua com status '"
            . ($statusAposCancelar ?? 'não encontrada') . "' — ou seja, NÃO foi cancelada de verdade, mesmo a chamada não tendo dado erro explícito.\n\n"
            . "Por segurança, NÃO criei a fatura avulsa (isso evitaria cobrar a mais em cima da fatura original). Resposta bruta que o SGP deu pro cancelamento: " . json_encode($respostaCancelar);
        echo "  [{$indicadorCpf}] ERRO: {$mensagem}\n";
        enviarEmailAlertaCritico($mensagem);
        return;
    }

    echo "  [{$indicadorCpf}] Fatura #{$faturaId} cancelada (confirmei de novo no SGP). Criando a fatura avulsa com desconto...\n";

    $respostaAvulso = chamarSGP('/api/ura/cliente/titulo/avulso/add/', [
        'contrato' => (int) $contratoId,
        'portador' => SGP_PORTADOR_DESCONTO,
        'parcelas' => 1,
        'valor' => $valorPago,
        'data_vencimento' => $vencimento,
        'plano_contas' => SGP_PLANO_CONTAS_DESCONTO,
        'observacao' => $observacaoAvulso,
    ]);

    $novoTituloId = is_array($respostaAvulso) ? ($respostaAvulso[0]['titulo_id'] ?? null) : null;

    if ($novoTituloId === null) {
        // Situação crítica: já cancelamos a fatura original e a nova não
        // foi criada. O cliente fica sem fatura nenhuma nesse ciclo até
        // alguém resolver à mão — por isso o email urgente, não só o log.
        $mensagem = "Cancelei a fatura #{$faturaId} do indicador {$indicadorCpf} (indicação de {$indicadoNome}), "
            . "mas NÃO consegui criar a fatura avulsa substituta de R$ " . number_format($valorPago, 2, ',', '.') . ".\n"
            . "Resposta do SGP: " . json_encode($respostaAvulso);
        echo "  [{$indicadorCpf}] ERRO CRÍTICO: {$mensagem}\n";
        enviarEmailAlertaCritico($mensagem);
        return;
    }

    $stmt = $conn->prepare(
        "UPDATE descontos
         SET status = 'aplicado', fatura_id = ?, valor_fatura = ?, valor_desconto = ?, valor_pago = ?, data_aplicacao = NOW()
         WHERE id = ?"
    );
    $novoTituloId = (int) $novoTituloId;
    $descontoId = (int) $desconto['id'];
    $stmt->bind_param('idddi', $novoTituloId, $valorFatura, $valorDesconto, $valorPago, $descontoId);
    $stmt->execute();
    $stmt->close();

    echo "  [{$indicadorCpf}] Pronto! Fatura avulsa #{$novoTituloId} criada com desconto, desconto marcado como aplicado.\n";
}
