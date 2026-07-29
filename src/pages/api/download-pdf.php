<?php
// api/download-pdf.php

error_reporting(0);
ini_set('display_errors', 0);

require_once 'config.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    die("ID de fatura inválido.");
}

/* =============================================================
   PARÂMETROS OFICIAIS DO IXC SOFT (Retornar arquivo do Boleto)
============================================================= */
$payload = [
    'boletos'         => (string)$id,
    'juro'            => 'N',
    'multa'           => 'N',
    'atualiza_boleto' => 'N',
    'tipo_boleto'     => 'arquivo', // Padrão exato exigido pelo IXC
    'base64'          => 'S'        // Solicita o PDF em formato Base64
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => ERP_API_URL . 'get_boleto',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ERP_HEADERS,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Parâmetro de teste/diagnóstico: abra no navegador com ?id=186418&debug=1
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    echo "<h2>Diagnóstico get_boleto (IXC)</h2>";
    echo "• Código HTTP: " . $httpCode . "<br>";
    echo "• Erro cURL: " . ($curlError ?: 'Nenhum') . "<br>";
    echo "• Resposta Bruta:<pre>" . htmlspecialchars($response) . "</pre>";
    exit;
}

if ($httpCode === 200 && !empty($response)) {
    $pdfData = null;

    // Tenta decodificar caso o IXC devolva JSON
    $json = json_decode($response, true);
    if (is_array($json)) {
        if (!empty($json['base64'])) {
            $pdfData = base64_decode($json['base64']);
        } elseif (!empty($json['pdf'])) {
            $pdfData = base64_decode($json['pdf']);
        } elseif (!empty($json['conteudo'])) {
            $pdfData = base64_decode($json['conteudo']);
        }
    } else {
        // Se a resposta veio como string base64 pura (padrão do IXC)
        $cleanResponse = trim($response, '"\' ');
        $decoded = base64_decode($cleanResponse, true);
        if ($decoded !== false && (substr($decoded, 0, 4) === '%PDF' || strlen($decoded) > 100)) {
            $pdfData = $decoded;
        } elseif (substr($response, 0, 4) === '%PDF') {
            $pdfData = $response; // Binário direto
        }
    }

    if ($pdfData) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="fatura_zamtech_' . $id . '.pdf"');
        echo $pdfData;
        exit;
    }
}

// Fallback caso a API do IXC não entregue o arquivo
echo "<div style='font-family:Arial, sans-serif; text-align:center; padding:40px;'>";
echo "<h2 style='color:#121f77;'>Zamtech Fibra Óptica</h2>";
echo "<p style='color:#d9534f; font-size:16px;'>Não foi possível gerar a visualização do boleto no momento.</p>";
echo "<p>Por favor, utilize a opção de <strong>Linha Digitável</strong> ou <strong>Chave PIX</strong> para realizar o pagamento.</p>";
echo "</div>";