-- Agro Life — Migration 026: CRMV do veterinário
--
-- Identificação do veterinário responsável (nome + CRMV) é exigência do
-- CFMV pra prontuário/prescrição — faltava um lugar pra guardar isso.

ALTER TABLE Usuarios
    ADD COLUMN CRMV VARCHAR(20) NULL AFTER Cargo;
