-- Migração 006: link oficial das provas que algumas fontes só mostram na
-- página de detalhe (ex. All4Running: botão "Visitar o site oficial").
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Usa CREATE TABLE IF NOT EXISTS: correr duas vezes não faz mal.

CREATE TABLE IF NOT EXISTS links_oficiais (
    hash_pagina    CHAR(40) NOT NULL,          -- sha1(url_pagina), para indexar URLs compridos
    url_pagina     VARCHAR(500) NOT NULL,      -- página da prova na fonte
    url_oficial    VARCHAR(500) NULL,          -- site oficial/inscrições; NULL se a página não o tiver
    procurado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (hash_pagina)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
