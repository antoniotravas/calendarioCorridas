-- Migração 016: acesso ao ranking dos prémios (página "Gestão").
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Se for corrida duas vezes dá o erro "Duplicate column name" — é inofensivo,
-- significa que já estava aplicada.
--
-- Pode ser corrida antes do `git pull` (ninguém fica com o ranking); sem ela,
-- depois do pull, a página Gestão dá erro.

ALTER TABLE utilizadores
    ADD COLUMN ver_ranking TINYINT(1) NOT NULL DEFAULT 0 AFTER ver_premios;
