-- Migração 008: administradores e acesso aos prémios monetários.
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Se for corrida duas vezes dá o erro "Duplicate column name" — é inofensivo,
-- significa que já estava aplicada.
--
-- Depois, para tornar um utilizador administrador (já tem de ter conta):
--   php scripts/definir_admin.php email@exemplo.pt

ALTER TABLE utilizadores
    ADD COLUMN e_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER ics_token,
    ADD COLUMN ver_premios TINYINT(1) NOT NULL DEFAULT 0 AFTER e_admin;
