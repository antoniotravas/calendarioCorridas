-- Migração 011: desativar utilizadores (página "Gestão").
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Se for corrida duas vezes dá o erro "Duplicate column name" — é inofensivo,
-- significa que já estava aplicada.
--
-- Pode ser corrida antes do `git pull`: todos os utilizadores ficam ativos.

ALTER TABLE utilizadores
    ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1 AFTER ver_premios;
