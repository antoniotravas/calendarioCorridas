-- Migração 013: coordenadas das provas (botão "Perto de mim").
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Se for corrida duas vezes dá o erro "Duplicate column name" — é inofensivo,
-- significa que já estava aplicada.
--
-- Tem de ser corrida ANTES de o scraper voltar a correr com o código novo
-- (no servidor: antes das 08:00 seguintes ao `git pull`).

ALTER TABLE corridas
    ADD COLUMN latitude DECIMAL(9,6) NULL AFTER inscricao_procurado_em,
    ADD COLUMN longitude DECIMAL(9,6) NULL AFTER latitude,
    ADD COLUMN coordenadas_procuradas_em DATETIME NULL AFTER longitude;
