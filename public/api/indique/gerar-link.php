<?php
require_once __DIR__ . '/_config.php';

/**
 * Monta um texto simples pra mostrar um contrato na tela de "qual contrato
 * você quer usar?" — tenta achar o nome do plano e o valor (olhando numa
 * fatura desse contrato), mas se não achar nada, ainda mostra o número do
 * contrato, nunca fica em branco.
 */
function descreverContratoParaEscolha(array $contrato, array $titulosDoCliente): array
{
    $id = (int) pegarCampo($contrato, ['id', 'contratoId', 'contrato_id', 'contrato']);
    $planoNome = pegarCampo($contrato, ['plano', 'planoNome', 'nome_plano', 'descricaoPlano', 'plano_nome']);
    $endereco = pegarCampo($contrato, ['endereco', 'enderecoInstalacao', 'logradouro']);

    $valor = null;
    foreach ($titulosDoCliente as $titulo) {
        $contratoDoTitulo = pegarCampo($titulo, ['clientecontrato_id', 'contrato_id', 'contratoId', 'contrato']);
        if ($contratoDoTitulo !== null && (string) $contratoDoTitulo === (string) $id) {
            $valor = pegarCampo($titulo, ['valor', 'valorTotal', 'valor_total', 'valor_fatura']);
            break;
        }
    }

    $partes = [];
    if ($planoNome !== null) {
        $partes[] = $planoNome;
    }
    if ($valor !== null) {
        $partes[] = 'R$ ' . number_format((float) $valor, 2, ',', '.') . '/mês';
    }
    if ($endereco !== null) {
        $partes[] = $endereco;
    }

    $descricao = !empty($partes) ? implode(' — ', $partes) : "Contrato #{$id}";

    return ['id' => $id, 'descricao' => $descricao];
}

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
$nome = isset($input['nome']) ? trim($input['nome']) : '';
$cpf = isset($input['cpf']) ? preg_replace('/\D/', '', $input['cpf']) : '';

if ($nome === '' || $cpf === '') {
    echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'Preencha nome e CPF.']);
    exit;
}

if (!validarCPF($cpf)) {
    // Isso também barra CNPJ (14 dígitos nunca passa na validação de CPF),
    // então já garante "só Pessoa Física" sem depender de campo nenhum do SGP.
    echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'CPF inválido.']);
    exit;
}

