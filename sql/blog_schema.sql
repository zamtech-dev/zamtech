-- ============================================================
-- Zamtech Blog - Schema do banco de dados
-- Banco: ztclas09_blog (siga o mesmo padrao das outras features)
-- ============================================================

-- Tabela de usuarios do admin (voce, e quem mais precisar logar no futuro)
CREATE TABLE IF NOT EXISTS blog_admin_usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuario admin inicial
-- Login: zamtech.marketing@proton.me
-- Senha gerada (troque depois em Configuracoes): tnbT4xwvn4FXMw9z
INSERT INTO blog_admin_usuarios (nome, email, senha_hash) VALUES (
    'Lidson',
    'zamtech.marketing@proton.me',
    '$2y$12$z8iSp3IYmEFyQjoCvgvKnOjWl250CGswMiVYMukFL04ntuho/u1ii'
);

-- Tabela dos artigos do blog
CREATE TABLE IF NOT EXISTS blog_artigos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    autor_id INT UNSIGNED NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    slug VARCHAR(220) NOT NULL UNIQUE,
    categoria VARCHAR(60) DEFAULT NULL,
    resumo VARCHAR(320) DEFAULT NULL,
    conteudo_json LONGTEXT NOT NULL COMMENT 'JSON bruto vindo do editor (Editor.js)',
    conteudo_html LONGTEXT NOT NULL COMMENT 'HTML ja renderizado, pronto pra exibir na pagina publica',
    imagem_capa VARCHAR(300) DEFAULT NULL COMMENT 'caminho da imagem de capa em webp',
    imagem_capa_alt VARCHAR(300) DEFAULT NULL,
    meta_titulo VARCHAR(70) DEFAULT NULL COMMENT 'title da tag <title>, ate 60-70 caracteres',
    meta_descricao VARCHAR(160) DEFAULT NULL COMMENT 'meta description, ate 160 caracteres',
    status ENUM('rascunho', 'publicado') NOT NULL DEFAULT 'rascunho',
    visualizacoes INT UNSIGNED NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    publicado_em DATETIME DEFAULT NULL,
    FOREIGN KEY (autor_id) REFERENCES blog_admin_usuarios(id) ON DELETE RESTRICT,
    INDEX idx_status_publicado (status, publicado_em),
    INDEX idx_slug (slug),
    INDEX idx_categoria (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de categorias (editável pelo painel admin, em /blog/admin/categorias.php)
CREATE TABLE IF NOT EXISTS blog_categorias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(60) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO blog_categorias (nome) VALUES
    ('Residencial'),
    ('Empresarial'),
    ('Dicas'),
    ('Novidades');

-- Tabela de tentativas de login (pra bloquear ataques de forca bruta no admin)
CREATE TABLE IF NOT EXISTS blog_login_tentativas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    email_tentado VARCHAR(150) DEFAULT NULL,
    sucesso TINYINT(1) NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_data (ip, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
