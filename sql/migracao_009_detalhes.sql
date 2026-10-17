-- Migração 009: página individual da prova — hora, programa (provas, horas e
-- escalões), organizador e tabela dos prémios monetários.
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Se for corrida duas vezes dá o erro "Duplicate column name" — é inofensivo,
-- significa que já estava aplicada. As colunas são preenchidas pelo scraper nas
-- execuções seguintes (as provas com prémios monetários voltam a ser lidas uma
-- vez, para tirar a tabela dos valores).

ALTER TABLE corridas
    ADD COLUMN hora_inicio TIME NULL AFTER data_prova,
    ADD COLUMN programa TEXT NULL AFTER hora_inicio,
    ADD COLUMN organizador VARCHAR(255) NULL AFTER url_fonte,
    ADD COLUMN organizador_url VARCHAR(500) NULL AFTER organizador,
    ADD COLUMN detalhes_procurado_em DATETIME NULL AFTER organizador_url,
    ADD COLUMN premios_tabela TEXT NULL AFTER premios_url;
