<?php
// Script SÓ PARA TESTE: manda um email simples pro financeiro, só pra
// confirmar se o mail() do servidor da Hostgator tá conseguindo entregar
// de verdade. Não mexe em nada do banco nem do SGP.
//
// Depois de confirmar que o email funciona (ou de trocar pro envio via
// SMTP do Gmail), pode apagar esse arquivo.
require_once __DIR__ . '/_config.php';

$chaveInformada = $_GET['chave'] ?? '';
if (!hash_equals(ROBO_CHAVE_TESTE, $chaveInformada)) {
    http_response_code(403);
    exit('Acesso negado.');
}
header('Content-Type: text/plain; charset=UTF-8');

$agora = date('Y-m-d H:i:s');
$assunto = "Indique e Ganhe - teste de email ({$agora})";
$corpo = "Esse é um email de teste, disparado na mão, só pra confirmar se o envio via SMTP tá entregando de verdade em " . EMAIL_FINANCEIRO . ".\n\n"
    . "Se você tá lendo isso, funcionou!\n\n"
    . "Horário do envio: {$agora}";

$enviado = enviarEmailViaSmtp(EMAIL_FINANCEIRO, $assunto, $corpo);

echo "Tentei mandar um email de teste pra " . EMAIL_FINANCEIRO . " via SMTP (" . SMTP_HOST . ").\n";
echo "Resultado: " . ($enviado ? 'SUCESSO -- o servidor confirmou o recebimento.' : 'FALHOU -- confere se SMTP_HOST, SMTP_USUARIO e SMTP_SENHA em _config.php já foram preenchidos certinho.') . "\n\n";
echo "Se deu sucesso, confere a caixa de entrada do " . EMAIL_FINANCEIRO . " (deve chegar rapidinho, sem cair em spam).\n";
