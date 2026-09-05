-- Agro Life — Migration 025: exclusão de registro clínico vira desativação
--
-- Prontuário é documento técnico-legal (CFMV) — excluir não pode apagar o
-- registro de vez, só tirar ele das listas normais, igual já acontece com
-- clientes/animais/equipe.

ALTER TABLE RegistrosClinicos
    ADD COLUMN Ativo TINYINT(1) NOT NULL DEFAULT 1 AFTER DataRegistro;

-- LogAuditoria precisa reconhecer esse tipo de entidade pra registrar
-- quem excluiu/reativou um registro clínico (ver painel/api_clinico.php).
ALTER TABLE LogAuditoria
    MODIFY COLUMN Entidade ENUM('cliente', 'animal', 'funcionario', 'registro_clinico') NOT NULL;
