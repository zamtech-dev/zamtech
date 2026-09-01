<?php
// Configuração central do Programa Indique e Ganhe.
// Fica fora de public/api/indique só na cabeça — na prática, como o site é
// estático e só o PHP dentro de public/ vai pro ar, todo config sensível
// tem que morar aqui mesmo. Isso é seguro: PHP nunca entrega o próprio
// código-fonte pra quem visita a URL direto, só executa (e essa página não
// imprime nada), então não tem vazamento de senha por aí.

// --- SGP (mesma credencial já usada em /api/segunda-via.php) ---
// Se quiser mais controle/auditoria separada no futuro, dá pra criar um
// token dedicado em Sistema -> Ferramentas -> Painel Admin -> Tokens no SGP
// e trocar só essas duas constantes.
define('SGP_URL', 'https://zamtech.sgp.tsmx.app');
define('SGP_APP', 'segunda-via-website');
define('SGP_TOKEN', '4ab8d0da-ed7d-4e91-b717-9d4a98625458');

// --- Banco de dados do Indique e Ganhe ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'ztclas09_indique');
define('DB_USER', 'ztclas09_indique');
define('DB_PASS', '%rR3Zw9Q_MMmZC3s');

// --- Aviso de desconto pendente pro financeiro ---
define('EMAIL_FINANCEIRO', 'zamtechcomercial@gmail.com');

/**
 * Abre a conexão com o banco. Se der erro, já responde em JSON e encerra
 * a requisição (nenhum endpoint precisa se preocupar com isso de novo).
 */
function conectarBanco(): mysqli
{
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        error_log('Indique e Ganhe - erro de conexão com banco: ' . $conn->connect_error);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro interno ao acessar o banco. Tente novamente em instantes.',
        ]);
        exit;
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}

/**
 * Chama um endpoint da API do SGP (mesmo padrão de autenticação usado em
 * /api/segunda-via.php: app + token no corpo da requisição).
 * Retorna o array decodificado, ou null se a chamada falhou.
 */
function chamarSGP(string $endpoint, array $params): ?array
{
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => SGP_URL . $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode(array_merge([
            'app' => SGP_APP,
            'token' => SGP_TOKEN,
        ], $params)),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);

    $response = curl_exec($curl);
    $erroCurl = curl_error($curl);
    curl_close($curl);

    if ($erroCurl) {
        error_log('Indique e Ganhe - erro ao chamar SGP (' . $endpoint . '): ' . $erroCurl);
        return null;
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

/**
 * Valida CPF pelo algoritmo oficial de dígitos verificadores.
 * Só aceita CPF (11 dígitos) — CNPJ (14 dígitos) já cai fora daqui,
 * então isso também garante "só Pessoa Física" sem depender de um campo
 * de texto variável que a API do SGP possa devolver.
 */
function validarCPF(string $cpf): bool
{
    $cpf = preg_replace('/\D/', '', $cpf);

    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }

    for ($t = 9; $t < 11; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) {
            $soma += (int) $cpf[$i] * (($t + 1) - $i);
        }
        $digito = ((10 * $soma) % 11) % 10;
        if ((int) $cpf[$t] !== $digito) {
            return false;
        }
    }

    return true;
}

/**
 * Gera um código de indicação único (8 caracteres, base36 maiúsculo).
 */
function gerarCodigoIndicacao(mysqli $conn): string
{
    do {
        $codigo = strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
        $stmt = $conn->prepare('SELECT id FROM indicacoes WHERE codigo = ?');
        $stmt->bind_param('s', $codigo);
        $stmt->execute();
        $existe = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    } while ($existe);

    return $codigo;
}

/**
 * Gera um token de aprovação único (64 caracteres hex) — é o "segredo" que
 * vai no link do email pro financeiro. Só quem tem o email consegue abrir
 * a página de aprovação daquele desconto específico.
 */
function gerarTokenAprovacao(mysqli $conn): string
{
    do {
        $token = bin2hex(random_bytes(32));
        $stmt = $conn->prepare('SELECT id FROM descontos WHERE token_aprovacao = ?');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $existe = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    } while ($existe);

    return $token;
}

/**
 * Manda o email pro financeiro avisando que tem um desconto de indicação
 * esperando aprovação. Usa o mail() nativo do PHP (Hostgator já manda sem
 * precisar de serviço externo) — se cair em spam no início, é coisa de
 * configuração de DNS (SPF/DKIM) do domínio, não do código em si.
 */
function enviarEmailAprovacao(
    string $tokenAprovacao,
    string $indicadorNome,
    string $indicadorCpf,
    string $indicadoNome,
    float $percentual
): void {
    $link = 'https://zamtech.com.br/api/indique/aprovar-desconto.php?token=' . $tokenAprovacao;

    $assunto = 'Indique e Ganhe - desconto pendente de aprovação';
    $corpo = "Olá!\n\n"
        . "Uma indicação foi validada (contrato ativo + 1ª fatura paga) e tem um desconto esperando aprovação:\n\n"
        . "Indicador: {$indicadorNome} (CPF {$indicadorCpf})\n"
        . "Indicado validado: {$indicadoNome}\n"
        . "Desconto: " . number_format($percentual, 0) . "% na próxima fatura do indicador\n\n"
        . "Pra aprovar ou rejeitar, acesse:\n{$link}\n\n"
        . "— Robô Indique e Ganhe, Zamtech";

    $headers = "From: Zamtech Indique e Ganhe <noreply@zamtech.com.br>\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    @mail(EMAIL_FINANCEIRO, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $corpo, $headers);
}
