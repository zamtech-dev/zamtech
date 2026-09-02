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

// --- Envio de email via SMTP autenticado ---
// Por que isso existe: o mail() nativo da Hostgator manda o email dizendo
// "From: ...@zamtech.com.br", mas o SPF do domínio não autoriza o servidor
// da Hostgator a mandar email como zamtech.com.br (e quem cuida do DNS do
// domínio não libera inclusão nova) — então o Gmail recusa ou joga fora.
//
// A solução: mandar autenticado direto pelo servidor de email de verdade
// do domínio (a Dynu, que já é quem recebe o email de vocês — é o mesmo
// jeito que o Outlook/Thunderbird da Carol usa pra mandar pelo
// comercial@zamtech.com.br). Como é o servidor oficial do domínio, passa
// no SPF sem precisar mexer em nada de DNS, e não custa nada extra.
//
// Como configurar (a Carol consegue pegar isso no painel da Dynu, na parte
// de configuração de cliente de email / SMTP de saída do comercial@
// zamtech.com.br — o mesmo que ela usaria pra configurar o Outlook):
// 1. Nome do servidor SMTP de saída (algo tipo "nome-smtp.dynu.com").
// 2. Porta (normalmente 587 com TLS, ou 465 com SSL).
// 3. Usuário: o email completo, comercial@zamtech.com.br.
// 4. Senha: a mesma senha que a Carol usa pra entrar nesse email.
define('SMTP_HOST', 'zamtech-com-br-smtp.dynu.com');
define('SMTP_PORTA', 587);
define('SMTP_USUARIO', 'comercial@zamtech.com.br');
define('SMTP_SENHA', '@Zamtech33');
define('SMTP_NOME_REMETENTE', 'Zamtech Indique e Ganhe');

// --- Aplicação do desconto na fatura de verdade (cancelar + gerar avulso) ---
// Portador e Plano de Contas confirmados com você: Sicredi SGP e
// Fibra > Mensalidade.
define('SGP_PORTADOR_DESCONTO', 10);
define('SGP_PLANO_CONTAS_DESCONTO', 9);

// FREIO DE MÃO: enquanto isso for false, o robô só SIMULA a aplicação do
// desconto — ele mostra no log tudo que faria (qual fatura cancelaria, qual
// valor criaria) mas não cancela nem cria nada de verdade no SGP, e não
// marca o desconto como "aplicado". Assim dá pra rodar contra um indicador
// real, ler o resultado com calma e confirmar que os números batem, antes
// de deixar o robô mexer em fatura de verdade.
//
// Só mude pra true depois de ter visto pelo menos uma simulação com um
// indicador real e conferido que faz sentido.
define('DESCONTO_APLICACAO_ATIVA', true);

// --- Chave pra rodar scripts de fundo (robô, diagnósticos) manualmente
// pelo navegador quando não tem Terminal no cPanel. Troque depois de usar. ---
define('ROBO_CHAVE_TESTE', '920c729a5e148cdaeb8349d233306b2b');

// --- CPFs de teste ---
// Esses CPFs nunca são bloqueados por "já foi indicado antes" — o
// registro anterior é apagado automaticamente a cada novo teste, pra
// poder testar o fluxo do /assinar quantas vezes quiser sem precisar
// mexer no phpMyAdmin toda hora. USE SÓ PRA TESTE, nunca CPF de cliente
// de verdade.
define('CPFS_DE_TESTE', ['03766282514']);

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
 * Procura um valor entre vários nomes de campo possíveis num item vindo do
 * SGP. A doc às vezes não deixa 100% claro qual é o nome exato do campo,
 * então em vez de arriscar um KeyError (ou pior, ler o campo errado
 * silenciosamente), a gente tenta cada opção em ordem e devolve null se
 * nenhuma existir — quem chama decide o que fazer com null. Usada tanto no
 * robô quanto no gerar-link.php.
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
 * Manda um email "de verdade", autenticado via SMTP (não usa o mail()
 * nativo do PHP, que não é confiável pra esse domínio — ver o comentário de
 * SMTP_HOST lá em cima). Fala o protocolo SMTP na mão, linha por linha, sem
 * precisar instalar nenhuma biblioteca externa (não temos Composer/Terminal
 * na Hostgator). Funciona com qualquer servidor SMTP (Dynu, Gmail, etc) —
 * só depende das constantes SMTP_* configuradas.
 *
 * Devolve true se o servidor confirmou o recebimento, false se algo falhou
 * — quem chama decide o que fazer (tentar de novo amanhã, avisar de outro
 * jeito, etc). Nunca lança exceção: um email que falha não pode derrubar o
 * robô no meio do processamento de indicados.
 */
