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

// "Excluir" um registro clínico é sempre desativação (Ativo=0), nunca
// apagar de vez — prontuário é documento técnico-legal (CFMV) e um registro
// criado errado precisa continuar rastreável, não virar lixo de informação
// nem sumir sem deixar rastro. Mesmo padrão de cliente/animal/equipe.
if ($acao === 'excluir' && $id) {
    try {
        $pdo->prepare('UPDATE RegistrosClinicos SET Ativo = 0 WHERE IDRegistro = :id')->execute([':id' => $id]);
        registrarAuditoria($pdo, 'registro_clinico', $id, 'excluido');

        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        error_log('[ApiClinico] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erro ao excluir registro.']);
    }
    exit;
}

if ($acao === 'reativar' && $id) {
    try {
        $pdo->prepare('UPDATE RegistrosClinicos SET Ativo = 1 WHERE IDRegistro = :id')->execute([':id' => $id]);
        registrarAuditoria($pdo, 'registro_clinico', $id, 'reativado');

        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        error_log('[ApiClinico] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erro ao reativar registro.']);
    }
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Ação inválida.']);
