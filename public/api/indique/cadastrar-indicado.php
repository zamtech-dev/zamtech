<?php
// Recebe os dados de quem foi indicado (preenchidos em /assinar), valida
// as regras do programa e cria o pré-cadastro no SGP — é isso que faz o
// time comercial da Zamtech realmente enxergar esse lead e agendar a
// instalação. Sem essa chamada, a indicação ficaria só no nosso banco e
// ninguém do time ficaria sabendo que uma pessoa nova quer assinar.
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

$ref = isset($input['ref']) ? trim($input['ref']) : '';
// Operação pediu nome sempre em caixa alta (fica mais fácil de conferir no
// SGP). O front-end já manda maiúsculo, mas força de novo aqui — assim
// funciona mesmo se alguém chamar essa API direto, sem passar pelo site.
$nome = isset($input['nome']) ? mb_strtoupper(trim($input['nome']), 'UTF-8') : '';
$cpf = isset($input['cpf']) ? preg_replace('/\D/', '', $input['cpf']) : '';
$telefone = isset($input['telefone']) ? preg_replace('/\D/', '', $input['telefone']) : '';
$logradouro = isset($input['logradouro']) ? trim($input['logradouro']) : '';
$numero = isset($input['numero']) ? trim($input['numero']) : '';
$complemento = isset($input['complemento']) ? trim($input['complemento']) : '';
$bairro = isset($input['bairro']) ? trim($input['bairro']) : '';
$cidade = isset($input['cidade']) ? trim($input['cidade']) : '';
$uf = isset($input['uf']) ? strtoupper(trim($input['uf'])) : '';
$cep = isset($input['cep']) ? preg_replace('/\D/', '', $input['cep']) : '';

// --- Validações básicas de preenchimento ---
if ($ref === '' || $nome === '' || $cpf === '' || $telefone === '' || $logradouro === ''
    || $numero === '' || $bairro === '' || $cidade === '' || $uf === '' || $cep === '') {
    echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'Preencha todos os campos obrigatórios.']);
    exit;
}

if (!validarCPF($cpf)) {
    // Também barra CNPJ, igual no /indique: só Pessoa Física participa.
    echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'CPF inválido.']);
    exit;
}

if (strlen($uf) !== 2) {
    echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'UF inválida.']);
    exit;
}

