<?php
// Script SÓ PARA TESTE: cria (ou apaga) um desconto com status "aprovado"
// falso, ligado a um CPF de cliente real, só pra dar pra rodar o
// robo-diario.php em modo simulação e ver se ele lê certinho os dados de
// contrato e fatura desse cliente vindos do SGP.
//
// Não toca em NADA no SGP — só grava (ou apaga) uma linha na nossa própria
// tabela "descontos". Continua 100% seguro mesmo rodando várias vezes.
//
// Depois que os testes acabarem e o robô estiver ligado de verdade
// (DESCONTO_APLICACAO_ATIVA = true), apague esse arquivo (ou me avise que
// eu apago).
require_once __DIR__ . '/_config.php';

$chaveInformada = $_GET['chave'] ?? '';
if (!hash_equals(ROBO_CHAVE_TESTE, $chaveInformada)) {
    http_response_code(403);
    exit('Acesso negado.');
}
header('Content-Type: text/plain; charset=UTF-8');

// Marca as linhas criadas por esse script, pra "apagar" nunca poder
// remover um desconto aprovado de verdade pelo financeiro por engano.
const MARCADOR_TESTE = 'TESTE (script de diagnóstico)';

$cpf = preg_replace('/\D/', '', $_GET['cpf'] ?? '');
$acao = $_GET['acao'] ?? 'criar';

if ($cpf === '') {
    exit("Passa o CPF do cliente de teste na URL, assim: ?chave=...&cpf=12345678900\n");
}

$conn = conectarBanco();
$marcador = MARCADOR_TESTE;

if ($acao === 'apagar') {
    $stmt = $conn->prepare('DELETE FROM descontos WHERE indicador_cpfcnpj = ? AND aprovado_por = ?');
    $stmt->bind_param('ss', $cpf, $marcador);
    $stmt->execute();
    $apagados = $stmt->affected_rows;
    $stmt->close();
    $conn->close();
    echo "Removi {$apagados} desconto(s) de teste desse CPF. Tabela limpa.\n";
    exit;
}

// Remove qualquer teste anterior desse mesmo CPF antes de criar outro, pra
// dar pra rodar esse link quantas vezes precisar sem acumular lixo.
$stmt = $conn->prepare('DELETE FROM descontos WHERE indicador_cpfcnpj = ? AND aprovado_por = ?');
$stmt->bind_param('ss', $cpf, $marcador);
$stmt->execute();
$stmt->close();

$stmt = $conn->prepare('SELECT id, indicado_nome FROM indicados ORDER BY id ASC LIMIT 1');
$stmt->execute();
$indicado = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$indicado) {
    $conn->close();
    exit("Não achei nenhuma linha na tabela 'indicados' pra usar de referência (o desconto precisa apontar pra algum indicado existente). Faz um cadastro de teste pelo /assinar primeiro.\n");
}

$stmt = $conn->prepare(
    "INSERT INTO descontos (indicador_cpfcnpj, indicado_id, percentual, status, aprovado_por, data_aprovacao)
     VALUES (?, ?, 50.00, 'aprovado', ?, NOW())"
);
$indicadoId = (int) $indicado['id'];
$stmt->bind_param('sis', $cpf, $indicadoId, $marcador);
$stmt->execute();
$novoId = $stmt->insert_id;
$stmt->close();
$conn->close();

echo "Criado! Desconto de teste #{$novoId}, 50%, status 'aprovado', pro CPF {$cpf}.\n\n";
echo "Agora abre o robo-diario.php (com a chave na URL, igual sempre) e procura a linha desse CPF — vai aparecer 'SIMULAÇÃO: cancelaria a fatura tal, criaria uma de R\$ tal'. Nada real muda, é só leitura.\n\n";
echo "Quando terminar de conferir, roda esse mesmo link de novo trocando pra &acao=apagar no final, pra limpar esse desconto de teste.\n";
