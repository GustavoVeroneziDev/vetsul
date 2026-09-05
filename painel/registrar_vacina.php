<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('admin');

$animalPreId = trim($_GET['animal'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/registrar_vacina.php?animal=' . $animalPreId, 'Token inválido.', 'danger');
    }

    $fkAnimal = trim($_POST['animal'] ?? '');
    $fkTipo   = trim($_POST['tipo']   ?? '');
    $dataAp   = trim($_POST['data_aplicacao'] ?? '');
    $proximaManual    = trim($_POST['proxima_data'] ?? '');
    $sequenciaExtra   = array_values(array_filter(array_map('trim', $_POST['proxima_data_extra'] ?? []), fn($v) => $v !== ''));
    $ciclica          = !empty($_POST['ciclica']);
    $intervaloValor   = (int) ($_POST['intervalo_valor'] ?? 0);
    $intervaloUnidade = trim($_POST['intervalo_unidade'] ?? '');
    $vet      = trim($_POST['veterinario'] ?? '');
    $lote     = trim($_POST['lote'] ?? '');
    $obs      = trim($_POST['observacoes'] ?? '');

    $unidadesValidas = ['semana' => 'weeks', 'mes' => 'months', 'ano' => 'years'];
    $voltar          = BASE . '/painel/registrar_vacina.php?animal=' . $fkAnimal;
    $formatoData     = '/^\d{4}-\d{2}-\d{2}$/';

    if ($fkAnimal === '' || $fkTipo === '' || $dataAp === '') {
        redirecionarComMensagem($voltar, 'Animal, vacina e data de aplicação são obrigatórios.', 'warning');
    }

    // Valida o formato de toda data recebida antes de tentar interpretá-la —
    // sem isso, um valor mal-formado (ou um ano digitado errado, tipo "2006"
    // em vez de "2026") só ia estourar mais na frente de um jeito confuso,
    // ou pior, quebrar a página inteira.
    $todasAsDatas = array_merge([$dataAp], $proximaManual !== '' ? [$proximaManual] : [], $sequenciaExtra);
    foreach ($todasAsDatas as $d) {
        if (!preg_match($formatoData, $d)) {
            redirecionarComMensagem($voltar, 'Uma das datas informadas é inválida.', 'warning');
        }
    }
    $limitePassado = '2000-01-01';
    $limiteFuturo  = date('Y-m-d', strtotime('+10 years'));
    if ($dataAp < $limitePassado || $dataAp > $limiteFuturo) {
        redirecionarComMensagem($voltar, 'Data de aplicação fora do intervalo permitido (confira o ano).', 'warning');
    }

    // Trava o intervalo entre 1 e 120 (mesmo limite do campo na tela) — sem
    // isso um valor absurdo vindo fora da tela normal estouraria o DATE_ADD.
    $intervaloValor = max(1, min(120, $intervaloValor));

    try {
        $tipoStmt = $pdo->prepare('SELECT Nome, IntervaloMeses FROM TiposVacina WHERE IDTipo = :id LIMIT 1');
        $tipoStmt->execute([':id' => $fkTipo]);
        $tipo = $tipoStmt->fetch();

        $nomeVacina = $tipo['Nome'] ?? 'aplicação';

        // Cíclica não depende mais do intervalo do catálogo — a pessoa
        // escolhe livremente "a cada X semanas/meses/anos" na hora.
        $ciclica = $ciclica && isset($unidadesValidas[$intervaloUnidade]);

        $proximaData = null;
        if ($ciclica) {
            $dt = new DateTimeImmutable($dataAp);
            $proximaData = $dt->modify('+' . $intervaloValor . ' ' . $unidadesValidas[$intervaloUnidade])->format('Y-m-d');
        } elseif ($proximaManual !== '') {
            $proximaData = $proximaManual;
        } elseif ($tipo && $tipo['IntervaloMeses']) {
            $dt = new DateTimeImmutable($dataAp);
            $proximaData = $dt->modify('+' . (int) $tipo['IntervaloMeses'] . ' months')->format('Y-m-d');
        }

        // A próxima dose (ou qualquer data da sequência) antes da aplicação
        // não faz sentido — normalmente é sinal de ano digitado errado.
        if ($proximaData !== null && $proximaData < $dataAp) {
            redirecionarComMensagem($voltar, 'A próxima dose não pode ser antes da data de aplicação — confira o ano.', 'warning');
        }
        foreach ($sequenciaExtra as $dataExtra) {
            if ($dataExtra < $dataAp) {
                redirecionarComMensagem($voltar, 'Uma das datas da sequência está antes da aplicação — confira o ano.', 'warning');
            }
        }

        // O "próximo evento" desse registro sempre vira um compromisso na
        // Agenda — se a aplicação em si ainda não aconteceu, é ela mesma;
        // se já aconteceu (ou é hoje), o que falta é o retorno da próxima
        // dose. Nunca os dois ao mesmo tempo — só existe um FKAgendamento
        // por registro, e só faz sentido lembrar do que ainda não passou.
        //
        // Cada compromisso é criado com notificar:false — um único cadastro
        // pode gerar vários (sequência manual), e mandar um WhatsApp por
        // data deixava o cliente recebendo várias mensagens seguidas pra
        // um só atendimento. Uma mensagem consolidada é enviada no final.
        $eventosCriados = [];
        if ($dataAp > date('Y-m-d')) {
            $fkAgendamentoPrimario = criarAgendamentoVacina($pdo, $fkAnimal, $nomeVacina, $vet, $dataAp, notificar: false);
            $eventosCriados[] = ['inicio' => $dataAp . ' 09:00:00', 'retorno' => false];
        } elseif ($proximaData !== null) {
            $fkAgendamentoPrimario = criarAgendamentoVacina($pdo, $fkAnimal, $nomeVacina, $vet, $proximaData, retorno: true, notificar: false);
            $eventosCriados[] = ['inicio' => $proximaData . ' 09:00:00', 'retorno' => true];
        } else {
            $fkAgendamentoPrimario = null;
        }

        $pdo->prepare(
            'INSERT INTO RegistrosVacinas (IDRegistro, FKAnimal, FKTipoVacina, DataAplicacao, ProximaData, Ciclica, IntervaloCiclicoValor, IntervaloCiclicoUnidade, FKAgendamento, FKVeterinario, Lote, Observacoes)
             VALUES (:id, :animal, :tipo, :data, :proxima, :ciclica, :intvalor, :intunidade, :agendamento, :vet, :lote, :obs)'
        )->execute([
            ':id'         => gerarUuid(),
            ':animal'     => $fkAnimal,
            ':tipo'       => $fkTipo,
            ':data'       => $dataAp,
            ':proxima'    => $proximaData,
            ':ciclica'    => $ciclica ? 1 : 0,
            ':intvalor'   => $ciclica ? $intervaloValor : null,
            ':intunidade' => $ciclica ? $intervaloUnidade : null,
            ':agendamento' => $fkAgendamentoPrimario,
            ':vet'        => $vet ?: null,
            ':lote'       => $lote ?: null,
            ':obs'        => $obs ?: null,
        ]);

        // Sequência manual: cada data extra vira um lembrete futuro
        // independente (ainda não aplicado — DataAplicacao fica em branco) E
        // um compromisso na Agenda, pra quem prefere planejar várias doses
        // na mão de uma vez em vez de depender do modo cíclico.
        if (!$ciclica) {
            foreach ($sequenciaExtra as $dataExtra) {
                $fkAg = criarAgendamentoVacina($pdo, $fkAnimal, $nomeVacina, $vet, $dataExtra, retorno: true, notificar: false);
                $eventosCriados[] = ['inicio' => $dataExtra . ' 09:00:00', 'retorno' => true];
                $pdo->prepare(
                    'INSERT INTO RegistrosVacinas (IDRegistro, FKAnimal, FKTipoVacina, DataAplicacao, ProximaData, FKAgendamento, FKVeterinario, Observacoes)
                     VALUES (:id, :animal, :tipo, NULL, :proxima, :agendamento, :vet, :obs)'
                )->execute([
                    ':id'      => gerarUuid(),
                    ':animal'  => $fkAnimal,
                    ':tipo'    => $fkTipo,
                    ':proxima' => $dataExtra,
                    ':agendamento' => $fkAg,
                    ':vet'     => $vet ?: null,
                    ':obs'     => 'Aplicação futura planejada manualmente.',
                ]);
            }
        }

        // Uma mensagem só pro cliente, mesmo quando o cadastro gerou vários
        // compromissos — uma linha por data quando são vários, ou o mesmo
        // texto de sempre quando é só um.
        if ($eventosCriados) {
            $donoStmt = $pdo->prepare(
                'SELECT u.Nome AS NomeCliente, u.Telefone, a.Nome AS NomeAnimal FROM Animais a JOIN Usuarios u ON u.IDUsuario = a.FKDono WHERE a.IDAnimal = :id'
            );
            $donoStmt->execute([':id' => $fkAnimal]);
            $dono = $donoStmt->fetch();
            if ($dono && $dono['Telefone']) {
                if (count($eventosCriados) === 1) {
                    $ev     = $eventosCriados[0];
                    $titulo = 'Vacina: ' . $nomeVacina . ($ev['retorno'] ? ' (retorno)' : '');
                    $msg    = $ev['retorno']
                        ? montarMensagemRetorno($pdo, $dono['NomeCliente'], $dono['NomeAnimal'], 'procedimento', $titulo, $ev['inicio'])
                        : montarMensagemNovoAgendamento($pdo, $dono['NomeCliente'], $dono['NomeAnimal'], 'procedimento', $titulo, $ev['inicio']);
                } else {
                    $msg = montarMensagemVariosRetornos(
                        $dono['NomeCliente'],
                        $dono['NomeAnimal'],
                        $nomeVacina,
                        array_map(fn($ev) => $ev['inicio'], $eventosCriados)
                    );
                }
                enviarWhatsApp(waNumero($dono['Telefone']), $msg);
            }
        }

        redirecionarComMensagem(BASE . '/painel/animal_detalhe.php?id=' . $fkAnimal, 'Vacina registrada com sucesso!', 'success');
    } catch (PDOException $e) {
        error_log('[RegistrarVacina] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/painel/registrar_vacina.php?animal=' . $fkAnimal, 'Erro ao registrar vacina.', 'danger');
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

    $tipos = $pdo->query(
        "SELECT IDTipo, Nome, IntervaloMeses, FKEspecie FROM TiposVacina WHERE Ativo = 1 ORDER BY Nome ASC"
    )->fetchAll();

    $vets = $pdo->query(
        "SELECT IDUsuario, Nome FROM Usuarios WHERE Cargo = 'veterinario' AND Ativo = 1 ORDER BY Nome ASC"
    )->fetchAll();

    // $animais já carrega todo mundo ativo (Nome/NomeDono/FKEspecie inclusos)
    // — acha o pré-selecionado ali em vez de rodar a mesma consulta de novo.
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
    error_log('[RegistrarVacinaForm] ' . $e->getMessage());
    $animais = $tipos = $vets = [];
    $animalPre = null;
}

$paginaTitulo = 'Registrar Vacina';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="<?= BASE ?>/painel/animais.php" onclick="voltarInteligente(event)" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="fw-bold mb-0"><i class="bi bi-shield-plus me-2 text-accent"></i>Registrar Vacina</h4>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card p-4">
            <form method="POST">
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

                <div class="mb-3">
                    <label class="form-label">Vacina *</label>
                    <?= campoPicker('rvTipo', 'tipo', 'Selecione a vacina', '', obrigatorio: true, comBusca: false) ?>
                </div>

                <div class="row g-2 mb-1">
                    <div class="col-6">
                        <label class="form-label">Data de aplicação *</label>
                        <input type="date" name="data_aplicacao" id="rvDataAplicacao" class="form-control" required
                            min="2000-01-01" max="<?= date('Y-m-d', strtotime('+10 years')) ?>" value="<?= date('Y-m-d') ?>">
                        <div class="form-text">Pode ser uma data futura — se ainda não aconteceu, fica marcada como "Planejada".</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Próxima dose <span class="text-secondary">(opcional)</span></label>
                        <input type="date" name="proxima_data" id="rvProximaData" class="form-control" min="2000-01-01" max="<?= date('Y-m-d', strtotime('+10 years')) ?>">
                        <div class="form-text">Deixe em branco para calcular automaticamente pelo intervalo da vacina.</div>
                    </div>
                </div>

                <div id="rvSequenciaExtra" class="mb-1"></div>
                <div class="mb-3">
                    <button type="button" id="rvAddSequencia" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-plus-lg me-1"></i> Adicionar outra data
                    </button>
                    <span class="text-secondary small ms-1">planeja uma sequência de aplicações futuras, uma por uma</span>
                </div>

                <div class="mb-3 p-3 rounded-3" style="background:var(--bg-hover);">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="ciclica" id="rvCiclica" value="1">
                        <label class="form-check-label fw-medium" for="rvCiclica">
                            Repetir automaticamente <span class="text-secondary fw-normal">(em vez da sequência manual acima)</span>
                        </label>
                    </div>
                    <div id="rvIntervaloWrap" class="row g-2 align-items-center" style="display:none;">
                        <label class="col-auto small text-secondary mb-0">A cada</label>
                        <div class="col-3">
                            <input type="number" name="intervalo_valor" id="rvIntervaloValor" class="form-control form-control-sm" min="1" max="120" value="12">
                        </div>
                        <div class="col-auto">
                            <select name="intervalo_unidade" id="rvIntervaloUnidade" class="form-select form-select-sm">
                                <option value="semana">semana(s)</option>
                                <option value="mes" selected>mês(es)</option>
                                <option value="ano">ano(s)</option>
                            </select>
                        </div>
                        <div class="col-12 mt-1">
                            <span class="text-secondary small me-2">Atalhos:</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 rv-atalho-intervalo" data-valor="3" data-unidade="semana">21 dias</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 rv-atalho-intervalo" data-valor="1" data-unidade="mes">1 mês</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 rv-atalho-intervalo" data-valor="6" data-unidade="mes">6 meses</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 rv-atalho-intervalo" data-valor="1" data-unidade="ano">1 ano</button>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label">Veterinário</label>
                        <?= campoPicker('vetResp', 'veterinario', 'Selecione…', 'Buscar veterinário…') ?>
                        <?php if (empty($vets)): ?>
                            <div class="form-text">Nenhum veterinário cadastrado — <a href="<?= BASE ?>/painel/equipe.php">cadastre um primeiro</a>.</div>
                        <?php endif ?>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Lote</label>
                        <input type="text" name="lote" class="form-control">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Observações</label>
                    <textarea name="observacoes" class="form-control" rows="2"></textarea>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-accent btn-lg">
                        <i class="bi bi-check2 me-2"></i> Registrar vacina
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

var TIPOS_VACINA = <?= json_encode(array_map(fn($t) => [
    'id' => $t['IDTipo'], 'nome' => $t['Nome'], 'especie' => $t['FKEspecie'] ?? '', 'intervalo' => $t['IntervaloMeses'],
], $tipos), JSON_UNESCAPED_UNICODE) ?>;

function labelVacina(t) {
    return t.nome + (t.intervalo ? ' (reforço em ' + t.intervalo + ' meses)' : ' (dose única)');
}

// Filtra a lista de vacinas pela espécie do animal escolhido — vacina sem
// espécie fixada (FKEspecie null) serve pra qualquer uma.
function vacinasParaEspecie(especie) {
    return TIPOS_VACINA.filter(function (t) { return !especie || !t.especie || t.especie === especie; });
}

// Pré-preenche o "a cada X" com o intervalo de reforço do catálogo da
// vacina, se ela tiver um — só um ponto de partida, continua editável.
function atualizarIntervaloSugerido(tipoId) {
    var t = TIPOS_VACINA.find(function (x) { return x.id === tipoId; });
    if (t && t.intervalo) {
        document.getElementById('rvIntervaloValor').value = t.intervalo;
        document.getElementById('rvIntervaloUnidade').value = 'mes';
    }
}

// Cíclica e sequência manual são estratégias alternativas pra mesma coisa
// (gerar as próximas datas) — liga uma, desliga a outra, pra não ficar uma
// combinação confusa de "repete sozinha" + "mas também tem 3 datas na mão".
var rvCiclicaChk = document.getElementById('rvCiclica');
rvCiclicaChk.addEventListener('change', function () {
    document.getElementById('rvIntervaloWrap').style.display = this.checked ? '' : 'none';
    document.getElementById('rvProximaData').disabled = this.checked;
    document.getElementById('rvSequenciaExtra').style.display = this.checked ? 'none' : '';
    document.getElementById('rvAddSequencia').disabled = this.checked;
});

// Atalhos pros intervalos cíclicos mais comuns na prática — evita ficar
// digitando "21" + trocar o seletor pra "semana(s)" toda vez.
document.querySelectorAll('.rv-atalho-intervalo').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('rvIntervaloValor').value = btn.dataset.valor;
        document.getElementById('rvIntervaloUnidade').value = btn.dataset.unidade;
    });
});

