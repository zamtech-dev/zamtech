-- Schema do Programa Indique e Ganhe Zamtech
-- Rodar isso inteiro de uma vez no phpMyAdmin, dentro do banco ztclas09_indique,
-- na aba "SQL" (cola tudo e clica em "Executar").

SET NAMES utf8mb4;

-- 1. Cada indicador que gerou um link de indicação.
CREATE TABLE IF NOT EXISTS indicacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(20) NOT NULL,
    indicador_cpfcnpj VARCHAR(20) NOT NULL,
    indicador_nome VARCHAR(255) NOT NULL,
    indicador_contrato_id INT NULL,
    status ENUM('ativo', 'bloqueado') NOT NULL DEFAULT 'ativo',
    data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_codigo (codigo),
    KEY idx_indicador_cpfcnpj (indicador_cpfcnpj)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Cada pessoa que preencheu o formulário através de um link (o "indicado").
CREATE TABLE IF NOT EXISTS indicados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    indicacao_codigo VARCHAR(20) NOT NULL,
    indicado_cpfcnpj VARCHAR(20) NOT NULL,
    indicado_nome VARCHAR(255) NOT NULL,
    indicado_telefone VARCHAR(20) NULL,
    endereco_logradouro VARCHAR(255) NULL,
    endereco_numero VARCHAR(20) NULL,
    endereco_bairro VARCHAR(120) NULL,
    endereco_cidade VARCHAR(120) NULL,
    endereco_uf CHAR(2) NULL,
    endereco_cep VARCHAR(10) NULL,
    endereco_complemento VARCHAR(255) NULL,
    sgp_precadastro_id INT NULL,
    sgp_contrato_id INT NULL,
    status ENUM(
        'pendente',
        'pre_cadastrado',
        'contrato_ativo',
        'primeira_fatura_paga',
        'valida',
        'invalida',
        'rejeitada'
    ) NOT NULL DEFAULT 'pendente',
    observacao_manual TEXT NULL,
    data_cadastro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_validacao DATETIME NULL,
    KEY idx_indicado_cpfcnpj (indicado_cpfcnpj),
    KEY idx_indicacao_codigo (indicacao_codigo),
    KEY idx_status (status),
    CONSTRAINT fk_indicados_indicacao FOREIGN KEY (indicacao_codigo)
        REFERENCES indicacoes (codigo) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ficha de cada desconto: nasce quando o robô valida uma indicação,
--    e só é efetivado quando alguém do financeiro confirma.
CREATE TABLE IF NOT EXISTS descontos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    indicador_cpfcnpj VARCHAR(20) NOT NULL,
    indicado_id INT NOT NULL,
    percentual DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    status ENUM(
        'pendente_aprovacao',
        'aprovado',
        'aplicado',
        'rejeitado'
    ) NOT NULL DEFAULT 'pendente_aprovacao',
    fatura_id INT NULL,
    valor_fatura DECIMAL(10,2) NULL,
    valor_desconto DECIMAL(10,2) NULL,
    valor_pago DECIMAL(10,2) NULL,
    token_aprovacao VARCHAR(64) NULL,
    aprovado_por VARCHAR(100) NULL,
    motivo_rejeicao TEXT NULL,
    data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_aprovacao DATETIME NULL,
    data_aplicacao DATETIME NULL,
    UNIQUE KEY uq_token_aprovacao (token_aprovacao),
    KEY idx_indicador_cpfcnpj (indicador_cpfcnpj),
    KEY idx_status (status),
    CONSTRAINT fk_descontos_indicado FOREIGN KEY (indicado_id)
        REFERENCES indicados (id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
