-- Migração 010: voltar a ler os valores dos prémios monetários.
--
-- A leitura das tabelas de prémios passou a perceber mais formatos ("1.º €100",
-- "350 € para o 1.º", "respetivamente, 50 €, 25 € e 15 €"...). As provas com
-- prémios em que os valores não tinham sido lidos voltam assim a ser vistas
-- uma vez pelo scraper, na execução seguinte.
--
-- Correr UMA vez, com a base de dados já selecionada (ex. `mariadb -u
-- UTILIZADOR -p NOME_BD < ficheiro`). Correr outra vez não faz mal.

UPDATE corridas SET premios_tabela = NULL WHERE premios_monetarios = 1 AND premios_tabela = '[]';
