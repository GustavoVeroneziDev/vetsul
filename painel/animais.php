<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('admin', 'funcionario');

// Cadastro de animal via POST (usado tanto pelo CTA de estado vazio quanto pelo botão do topo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'novo_animal') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/animais.php', 'Token inválido.', 'danger');
    }
    exigirAdmin(BASE . '/painel/animais.php');
    $fkDono  = trim($_POST['dono']       ?? '');
    $nome    = trim($_POST['nome']       ?? '');
    $especie = trim($_POST['especie']    ?? '');
    $raca    = trim($_POST['raca']       ?? '');
    $nasc    = trim($_POST['nascimento'] ?? '');
    $sexo    = trim($_POST['sexo']       ?? '');
    $cor     = trim($_POST['cor']        ?? '');
    $peso    = trim($_POST['peso']       ?? '');
    $obs     = trim($_POST['observacoes'] ?? '');

    if ($fkDono === '' || $nome === '' || $especie === '' || $raca === '' || $sexo === '') {
        redirecionarComMensagem(BASE . '/painel/animais.php', 'Cliente, nome, espécie, raça e sexo são obrigatórios.', 'warning');
    }
    if (!dataNascimentoValida($nasc)) {
        redirecionarComMensagem(BASE . '/painel/animais.php', 'Data de nascimento inválida — não pode ser no futuro nem passar de 100 anos atrás.', 'warning');
    }

    $foto = !empty($_FILES['foto']['tmp_name']) ? salvarImagemEnviada($_FILES['foto'], 'animais') : null;
    if (!empty($_FILES['foto']['tmp_name']) && $foto === null) {
        redirecionarComMensagem(BASE . '/painel/animais.php', 'Foto inválida — envie um JPG, PNG ou WEBP de até 5 MB.', 'warning');
    }

    try {
        $novoId = gerarUuid();
        $pdo->prepare(
            'INSERT INTO Animais (IDAnimal, FKDono, FKEspecie, Nome, Raca, DataNascimento, Sexo, Pelagem, PesoKg, Observacoes, FotoUrl)
             VALUES (:id, :dono, :esp, :nome, :raca, :nasc, :sexo, :cor, :peso, :obs, :foto)'
        )->execute([
            ':id'   => $novoId,
            ':dono' => $fkDono,
            ':esp'  => $especie,
            ':nome' => $nome,
            ':raca' => $raca,
            ':nasc' => $nasc ?: null,
            ':sexo' => $sexo,
            ':cor'  => $cor ?: null,
            ':peso' => $peso !== '' ? $peso : null,
            ':obs'  => $obs ?: null,
            ':foto' => $foto,
        ]);
        registrarAuditoria($pdo, 'animal', $novoId, 'criado', $nome);
        redirecionarComMensagem(BASE . '/painel/animal_detalhe.php?id=' . $novoId, 'Animal cadastrado com sucesso!', 'success');
    } catch (PDOException $e) {
        error_log('[NovoAnimal] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/painel/animais.php', 'Erro ao cadastrar animal.', 'danger');
    }
}

$busca    = trim($_GET['q'] ?? '');
$especieF = trim($_GET['especie'] ?? '');
$statusF  = in_array($_GET['status'] ?? '', ['ativos', 'inativos', 'todos'], true) ? $_GET['status'] : 'ativos';
$pag      = max(1, (int) ($_GET['pag'] ?? 1));
$por      = 24;
$off      = ($pag - 1) * $por;

$statusLabels = ['ativos' => 'Ativos', 'inativos' => 'Excluídos', 'todos' => 'Todos'];

