-- Migração 010: voltar a ler os valores dos prémios monetários.
--
-- A leitura das tabelas de prémios passou a perceber mais formatos ("1.º €100",
-- "350 € para o 1.º", "respetivamente, 50 €, 25 € e 15 €", tabelas com uma
-- coluna por escalão...) e o leitor de PDF passou a ler bem as tabelas feitas
-- no Word (antes perdia letras e números). As provas com prémios voltam assim
-- a ser lidas uma vez pelo scraper, na execução seguinte.
--
-- Correr com a base de dados já selecionada (ex. `mariadb -u UTILIZADOR -p
-- NOME_BD < ficheiro`). Correr outra vez não faz mal.

UPDATE corridas SET premios_tabela = NULL WHERE premios_monetarios = 1;
