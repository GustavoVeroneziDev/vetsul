<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('admin', 'funcionario');

// Período padrão: últimos 30 dias — cobre "esse mês" e "mês passado" na
// prática sem a pessoa precisar mexer no filtro na primeira visita.
$de  = trim($_GET['de']  ?? '') ?: date('Y-m-d', strtotime('-30 days'));
$ate = trim($_GET['ate'] ?? '') ?: date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de))  { $de  = date('Y-m-d', strtotime('-30 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) { $ate = date('Y-m-d'); }
$deInicio = $de . ' 00:00:00';
$ateFim   = $ate . ' 23:59:59';

$tiposAgenda = tiposAgendaMap();

try {
    // Visão geral do período — contagens por status, faturamento e a receber.
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS Total,
            SUM(Status = 'concluido') AS Concluidos,
            SUM(Status = 'faltou') AS Faltas,
            SUM(Status = 'cancelado') AS Cancelados,
            SUM(CASE WHEN StatusPagamento = 'pago' THEN Valor ELSE 0 END) AS Faturado,
            SUM(CASE WHEN StatusPagamento = 'pendente' THEN Valor ELSE 0 END) AS AReceber
         FROM Agendamentos
         WHERE DataHoraInicio BETWEEN :de AND :ate"
    );
    $stmt->execute([':de' => $deInicio, ':ate' => $ateFim]);
    $resumo = $stmt->fetch();

    // Taxa de falta só faz sentido sobre quem já devia ter acontecido
    // (concluído, faltou) — pendente/confirmado ainda não teve chance de
    // faltar, e cancelado é uma decisão separada de "não apareceu".
    $baseFalta = (int) $resumo['Concluidos'] + (int) $resumo['Faltas'];
    $taxaFalta = $baseFalta > 0 ? round((int) $resumo['Faltas'] / $baseFalta * 100, 1) : null;

    // Por veterinário — total atendido e taxa de falta individual, pra ver
    // se a falta é um problema geral ou concentrado.
    $porVet = $pdo->prepare(
        "SELECT COALESCE(v.Nome, 'Sem veterinário definido') AS NomeVeterinario,
                COUNT(*) AS Total,
                SUM(ag.Status = 'concluido') AS Concluidos,
                SUM(ag.Status = 'faltou') AS Faltas
         FROM Agendamentos ag
         LEFT JOIN Usuarios v ON v.IDUsuario = ag.FKVeterinario
         WHERE ag.DataHoraInicio BETWEEN :de AND :ate
           AND ag.Status IN ('concluido', 'faltou')
         GROUP BY COALESCE(v.Nome, 'Sem veterinário definido')
         ORDER BY Total DESC"
    );
    $porVet->execute([':de' => $deInicio, ':ate' => $ateFim]);
    $porVet = $porVet->fetchAll();

    // Procedimentos mais comuns — pelo Tipo (categoria), não pelo Título
    // livre, senão "Consulta de rotina" e "consulta de rotina" viravam
    // linhas separadas.
    $porTipo = $pdo->prepare(
        "SELECT Tipo, COUNT(*) AS Total
         FROM Agendamentos
         WHERE DataHoraInicio BETWEEN :de AND :ate AND Status = 'concluido'
         GROUP BY Tipo
         ORDER BY Total DESC"
    );
    $porTipo->execute([':de' => $deInicio, ':ate' => $ateFim]);
    $porTipo = $porTipo->fetchAll();
    $maxTipo = $porTipo ? (int) $porTipo[0]['Total'] : 0;

    // Vacinas realmente aplicadas no período (não "planejadas") — conta pela
    // DataAplicacao de verdade, não pela ProximaData.
    $porVacina = $pdo->prepare(
        "SELECT tv.Nome, COUNT(*) AS Total
         FROM RegistrosVacinas rv
         JOIN TiposVacina tv ON tv.IDTipo = rv.FKTipoVacina
         WHERE rv.DataAplicacao BETWEEN :de AND :ate
         GROUP BY tv.Nome
         ORDER BY Total DESC"
    );
    $porVacina->execute([':de' => $de, ':ate' => $ate]);
    $porVacina = $porVacina->fetchAll();
    $totalVacinas = array_sum(array_column($porVacina, 'Total'));
} catch (PDOException $e) {
    error_log('[Relatorios] ' . $e->getMessage());
    $resumo = ['Total' => 0, 'Concluidos' => 0, 'Faltas' => 0, 'Cancelados' => 0, 'Faturado' => 0, 'AReceber' => 0];
    $taxaFalta = null;
    $porVet = [];
    $porTipo = [];
    $maxTipo = 0;
    $porVacina = [];
    $totalVacinas = 0;
}

$paginaTitulo = 'Relatórios';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<h4 class="fw-bold mb-4"><i class="bi bi-bar-chart-line me-2 text-accent"></i>Relatórios</h4>

<form class="row g-2 mb-4" method="GET">
    <div class="col-sm-4 col-md-3">
        <label class="form-label small mb-1">De</label>
        <input type="date" name="de" class="form-control" value="<?= h($de) ?>" max="<?= date('Y-m-d') ?>">
    </div>
    <div class="col-sm-4 col-md-3">
        <label class="form-label small mb-1">Até</label>
        <input type="date" name="ate" class="form-control" value="<?= h($ate) ?>" max="<?= date('Y-m-d') ?>">
    </div>
    <div class="col-sm-4 col-md-2 d-flex align-items-end">
        <button class="btn btn-accent w-100" type="submit">Filtrar</button>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card p-3 h-100">
            <div class="small text-secondary">Atendimentos concluídos</div>
            <div class="fs-3 fw-bold"><?= number_format((int) $resumo['Concluidos']) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 h-100">
            <div class="small text-secondary">Taxa de falta</div>
            <div class="fs-3 fw-bold"><?= $taxaFalta !== null ? $taxaFalta . '%' : '—' ?></div>
            <?php if ($taxaFalta !== null): ?>
                <div class="small text-secondary"><?= (int) $resumo['Faltas'] ?> de <?= $baseFalta ?></div>
            <?php endif ?>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 h-100">
            <div class="small text-secondary">Faturado (pago)</div>
            <div class="fs-3 fw-bold text-success">R$ <?= number_format((float) $resumo['Faturado'], 2, ',', '.') ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 h-100">
            <div class="small text-secondary">A receber</div>
            <div class="fs-3 fw-bold text-warning">R$ <?= number_format((float) $resumo['AReceber'], 2, ',', '.') ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card p-4 mb-4">
            <h6 class="fw-semibold mb-3"><i class="bi bi-people me-2 text-accent"></i>Por veterinário</h6>
            <?php if (empty($porVet)): ?>
                <p class="text-secondary small mb-0">Nenhum atendimento concluído ou faltado nesse período.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Veterinário</th><th class="text-end">Atendidos</th><th class="text-end">Faltas</th></tr></thead>
                        <tbody>
                            <?php foreach ($porVet as $v): ?>
                                <tr>
                                    <td><?= h($v['NomeVeterinario']) ?></td>
                                    <td class="text-end"><?= (int) $v['Concluidos'] ?></td>
                                    <td class="text-end"><?= (int) $v['Faltas'] ?></td>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </div>

        <div class="card p-4 mb-4">
            <h6 class="fw-semibold mb-3"><i class="bi bi-shield-plus me-2 text-accent"></i>Vacinas aplicadas</h6>
            <?php if (empty($porVacina)): ?>
                <p class="text-secondary small mb-0">Nenhuma vacina aplicada nesse período.</p>
            <?php else: ?>
                <?php foreach ($porVacina as $v): ?>
                    <div class="d-flex justify-content-between small mb-1">
                        <span><?= h($v['Nome']) ?></span>
                        <span class="fw-medium"><?= (int) $v['Total'] ?></span>
                    </div>
                <?php endforeach ?>
                <div class="text-secondary small mt-2 pt-2 border-top">Total: <?= $totalVacinas ?></div>
            <?php endif ?>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-4 mb-4">
            <h6 class="fw-semibold mb-3"><i class="bi bi-clipboard2-pulse me-2 text-accent"></i>Procedimentos mais comuns</h6>
            <?php if (empty($porTipo)): ?>
                <p class="text-secondary small mb-0">Nenhum atendimento concluído nesse período.</p>
            <?php else: ?>
                <?php foreach ($porTipo as $t): ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?= h($tiposAgenda[$t['Tipo']] ?? $t['Tipo']) ?></span>
                            <span class="fw-medium"><?= (int) $t['Total'] ?></span>
                        </div>
                        <div class="progress" style="height:6px;">
                            <div class="progress-bar" style="width:<?= $maxTipo > 0 ? round((int) $t['Total'] / $maxTipo * 100) : 0 ?>%;background:var(--accent);"></div>
                        </div>
                    </div>
                <?php endforeach ?>
            <?php endif ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