// Tudo que mexe em banco/rede fica protegido: se algo quebrar (tabela que
// ainda não existe, SGP fora do ar, etc.) o cliente recebe um JSON limpo
// em vez de uma tela de erro do PHP quebrando o front-end.
try {
    $conn = conectarBanco();

    // Se esse CPF já tem um link ativo, devolve o mesmo em vez de criar outro.
    $stmt = $conn->prepare('SELECT codigo FROM indicacoes WHERE indicador_cpfcnpj = ? AND status = "ativo" LIMIT 1');
    $stmt->bind_param('s', $cpf);
    $stmt->execute();
    $existente = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existente) {
        echo json_encode([
            'sucesso' => true,
            'link' => 'https://zamtech.com.br/assinar?ref=' . $existente['codigo'],
            'ja_existia' => true,
        ]);
        exit;
    }

    // Consulta o cliente no SGP pelo CPF (mesmo endpoint que a 2ª via já usa).
    $dadosCliente = chamarSGP('/api/ura/clientes/', ['cpfcnpj' => $cpf]);

    if ($dadosCliente === null) {
        http_response_code(502);
        echo json_encode([
            'sucesso' => false,
            'tipo' => 'erro',
            'mensagem' => 'Não consegui confirmar seus dados agora. Tente novamente em instantes ou fale com a gente pelo WhatsApp.',
        ]);
        exit;
    }

    $clientes = $dadosCliente['clientes'] ?? [];

    if (empty($clientes)) {
        // Bloqueio, não erro: se o CPF nem aparece como cliente, pedir o
        // link por WhatsApp não resolve nada — a pessoa só confere o CPF
        // digitado (o botão de WhatsApp só faz sentido quando é a gente
        // que quebrou, não quando a regra do programa não foi cumprida).
        echo json_encode([
            'sucesso' => false,
            'tipo' => 'bloqueio',
            'mensagem' => 'Não encontramos um cadastro com esse CPF na Zamtech. Confira se digitou certo.',
        ]);
        exit;
    }

    $cliente = $clientes[0];
    $titulosDoCliente = $cliente['titulos'] ?? [];

    // Precisa ter pelo menos um contrato Ativo.
    $contratosAtivos = [];
    foreach (($cliente['contratos'] ?? []) as $contrato) {
        if (strtolower(trim($contrato['status'] ?? '')) === 'ativo') {
            $contratosAtivos[] = $contrato;
        }
    }

    if (empty($contratosAtivos)) {
        // Regra dura: sem contrato ativo não tem link, e WhatsApp não muda isso agora.
        echo json_encode([
            'sucesso' => false,
            'tipo' => 'bloqueio',
            'mensagem' => 'Seu contrato precisa estar ativo pra gerar o link de indicação.',
        ]);
        exit;
    }

    // Quem tem só 1 contrato ativo segue direto, sem perguntar nada. Quem
    // tem mais de 1 precisa escolher qual contrato quer usar pra indicar —
    // sem isso, a gente não saberia em qual contrato aplicar o desconto lá
    // na frente (é pra evitar exatamente o problema que a gente conversou:
    // desconto caindo no contrato errado).
    $contratoEscolhidoId = isset($input['contrato_id']) ? preg_replace('/\D/', '', (string) $input['contrato_id']) : '';

    $contratoAtivo = null;
    if (count($contratosAtivos) === 1) {
        $contratoAtivo = $contratosAtivos[0];
    } elseif ($contratoEscolhidoId !== '') {
        foreach ($contratosAtivos as $contrato) {
            $idContrato = pegarCampo($contrato, ['id', 'contratoId', 'contrato_id', 'contrato']);
            if ($idContrato !== null && (string) $idContrato === $contratoEscolhidoId) {
                $contratoAtivo = $contrato;
                break;
            }
        }
        if ($contratoAtivo === null) {
            echo json_encode(['sucesso' => false, 'tipo' => 'bloqueio', 'mensagem' => 'Esse contrato não é válido. Recarregue a página e tenta de novo.']);
            exit;
        }
    } else {
        $opcoes = [];
        foreach ($contratosAtivos as $contrato) {
            $opcoes[] = descreverContratoParaEscolha($contrato, $titulosDoCliente);
        }
        echo json_encode([
            'sucesso' => false,
            'tipo' => 'escolher_contrato',
            'mensagem' => 'Vimos que você tem mais de um contrato com a gente. Qual deles você quer usar pra indicar?',
            'contratos' => $opcoes,
        ]);
        exit;
    }

    $contratoId = (int) pegarCampo($contratoAtivo, ['id', 'contratoId', 'contrato_id', 'contrato']);

    // Precisa ter pelo menos uma fatura já paga NESSE contrato (comprova
    // que passou da 1ª fatura) — não vale fatura paga de outro contrato.
    $temFaturaPaga = false;
    foreach ($titulosDoCliente as $titulo) {
        $contratoDoTitulo = pegarCampo($titulo, ['clientecontrato_id', 'contrato_id', 'contratoId', 'contrato']);
        if ($contratoDoTitulo === null || (string) $contratoDoTitulo !== (string) $contratoId) {
            continue;
        }
        $status = strtolower(trim($titulo['status'] ?? ''));
        if (in_array($status, ['pago', 'liquidado', 'baixado'], true)) {
            $temFaturaPaga = true;
            break;
        }
    }

    if (!$temFaturaPaga) {
        echo json_encode([
            'sucesso' => false,
            'tipo' => 'bloqueio',
            'mensagem' => 'Você só pode gerar seu link depois que a 1ª fatura desse contrato for paga.',
        ]);
        exit;
    }

    // Elegível! Gera o código e salva.
    $codigo = gerarCodigoIndicacao($conn);

    $stmt = $conn->prepare(
        'INSERT INTO indicacoes (codigo, indicador_cpfcnpj, indicador_nome, indicador_contrato_id) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('sssi', $codigo, $cpf, $nome, $contratoId);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode([
        'sucesso' => true,
        'link' => 'https://zamtech.com.br/assinar?ref=' . $codigo,
        'ja_existia' => false,
    ]);
} catch (\Throwable $e) {
    error_log('Indique e Ganhe - gerar-link.php falhou: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'tipo' => 'erro',
        'mensagem' => 'Deu um erro aqui do nosso lado. Tenta de novo em instantes ou fala com a gente pelo WhatsApp.',
    ]);
}
