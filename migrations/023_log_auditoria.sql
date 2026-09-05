-- Agro Life — Migration 023: trilha de auditoria genérica pra cliente,
-- animal e equipe — hoje só a Agenda tem histórico de quem fez o quê
-- (EventosAgendamento); editar/excluir/reativar um cliente, animal ou
-- membro da equipe não deixava rastro nenhum.

CREATE TABLE IF NOT EXISTS LogAuditoria (
    IDLog           VARCHAR(36)  NOT NULL,
    FKUsuario       VARCHAR(36)  NULL,
    Entidade        ENUM('cliente', 'animal', 'funcionario') NOT NULL,
    FKEntidade      VARCHAR(36)  NOT NULL,
    Acao            ENUM('criado', 'editado', 'excluido', 'reativado') NOT NULL,
    Detalhes        VARCHAR(255) NULL,
    MomentoRegistro TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (IDLog),
    CONSTRAINT fk_auditoria_usuario FOREIGN KEY (FKUsuario) REFERENCES Usuarios(IDUsuario) ON DELETE SET NULL,
    INDEX idx_auditoria_entidade (Entidade, FKEntidade),
    INDEX idx_auditoria_momento (MomentoRegistro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