try {
    $where  = match ($statusF) {
        'inativos' => 'WHERE a.Ativo = 0',
        'todos'    => 'WHERE 1=1',
        default    => 'WHERE a.Ativo = 1',
    };
    $params = [];
    if ($busca !== '') {
        // Prepare nativo (EMULATE_PREPARES=false) não aceita o mesmo
        // placeholder nomeado repetido — precisa de um por ocorrência,
        // mesmo repetindo o valor.
        $where .= ' AND (a.Nome LIKE :q1 OR u.Nome LIKE :q2 OR a.Raca LIKE :q3)';
        $curinga = '%' . $busca . '%';
        $params[':q1'] = $curinga;
        $params[':q2'] = $curinga;
        $params[':q3'] = $curinga;
    }
    if ($especieF !== '') {
        $where .= ' AND a.FKEspecie = :esp';
        $params[':esp'] = $especieF;
    }

    $cntStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM Animais a JOIN Usuarios u ON u.IDUsuario = a.FKDono {$where}"
    );
    $cntStmt->execute($params);
    $total = (int) $cntStmt->fetchColumn();

    // Total sem filtro — distingue "nenhum animal no sistema" de "busca sem resultado"
    $totalGeral = (int) $pdo->query('SELECT COUNT(*) FROM Animais WHERE Ativo = 1')->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT a.*, e.Nome AS NomeEspecie, e.Icone AS IconeEspecie, u.Nome AS NomeDono,
                (SELECT MIN(rv.ProximaData) FROM RegistrosVacinas rv
                  WHERE rv.FKAnimal = a.IDAnimal AND rv.ProximaData IS NOT NULL) AS ProximaVacina,
                (SELECT MAX(rc.DataRegistro) FROM RegistrosClinicos rc
                  WHERE rc.FKAnimal = a.IDAnimal) AS UltimoClinico
         FROM Animais a
         JOIN Especies e  ON e.IDEspecie = a.FKEspecie
         JOIN Usuarios u  ON u.IDUsuario = a.FKDono
         {$where}
         ORDER BY a.Nome ASC
         LIMIT :lim OFFSET :off"
    );
    $params[':lim'] = $por;
    $params[':off'] = $off;
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, in_array($k, [':lim', ':off']) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $animais = $stmt->fetchAll();

    $especies = $pdo->query('SELECT * FROM Especies ORDER BY Ordem ASC')->fetchAll();
    $racas    = $pdo->query('SELECT IDRaca, FKEspecie, Nome FROM Racas ORDER BY Ordem ASC')->fetchAll();
    // Inclui admin também — a equipe da clínica pode ter animal próprio
    // cadastrado no sistema, não só os clientes.
    $donos    = $pdo->query(
        "SELECT IDUsuario, Nome, Email, NivelAcesso FROM Usuarios WHERE Ativo = 1 ORDER BY Nome ASC"
    )->fetchAll();
} catch (PDOException $e) {
    error_log('[Animais] ' . $e->getMessage());
    $animais    = [];
    $especies   = [];
    $racas      = [];
    $donos      = [];
    $total      = 0;
    $totalGeral = 0;
}

$totalPag = max(1, (int) ceil($total / $por));

$paginaTitulo = 'Animais';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<?php $souAdmin = ($_SESSION['nivel_acesso'] ?? '') === 'admin'; ?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <h4 class="fw-bold mb-0">Animais <span class="text-secondary small">(<?= number_format($total) ?>)</span></h4>
    <?php if ($souAdmin): ?>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($totalGeral > 0): ?>
                <button class="btn btn-outline-accent btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoAnimal">
                    <i class="bi bi-plus-lg me-1"></i> Novo animal
                </button>
            <?php endif ?>
            <a href="<?= BASE ?>/painel/registrar_vacina.php" class="btn btn-outline-accent btn-sm">
                <i class="bi bi-shield-plus me-1"></i> Aplicar vacina
            </a>
            <a href="<?= BASE ?>/painel/registrar_clinico.php" class="btn btn-outline-accent btn-sm">
                <i class="bi bi-journal-medical me-1"></i> Registrar clínico
            </a>
        </div>
    <?php endif ?>
</div>

<form class="row g-2 mb-4" method="GET" id="formAnimais">
    <div class="col-sm-5">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" name="q" class="form-control" placeholder="Buscar por nome do animal, cliente ou raça..."
                value="<?= h($busca) ?>">
        </div>
    </div>
    <div class="col-sm-3">
        <?php
            $especieFNome = 'Todas as espécies';
            foreach ($especies as $e) {
                if ($e['IDEspecie'] === $especieF) { $especieFNome = $e['Nome']; break; }
            }
        ?>
        <?= campoPicker('animaisEspecie', 'especie', 'Todas as espécies', '', $especieF, $especieFNome, obrigatorio: false, comBusca: false) ?>
    </div>
    <div class="col-sm-2">
        <select name="status" class="form-select" onchange="this.form.submit()">
            <?php foreach ($statusLabels as $valor => $label): ?>
                <option value="<?= h($valor) ?>" <?= $statusF === $valor ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="col-sm-2 d-grid">
        <button class="btn btn-accent" type="submit">Buscar</button>
    </div>
