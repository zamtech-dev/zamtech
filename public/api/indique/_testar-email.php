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
$corpo = "Esse é um email de teste, disparado na mão, só pra confirmar se o mail() do servidor tá entregando de verdade em " . EMAIL_FINANCEIRO . ".\n\n"
    . "Se você tá lendo isso (mesmo que tenha caído no spam), funcionou!\n\n"
    . "Horário do envio: {$agora}";

$headers = "From: Zamtech Indique e Ganhe <noreply@zamtech.com.br>\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

$enviado = mail(EMAIL_FINANCEIRO, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $corpo, $headers);

echo "Tentei mandar um email de teste pra " . EMAIL_FINANCEIRO . ".\n";
echo "mail() retornou: " . ($enviado ? 'true (o servidor aceitou tentar enviar)' : 'false (o servidor recusou nem tentar)') . "\n\n";
echo "Isso NÃO garante que chegou na caixa de entrada de verdade -- só diz que o servidor da Hostgator aceitou processar o envio.\n";
echo "Confere a caixa de entrada E a pasta de spam/lixo eletrônico do " . EMAIL_FINANCEIRO . " agora.\n";
