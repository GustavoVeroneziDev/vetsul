<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('admin');

$animalPreId = trim($_GET['animal'] ?? '');

$tiposClinico = [
    'cirurgia'     => 'Cirurgia',
    'consulta'     => 'Consulta',
    'exame'        => 'Exame',
    'procedimento' => 'Procedimento',
    'observacao'   => 'Observação',
    'outro'        => 'Outro',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/registrar_clinico.php?animal=' . $animalPreId, 'Token inválido.', 'danger');
    }

    $fkAnimal = trim($_POST['animal'] ?? '');
    $tipo     = trim($_POST['tipo'] ?? '');
    $titulo   = trim($_POST['titulo'] ?? '');
    $dataReg  = trim($_POST['data_registro'] ?? '');
    $vet      = trim($_POST['veterinario'] ?? '');
    $anot     = trim($_POST['anotacoes'] ?? '');

    if ($fkAnimal === '' || $titulo === '' || $dataReg === '' || !isset($tiposClinico[$tipo])) {
        redirecionarComMensagem(BASE . '/painel/registrar_clinico.php?animal=' . $fkAnimal, 'Animal, tipo, título e data são obrigatórios.', 'warning');
    }

    try {
        $idRegistro = gerarUuid();
        $pdo->prepare(
            'INSERT INTO RegistrosClinicos (IDRegistro, FKAnimal, FKVeterinario, Tipo, Titulo, Anotacoes, DataRegistro)
             VALUES (:id, :animal, :vet, :tipo, :titulo, :anot, :data)'
        )->execute([
            ':id'     => $idRegistro,
            ':animal' => $fkAnimal,
            ':vet'    => $vet ?: null,
            ':tipo'   => $tipo,
            ':titulo' => $titulo,
            ':anot'   => $anot ?: null,
            ':data'   => $dataReg,
        ]);

        foreach ($_FILES['imagens']['tmp_name'] ?? [] as $i => $tmp) {
            $arquivo = [
                'tmp_name' => $tmp,
                'error'    => $_FILES['imagens']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $_FILES['imagens']['size'][$i] ?? 0,
            ];
            $caminho = salvarImagemEnviada($arquivo, 'clinico', permitirPdf: true);
            if ($caminho !== null) {
                $pdo->prepare(
                    'INSERT INTO AnexosClinicos (IDAnexo, FKRegistro, CaminhoArquivo, NomeOriginal)
                     VALUES (:id, :reg, :caminho, :nome)'
                )->execute([
                    ':id'      => gerarUuid(),
                    ':reg'     => $idRegistro,
                    ':caminho' => $caminho,
                    ':nome'    => $_FILES['imagens']['name'][$i] ?? null,
                ]);
            }
        }

        redirecionarComMensagem(BASE . '/painel/animal_detalhe.php?id=' . $fkAnimal, 'Registro clínico salvo com sucesso!', 'success');
    } catch (PDOException $e) {
        error_log('[RegistrarClinico] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/painel/registrar_clinico.php?animal=' . $fkAnimal, 'Erro ao salvar registro.', 'danger');
    }
}

try {
    $animais = $pdo->query(
        "SELECT a.IDAnimal, a.Nome, a.FKEspecie, u.Nome AS NomeDono, e.Icone AS IconeEspecie
         FROM Animais a
         JOIN Usuarios u ON u.IDUsuario = a.FKDono
         JOIN Especies e ON e.IDEspecie = a.FKEspecie
         WHERE a.Ativo = 1 ORDER BY a.Nome ASC"
    )->fetchAll();

    $vets = $pdo->query(
        "SELECT IDUsuario, Nome FROM Usuarios WHERE Cargo = 'veterinario' AND Ativo = 1 ORDER BY Nome ASC"
    )->fetchAll();

    // $animais já carrega todo mundo ativo (Nome/NomeDono inclusos) — acha o
    // pré-selecionado ali em vez de rodar a mesma consulta de novo.
    $animalPre = null;
    if ($animalPreId) {
        foreach ($animais as $a) {
            if ($a['IDAnimal'] === $animalPreId) {
                $animalPre = $a;
                break;
            }
        }
    }
} catch (PDOException $e) {
    error_log('[RegistrarClinicoForm] ' . $e->getMessage());
    $animais = $vets = [];
    $animalPre = null;
}