</form>

<?php if (empty($animais) && $totalGeral === 0): ?>
    <div class="card text-center py-5 text-secondary">
        <i class="bi bi-emoji-smile fs-1 d-block mb-2 opacity-25"></i>
        <p class="mb-3">Nenhum animal cadastrado ainda.</p>
        <?php if ($souAdmin): ?>
            <div>
                <button class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#modalNovoAnimal">
                    <i class="bi bi-plus-lg me-1"></i> Registrar primeiro animal
                </button>
            </div>
        <?php endif ?>
    </div>
<?php elseif (empty($animais)): ?>
    <div class="card text-center py-5 text-secondary">
        <i class="bi bi-search fs-1 d-block mb-2 opacity-25"></i>
        <p class="mb-0">Nenhum animal encontrado para essa busca.</p>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($animais as $a): ?>
            <div class="col-sm-6 col-lg-4 col-xl-3">
                <a href="<?= BASE ?>/painel/animal_detalhe.php?id=<?= h($a['IDAnimal']) ?>" class="text-decoration-none">
                    <div class="card p-3 h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <?= especieIconeHtml($a['IconeEspecie'], '1.6rem') ?>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-bold text-truncate" style="color:var(--text-main);"><?= h($a['Nome']) ?></div>
                                <div class="small text-secondary text-truncate"><?= h($a['NomeDono']) ?></div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            <?php if (!$a['Ativo']): ?>
                                <span class="badge bg-secondary">Excluído</span>
                            <?php endif ?>
                            <?php if ($a['ProximaVacina']): [$labelVac, $corVac] = situacaoVacina($a['ProximaVacina']); ?>
                                <span class="badge bg-<?= $corVac ?>">
                                    <i class="bi bi-shield-plus me-1"></i><?= h($labelVac) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><i class="bi bi-shield-plus me-1"></i>Sem vacina</span>
                            <?php endif ?>
                            <?php if ($a['UltimoClinico']): ?>
                                <span class="badge" style="background:var(--accent-light);color:var(--accent);">
                                    <i class="bi bi-journal-medical me-1"></i><?= formatarData($a['UltimoClinico']) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><i class="bi bi-journal-medical me-1"></i>Sem clínico</span>
                            <?php endif ?>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach ?>
    </div>

    <?php if ($totalPag > 1): ?>
        <div class="d-flex justify-content-center py-4">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $totalPag; $p++): ?>
                        <li class="page-item <?= $p === $pag ? 'active' : '' ?>">
                            <a class="page-link" href="?pag=<?= $p ?>&q=<?= urlencode($busca) ?>&especie=<?= urlencode($especieF) ?>&status=<?= urlencode($statusF) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor ?>
                </ul>
            </nav>
        </div>
    <?php endif ?>
<?php endif ?>