function enviarEmailViaSmtp(string $destinatario, string $assunto, string $corpo): bool
{
    if (SMTP_HOST === '' || SMTP_SENHA === '') {
        error_log('Indique e Ganhe - SMTP: falta configurar SMTP_HOST e/ou SMTP_SENHA em _config.php.');
        return false;
    }

    // Porta 465 é SSL "direto" desde o primeiro byte da conexão; as outras
    // portas conectam sem criptografia e usam STARTTLS logo depois.
    $protocolo = (SMTP_PORTA === 465) ? 'ssl' : 'tcp';
    $conexao = @stream_socket_client($protocolo . '://' . SMTP_HOST . ':' . SMTP_PORTA, $erroNum, $erroMsg, 15);
    if (!$conexao) {
        error_log("Indique e Ganhe - SMTP: não consegui conectar ({$erroMsg})");
        return false;
    }

    // Cada resposta do servidor SMTP pode vir em várias linhas; só a
    // última tem um espaço (em vez de hífen) logo depois do código de 3
    // dígitos — é o jeito de saber que a resposta terminou.
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

    // Porta 465 já é SSL direto na conexão; porta 587 (ou outras) usa
    // STARTTLS pra "promover" a conexão pra criptografada no meio do
    // caminho. Só faz STARTTLS se não for a porta 465.
    if (SMTP_PORTA !== 465) {
        $mandar('STARTTLS');
        if (strpos($ler(), '220') !== 0) {
            fclose($conexao);
            error_log('Indique e Ganhe - SMTP: STARTTLS recusado.');
            return false;
        }

        if (!stream_socket_enable_crypto($conexao, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($conexao);
            error_log('Indique e Ganhe - SMTP: falha ao iniciar TLS.');
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
        error_log('Indique e Ganhe - SMTP: autenticação recusada (confere SMTP_USUARIO e SMTP_SENHA em _config.php).');
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

    // No protocolo SMTP, uma linha com só um ponto marca o fim da
    // mensagem — se o corpo tiver uma linha assim por acaso, duplica o
    // ponto pra não confundir o servidor.
    $corpoEscapado = preg_replace('/^\./m', '..', $corpo);

    $mandar($cabecalhos . "\r\n" . $corpoEscapado . "\r\n.");
    $respostaEnvio = $ler();

    $mandar('QUIT');
    fclose($conexao);

    return strpos($respostaEnvio, '250') === 0;
}

/**
 * Manda o email pro financeiro avisando que tem um desconto de indicação
 * esperando aprovação.
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

    enviarEmailViaSmtp(EMAIL_FINANCEIRO, $assunto, $corpo);
}

/**
 * Email pro financeiro quando um indicador acumula 2+ descontos aprovados
 * ao mesmo tempo (o caso de 100% que vocês nunca fizeram na prática). O
 * robô NÃO tenta calcular isso sozinho — só avisa pra alguém decidir à mão.
 */
function enviarEmailAlertaAcumulo(string $indicadorCpf, string $indicadorNome, int $quantidade, string $detalhes = ''): void
{
    $assunto = 'Indique e Ganhe - ATENÇÃO: acúmulo de descontos precisa de revisão manual';
    $corpo = "Olá!\n\n"
        . "O indicador {$indicadorNome} (CPF {$indicadorCpf}) tem {$quantidade} descontos esperando aplicação ao mesmo tempo.\n\n"
        . "Isso é o caso de acúmulo (ex: 2 indicações = 100% de desconto) que vocês nunca aplicaram na prática antes. Por segurança, o robô NÃO calculou nada sozinho nem mexeu em nenhuma fatura desse indicador — nenhuma fatura foi tocada, pode ficar tranquilo(a).\n\n"
        . ($detalhes !== '' ? "Detalhes:\n{$detalhes}\n\n" : '')
        . "O que fazer: decida quanto de desconto (%) esse indicador deve receber no total, depois aplique manualmente no SGP — cancela a próxima fatura em aberto do contrato dele e cria uma avulsa no valor já com o desconto, do mesmo jeito que sempre foi feito (portador Sicredi, plano de contas Fibra > Mensalidade).\n\n"
        . "— Robô Indique e Ganhe, Zamtech";

    enviarEmailViaSmtp(EMAIL_FINANCEIRO, $assunto, $corpo);
}

/**
 * Email pro financeiro avisando que um desconto aprovado foi cancelado
 * automaticamente porque o indicador estava com fatura em atraso no
 * momento da aplicação — conforme o regulamento, quem está em atraso não
 * participa e perde o benefício daquele ciclo.
 */
function enviarEmailDescontoCanceladoAtraso(
    string $indicadorCpf,
    string $indicadorNome,
    string $indicadoNome,
    float $percentual,
    string $vencimentoAtrasado
): void {
    $assunto = 'Indique e Ganhe - desconto cancelado (indicador em atraso)';
    $corpo = "Olá!\n\n"
        . "Um desconto que já estava aprovado foi cancelado automaticamente porque, na hora de aplicar, o indicador estava com uma fatura vencida (vencimento {$vencimentoAtrasado}).\n\n"
        . "Conforme o regulamento, quem está em atraso não participa do ciclo e perde o benefício.\n\n"
        . "Indicador: {$indicadorNome} (CPF {$indicadorCpf})\n"
        . "Indicado validado: {$indicadoNome}\n"
        . "Desconto cancelado: " . number_format($percentual, 0) . "%\n\n"
        . "Não precisa fazer nada — é só um aviso. Se quiser conferir, o registro está na tabela descontos com status 'rejeitado'.\n\n"
        . "— Robô Indique e Ganhe, Zamtech";

    enviarEmailViaSmtp(EMAIL_FINANCEIRO, $assunto, $corpo);
}

/**
 * Email URGENTE pro financeiro quando o robô cancela uma fatura mas, por
 * algum motivo, não consegue criar a fatura avulsa substituta em seguida.
 * Isso deixa o cliente sem nenhuma fatura naquele ciclo — precisa de
 * atenção humana imediata, não pode esperar o próximo dia.
 */
function enviarEmailAlertaCritico(string $mensagem): void
{
    $assunto = 'Indique e Ganhe - URGENTE: fatura cancelada sem substituta';
    $corpo = "Atenção!\n\n{$mensagem}\n\nIsso precisa ser resolvido manualmente no SGP o quanto antes.\n\n— Robô Indique e Ganhe, Zamtech";

    enviarEmailViaSmtp(EMAIL_FINANCEIRO, $assunto, $corpo);
}
