<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('admin', 'funcionario');

$id = trim($_GET['id'] ?? '');
if (!$id) {
    redirecionarComMensagem(BASE . '/painel/animais.php', 'Animal não encontrado.', 'warning');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/animal_detalhe.php?id=' . $id, 'Token inválido.', 'danger');
    }
    exigirAdmin(BASE . '/painel/animal_detalhe.php?id=' . $id);
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'editar') {
        $nome  = trim($_POST['nome'] ?? '');
        $raca  = trim($_POST['raca'] ?? '');
        $nasc  = trim($_POST['nascimento'] ?? '');
        $sexo  = trim($_POST['sexo'] ?? '');
        $cor   = trim($_POST['cor'] ?? '');
        $peso  = trim($_POST['peso'] ?? '');
        $chip  = trim($_POST['microchip'] ?? '');
        $obs   = trim($_POST['observacoes'] ?? '');

        if ($nome === '' || $raca === '' || $sexo === '') {
            redirecionarComMensagem(BASE . '/painel/animal_detalhe.php?id=' . $id, 'Nome, raça e sexo são obrigatórios.', 'warning');
        }
        if (!dataNascimentoValida($nasc)) {
            redirecionarComMensagem(BASE . '/painel/animal_detalhe.php?id=' . $id, 'Data de nascimento inválida — não pode ser no futuro nem passar de 100 anos atrás.', 'warning');
        }

        $foto = !empty($_FILES['foto']['tmp_name']) ? salvarImagemEnviada($_FILES['foto'], 'animais') : null;
        if (!empty($_FILES['foto']['tmp_name']) && $foto === null) {
            redirecionarComMensagem(BASE . '/painel/animal_detalhe.php?id=' . $id, 'Foto inválida — envie um JPG, PNG ou WEBP de até 5 MB.', 'warning');
        }

        try {
            $sql = 'UPDATE Animais SET Nome=:nome, Raca=:raca, DataNascimento=:nasc, Sexo=:sexo, Pelagem=:cor,
                        PesoKg=:peso, Microchip=:chip, Observacoes=:obs' . ($foto ? ', FotoUrl=:foto' : '') . '
                 WHERE IDAnimal = :id';
            $params = [
                ':nome' => $nome, ':raca' => $raca, ':nasc' => $nasc ?: null,
                ':sexo' => $sexo, ':cor' => $cor ?: null, ':peso' => $peso !== '' ? $peso : null,
                ':chip' => $chip ?: null, ':obs' => $obs ?: null, ':id' => $id,
            ];
            if ($foto) {
                $params[':foto'] = $foto;
            }
            $pdo->prepare($sql)->execute($params);
            registrarAuditoria($pdo, 'animal', $id, 'editado');
            redirecionarComMensagem(BASE . '/painel/animal_detalhe.php?id=' . $id, 'Animal atualizado com sucesso!', 'success');
        } catch (PDOException $e) {
            error_log('[EditarAnimal] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/animal_detalhe.php?id=' . $id, 'Erro ao atualizar.', 'danger');
        }
    }

    if ($acao === 'desativar') {
        try {
            desativarAnimal($pdo, $id);
            registrarAuditoria($pdo, 'animal', $id, 'excluido');
            redirecionarComMensagem(BASE . '/painel/animal_detalhe.php?id=' . $id, 'Animal excluído — some das listas, mas o histórico fica guardado. Dá pra reativar quando quiser.', 'success');
        } catch (PDOException $e) {
            error_log('[DesativarAnimal] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/animal_detalhe.php?id=' . $id, 'Erro ao excluir.', 'danger');
        }
    }

    if ($acao === 'reativar') {
        try {
            $pdo->prepare('UPDATE Animais SET Ativo = 1 WHERE IDAnimal = :id')->execute([':id' => $id]);
            registrarAuditoria($pdo, 'animal', $id, 'reativado');
            redirecionarComMensagem(BASE . '/painel/animal_detalhe.php?id=' . $id, 'Animal reativado com sucesso!', 'success');
        } catch (PDOException $e) {
            error_log('[ReativarAnimal] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/animal_detalhe.php?id=' . $id, 'Erro ao reativar.', 'danger');
        }
    }
}