// Cada "+" sugere a próxima data 21 dias depois da última já preenchida
// (ou da data de aplicação, se ainda não tem nenhuma) — só um ponto de
// partida pra não começar sempre em branco, continua editável.
function ultimaDataDaSequencia() {
    var inputs = document.querySelectorAll('#rvSequenciaExtra input[type="date"]');
    for (var i = inputs.length - 1; i >= 0; i--) {
        if (inputs[i].value) return inputs[i].value;
    }
    var proxima = document.getElementById('rvProximaData').value;
    if (proxima) return proxima;
    return document.getElementById('rvDataAplicacao').value;
}

document.getElementById('rvAddSequencia').addEventListener('click', function () {
    var base = ultimaDataDaSequencia();
    var sugestao = '';
    if (base) {
        var d = new Date(base + 'T00:00:00');
        d.setDate(d.getDate() + 21);
        sugestao = d.toISOString().slice(0, 10);
    }

    var wrap = document.createElement('div');
    wrap.className = 'input-group input-group-sm mb-2';
    wrap.style.maxWidth = '260px';
    wrap.innerHTML = '<input type="date" name="proxima_data_extra[]" class="form-control" required min="2000-01-01" max="<?= date('Y-m-d', strtotime('+10 years')) ?>" value="' + sugestao + '">'
        + '<button type="button" class="btn btn-outline-danger">&times;</button>';
    wrap.querySelector('button').addEventListener('click', function () { wrap.remove(); });
    document.getElementById('rvSequenciaExtra').appendChild(wrap);
});

