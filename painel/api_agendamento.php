<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['nivel_acesso'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Acesso não permitido.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método não permitido.']);
    exit;
}

$dados = json_decode(file_get_contents('php://input'), true) ?? [];

if (!validarTokenCSRF($dados['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'Token inválido.']);
    exit;
}

$acao = $dados['acao'] ?? '';
$id   = trim($dados['id'] ?? '');

// Alterna pago/pendente depois do agendamento já concluído — o pagamento
// às vezes só acontece depois do atendimento em si.
if ($acao === 'alternar_pagamento' && $id) {
    try {
        $stmt = $pdo->prepare('SELECT StatusPagamento FROM Agendamentos WHERE IDAgendamento = :id AND Valor IS NOT NULL LIMIT 1');
        $stmt->execute([':id' => $id]);
        $statusAtual = $stmt->fetchColumn();
        if ($statusAtual === false) {
            echo json_encode(['ok' => false, 'msg' => 'Esse agendamento não tem valor cobrado registrado.']);
            exit;
        }
        $novoStatus = $statusAtual === 'pago' ? 'pendente' : 'pago';
        $pdo->prepare('UPDATE Agendamentos SET StatusPagamento = :s WHERE IDAgendamento = :id')
            ->execute([':s' => $novoStatus, ':id' => $id]);
        echo json_encode(['ok' => true, 'status' => $novoStatus]);
    } catch (PDOException $e) {
        error_log('[ApiAgendamento] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erro ao atualizar pagamento.']);
    }
    exit;
}

$transicoes = [
    'confirmar'    => ['de' => ['pendente'],                         'para' => 'confirmado'],
    'cancelar'     => ['de' => ['pendente', 'confirmado'],           'para' => 'cancelado'],
    'marcar_falta' => ['de' => ['confirmado'],                       'para' => 'faltou'],
    'reabrir'      => ['de' => ['concluido', 'cancelado', 'faltou'], 'para' => 'confirmado'],
];
// Nome do evento no histórico de movimentações — "reabrir" também vira
// Status "confirmado", mas o evento precisa ficar distinto de um
// "confirmar" de verdade.
$eventoTipos = [
    'confirmar'    => 'confirmado',
    'cancelar'     => 'cancelado',
    'marcar_falta' => 'faltou',
    'reabrir'      => 'reaberto',
];

if (!$id || !isset($transicoes[$acao])) {
    echo json_encode(['ok' => false, 'msg' => 'Ação inválida.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT ag.Status, ag.Tipo, ag.Titulo, ag.DataHoraInicio, a.Nome AS NomeAnimal, u.Telefone
         FROM Agendamentos ag
         JOIN Animais a  ON a.IDAnimal = ag.FKAnimal
         JOIN Usuarios u ON u.IDUsuario = a.FKDono
         WHERE ag.IDAgendamento = :id LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $ag = $stmt->fetch();

    if (!$ag) {
        echo json_encode(['ok' => false, 'msg' => 'Agendamento não encontrado.']);
        exit;
    }
    if (!in_array($ag['Status'], $transicoes[$acao]['de'], true)) {
        echo json_encode(['ok' => false, 'msg' => 'Esse agendamento não está num estado que permite essa ação.']);
        exit;
    }

    $pdo->prepare('UPDATE Agendamentos SET Status = :status WHERE IDAgendamento = :id')
        ->execute([':status' => $transicoes[$acao]['para'], ':id' => $id]);
    registrarEventoAgendamento($pdo, $id, $eventoTipos[$acao]);

    // Avisa o cliente quando a clínica cancela — as outras transições
    // (confirmar, marcar_falta, reabrir) ficam sem notificação automática
    // por ora, são ajustes mais internos.
    if ($acao === 'cancelar' && $ag['Telefone']) {
        $msg = montarMensagemCancelamento($pdo, $ag['NomeAnimal'], $ag['Tipo'], $ag['Titulo'], $ag['DataHoraInicio']);
        enviarWhatsApp(waNumero($ag['Telefone']), $msg);
    }

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    error_log('[ApiAgendamento] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Erro ao atualizar agendamento.']);
}
