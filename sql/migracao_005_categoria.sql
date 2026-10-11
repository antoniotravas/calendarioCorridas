-- Migração 005: tipo de corrida (estrada, trail, corta-mato) para o filtro "Tipo".
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Se for corrida duas vezes dá o erro "Duplicate column name 'categoria'" — é
-- inofensivo, significa que já estava aplicada. A coluna é preenchida pelo
-- scraper (Database::atualizarCategorias) na execução seguinte.

ALTER TABLE corridas
    ADD COLUMN categoria VARCHAR(20) NOT NULL DEFAULT 'estrada' AFTER tipo,
    ADD KEY idx_categoria (categoria);
