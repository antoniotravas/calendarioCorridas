-- Migração 004: miniatura (imagem/cartaz) de cada prova.
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Se for corrida duas vezes dá o erro "Duplicate column name 'imagem_url'" — é
-- inofensivo, significa que já estava aplicada.

ALTER TABLE corridas
    ADD COLUMN imagem_url VARCHAR(500) NULL AFTER url_fonte,
    ADD COLUMN imagem_procurada_em DATETIME NULL AFTER imagem_url;
