-- Migração 012: valor das inscrições (página da prova e filtro "Inscrição").
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Se for corrida duas vezes dá o erro "Duplicate column name" — é inofensivo,
-- significa que já estava aplicada.
--
-- Tem de ser corrida ANTES de o scraper voltar a correr com o código novo
-- (no servidor: antes das 08:00 seguintes ao `git pull`).

ALTER TABLE corridas
    ADD COLUMN inscricao_min DECIMAL(6,2) NULL AFTER premios_procurado_em,
    ADD COLUMN inscricao_max DECIMAL(6,2) NULL AFTER inscricao_min,
    ADD COLUMN inscricao_excerto VARCHAR(300) NULL AFTER inscricao_max,
    ADD COLUMN inscricao_url VARCHAR(500) NULL AFTER inscricao_excerto,
    ADD COLUMN inscricao_procurado_em DATETIME NULL AFTER inscricao_url,
    ADD KEY idx_inscricao_min (inscricao_min);