$paginaTitulo = 'Registrar Clínico';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="<?= BASE ?>/painel/animais.php" onclick="voltarInteligente(event)" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="fw-bold mb-0"><i class="bi bi-journal-medical me-2 text-accent"></i>Registrar Clínico</h4>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card p-4">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">

                <div class="mb-3">
                    <label class="form-label">Animal *</label>
                    <input type="hidden" name="animal" id="inpAnimalId" required value="<?= $animalPre ? h($animalPre['IDAnimal']) : '' ?>">
                    <div class="picker" id="animalPicker">
                        <div class="picker-trigger" id="animalTrigger" tabindex="0">
                            <span id="animalLabel" class="<?= $animalPre ? 'picker-selected' : 'picker-placeholder' ?>">
                                <?= $animalPre ? h($animalPre['Nome']) . ' — ' . h($animalPre['NomeDono']) : 'Buscar animal ou cliente…' ?>
                            </span>
                            <span class="picker-caret"><i class="bi bi-chevron-down"></i></span>
                        </div>
                        <div class="picker-dropdown d-none" id="animalDropdown">
                            <div class="picker-search-wrap">
                                <i class="bi bi-search picker-search-icon"></i>
                                <input type="text" class="picker-search" id="animalSearch" placeholder="Nome do animal ou do cliente…" autocomplete="off">
                            </div>
                            <div class="picker-list" id="animalList"></div>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label">Tipo *</label>
                        <?= campoPicker('rcTipo', 'tipo', '—', '', 'cirurgia', 'Cirurgia', obrigatorio: true, comBusca: false) ?>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Data *</label>
                        <input type="date" name="data_registro" class="form-control" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Título *</label>
                    <input type="text" name="titulo" class="form-control" placeholder="Ex: Castração, Exame de sangue…" required maxlength="150">
                </div>

                <div class="mb-3">
                    <label class="form-label">Veterinário responsável</label>
                    <?= campoPicker('vetResp', 'veterinario', 'Selecione…', 'Buscar veterinário…') ?>
                    <?php if (empty($vets)): ?>
                        <div class="form-text">Nenhum veterinário cadastrado — <a href="<?= BASE ?>/painel/equipe.php">cadastre um primeiro</a>.</div>
                    <?php endif ?>
                </div>

                <div class="mb-3">
                    <label class="form-label">Anotações</label>
                    <textarea name="anotacoes" class="form-control" rows="4" placeholder="Comentários, observações, conduta…"></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label">Imagens ou PDF <span class="text-secondary">(opcional)</span></label>
                    <input type="file" name="imagens[]" class="form-control" accept="image/png,image/jpeg,image/webp,application/pdf" multiple>
                    <div class="form-text">JPG, PNG, WEBP ou PDF (laudos, exames) — até 5 MB cada. No celular, dá pra tirar a foto na hora.</div>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-accent btn-lg">
                        <i class="bi bi-check2 me-2"></i> Salvar registro
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var ANIMAIS = <?= json_encode(array_map(fn($a) => [
    'id' => $a['IDAnimal'], 'nome' => $a['Nome'], 'dono' => $a['NomeDono'],
    'especie' => $a['FKEspecie'], 'icone' => $a['IconeEspecie'],
], $animais), JSON_UNESCAPED_UNICODE) ?>;
var VETS = <?= json_encode(array_map(fn($v) => [
    'id' => $v['IDUsuario'], 'nome' => $v['Nome'],
], $vets), JSON_UNESCAPED_UNICODE) ?>;
var TIPOS_CLINICO = <?= json_encode(array_map(fn($valor, $label) => [
    'id' => $valor, 'nome' => $label,
], array_keys($tiposClinico), $tiposClinico), JSON_UNESCAPED_UNICODE) ?>;

initPicker({
    pickerId: 'rcTipoPicker', triggerId: 'rcTipoTrigger', dropdownId: 'rcTipoDropdown',
    searchId: 'rcTipoSearch', listId: 'rcTipoList', hiddenId: 'inprcTipoId', labelId: 'rcTipoLabel',
    items: TIPOS_CLINICO,
    chave: function (t) { return t.id; },
    renderItem: function (t) { return { title: t.nome }; },
    matches: function (t, q) { return t.nome.toLowerCase().indexOf(q) !== -1; },
    vazioMsg: 'Nada encontrado.',
});

initPicker({
    pickerId: 'animalPicker', triggerId: 'animalTrigger', dropdownId: 'animalDropdown',
    searchId: 'animalSearch', listId: 'animalList', hiddenId: 'inpAnimalId', labelId: 'animalLabel',
    items: ANIMAIS,
    chave: function (a) { return a.id; },
    renderItem: function (a) { return { title: a.nome, icon: a.icone, sub: a.dono }; },
    matches: function (a, q) {
        return a.nome.toLowerCase().indexOf(q) !== -1 || a.dono.toLowerCase().indexOf(q) !== -1;
    },
    vazioMsg: 'Nenhum animal encontrado.',
});

initPicker({
    pickerId: 'vetRespPicker', triggerId: 'vetRespTrigger', dropdownId: 'vetRespDropdown',
    searchId: 'vetRespSearch', listId: 'vetRespList', hiddenId: 'inpvetRespId', labelId: 'vetRespLabel',
    items: VETS,
    chave: function (v) { return v.id; },
    renderItem: function (v) { return { title: v.nome }; },
    matches: function (v, q) { return v.nome.toLowerCase().indexOf(q) !== -1; },
    vazioMsg: 'Nenhum veterinário encontrado.',
});
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
