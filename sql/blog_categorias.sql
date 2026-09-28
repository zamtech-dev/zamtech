-- ============================================================
-- Zamtech Blog - Categorias como tabela (antes era uma lista fixa
-- dentro do _config.php; agora dá pra criar/excluir pelo painel admin
-- sem precisar mexer em código nem esperar deploy).
-- Rode isso uma vez no phpMyAdmin do banco ztclas09_blog.
-- ============================================================

CREATE TABLE IF NOT EXISTS blog_categorias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(60) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migra as 4 categorias que já existiam fixas no código. INSERT IGNORE
-- pra não dar erro se você rodar isso de novo por engano.
INSERT IGNORE INTO blog_categorias (nome) VALUES
    ('Residencial'),
    ('Empresarial'),
    ('Dicas'),
    ('Novidades');
