<?php
// Configuração central do Pré-Cadastro (site -> SGP).
// Feature independente do Indique e Ganhe — por isso tem seu próprio
// _config.php, mesmo repetindo algumas constantes/funções que já existem em
// /api/indique/_config.php. Assim um não depende do outro pra funcionar ou
// pra ser desligado/alterado no futuro.
//
// Diferente do Indique e Ganhe, esse fluxo não guarda nada em banco de
// dados próprio: o pré-cadastro é criado direto no SGP na hora (é lá que a
// Carol já vê e finaliza tudo), e só manda um email de aviso. Por isso não
// tem DB_HOST/DB_NAME aqui.

// --- SGP (mesma credencial já usada em /api/segunda-via.php e /api/indique) ---
define('SGP_URL', 'https://zamtech.sgp.tsmx.app');
define('SGP_APP', 'segunda-via-website');
define('SGP_TOKEN', '4ab8d0da-ed7d-4e91-b717-9d4a98625458');

// --- Aviso de pré-cadastro novo pra Carol (comercial) ---
// Mesmo endereço já usado pelo Indique e Ganhe — decisão confirmada com o
// cliente: "1. Mesmo email".
define('EMAIL_FINANCEIRO', 'zamtechcomercial@gmail.com');

// --- Login e senha padrão do pré-cadastro criado no SGP ---
// O "login" do cliente no SGP sempre usa o email que a pessoa preencheu no
// formulário (resolvido direto no cadastrar.php). Senha e senha da central
// são fixas — padrão combinado com a Operação — e nunca aparecem na
// página, só são preenchidas aqui no backend.
define('PRECADASTRO_SENHA_PADRAO', '12345678');
define('PRECADASTRO_CENTRAL_SENHA_PADRAO', '1234');

// --- Envio de email via SMTP autenticado ---
// Mesmo esquema (e mesmo motivo) do /api/indique/_config.php: o mail()
// nativo da Hostgator não passa no SPF do domínio, então manda-se
// autenticado direto pelo servidor de email oficial da Zamtech (Dynu).
define('SMTP_HOST', 'zamtech-com-br-smtp.dynu.com');
define('SMTP_PORTA', 587);
define('SMTP_USUARIO', 'comercial@zamtech.com.br');
define('SMTP_SENHA', '@Zamtech33');
define('SMTP_NOME_REMETENTE', 'Zamtech Pré-Cadastro');

/**
 * Chama um endpoint da API do SGP (app + token no corpo da requisição).
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
        error_log('Pré-Cadastro - erro ao chamar SGP (' . $endpoint . '): ' . $erroCurl);
        return null;
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

/**
 * Valida CPF pelo algoritmo oficial de dígitos verificadores.
 * Só aceita CPF (11 dígitos) — CNPJ (14 dígitos) é validado à parte por
 * validarCNPJ(), já que aqui (diferente do Indique e Ganhe) o pré-cadastro
 * aceita Pessoa Jurídica também.
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
 * Valida CNPJ pelo algoritmo oficial de dígitos verificadores (14 dígitos).
 */
function validarCNPJ(string $cnpj): bool
{
    $cnpj = preg_replace('/\D/', '', $cnpj);

    if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
        return false;
    }

    $calcularDigito = function (string $base, array $pesos) {
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += (int) $base[$i] * $peso;
        }
        $resto = $soma % 11;
        return $resto < 2 ? 0 : 11 - $resto;
    };

    $pesos1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    $digito1 = $calcularDigito(substr($cnpj, 0, 12), $pesos1);
    if ($digito1 !== (int) $cnpj[12]) {
        return false;
    }

    $pesos2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    $digito2 = $calcularDigito(substr($cnpj, 0, 13), $pesos2);
    if ($digito2 !== (int) $cnpj[13]) {
        return false;
    }

    return true;
}

/**
 * Procura um valor entre vários nomes de campo possíveis num item vindo do
 * SGP (mesma ideia do /api/indique/_config.php — a doc às vezes não deixa
 * 100% claro qual é o nome exato do campo).
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
 * Manda um email "de verdade", autenticado via SMTP — mesma implementação
 * (linha por linha do protocolo, sem biblioteca externa) do
 * /api/indique/_config.php, já que a Hostgator não tem Composer/Terminal.
 */
function enviarEmailViaSmtp(string $destinatario, string $assunto, string $corpo): bool
{
    if (SMTP_HOST === '' || SMTP_SENHA === '') {
        error_log('Pré-Cadastro - SMTP: falta configurar SMTP_HOST e/ou SMTP_SENHA em _config.php.');
        return false;
    }

    $protocolo = (SMTP_PORTA === 465) ? 'ssl' : 'tcp';
    $conexao = @stream_socket_client($protocolo . '://' . SMTP_HOST . ':' . SMTP_PORTA, $erroNum, $erroMsg, 15);
    if (!$conexao) {
        error_log("Pré-Cadastro - SMTP: não consegui conectar ({$erroMsg})");
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
            error_log('Pré-Cadastro - SMTP: STARTTLS recusado.');
            return false;
        }

        if (!stream_socket_enable_crypto($conexao, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($conexao);
            error_log('Pré-Cadastro - SMTP: falha ao iniciar TLS.');
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
        error_log('Pré-Cadastro - SMTP: autenticação recusada (confere SMTP_USUARIO e SMTP_SENHA em _config.php).');
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

/**
 * Manda pra Carol o aviso de que um pré-cadastro novo chegou. Ela já vê o
 * registro completo dentro do SGP — esse email é só o "toque" pra ela saber
 * que precisa ir lá conferir e ligar oferecendo o plano (Mesh, câmeras etc).
 */
function enviarEmailAvisoPreCadastro(
    string $tipoPessoa,
    string $nome,
    string $cpfCnpj,
    string $telefone1,
    string $telefone2,
    string $email,
    string $cidade,
    string $uf,
    string $mensagemSgp
): void {
    $assunto = 'Pré-cadastro novo no site — ' . $nome;
    $rotuloDocumento = $tipoPessoa === 'J' ? 'CNPJ' : 'CPF';

    $corpo = "Olá!\n\n"
        . "Chegou um pré-cadastro novo pelo site (zamtech.com.br/pre-cadastro):\n\n"
        . "Nome: {$nome}\n"
        . "{$rotuloDocumento}: {$cpfCnpj}\n"
        . "Telefone 1: {$telefone1}\n"
        . "Telefone 2: " . ($telefone2 !== '' ? $telefone2 : '(não informado)') . "\n"
        . "Email: {$email}\n"
        . "Cidade/UF: {$cidade}/{$uf}\n\n"
        . "O cadastro já foi criado no SGP ({$mensagemSgp}) — sem plano definido de propósito, pra você fechar a venda oferecendo o plano certo (e Mesh, câmeras, etc).\n\n"
        . "— Site Zamtech, Pré-Cadastro";

    enviarEmailViaSmtp(EMAIL_FINANCEIRO, $assunto, $corpo);
}
