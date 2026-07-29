<?php
// api/segunda-via.php

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.']);
    exit;
}

require_once 'config.php';
require_once 'rate_limit.php';

checarRateLimit(10, 60);

$input = json_decode(file_get_contents('php://input'), true);
$docInput = isset($input['cpf_cnpj']) ? trim($input['cpf_cnpj']) : '';
$docLimpo = preg_replace('/[^0-9]/', '', $docInput);

if (strlen($docLimpo) !== 11 && strlen($docLimpo) !== 14) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Por favor, digite um CPF ou CNPJ válido.']);
    exit;
}

function formatarCpfCnpj($doc) {
    if (strlen($doc) === 11) {
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $doc);
    } elseif (strlen($doc) === 14) {
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $doc);
    }
    return $doc;
}

function chamarWebserviceIXC($tabela, $payload) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => ERP_API_URL . $tabela,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ERP_HEADERS,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['erro_curl' => $error];
    return json_decode($response, true);
}

/* 1ª ETAPA: Buscar Cliente no IXC */
$docFormatado = formatarCpfCnpj($docLimpo);
$clientIds = [];

$resCliente = chamarWebserviceIXC('cliente', [
    'qtype' => 'cliente.cnpj_cpf', 'query' => $docFormatado, 'oper' => '=', 'page' => '1', 'rp' => '20'
]);

if (!empty($resCliente['registros'])) {
    foreach ($resCliente['registros'] as $c) { $clientIds[] = $c['id']; }
}

if (empty($clientIds)) {
    $resCliente = chamarWebserviceIXC('cliente', [
        'qtype' => 'cliente.cnpj_cpf', 'query' => $docLimpo, 'oper' => '=', 'page' => '1', 'rp' => '20'
    ]);
    if (!empty($resCliente['registros'])) {
        foreach ($resCliente['registros'] as $c) { $clientIds[] = $c['id']; }
    }
}

if (empty($clientIds)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Nenhum cadastro localizado para este CPF/CNPJ.']);
    exit;
}

/* 2ª ETAPA: Filtrar Faturas (Mês Atual e Próximo Mês) */
$hoje = date('Y-m-d');
$mesAtualAno = date('Y-m');
$proximoMesAno = date('Y-m', strtotime('first day of +1 month'));
$limiteDataMax = date('Y-m-t', strtotime('first day of +1 month'));

$faturasAbertas = [];

foreach ($clientIds as $idCli) {
    $resFaturas = chamarWebserviceIXC('fn_areceber', [
        'qtype' => 'fn_areceber.id_cliente', 'query' => $idCli, 'oper' => '=', 'page' => '1', 'rp' => '100',
        'sortname' => 'fn_areceber.data_vencimento', 'sortorder' => 'asc'
    ]);

    if (!empty($resFaturas['registros'])) {
        foreach ($resFaturas['registros'] as $fatura) {
            $status = strtoupper($fatura['status'] ?? '');
            $recebido = strtoupper($fatura['recebido'] ?? 'N');
            $vencimento = $fatura['data_vencimento'] ?? '';

            if ($status === 'A' && $recebido !== 'S' && !empty($vencimento)) {
                if ($vencimento <= $limiteDataMax) {
                    $vencMesAno = date('Y-m', strtotime($vencimento));

                    if ($vencimento < $hoje) { $periodo = 'Em Atraso'; }
                    elseif ($vencMesAno === $mesAtualAno) { $periodo = 'Mês Atual'; }
                    elseif ($vencMesAno === $proximoMesAno) { $periodo = 'Próximo Mês'; }
                    else { $periodo = 'Em Aberto'; }

                    $pixCode = '';
                    $linhaDigitavel = $fatura['linha_digitavel'] ?? '';

                    // 1. Tenta pegar via get_boleto com dados detalhados
                    $dadosBoleto = chamarWebserviceIXC('get_boleto', [
                        'boletos'         => $fatura['id'],
                        'juro'            => 'N',
                        'multa'           => 'N',
                        'atualiza_boleto' => 'N',
                        'tipo_boleto'     => 'dados'
                    ]);

                    if (is_array($dadosBoleto)) {
                        $pixCode = $dadosBoleto['pix_qrcode'] ?? $dadosBoleto['qr_code_pix'] ?? $dadosBoleto['pix_code_qrcode'] ?? '';
                        if (!empty($dadosBoleto['linha_digitavel'])) {
                            $linhaDigitavel = trim($dadosBoleto['linha_digitavel']);
                        }
                    }

                    // 2. Se o PIX continuou vazio, chama a rota especifica get_pix do IXC
                    if (empty($pixCode)) {
                        $resGetPix = chamarWebserviceIXC('get_pix', [
                            'id_receber' => $fatura['id']
                        ]);
                        if (is_array($resGetPix)) {
                            $pixCode = $resGetPix['qrcode'] ?? $resGetPix['pix_code'] ?? $resGetPix['emv'] ?? $resGetPix['payload'] ?? '';
                        }
                    }

                    $faturasAbertas[] = [
                        'id'              => $fatura['id'],
                        'vencimento'      => date('d/m/Y', strtotime($vencimento)),
                        'periodo'         => $periodo,
                        'valor'           => number_format($fatura['valor'], 2, ',', '.'),
                        'linha_digitavel' => $linhaDigitavel,
                        'pix_copia_cola'  => trim($pixCode),
                        'link_pdf'        => 'api/download-pdf.php?id=' . $fatura['id']
                    ];
                }
            }
        }
    }
}

if (empty($faturasAbertas)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Nenhuma fatura em aberto encontrada para o mês atual ou próximo mês.']);
    exit;
}

echo json_encode(['sucesso' => true, 'faturas' => $faturasAbertas]);