var rvTipoPk = initPicker({
    pickerId: 'rvTipoPicker', triggerId: 'rvTipoTrigger', dropdownId: 'rvTipoDropdown',
    searchId: 'rvTipoSearch', listId: 'rvTipoList', hiddenId: 'inprvTipoId', labelId: 'rvTipoLabel',
    items: TIPOS_VACINA,
    chave: function (t) { return t.id; },
    renderItem: function (t) { return { title: labelVacina(t) }; },
    matches: function (t, q) { return t.nome.toLowerCase().indexOf(q) !== -1; },
    vazioMsg: 'Nenhuma vacina encontrada.',
    onSelect: function (t) { atualizarIntervaloSugerido(t.id); },
});

var animalPicker = initPicker({
    pickerId: 'animalPicker', triggerId: 'animalTrigger', dropdownId: 'animalDropdown',
    searchId: 'animalSearch', listId: 'animalList', hiddenId: 'inpAnimalId', labelId: 'animalLabel',
    items: ANIMAIS,
    chave: function (a) { return a.id; },
    renderItem: function (a) { return { title: a.nome, icon: a.icone, sub: a.dono }; },
    matches: function (a, q) {
        return a.nome.toLowerCase().indexOf(q) !== -1 || a.dono.toLowerCase().indexOf(q) !== -1;
    },
    vazioMsg: 'Nenhum animal encontrado.',
    onSelect: function (a) {
        var itens = vacinasParaEspecie(a.especie);
        rvTipoPk.setItems(itens, 'Selecione a vacina');
        // Abre a vacina sozinha pra fluir direto — mesmo padrão do Tipo/
        // Procedimento na agenda. setTimeout pelo mesmo motivo: o "click"
        // nativo que ainda vai disparar fecharia o dropdown na hora.
        if (itens.length) {
            setTimeout(function () { rvTipoPk.abrir(); }, 50);
        }
    },
});

<?php if ($animalPre): ?>
    rvTipoPk.setItems(vacinasParaEspecie(<?= json_encode($animalPre['FKEspecie']) ?>), 'Selecione a vacina');
<?php endif ?>

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
