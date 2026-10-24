-- Migração 015: prémios e inscrição lidos na página errada.
--
-- Algumas provas tinham como site uma página geral do organizador (página
-- inicial ou listagem de notícias, ex. o "regulamento" que a FPA dá para o
-- 1º Corta Mato de Cardielos: cyclonessports.com/index.php?limit=6&start=0).
-- O scraper seguia o regulamento de OUTRA prova que lá estava e gravava os
-- prémios e o preço dessa. O código novo já não lê essas páginas; esta
-- migração apaga o que foi lido nelas, para as provas voltarem a ser vistas
-- na execução seguinte do scraper (o site da prova fica certo nessa execução).
--
-- Correr com a base de dados já selecionada (ex. `mariadb -u UTILIZADOR -p
-- NOME_BD < ficheiro`). Correr outra vez não faz mal.

UPDATE corridas c
SET c.premios_monetarios = NULL, c.premios_excerto = NULL, c.premios_url = NULL,
    c.premios_tabela = NULL, c.premios_procurado_em = NULL,
    c.inscricao_min = NULL, c.inscricao_max = NULL, c.inscricao_excerto = NULL,
    c.inscricao_url = NULL, c.inscricao_procurado_em = NULL
WHERE c.data_prova >= CURDATE()
  AND EXISTS (
      SELECT 1 FROM corridas_fontes cf
      WHERE cf.corrida_id = c.id
        -- Página inicial ou index.php, sem mais nada além de paginação/idioma...
        AND cf.url_evento REGEXP '^https?://[^/?#]+/?(index\\.(php|html?|aspx?))?(\\?((limit|start|page|pagina|lang)=[^&]*&?)*)?$'
        -- ...que seja uma listagem, ou o "regulamento" dado pela FPA.
        AND (cf.url_evento REGEXP '[?&](limit|start|page|pagina)=' OR cf.fonte = 'FPA Competições')
  );