try {
    $conn = conectarBanco();

    // --- 1. O link de indicação precisa existir e estar ativo ---
    $stmt = $conn->prepare('SELECT indicador_cpfcnpj, indicador_nome FROM indicacoes WHERE codigo = ? AND status = "ativo" LIMIT 1');
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $indicacao = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$indicacao) {
        $conn->close();
        echo json_encode([
            'sucesso' => false,
            'tipo' => 'bloqueio',
            'mensagem' => 'Esse link de indicação não é válido ou já expirou. Peça um novo link de quem te indicou.',
        ]);
        exit;
    }

    // --- 2. Ninguém pode se autoindicar ---
    if ($cpf === $indicacao['indicador_cpfcnpj']) {
        $conn->close();
        echo json_encode([
            'sucesso' => false,
            'tipo' => 'bloqueio',
            'mensagem' => 'Você não pode se indicar com o seu próprio CPF.',
        ]);
        exit;
    }

    // --- 3. Esse CPF já foi indicado antes (por esse ou outro indicador)? ---
    // Cada CPF só participa uma vez do programa — trava furo óbvio de
    // "farmar" indicações repetindo o mesmo indicado em vários links.
    $stmt = $conn->prepare(
        "SELECT id FROM indicados WHERE indicado_cpfcnpj = ? AND status NOT IN ('rejeitada', 'invalida') LIMIT 1"
    );
    $stmt->bind_param('s', $cpf);
    $stmt->execute();
    $jaIndicado = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($jaIndicado) {
        if (in_array($cpf, CPFS_DE_TESTE, true)) {
            // CPF de teste: apaga o registro (e qualquer desconto ligado a
            // ele) e deixa seguir o fluxo normal, como se fosse a 1ª vez.
            $del = $conn->prepare(
                'DELETE d FROM descontos d JOIN indicados i ON i.id = d.indicado_id WHERE i.indicado_cpfcnpj = ?'
            );
            $del->bind_param('s', $cpf);
            $del->execute();
            $del->close();

            $del = $conn->prepare('DELETE FROM indicados WHERE indicado_cpfcnpj = ?');
            $del->bind_param('s', $cpf);
            $del->execute();
            $del->close();
        } else {
            $conn->close();
            echo json_encode([
                'sucesso' => false,
                'tipo' => 'bloqueio',
                'mensagem' => 'Esse CPF já está participando de uma indicação. Cada pessoa só pode ser indicada uma vez.',
            ]);
            exit;
        }
    }

    // --- 4. Esse CPF já é cliente Zamtech? Programa vale só pra gente nova. ---
    $dadosCliente = chamarSGP('/api/ura/clientes/', ['cpfcnpj' => $cpf]);

    if ($dadosCliente === null) {
        $conn->close();
        http_response_code(502);
        echo json_encode([
            'sucesso' => false,
            'tipo' => 'erro',
            'mensagem' => 'Não consegui confirmar seus dados agora. Tente novamente em instantes ou fale com a gente pelo WhatsApp.',
        ]);
        exit;
    }

    if (!empty($dadosCliente['clientes'])) {
        $conn->close();
        echo json_encode([
            'sucesso' => false,
            'tipo' => 'bloqueio',
            'mensagem' => 'Esse CPF já é cliente Zamtech. O Indique e Ganhe vale só pra quem ainda não é cliente.',
        ]);
        exit;
    }

    // --- Tudo certo: cria o pré-cadastro no SGP (é isso que faz o time
    // comercial ver esse lead na fila normal deles, igual qualquer
    // contratação nova) ---
    $observacao = "Indicado via Programa Indique e Ganhe. Código: {$ref}. Indicado por: "
        . "{$indicacao['indicador_nome']} (CPF {$indicacao['indicador_cpfcnpj']}).";

    $resultadoSGP = chamarSGP('/api/precadastro/F', [
        'nome' => $nome,
        'cpfcnpj' => $cpf,
        'celular' => $telefone,
        'logradouro' => $logradouro,
        'numero' => $numero,
        'complemento' => $complemento,
        'bairro' => $bairro,
        'cidade' => $cidade,
        'uf' => $uf,
        'cep' => $cep,
        'pais' => 'BR',
        'observacao' => $observacao,
    ]);

    if ($resultadoSGP === null) {
        $conn->close();
        http_response_code(502);
        echo json_encode([
            'sucesso' => false,
            'tipo' => 'erro',
            'mensagem' => 'Não consegui enviar seu cadastro agora. Tente novamente em instantes ou fale com a gente pelo WhatsApp.',
        ]);
        exit;
    }

    // --- Salva no nosso banco (é isso que o robô diário vai acompanhar
    // até a instalação e a 1ª fatura paga, pra liberar o desconto do
    // indicador) ---
    $stmt = $conn->prepare(
        'INSERT INTO indicados (
            indicacao_codigo, indicado_cpfcnpj, indicado_nome, indicado_telefone,
            endereco_logradouro, endereco_numero, endereco_bairro, endereco_cidade,
            endereco_uf, endereco_cep, endereco_complemento, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pre_cadastrado")'
    );
    $stmt->bind_param(
        'sssssssssss',
        $ref, $cpf, $nome, $telefone,
        $logradouro, $numero, $bairro, $cidade,
        $uf, $cep, $complemento
    );
    $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Prontinho! Recebemos o seu cadastro. Nosso time comercial vai entrar em contato pra agendar a instalação.',
    ]);
} catch (\Throwable $e) {
    error_log('Indique e Ganhe - cadastrar-indicado.php falhou: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'tipo' => 'erro',
        'mensagem' => 'Deu um erro aqui do nosso lado. Tenta de novo em instantes ou fala com a gente pelo WhatsApp.',
    ]);
}
