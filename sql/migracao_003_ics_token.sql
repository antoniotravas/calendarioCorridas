-- Migração 003: link secreto de subscrição do "meu calendário" (.ics).
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Se for corrida duas vezes dá o erro "Duplicate column name 'ics_token'" — é
-- inofensivo, significa que já estava aplicada.

ALTER TABLE utilizadores
    ADD COLUMN ics_token CHAR(32) NULL AFTER google_id,
    ADD UNIQUE KEY uq_ics_token (ics_token);
