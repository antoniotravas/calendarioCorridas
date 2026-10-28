-- Migração 018: alterações às provas (data, hora ou local mudam numa fonte) e
-- fila de notificações para quem tem a prova guardada.
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Pode ser corrida mais do que uma vez (IF NOT EXISTS).
--
-- corridas.alterada_em: data da última alteração detetada (o cartão mostra "Alterada"
--   durante alguns dias).
-- corridas_alteracoes: histórico de cada alteração (campo, valor antigo e novo).
-- notificacoes_pendentes: um registo por utilizador que tem a prova guardada. São
--   enviadas pelo script scripts/enviar_alertas.php (enviado_em fica preenchido).

ALTER TABLE corridas ADD COLUMN IF NOT EXISTS alterada_em DATETIME NULL;

CREATE TABLE IF NOT EXISTS corridas_alteracoes (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    corrida_id    INT UNSIGNED NOT NULL,
    campo         VARCHAR(30)  NOT NULL,          -- 'data', 'hora' ou 'local'
    valor_antigo  VARCHAR(255) NULL,
    valor_novo    VARCHAR(255) NULL,
    detetado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_alteracoes_corrida (corrida_id),
    CONSTRAINT fk_alteracoes_corrida FOREIGN KEY (corrida_id)
        REFERENCES corridas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notificacoes_pendentes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilizador_id   INT UNSIGNED NOT NULL,
    corrida_id      INT UNSIGNED NOT NULL,
    mensagem        VARCHAR(500) NOT NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_em      DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_notificacoes_envio (enviado_em),
    CONSTRAINT fk_notificacoes_utilizador FOREIGN KEY (utilizador_id)
        REFERENCES utilizadores (id) ON DELETE CASCADE,
    CONSTRAINT fk_notificacoes_corrida FOREIGN KEY (corrida_id)
        REFERENCES corridas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
