-- Migração 007: prémios monetários (ícone "€" e filtro "Prémios" do site).
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Se for corrida duas vezes dá o erro "Duplicate column name" — é inofensivo,
-- significa que já estava aplicada. As colunas são preenchidas pelo scraper
-- (procura no regulamento/página de cada prova, algumas dezenas por execução).

ALTER TABLE corridas
    ADD COLUMN premios_monetarios TINYINT(1) NULL AFTER imagem_procurada_em,
    ADD COLUMN premios_excerto VARCHAR(300) NULL AFTER premios_monetarios,
    ADD COLUMN premios_url VARCHAR(500) NULL AFTER premios_excerto,
    ADD COLUMN premios_procurado_em DATETIME NULL AFTER premios_url,
    ADD KEY idx_premios (premios_monetarios);