try {
    $stmt = $pdo->prepare(
        'SELECT a.*, e.Nome AS NomeEspecie, e.Icone AS IconeEspecie, e.IDEspecie,
                u.Nome AS NomeDono, u.Telefone AS TelefoneDono, u.IDUsuario AS IDDono
         FROM Animais a
         JOIN Especies e ON e.IDEspecie = a.FKEspecie
         JOIN Usuarios u ON u.IDUsuario = a.FKDono
         WHERE a.IDAnimal = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $animal = $stmt->fetch();
    if (!$animal) {
        redirecionarComMensagem(BASE . '/painel/animais.php', 'Animal não encontrado.', 'warning');
    }

    $historico = $pdo->prepare(
        'SELECT rv.*, tv.Nome AS NomeVacina, tv.IntervaloMeses
         FROM RegistrosVacinas rv
         JOIN TiposVacina tv ON tv.IDTipo = rv.FKTipoVacina
         WHERE rv.FKAnimal = :id
         ORDER BY COALESCE(rv.DataAplicacao, rv.ProximaData) DESC'
    );
    $historico->execute([':id' => $id]);
    $historico = $historico->fetchAll();

    $mostrarClinicoExcluidos = ($_GET['clinico'] ?? '') === 'todos';
    $clinico = $pdo->prepare(
        'SELECT rc.*, u.Nome AS NomeVeterinario
         FROM RegistrosClinicos rc
         LEFT JOIN Usuarios u ON u.IDUsuario = rc.FKVeterinario
         WHERE rc.FKAnimal = :id' . ($mostrarClinicoExcluidos ? '' : ' AND rc.Ativo = 1') . '
         ORDER BY rc.DataRegistro DESC, rc.MomentoRegistro DESC'
    );
    $clinico->execute([':id' => $id]);
    $clinico = $clinico->fetchAll();

    if ($clinico) {
        // Um IN() só pra todos os registros em vez de uma consulta por
        // registro — um animal com histórico longo não vira N+1 consultas.
        $idsRegistros = array_column($clinico, 'IDRegistro');
        $placeholders = implode(',', array_fill(0, count($idsRegistros), '?'));
        $anexosStmt = $pdo->prepare(
            "SELECT IDAnexo, CaminhoArquivo, FKRegistro FROM AnexosClinicos
             WHERE FKRegistro IN ({$placeholders}) ORDER BY MomentoUpload ASC"
        );
        $anexosStmt->execute($idsRegistros);
        $anexosPorRegistro = [];
        foreach ($anexosStmt->fetchAll() as $anexo) {
            $anexosPorRegistro[$anexo['FKRegistro']][] = $anexo;
        }
        foreach ($clinico as &$reg) {
            $reg['Anexos'] = $anexosPorRegistro[$reg['IDRegistro']] ?? [];
        }
        unset($reg);
    }

    $racas = $pdo->prepare('SELECT Nome FROM Racas WHERE FKEspecie = :esp ORDER BY Ordem ASC');
    $racas->execute([':esp' => $animal['FKEspecie']]);
    $racas = $racas->fetchAll(PDO::FETCH_COLUMN);

    // Agendamentos ativos desse animal — visível antes do botão "Agendar"
    // pra ninguém marcar em cima de um horário que já existe.
    $agAtivosStmt = $pdo->prepare(
        "SELECT ag.*, v.Nome AS NomeVeterinario
         FROM Agendamentos ag
         LEFT JOIN Usuarios v ON v.IDUsuario = ag.FKVeterinario
         WHERE ag.FKAnimal = :id AND ag.Status IN ('pendente', 'confirmado')
         ORDER BY ag.DataHoraInicio ASC"
    );
    $agAtivosStmt->execute([':id' => $id]);
    $agendamentosAtivos = $agAtivosStmt->fetchAll();

    // Linha do tempo de tudo que já aconteceu com os agendamentos desse
    // animal — criado, confirmado, remarcado (com de/pra), faltou,
    // cancelado, concluído, reaberto. Faltou/cancelado nunca viram registro
    // clínico (só "Concluir" cria um, e só se marcado), e remarcar
    // sobrescreve a data antiga sem deixar rastro — sem essa tabela, os
    // dois somem da tela do animal como se nunca tivessem existido.
    $movStmt = $pdo->prepare(
        "SELECT ev.*, ag.Titulo, u.Nome AS NomeUsuario
         FROM EventosAgendamento ev
         JOIN Agendamentos ag ON ag.IDAgendamento = ev.FKAgendamento
         LEFT JOIN Usuarios u ON u.IDUsuario = ev.FKUsuario
         WHERE ag.FKAnimal = :id
         ORDER BY ev.MomentoEvento DESC"
    );
    $movStmt->execute([':id' => $id]);
    $movimentacoes = $movStmt->fetchAll();
} catch (PDOException $e) {
    error_log('[AnimalDetalhe] ' . $e->getMessage());
    $historico = [];
    $clinico   = [];
    $racas     = [];
    $movimentacoes          = [];
    $agendamentosAtivos     = [];
    $agendamentosResolvidos = [];
}

$tiposClinicoLabel = [
    'cirurgia' => 'Cirurgia', 'consulta' => 'Consulta', 'exame' => 'Exame',
    'procedimento' => 'Procedimento', 'observacao' => 'Observação', 'outro' => 'Outro',
];

$souAdmin     = ($_SESSION['nivel_acesso'] ?? '') === 'admin';
$paginaTitulo = h($animal['Nome']);
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="<?= BASE ?>/painel/cliente_detalhe.php?id=<?= h($animal['IDDono']) ?>" onclick="voltarInteligente(event)" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="fw-bold mb-0">
        <?= especieIconeHtml($animal['IconeEspecie'], '1.4rem') ?> <?= h($animal['Nome']) ?>
        <?php if (!$animal['Ativo']): ?><span class="badge bg-secondary align-middle">Inativo</span><?php endif ?>
    </h4>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card p-4">
            <?php if ($animal['FotoUrl']): ?>
                <img src="<?= BASE ?><?= h($animal['FotoUrl']) ?>" alt="Foto de <?= h($animal['Nome']) ?>"
                     class="w-100 mb-3" style="aspect-ratio:1;object-fit:cover;border-radius:var(--radius-btn);">
            <?php endif ?>
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h5 class="fw-bold mb-0"><?= h($animal['Nome']) ?></h5>
                    <p class="small text-secondary mb-0"><?= h($animal['NomeEspecie']) ?><?= $animal['Raca'] ? ' · ' . h($animal['Raca']) : '' ?></p>
                </div>
                <?php if ($souAdmin): ?>
                    <button class="btn btn-sm btn-outline-accent" data-bs-toggle="modal" data-bs-target="#modalEditarAnimal">
                        <i class="bi bi-pencil"></i>
                    </button>
                <?php endif ?>
            </div>
            <dl class="dl-info mb-3">
                <dt>Cliente</dt>
                <dd><a href="<?= BASE ?>/painel/cliente_detalhe.php?id=<?= h($animal['IDDono']) ?>"><?= h($animal['NomeDono']) ?></a></dd>
                <?php if ($animal['DataNascimento']): ?>
                    <dt>Idade</dt>
                    <dd><?= h(formatarIdade($animal['DataNascimento'])) ?> (<?= formatarData($animal['DataNascimento']) ?>)</dd>
                <?php endif ?>
                <?php if ($animal['Sexo']): ?>
                    <dt>Sexo</dt>
                    <dd><?= formatarSexo($animal['Sexo']) ?></dd>
                <?php endif ?>
                <?php if ($animal['Pelagem']): ?>
                    <dt>Cor</dt>
                    <dd><?= h($animal['Pelagem']) ?></dd>
                <?php endif ?>
                <?php if ($animal['PesoKg']): ?>
                    <dt>Peso</dt>
                    <dd><?= h(number_format((float) $animal['PesoKg'], 3, ',', '.')) ?> kg</dd>
                <?php endif ?>
                <?php if ($animal['Microchip']): ?>
                    <dt>Microchip</dt>
                    <dd><?= h($animal['Microchip']) ?></dd>
                <?php endif ?>
                <?php if ($animal['Observacoes']): ?>
                    <dt>Observações</dt>
                    <dd><?= nl2br(h($animal['Observacoes'])) ?></dd>
                <?php endif ?>
            </dl>
            <?php if (!empty($agendamentosAtivos)): ?>
                <div class="mb-3 p-2 rounded-xl" style="background:var(--accent-light);">
                    <div class="small fw-semibold mb-1" style="color:var(--accent);">
                        <i class="bi bi-calendar-event me-1"></i>
                        <?= count($agendamentosAtivos) === 1 ? 'Já tem um agendamento' : count($agendamentosAtivos) . ' agendamentos ativos' ?>
                    </div>
                    <?php foreach ($agendamentosAtivos as $ag): ?>
                        <div class="small mb-2">
                            <span class="badge" style="background:var(--bg-card);color:var(--accent);"><?= h($tiposClinicoLabel[$ag['Tipo']] ?? $ag['Tipo']) ?></span>
                            <span class="fw-medium"><?= h($ag['Titulo']) ?></span>
                            —
                            <?= substr($ag['DataHoraInicio'], 0, 10) === date('Y-m-d') ? 'Hoje' : formatarData($ag['DataHoraInicio']) ?>
                            às <?= date('H:i', strtotime($ag['DataHoraInicio'])) ?>
                            <?= labelStatusAgendamento($ag['Status']) ?>
                            <?php if ($ag['NomeVeterinario']): ?><span class="text-secondary">· <?= h($ag['NomeVeterinario']) ?></span><?php endif ?>
                            <?php if ($ag['Observacoes']): ?>
                                <div class="text-secondary mt-1" style="font-size:.85em;"><?= nl2br(h($ag['Observacoes'])) ?></div>
                            <?php endif ?>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
            <?php if ($souAdmin): ?>
                <a href="<?= BASE ?>/painel/agenda.php?animal=<?= h($animal['IDAnimal']) ?>" class="btn btn-accent w-100 mb-2">
                    <i class="bi bi-calendar-plus me-1"></i> Agendar
                </a>
                <a href="<?= BASE ?>/painel/registrar_vacina.php?animal=<?= h($animal['IDAnimal']) ?>" class="btn btn-outline-accent w-100 mb-2">
                    <i class="bi bi-shield-plus me-1"></i> Registrar vacina
                </a>
                <a href="<?= BASE ?>/painel/registrar_clinico.php?animal=<?= h($animal['IDAnimal']) ?>" class="btn btn-outline-accent w-100 mb-2">
                    <i class="bi bi-journal-medical me-1"></i> Registrar clínico
                </a>
                <?php if ($animal['Ativo']): ?>
                    <form method="POST" data-confirm="Excluir <?= h($animal['Nome']) ?>? Fica oculto das listas e os agendamentos futuros são cancelados, mas o histórico é mantido — dá pra reativar depois.">
                        <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                        <input type="hidden" name="acao" value="desativar">
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="bi bi-trash me-1"></i> Excluir animal
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" data-confirm="Reativar <?= h($animal['Nome']) ?>?">
                        <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                        <input type="hidden" name="acao" value="reativar">
                        <button type="submit" class="btn btn-accent w-100">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Reativar animal
                        </button>
                    </form>
                <?php endif ?>
            <?php endif ?>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header px-4 py-3">
                <i class="bi bi-clipboard2-pulse me-2 text-accent"></i>Histórico de vacinação
            </div>
            <div class="card-body p-0">
                <?php if (empty($historico)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="bi bi-shield-plus fs-1 d-block mb-2 opacity-25"></i>
                        <p class="mb-0">Nenhuma vacina registrada.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="tabelaVacinas">
                            <thead style="background:var(--bg-hover);">
                                <tr>
                                    <th class="px-4 py-3">Vacina</th>
                                    <th>Aplicada</th>
                                    <th>Próxima</th>
                                    <th>Situação</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                    $unidadesPlural = ['semana' => 'semana(s)', 'mes' => 'mês(es)', 'ano' => 'ano(s)'];
                                ?>
                                <?php foreach ($historico as $reg): ?>
                                    <tr data-id="<?= h($reg['IDRegistro']) ?>">
                                        <td class="px-4 fw-medium"><?= h($reg['NomeVacina']) ?></td>
                                        <td class="small"><?= labelAplicacaoVacina($reg['DataAplicacao'], $reg['ProximaData']) ?></td>
                                        <td class="small">
                                            <?= $reg['ProximaData'] ? formatarData($reg['ProximaData']) : '—' ?>
                                            <?php if ($reg['Ciclica']): ?>
                                                <i class="bi bi-arrow-repeat text-accent ms-1" title="Cíclica — renova sozinha a cada <?= (int) $reg['IntervaloCiclicoValor'] ?> <?= $unidadesPlural[$reg['IntervaloCiclicoUnidade']] ?? 'meses' ?>"></i>
                                            <?php endif ?>
                                        </td>
                                        <td><?= labelSituacaoVacina($reg['ProximaData']) ?></td>
                                        <td class="text-nowrap">
                                            <?php if ($souAdmin): ?>
                                                <button class="btn btn-sm btn-outline-secondary btn-editar-proxima-vacina"
                                                    data-id="<?= h($reg['IDRegistro']) ?>"
                                                    data-vacina="<?= h($reg['NomeVacina']) ?>"
                                                    data-proxima="<?= h($reg['ProximaData'] ?? '') ?>"
                                                    data-ciclica="<?= $reg['Ciclica'] ? '1' : '0' ?>"
                                                    data-intervalo-valor="<?= (int) ($reg['IntervaloCiclicoValor'] ?: ($reg['IntervaloMeses'] ?: 12)) ?>"
                                                    data-intervalo-unidade="<?= h($reg['IntervaloCiclicoUnidade'] ?: 'mes') ?>"
                                                    title="Definir/agendar próxima aplicação">
                                                    <i class="bi bi-calendar-plus"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger btn-excluir-vacina"
                                                    data-id="<?= h($reg['IDRegistro']) ?>"
                                                    data-confirm="Excluir este registro de vacina?">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif ?>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif ?>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header px-4 py-3 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-journal-medical me-2 text-accent"></i>Histórico clínico</span>
                <?php if ($souAdmin): ?>
                    <a href="<?= BASE ?>/painel/animal_detalhe.php?id=<?= h($id) ?><?= $mostrarClinicoExcluidos ? '' : '&clinico=todos' ?>#historico-clinico" class="small">
                        <?= $mostrarClinicoExcluidos ? 'Ocultar excluídos' : 'Mostrar excluídos' ?>
                    </a>
                <?php endif ?>
            </div>
            <div class="card-body" id="historico-clinico">
                <?php if (empty($clinico)): ?>
                    <div class="text-center py-4 text-secondary">
                        <i class="bi bi-journal-medical fs-1 d-block mb-2 opacity-25"></i>
                        <p class="mb-0">Nenhum registro clínico.</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($clinico as $reg): ?>
                            <div class="border rounded-3 p-3<?= $reg['Ativo'] ? '' : ' opacity-75' ?>" style="border-color:var(--card-border-color) !important;" data-id-clinico="<?= h($reg['IDRegistro']) ?>">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <div>
                                        <span class="badge" style="background:var(--accent-light);color:var(--accent);"><?= h($tiposClinicoLabel[$reg['Tipo']] ?? $reg['Tipo']) ?></span>
                                        <?php if (!$reg['Ativo']): ?><span class="badge bg-secondary">Excluído</span><?php endif ?>
                                        <span class="fw-medium ms-1"><?= h($reg['Titulo']) ?></span>
                                    </div>
                                    <?php if ($souAdmin && $reg['Ativo']): ?>
                                        <button class="btn btn-sm btn-outline-danger btn-excluir-clinico"
                                            data-id="<?= h($reg['IDRegistro']) ?>"
                                            data-confirm="Excluir este registro clínico? Dá pra reativar depois em &quot;Mostrar excluídos&quot;.">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php elseif ($souAdmin): ?>
                                        <button class="btn btn-sm btn-outline-accent btn-reativar-clinico"
                                            data-id="<?= h($reg['IDRegistro']) ?>"
                                            data-confirm="Reativar este registro clínico?">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </button>
                                    <?php endif ?>
                                </div>
                                <p class="small text-secondary mb-2">
                                    <?= formatarData($reg['DataRegistro']) ?><?= $reg['NomeVeterinario'] ? ' · ' . h($reg['NomeVeterinario']) : '' ?>
                                </p>
                                <?php if ($reg['Anotacoes']): ?>
                                    <p class="small mb-2"><?= nl2br(h($reg['Anotacoes'])) ?></p>
                                <?php endif ?>
                                <?php if (!empty($reg['Anexos'])): ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($reg['Anexos'] as $anexo): ?>
                                            <a href="<?= BASE ?><?= h($anexo['CaminhoArquivo']) ?>" target="_blank" rel="noopener">
                                                <?php if (str_ends_with(strtolower($anexo['CaminhoArquivo']), '.pdf')): ?>
                                                    <span class="d-flex flex-column align-items-center justify-content-center text-secondary"
                                                          style="width:72px;height:72px;border-radius:var(--radius-btn);border:1px solid var(--card-border-color);">
                                                        <i class="bi bi-file-earmark-pdf fs-3 text-danger"></i>
                                                        <span style="font-size:.65rem;">PDF</span>
                                                    </span>
                                                <?php else: ?>
                                                    <img src="<?= BASE ?><?= h($anexo['CaminhoArquivo']) ?>" alt="Anexo"
                                                         style="width:72px;height:72px;object-fit:cover;border-radius:var(--radius-btn);border:1px solid var(--card-border-color);">
                                                <?php endif ?>
                                            </a>
                                        <?php endforeach ?>
                                    </div>
                                <?php endif ?>
                            </div>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header px-4 py-3">
                <i class="bi bi-clock-history me-2 text-accent"></i>Histórico de movimentações
            </div>
            <div class="card-body">
                <?php if (empty($movimentacoes)): ?>
                    <div class="text-center py-4 text-secondary">
                        <i class="bi bi-clock-history fs-1 d-block mb-2 opacity-25"></i>
                        <p class="mb-0">Nenhuma movimentação de agendamento ainda.</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($movimentacoes as $ev): ?>
                            <div class="border rounded-3 p-3" style="border-color:var(--card-border-color) !important;">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <div>
                                        <?= labelEventoAgendamento($ev['Tipo']) ?>
                                        <span class="fw-medium ms-1"><?= h($ev['Titulo']) ?></span>
                                    </div>
                                </div>
                                <p class="small text-secondary mb-0">
                                    <?= formatarDataHora($ev['MomentoEvento']) ?>
                                    <?= $ev['NomeUsuario'] ? ' · ' . h($ev['NomeUsuario']) : '' ?>
                                </p>
                                <?php if ($ev['Detalhes']): ?>
                                    <p class="small mb-0 mt-1"><?= h($ev['Detalhes']) ?></p>
                                <?php endif ?>
                            </div>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditarAnimal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="editar">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">Editar animal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Foto</label>
                        <input type="file" name="foto" class="form-control" accept="image/png,image/jpeg,image/webp" capture="environment">
                        <div class="form-text">JPG, PNG ou WEBP — até 5 MB. No celular, dá pra tirar a foto na hora. Deixe em branco pra manter a foto atual.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nome do animal *</label>
                        <input type="text" name="nome" class="form-control" required value="<?= h($animal['Nome']) ?>">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Raça *</label>
                            <?= campoPicker('eaRaca', 'raca', 'Selecione…', 'Buscar raça…', $animal['Raca'] ?? '', $animal['Raca'] ?? '', obrigatorio: true) ?>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Sexo *</label>
                            <?php
                                $iconeSexoAtual = match ($animal['Sexo'] ?? '') {
                                    'macho' => 'bi-gender-male',
                                    'femea' => 'bi-gender-female',
                                    default => '',
                                };
                                $textoSexoAtual = match ($animal['Sexo'] ?? '') {
                                    'macho' => 'Macho',
                                    'femea' => 'Fêmea',
                                    'indeterminado' => 'Indeterminado',
                                    default => '',
                                };
                            ?>
                            <?= campoPicker('eaSexo', 'sexo', '—', '', $animal['Sexo'] ?? '', $textoSexoAtual, obrigatorio: true, comBusca: false, iconeInicial: $iconeSexoAtual) ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cor</label>
                        <input type="text" name="cor" class="form-control" placeholder="Ex: Caramelo, Preto e branco…" value="<?= h($animal['Pelagem']) ?>">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Nascimento</label>
                            <input type="date" name="nascimento" class="form-control" data-validar="nascimento" min="<?= date('Y-m-d', strtotime('-100 years')) ?>" max="<?= date('Y-m-d') ?>" value="<?= h($animal['DataNascimento']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Peso (kg)</label>
                            <input type="text" id="eaPesoVisivel" class="form-control" data-mask="peso" data-target="eaPesoReal" placeholder="0,000" inputmode="numeric" value="<?= $animal['PesoKg'] ? h(number_format((float) $animal['PesoKg'], 3, ',', '')) : '' ?>">
                            <input type="hidden" name="peso" id="eaPesoReal" value="<?= h($animal['PesoKg']) ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Microchip</label>
                        <input type="text" name="microchip" class="form-control" value="<?= h($animal['Microchip']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Observações</label>
                        <textarea name="observacoes" class="form-control" rows="3"><?= h($animal['Observacoes']) ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-accent"><i class="bi bi-check2 me-1"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalProximaVacina" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold">Próxima aplicação: <span id="pvVacina"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Data</label>
                    <input type="date" id="pvData" class="form-control" min="2000-01-01" max="<?= date('Y-m-d', strtotime('+10 years')) ?>">
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="pvCiclica">
                    <label class="form-check-label" for="pvCiclica">Repetir automaticamente</label>
                </div>
                <div id="pvIntervaloWrap" class="row g-2 align-items-center mb-2" style="display:none;">
                    <label class="col-auto small text-secondary mb-0">A cada</label>
                    <div class="col-3">
                        <input type="number" id="pvIntervaloValor" class="form-control form-control-sm" min="1" max="120" value="12">
                    </div>
                    <div class="col-auto">
                        <select id="pvIntervaloUnidade" class="form-select form-select-sm">
                            <option value="semana">semana(s)</option>
                            <option value="mes" selected>mês(es)</option>
                            <option value="ano">ano(s)</option>
                        </select>
                    </div>
                </div>
                <p class="small text-secondary mb-0">
                    Pra pré-agendar mais de uma data futura, salve essa e repita a operação depois que ela passar —
                    ou marque "repetir automaticamente" pra deixar o sistema renovando sozinho.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="pvSalvar" class="btn btn-accent"><i class="bi bi-check2 me-1"></i> Salvar</button>
            </div>
        </div>
    </div>
</div>

<script>
var EA_RACAS = <?= json_encode($racas, JSON_UNESCAPED_UNICODE) ?>.map(function (n) { return { nome: n }; });

initPicker({
    pickerId: 'eaRacaPicker', triggerId: 'eaRacaTrigger', dropdownId: 'eaRacaDropdown',
    searchId: 'eaRacaSearch', listId: 'eaRacaList', hiddenId: 'inpeaRacaId', labelId: 'eaRacaLabel',
    items: EA_RACAS,
    chave: function (r) { return r.nome; },
    renderItem: function (r) { return { title: r.nome }; },
    matches: function (r, q) { return r.nome.toLowerCase().indexOf(q) !== -1; },
    vazioMsg: 'Nenhuma raça encontrada.',
});

initPicker({
    pickerId: 'eaSexoPicker', triggerId: 'eaSexoTrigger', dropdownId: 'eaSexoDropdown',
    searchId: 'eaSexoSearch', listId: 'eaSexoList', hiddenId: 'inpeaSexoId', labelId: 'eaSexoLabel',
    items: [
        { id: 'macho', label: 'Macho', icon: 'bi-gender-male' },
        { id: 'femea', label: 'Fêmea', icon: 'bi-gender-female' },
        { id: 'indeterminado', label: 'Indeterminado', icon: '' },
    ],
    chave: function (s) { return s.id; },
    renderItem: function (s) { return { title: s.label, icon: s.icon }; },
    matches: function (s, q) { return s.label.toLowerCase().indexOf(q) !== -1; },
    vazioMsg: 'Nada encontrado.',
});

var pvIdAtual = null;
document.querySelectorAll('.btn-editar-proxima-vacina').forEach(function (btn) {
    btn.addEventListener('click', function () {
        pvIdAtual = btn.dataset.id;
        document.getElementById('pvVacina').textContent = btn.dataset.vacina;
        document.getElementById('pvData').value = btn.dataset.proxima || '';
        document.getElementById('pvCiclica').checked = btn.dataset.ciclica === '1';
        document.getElementById('pvIntervaloValor').value = btn.dataset.intervaloValor || 12;
        document.getElementById('pvIntervaloUnidade').value = btn.dataset.intervaloUnidade || 'mes';
        document.getElementById('pvIntervaloWrap').style.display = btn.dataset.ciclica === '1' ? '' : 'none';

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalProximaVacina')).show();
    });
});

document.getElementById('pvCiclica').addEventListener('change', function () {
    document.getElementById('pvIntervaloWrap').style.display = this.checked ? '' : 'none';
});

document.getElementById('pvSalvar').addEventListener('click', function () {
    var data = document.getElementById('pvData').value;
    if (!data) {
        vsToast('Escolha uma data.', 'warning');
        return;
    }
    fetch(BASE + '/painel/api_vacina.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            acao: 'editar_proxima',
            id: pvIdAtual,
            proxima_data: data,
            ciclica: document.getElementById('pvCiclica').checked,
            intervalo_valor: parseInt(document.getElementById('pvIntervaloValor').value, 10) || 0,
            intervalo_unidade: document.getElementById('pvIntervaloUnidade').value,
            csrf_token: '<?= gerarTokenCSRF() ?>',
        }),
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (d.ok) {
            vsRecarregarPreservandoScroll();
        } else {
            vsToast(d.msg || 'Erro ao salvar.', 'danger');
        }
    })
    .catch(function () { vsToast('Falha na conexão.', 'danger'); });
});

