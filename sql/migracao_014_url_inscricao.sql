-- Migração 014: link direto para a inscrição, dado pela própria fonte
-- (ex. AA Lisboa: a página da prova com o botão "Inscrever", ou o link para a
-- inscrição no site do organizador). Botão "Inscrição" da página da prova.
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Se for corrida duas vezes dá o erro "Duplicate column name" — é inofensivo,
-- significa que já estava aplicada.
--
-- Tem de ser corrida ANTES de o scraper voltar a correr com o código novo
-- (no servidor: antes das 08:00 seguintes ao `git pull`).

ALTER TABLE corridas_fontes
    ADD COLUMN url_inscricao VARCHAR(500) NULL AFTER url_evento;
