<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('admin');

$entidadeF = in_array($_GET['entidade'] ?? '', ['cliente', 'animal', 'funcionario'], true) ? $_GET['entidade'] : '';
$de        = trim($_GET['de'] ?? '');
$ate       = trim($_GET['ate'] ?? '');
$pag       = max(1, (int) ($_GET['pag'] ?? 1));
$por       = 40;
$off       = ($pag - 1) * $por;

$entidadeLabels = ['cliente' => 'Cliente', 'animal' => 'Animal', 'funcionario' => 'Funcionário'];
$acaoLabels     = ['criado' => 'Criado', 'editado' => 'Editado', 'excluido' => 'Excluído', 'reativado' => 'Reativado'];
$acaoCores      = ['criado' => 'info', 'editado' => 'secondary', 'excluido' => 'danger', 'reativado' => 'success'];

try {
    $where  = 'WHERE 1=1';
    $params = [];
    if ($entidadeF !== '') {
        $where .= ' AND l.Entidade = :entidade';
        $params[':entidade'] = $entidadeF;
    }
    if ($de !== '') {
        $where .= ' AND l.MomentoRegistro >= :de';
        $params[':de'] = $de . ' 00:00:00';
    }
    if ($ate !== '') {
        $where .= ' AND l.MomentoRegistro <= :ate';
        $params[':ate'] = $ate . ' 23:59:59';
    }

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM LogAuditoria l {$where}");
    $cntStmt->execute($params);
    $total = (int) $cntStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT l.*, u.Nome AS NomeUsuario
         FROM LogAuditoria l
         LEFT JOIN Usuarios u ON u.IDUsuario = l.FKUsuario
         {$where}
         ORDER BY l.MomentoRegistro DESC
         LIMIT :lim OFFSET :off"
    );
    $params[':lim'] = $por;
    $params[':off'] = $off;
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, in_array($k, [':lim', ':off']) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $logs = $stmt->fetchAll();

    // Nome de quem/o-que sofreu a ação — não dá pra usar FKEntidade direto
    // porque ela aponta pra tabelas diferentes dependendo de Entidade
    // (Usuarios pra cliente/funcionario, Animais pra animal).
    $idsClientesFunc = array_column(array_filter($logs, fn($l) => in_array($l['Entidade'], ['cliente', 'funcionario'], true)), 'FKEntidade');
    $idsAnimais      = array_column(array_filter($logs, fn($l) => $l['Entidade'] === 'animal'), 'FKEntidade');
    $nomesPorId = [];
    if ($idsClientesFunc) {
        $ph = implode(',', array_fill(0, count($idsClientesFunc), '?'));
        $r = $pdo->prepare("SELECT IDUsuario, Nome FROM Usuarios WHERE IDUsuario IN ({$ph})");
        $r->execute(array_values($idsClientesFunc));
        foreach ($r->fetchAll() as $row) { $nomesPorId[$row['IDUsuario']] = $row['Nome']; }
    }
    if ($idsAnimais) {
        $ph = implode(',', array_fill(0, count($idsAnimais), '?'));
        $r = $pdo->prepare("SELECT IDAnimal, Nome FROM Animais WHERE IDAnimal IN ({$ph})");
        $r->execute(array_values($idsAnimais));
        foreach ($r->fetchAll() as $row) { $nomesPorId[$row['IDAnimal']] = $row['Nome']; }
    }
} catch (PDOException $e) {
    error_log('[Auditoria] ' . $e->getMessage());
    $logs = [];
    $total = 0;
    $nomesPorId = [];
}

$totalPag = max(1, (int) ceil($total / $por));

$paginaTitulo = 'Auditoria';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<h4 class="fw-bold mb-4"><i class="bi bi-clock-history me-2 text-accent"></i>Auditoria <span class="text-secondary small">(<?= number_format($total) ?>)</span></h4>
<p class="text-secondary small mb-4">
    <i class="bi bi-info-circle me-1"></i>
    Quem criou, editou, excluiu ou reativou cada cliente, animal ou membro da equipe — e quando.
</p>

<form class="row g-2 mb-4" method="GET">
    <div class="col-sm-3">
        <select name="entidade" class="form-select" onchange="this.form.submit()">
            <option value="">Todos os tipos</option>
            <?php foreach ($entidadeLabels as $valor => $label): ?>
                <option value="<?= h($valor) ?>" <?= $entidadeF === $valor ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="col-sm-3">
        <input type="date" name="de" class="form-control" value="<?= h($de) ?>" placeholder="De">
    </div>
    <div class="col-sm-3">
        <input type="date" name="ate" class="form-control" value="<?= h($ate) ?>" placeholder="Até">
    </div>
    <div class="col-sm-3 d-grid">
        <button class="btn btn-accent" type="submit">Filtrar</button>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="text-center py-5 text-secondary">
                <i class="bi bi-clock-history fs-1 d-block mb-2 opacity-25"></i>
                <p class="mb-0">Nenhum registro de auditoria pra esses filtros.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead style="background:var(--bg-hover);">
                        <tr>
                            <th class="px-4 py-3">Quando</th>
                            <th>Quem fez</th>
                            <th>Tipo</th>
                            <th>Ação</th>
                            <th>Alvo</th>
                            <th class="d-none d-md-table-cell">Detalhes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                            <?php
                                $nomeAlvo = $nomesPorId[$l['FKEntidade']] ?? null;
                                $linkAlvo = match ($l['Entidade']) {
                                    'cliente'     => BASE . '/painel/cliente_detalhe.php?id=' . $l['FKEntidade'],
                                    'animal'      => BASE . '/painel/animal_detalhe.php?id=' . $l['FKEntidade'],
                                    'funcionario' => BASE . '/painel/equipe.php',
                                    default       => null,
                                };
                            ?>
                            <tr>
                                <td class="px-4 small text-secondary text-nowrap"><?= formatarDataHora($l['MomentoRegistro']) ?></td>
                                <td class="small"><?= $l['NomeUsuario'] ? h($l['NomeUsuario']) : '<span class="text-secondary">Sistema</span>' ?></td>
                                <td class="small"><?= h($entidadeLabels[$l['Entidade']] ?? $l['Entidade']) ?></td>
                                <td><span class="badge bg-<?= $acaoCores[$l['Acao']] ?? 'secondary' ?>"><?= h($acaoLabels[$l['Acao']] ?? $l['Acao']) ?></span></td>
                                <td class="small">
                                    <?php if ($nomeAlvo && $linkAlvo): ?>
                                        <a href="<?= h($linkAlvo) ?>"><?= h($nomeAlvo) ?></a>
                                    <?php elseif ($nomeAlvo): ?>
                                        <?= h($nomeAlvo) ?>
                                    <?php else: ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif ?>
                                </td>
                                <td class="d-none d-md-table-cell small text-secondary"><?= $l['Detalhes'] ? h($l['Detalhes']) : '—' ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPag > 1): ?>
                <div class="d-flex justify-content-center py-4">
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($p = 1; $p <= $totalPag; $p++): ?>
                                <li class="page-item <?= $p === $pag ? 'active' : '' ?>">
                                    <a class="page-link" href="?pag=<?= $p ?>&entidade=<?= urlencode($entidadeF) ?>&de=<?= urlencode($de) ?>&ate=<?= urlencode($ate) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor ?>
                        </ul>
                    </nav>
                </div>
            <?php endif ?>
        <?php endif ?>
    </div>
</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
