-- Agro Life — Migration 024: valor cobrado e status de pagamento por
-- agendamento — controle de serviço prestado (não é estoque/loja), pra
-- habilitar relatório de faturamento e ticket médio.

ALTER TABLE Agendamentos
    ADD COLUMN Valor           DECIMAL(10,2) NULL AFTER ObservacoesPos,
    ADD COLUMN StatusPagamento ENUM('pendente', 'pago') NULL AFTER Valor;