<div class="modal fade" id="modalNovoAnimal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="novo_animal">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">Cadastrar animal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Foto</label>
                        <input type="file" name="foto" class="form-control" accept="image/png,image/jpeg,image/webp" capture="environment">
                        <div class="form-text">JPG, PNG ou WEBP — até 5 MB. No celular, dá pra tirar a foto na hora.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cliente *</label>
                        <input type="hidden" name="dono" id="inpDonoId" required>
                        <div class="picker" id="donoPicker">
                            <div class="picker-trigger" id="donoTrigger" tabindex="0">
                                <span id="donoLabel" class="picker-placeholder">Buscar cliente por nome ou e-mail…</span>
                                <span class="picker-caret"><i class="bi bi-chevron-down"></i></span>
                            </div>
                            <div class="picker-dropdown d-none" id="donoDropdown">
                                <div class="picker-search-wrap">
                                    <i class="bi bi-search picker-search-icon"></i>
                                    <input type="text" class="picker-search" id="donoSearch" placeholder="Nome ou e-mail…" autocomplete="off">
                                </div>
                                <div class="picker-list" id="donoList"></div>
                            </div>
                        </div>
                        <?php if (empty($donos)): ?>
                            <div class="form-text text-danger">Nenhum cliente cadastrado ainda — <a href="<?= BASE ?>/painel/clientes.php?acao=novo">cadastre um primeiro</a>.</div>
                        <?php endif ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nome do animal *</label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Espécie *</label>
                            <?= campoPicker('naEspecie', 'especie', 'Selecione…', 'Buscar espécie…', obrigatorio: true) ?>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Sexo *</label>
                            <?= campoPicker('naSexo', 'sexo', '—', '', obrigatorio: true, comBusca: false) ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Raça *</label>
                        <?= campoPicker('naRaca', 'raca', 'Selecione a espécie primeiro', 'Buscar raça…', obrigatorio: true) ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cor</label>
                        <input type="text" name="cor" class="form-control" placeholder="Ex: Caramelo, Preto e branco…">
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Nascimento</label>
                            <input type="date" name="nascimento" class="form-control" data-validar="nascimento" min="<?= date('Y-m-d', strtotime('-100 years')) ?>" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Peso (kg)</label>
                            <input type="text" id="naPesoVisivel" class="form-control" data-mask="peso" data-target="naPesoReal" placeholder="0,000" inputmode="numeric">
                            <input type="hidden" name="peso" id="naPesoReal">
                        </div>
                    </div>
                    <div class="mb-1 mt-3">
                        <label class="form-label">Observações</label>
                        <textarea name="observacoes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> Cadastrar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var DONOS = <?= json_encode(array_map(fn($d) => [
    'id' => $d['IDUsuario'], 'nome' => $d['Nome'], 'email' => $d['Email'],
    'equipe' => $d['NivelAcesso'] !== 'cliente',
], $donos), JSON_UNESCAPED_UNICODE) ?>;
var NA_ESPECIES = <?= json_encode(array_map(fn($e) => [
    'id' => $e['IDEspecie'], 'nome' => $e['Nome'], 'icone' => $e['Icone'],
], $especies), JSON_UNESCAPED_UNICODE) ?>;
var NA_RACAS = <?= json_encode(array_map(fn($r) => [
    'especie' => $r['FKEspecie'], 'nome' => $r['Nome'],
], $racas), JSON_UNESCAPED_UNICODE) ?>;

initPicker({
    pickerId: 'donoPicker', triggerId: 'donoTrigger', dropdownId: 'donoDropdown',
    searchId: 'donoSearch', listId: 'donoList', hiddenId: 'inpDonoId', labelId: 'donoLabel',
    items: DONOS,
    chave: function (d) { return d.id; },
    renderItem: function (d) { return { title: d.nome + (d.equipe ? ' (equipe)' : ''), sub: d.email }; },
    matches: function (d, q) {
        return d.nome.toLowerCase().indexOf(q) !== -1 || d.email.toLowerCase().indexOf(q) !== -1;
    },
    vazioMsg: 'Nenhum cliente encontrado.',
});

initAnimalPickers('na', NA_ESPECIES, NA_RACAS);

initPicker({
    pickerId: 'animaisEspeciePicker', triggerId: 'animaisEspecieTrigger', dropdownId: 'animaisEspecieDropdown',
    searchId: 'animaisEspecieSearch', listId: 'animaisEspecieList', hiddenId: 'inpanimaisEspecieId', labelId: 'animaisEspecieLabel',
    items: [{ id: '', nome: 'Todas as espécies', icone: '' }].concat(NA_ESPECIES),
    chave: function (e) { return e.id; },
    renderItem: function (e) { return { title: e.nome, icon: e.icone }; },
    matches: function (e, q) { return e.nome.toLowerCase().indexOf(q) !== -1; },
    vazioMsg: 'Nenhuma espécie encontrada.',
    onSelect: function () { document.getElementById('formAnimais').submit(); },
});
</script>

<?php if ($souAdmin && ($_GET['acao'] ?? '') === 'novo'): ?>
<script>new bootstrap.Modal(document.getElementById('modalNovoAnimal')).show();</script>
<?php endif ?>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
