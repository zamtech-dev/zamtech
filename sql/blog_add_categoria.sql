-- ============================================================
-- Zamtech Blog - Adiciona categoria aos artigos
-- Rode isso no phpMyAdmin do banco ztclas09_blog (mesmo onde rodou o
-- blog_schema.sql). É seguro rodar mesmo com artigos já existentes —
-- eles só ficam sem categoria até você editar e escolher uma.
-- ============================================================

ALTER TABLE blog_artigos
    ADD COLUMN categoria VARCHAR(60) DEFAULT NULL AFTER slug,
    ADD INDEX idx_categoria (categoria);
