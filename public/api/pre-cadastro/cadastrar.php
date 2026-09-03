<?php
// Recebe o pré-cadastro preenchido em /pre-cadastro e cria o registro na
// hora dentro do SGP — é exatamente o que a Carol hoje faz na mão a partir
// da mensagem de WhatsApp do cliente, só que sem ela precisar digitar nada.
// De propósito, NUNCA manda plano_id/planointernet_id: quem decide o plano
// (e tenta vender Mesh, câmera etc) é a Carol, na ligação dela.
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

$tipoPessoa = isset($input['tipoPessoa']) && $input['tipoPessoa'] === 'J' ? 'J' : 'F';

$nome = isset($input['nome']) ? trim($input['nome']) : '';
$nomeFantasia = isset($input['nomeFantasia']) ? trim($input['nomeFantasia']) : '';
$respNome = isset($input['respNome']) ? trim($input['respNome']) : '';
$respCpf = isset($input['respCpf']) ? preg_replace('/\D/', '', $input['respCpf']) : '';
$cpfCnpj = isset($input['cpfCnpj']) ? preg_replace('/\D/', '', $input['cpfCnpj']) : '';
$dataNascimento = isset($input['dataNascimento']) ? trim($input['dataNascimento']) : '';
$telefone1 = isset($input['telefone1']) ? preg_replace('/\D/', '', $input['telefone1']) : '';
$telefone2 = isset($input['telefone2']) ? preg_replace('/\D/', '', $input['telefone2']) : '';
$email = isset($input['email']) ? trim($input['email']) : '';
$cep = isset($input['cep']) ? preg_replace('/\D/', '', $input['cep']) : '';
$logradouro = isset($input['logradouro']) ? trim($input['logradouro']) : '';
$numero = isset($input['numero']) ? trim($input['numero']) : '';
$complemento = isset($input['complemento']) ? trim($input['complemento']) : '';
$bairro = isset($input['bairro']) ? trim($input['bairro']) : '';
$cidade = isset($input['cidade']) ? trim($input['cidade']) : '';
$uf = isset($input['uf']) ? strtoupper(trim($input['uf'])) : '';
$pontoReferencia = isset($input['pontoReferencia']) ? trim($input['pontoReferencia']) : '';
$diaVencimento = isset($input['diaVencimento']) ? (int) $input['diaVencimento'] : 0;
$aceite = !empty($input['aceite']);

// --- Validações básicas de preenchimento ---
$camposObrigatorios = [$nome, $cpfCnpj, $dataNascimento, $telefone1, $email, $cep, $logradouro, $numero, $bairro, $cidade, $uf];
foreach ($camposObrigatorios as $campo) {
    if ($campo === '') {
        echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'Preencha todos os campos obrigatórios.']);
        exit;
    }
}

if (!$aceite) {
    echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'É preciso concordar com os termos pra continuar.']);
    exit;
}

if ($tipoPessoa === 'F') {
    if (!validarCPF($cpfCnpj)) {
        echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'CPF inválido.']);
        exit;
    }
} else {
    if (!validarCNPJ($cpfCnpj)) {
        echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'CNPJ inválido.']);
        exit;
    }
    if ($respNome === '') {
        echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'Preencha o nome do responsável pela empresa.']);
        exit;
    }
}

if (strlen($uf) !== 2) {
    echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'UF inválida.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'Email inválido.']);
    exit;
}

if ($diaVencimento < 1 || $diaVencimento > 25) {
    echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'Escolha um dia de vencimento entre 1 e 25.']);
    exit;
}

try {
    // --- Resolve o dia de vencimento (1 a 25) pro vencimento_id interno do
    // SGP. Se por algum motivo a lista não vier ou não tiver esse dia
    // cadastrado, segue sem vencimento_id (não trava o cadastro por causa
    // disso) e deixa registrado na observação pra Carol ajustar na mão. ---
    $vencimentoId = null;
    $listaVencimentos = chamarSGP('/api/precadastro/vencimento/list', []);
    if (is_array($listaVencimentos)) {
        foreach ($listaVencimentos as $item) {
            if (!is_array($item)) {
                continue;
            }
            $dia = pegarCampo($item, ['dia']);
            if ($dia !== null && (int) $dia === $diaVencimento) {
                $vencimentoId = (int) pegarCampo($item, ['id']);
                break;
            }
        }
    }

    $observacaoPartes = ['Pré-cadastro via site (zamtech.com.br/pre-cadastro).'];
    $observacaoPartes[] = 'Telefone secundário: ' . ($telefone2 !== '' ? $telefone2 : 'não informado') . '.';
    $observacaoPartes[] = 'Dia de vencimento desejado: ' . $diaVencimento . ($vencimentoId === null ? ' (não encontrado na lista do SGP — ajustar manualmente).' : '.');
    $observacaoPartes[] = 'Aceite de fidelidade (mediante aprovação): sim.';
    if ($tipoPessoa === 'J') {
        $observacaoPartes[] = 'Responsável: ' . $respNome . ($respCpf !== '' ? " (CPF {$respCpf})" : '') . '.';
    }
    $observacao = implode(' ', $observacaoPartes);

    $camposComuns = [
        'cpfcnpj' => $cpfCnpj,
        'datanasc' => $dataNascimento,
        'email' => $email,
        'celular' => $telefone1,
        'logradouro' => $logradouro,
        'numero' => $numero,
        'complemento' => $complemento,
        'bairro' => $bairro,
        'cidade' => $cidade,
        'uf' => $uf,
        'cep' => $cep,
        'pais' => 'BR',
        'pontoreferencia' => $pontoReferencia,
        'observacao' => $observacao,
    ];
    if ($vencimentoId !== null) {
        $camposComuns['vencimento_id'] = $vencimentoId;
    }

    if ($tipoPessoa === 'F') {
        $payload = array_merge(['nome' => $nome], $camposComuns);
        $endpoint = '/api/precadastro/F';
    } else {
        $payload = array_merge([
            'nome' => $nome,
            'nomefantasia' => $nomeFantasia,
            'respempresa' => $respNome,
            'respcpf' => $respCpf,
        ], $camposComuns);
        $endpoint = '/api/precadastro/J';
    }

    $resultadoSGP = chamarSGP($endpoint, $payload);

    if ($resultadoSGP === null) {
        http_response_code(502);
        echo json_encode([
            'sucesso' => false,
            'tipo' => 'erro',
            'mensagem' => 'Não consegui enviar seu cadastro agora. Tente novamente em instantes ou fale com a gente pelo WhatsApp.',
        ]);
        exit;
    }

    $mensagemSgp = pegarCampo($resultadoSGP, ['message', 'mensagem']) ?? 'sem mensagem de retorno';

    enviarEmailAvisoPreCadastro($tipoPessoa, $nome, $cpfCnpj, $telefone1, $telefone2, $email, $cidade, $uf, (string) $mensagemSgp);

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Prontinho! Recebemos o seu pré-cadastro. Nosso time comercial vai entrar em contato pra fechar seu plano e agendar a instalação.',
    ]);
} catch (\Throwable $e) {
    error_log('Pré-Cadastro - cadastrar.php falhou: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'tipo' => 'erro',
        'mensagem' => 'Deu um erro aqui do nosso lado. Tenta de novo em instantes ou fala com a gente pelo WhatsApp.',
    ]);
}