document.querySelectorAll('.btn-excluir-vacina').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        vsConfirm(btn.dataset.confirm, function () {
            fetch(BASE + '/painel/api_vacina.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ acao: 'excluir', id: btn.dataset.id, csrf_token: '<?= gerarTokenCSRF() ?>' }),
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    document.querySelector('tr[data-id="' + btn.dataset.id + '"]')?.remove();
                    vsToast('Registro excluído.', 'success');
                } else {
                    vsToast(d.msg || 'Erro ao excluir.', 'danger');
                }
            })
            .catch(function () { vsToast('Falha na conexão.', 'danger'); });
        });
    });
});

document.querySelectorAll('.btn-excluir-clinico').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        vsConfirm(btn.dataset.confirm, function () {
            fetch(BASE + '/painel/api_clinico.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ acao: 'excluir', id: btn.dataset.id, csrf_token: '<?= gerarTokenCSRF() ?>' }),
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    document.querySelector('[data-id-clinico="' + btn.dataset.id + '"]')?.remove();
                    vsToast('Registro excluído.', 'success');
                } else {
                    vsToast(d.msg || 'Erro ao excluir.', 'danger');
                }
            })
            .catch(function () { vsToast('Falha na conexão.', 'danger'); });
        });
    });
});

document.querySelectorAll('.btn-reativar-clinico').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        vsConfirm(btn.dataset.confirm, function () {
            fetch(BASE + '/painel/api_clinico.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ acao: 'reativar', id: btn.dataset.id, csrf_token: '<?= gerarTokenCSRF() ?>' }),
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    vsToast('Registro reativado.', 'success');
                    location.reload();
                } else {
                    vsToast(d.msg || 'Erro ao reativar.', 'danger');
                }
            })
            .catch(function () { vsToast('Falha na conexão.', 'danger'); });
        });
    });
});
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
