<?php
// Página que o financeiro abre a partir do link no email de aprovação.
// GET com ?token=... mostra os detalhes e pede confirmação humana.
// POST aprova ou rejeita. Sem essa confirmação, nenhum desconto encosta
// numa fatura de verdade — é o freio de mão combinado.
require_once __DIR__ . '/_config.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

function estiloBase(): string
{
    return '<style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f4f4f7; margin: 0; padding: 40px 16px; display: flex; justify-content: center; }
        .caixa { max-width: 480px; width: 100%; background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
        h1 { font-size: 1.25rem; color: #0325D0; margin-top: 0; }
        .dado { margin: 10px 0; font-size: .95rem; color: #333; }
        .dado strong { color: #111; }
        .btns { margin-top: 20px; }
        button { width: 100%; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: .95rem; }
        .aprovar { background: #16a34a; color: #fff; }
        .rejeitar { background: #dc2626; color: #fff; margin-top: 12px; }
        textarea { width: 100%; margin-top: 8px; padding: 8px; border-radius: 6px; border: 1px solid #ccc; font-family: inherit; box-sizing: border-box; }
        form { margin: 0; }
    </style>';
}

function paginaMensagem(string $titulo, string $mensagem): void
{
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Indique e Ganhe</title>'
        . estiloBase()
        . '</head><body><div class="caixa"><h1>' . htmlspecialchars($titulo) . '</h1><p class="dado">'
        . htmlspecialchars($mensagem) . '</p></div></body></html>';
    exit;
}

if ($token === '') {
    paginaMensagem('Link inválido', 'Esse link de aprovação está incompleto.');
}

try {
    $conn = conectarBanco();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = $_POST['acao'] ?? '';
        $motivo = trim($_POST['motivo'] ?? '');

        $stmt = $conn->prepare('SELECT id FROM descontos WHERE token_aprovacao = ? AND status = "pendente_aprovacao" LIMIT 1');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $desconto = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$desconto) {
            $conn->close();
            paginaMensagem('Já processado', 'Esse desconto já foi aprovado ou rejeitado antes (ou o link não existe mais).');
        }

        $descontoId = (int) $desconto['id'];

        if ($acao === 'aprovar') {
            $aprovadoPor = 'Financeiro Zamtech';
            $stmt = $conn->prepare('UPDATE descontos SET status = "aprovado", aprovado_por = ?, data_aprovacao = NOW() WHERE id = ?');
            $stmt->bind_param('si', $aprovadoPor, $descontoId);
            $stmt->execute();
            $stmt->close();
            $conn->close();
            paginaMensagem('Desconto aprovado!', 'Prontinho. A aplicação na fatura do indicador é o próximo passo (ainda não automatizado nessa etapa).');
        }

        if ($acao === 'rejeitar') {
            $stmt = $conn->prepare('UPDATE descontos SET status = "rejeitado", motivo_rejeicao = ?, data_aprovacao = NOW() WHERE id = ?');
            $stmt->bind_param('si', $motivo, $descontoId);
            $stmt->execute();
            $stmt->close();
            $conn->close();
            paginaMensagem('Desconto rejeitado', 'Registrado. Esse desconto não vai ser aplicado.');
        }

        $conn->close();
        paginaMensagem('Ação inválida', 'Não entendi essa ação.');
    }

    // GET: mostra os detalhes pra confirmar.
    $stmt = $conn->prepare(
        'SELECT d.id, d.percentual, d.indicador_cpfcnpj, ic.indicador_nome, ind.indicado_nome, ind.indicado_cpfcnpj
         FROM descontos d
         JOIN indicados ind ON ind.id = d.indicado_id
         JOIN indicacoes ic ON ic.codigo = ind.indicacao_codigo
         WHERE d.token_aprovacao = ? AND d.status = "pendente_aprovacao"
         LIMIT 1'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $desconto = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$desconto) {
        paginaMensagem('Já processado', 'Esse desconto já foi aprovado ou rejeitado antes (ou o link não existe mais).');
    }
} catch (\Throwable $e) {
    error_log('Indique e Ganhe - aprovar-desconto.php falhou: ' . $e->getMessage());
    paginaMensagem('Erro', 'Deu um erro ao carregar essa aprovação. Tenta de novo em instantes.');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>Aprovar desconto - Indique e Ganhe</title>
<?= estiloBase() ?>
</head>
<body>
<div class="caixa">
    <h1>Aprovar desconto de indicação?</h1>
    <p class="dado"><strong>Indicador:</strong> <?= htmlspecialchars($desconto['indicador_nome']) ?> (CPF <?= htmlspecialchars($desconto['indicador_cpfcnpj']) ?>)</p>
    <p class="dado"><strong>Indicado validado:</strong> <?= htmlspecialchars($desconto['indicado_nome']) ?> (CPF <?= htmlspecialchars($desconto['indicado_cpfcnpj']) ?>)</p>
    <p class="dado"><strong>Desconto:</strong> <?= htmlspecialchars(number_format((float) $desconto['percentual'], 0)) ?>% na próxima fatura do indicador</p>

    <div class="btns">
        <form method="POST">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="acao" value="aprovar">
            <button type="submit" class="aprovar">Aprovar desconto</button>
        </form>

        <form method="POST">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="acao" value="rejeitar">
            <textarea name="motivo" placeholder="Motivo da rejeição (opcional)" rows="2"></textarea>
            <button type="submit" class="rejeitar">Rejeitar</button>
        </form>
    </div>
</div>
</body>
</html>
