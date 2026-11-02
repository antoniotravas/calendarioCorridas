-- Migração 019: país e distrito/província de cada prova, para os filtros "País" e "Distrito".
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
--
-- País: todas as provas já gravadas ficam com 'PT' (é o valor por omissão).
-- Distrito: para as provas portuguesas, preenche-se depois com o script abaixo;
-- as espanholas recebem a província quando o scraper de Espanha correr.
--
-- Antes de correr o scraper das fontes espanholas, esta migração tem de estar aplicada.
-- Depois de a aplicar, para preencher os distritos das provas já gravadas:
--   php scripts/preencher_distritos.php

ALTER TABLE corridas
    ADD COLUMN IF NOT EXISTS pais CHAR(2) NOT NULL DEFAULT 'PT' AFTER regiao,
    ADD COLUMN IF NOT EXISTS distrito VARCHAR(100) NULL AFTER pais;

-- Índices para os filtros. Se algum já existir, este comando dá erro e pode ignorar-se.
ALTER TABLE corridas ADD INDEX idx_pais (pais);
ALTER TABLE corridas ADD INDEX idx_distrito (distrito);
