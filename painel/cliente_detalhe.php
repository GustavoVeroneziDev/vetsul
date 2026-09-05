<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('admin', 'funcionario');

$id = trim($_GET['id'] ?? '');
if (!$id) {
    redirecionarComMensagem(BASE . '/painel/clientes.php', 'Cliente não encontrado.', 'warning');
}

// Cadastro rápido de animal via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'novo_animal') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Token inválido.', 'danger');
    }
    exigirAdmin(BASE . '/painel/cliente_detalhe.php?id=' . $id);
    $nome     = trim($_POST['nome']      ?? '');
    $especie  = trim($_POST['especie']   ?? '');
    $raca     = trim($_POST['raca']      ?? '');
    $nasc     = trim($_POST['nascimento'] ?? '');
    $sexo     = trim($_POST['sexo']      ?? '');
    $cor      = trim($_POST['cor']       ?? '');
    $peso     = trim($_POST['peso']      ?? '');
    $obs      = trim($_POST['observacoes'] ?? '');

    if ($nome === '' || $especie === '' || $raca === '' || $sexo === '') {
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Nome, espécie, raça e sexo são obrigatórios.', 'warning');
    }
    if (!dataNascimentoValida($nasc)) {
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Data de nascimento inválida — não pode ser no futuro nem passar de 100 anos atrás.', 'warning');
    }

    $foto = !empty($_FILES['foto']['tmp_name']) ? salvarImagemEnviada($_FILES['foto'], 'animais') : null;
    if (!empty($_FILES['foto']['tmp_name']) && $foto === null) {
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Foto inválida — envie um JPG, PNG ou WEBP de até 5 MB.', 'warning');
    }

    try {
        $novoId = gerarUuid();
        $pdo->prepare(
            'INSERT INTO Animais (IDAnimal, FKDono, FKEspecie, Nome, Raca, DataNascimento, Sexo, Pelagem, PesoKg, Observacoes, FotoUrl)
             VALUES (:id, :dono, :esp, :nome, :raca, :nasc, :sexo, :cor, :peso, :obs, :foto)'
        )->execute([
            ':id'   => $novoId,
            ':dono' => $id,
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
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Erro ao cadastrar animal.', 'danger');
    }
}

// Editar dados do cliente (nome/e-mail/WhatsApp) e, opcionalmente, definir
// uma nova senha na hora — sem isso, um cliente sem e-mail cadastrado (agora
// possível, já que WhatsApp virou o dado essencial) não tinha NENHUMA forma
// de recuperar o acesso se esquecesse a senha.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_cliente') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Token inválido.', 'danger');
    }
    exigirAdmin(BASE . '/painel/cliente_detalhe.php?id=' . $id);

    $nome        = trim($_POST['nome']  ?? '');
    $email       = trim($_POST['email'] ?? '');
    $tel         = trim($_POST['tel']   ?? '');
    $novaSenha   = trim($_POST['nova_senha'] ?? '');

    if ($nome === '' || $tel === '') {
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Nome e WhatsApp são obrigatórios.', 'warning');
    }
    $telSanitizado = sanitizarTelefone($tel);
    if (!$telSanitizado) {
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'WhatsApp inválido.', 'warning');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'E-mail inválido.', 'warning');
    }
    if ($novaSenha !== '' && strlen($novaSenha) < 4) {
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'A nova senha deve ter pelo menos 4 caracteres.', 'warning');
    }

    try {
        // Só é cliente de verdade — não deixa esse endpoint mexer em conta
        // de equipe/admin.
        $chkAlvo = $pdo->prepare('SELECT NivelAcesso FROM Usuarios WHERE IDUsuario = :id LIMIT 1');
        $chkAlvo->execute([':id' => $id]);
        if ($chkAlvo->fetchColumn() !== 'cliente') {
            redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Cliente não encontrado.', 'warning');
        }

        if ($email !== '') {
            $chk = $pdo->prepare('SELECT IDUsuario FROM Usuarios WHERE Email = :e AND IDUsuario != :id LIMIT 1');
            $chk->execute([':e' => $email, ':id' => $id]);
            if ($chk->fetch()) {
                redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'E-mail já cadastrado por outra conta.', 'warning');
            }
        }
        $chkTel = $pdo->prepare('SELECT IDUsuario FROM Usuarios WHERE Telefone = :t AND IDUsuario != :id LIMIT 1');
        $chkTel->execute([':t' => $telSanitizado, ':id' => $id]);
        if ($chkTel->fetch()) {
            redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Esse WhatsApp já está cadastrado por outra conta.', 'warning');
        }

        $params = [
            ':nome'  => $nome,
            ':email' => $email !== '' ? $email : null,
            ':tel'   => $telSanitizado,
            ':id'    => $id,
        ];
        $sql = 'UPDATE Usuarios SET Nome = :nome, Email = :email, Telefone = :tel';
        if ($novaSenha !== '') {
            $sql .= ', Senha = :senha';
            $params[':senha'] = password_hash($novaSenha, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE IDUsuario = :id';
        $pdo->prepare($sql)->execute($params);
        registrarAuditoria($pdo, 'cliente', $id, 'editado', $novaSenha !== '' ? 'Dados e senha atualizados' : 'Dados atualizados');

        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Cliente atualizado com sucesso!', 'success');
    } catch (PDOException $e) {
        error_log('[EditarCliente] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Erro ao atualizar.', 'danger');
    }
}

// Excluir cliente = desativação (Ativo=0), arrastando os animais dele
// junto — mantém todo o histórico, só some das listas. Reativar volta o
// cliente (não os animais dele automaticamente — cada um se reativa à
// parte, caso só alguns devam voltar).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_cliente') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Token inválido.', 'danger');
    }
    exigirAdmin(BASE . '/painel/cliente_detalhe.php?id=' . $id);
    try {
        desativarCliente($pdo, $id);
        registrarAuditoria($pdo, 'cliente', $id, 'excluido');
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Cliente excluído — os animais dele também ficaram inativos. Dá pra reativar quando quiser.', 'success');
    } catch (PDOException $e) {
        error_log('[ExcluirCliente] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Erro ao excluir.', 'danger');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'reativar_cliente') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Token inválido.', 'danger');
    }
    exigirAdmin(BASE . '/painel/cliente_detalhe.php?id=' . $id);
    try {
        $pdo->prepare('UPDATE Usuarios SET Ativo = 1 WHERE IDUsuario = :id')->execute([':id' => $id]);
        registrarAuditoria($pdo, 'cliente', $id, 'reativado');
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Cliente reativado com sucesso!', 'success');
    } catch (PDOException $e) {
        error_log('[ReativarCliente] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $id, 'Erro ao reativar.', 'danger');
    }
}

try {
    // Sem filtrar por NivelAcesso: um admin pode ser dono de animal também,
    // e essa página precisa abrir certo quando alguém clicar no dono dele.
    $stmt = $pdo->prepare("SELECT * FROM Usuarios WHERE IDUsuario = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $dono = $stmt->fetch();
    if (!$dono) {
        redirecionarComMensagem(BASE . '/painel/clientes.php', 'Cliente não encontrado.', 'warning');
    }

    // Além do que tá vindo (próxima vacina, próximo agendamento), busca
    // também o que já aconteceu — sem isso, um animal com anos de
    // histórico mas nada agendado no momento parecia idêntico a um
    // cadastro novo em folha, sem nenhuma pista de que já teve vida por
    // aqui.
    $animaisStmt = $pdo->prepare(
        'SELECT a.*, e.Nome AS NomeEspecie, e.Icone AS IconeEspecie,
                (SELECT MIN(rv.ProximaData) FROM RegistrosVacinas rv
                  WHERE rv.FKAnimal = a.IDAnimal AND rv.ProximaData IS NOT NULL) AS ProximaVacina,
                (SELECT MAX(rv2.DataAplicacao) FROM RegistrosVacinas rv2
                  WHERE rv2.FKAnimal = a.IDAnimal) AS UltimaVacinaAplicada,
                (SELECT ag.DataHoraInicio FROM Agendamentos ag
                  WHERE ag.FKAnimal = a.IDAnimal AND ag.Status IN (\'concluido\', \'faltou\', \'cancelado\')
                  ORDER BY ag.DataHoraInicio DESC LIMIT 1) AS UltimoAtendimentoData,
                (SELECT ag.Tipo FROM Agendamentos ag
                  WHERE ag.FKAnimal = a.IDAnimal AND ag.Status IN (\'concluido\', \'faltou\', \'cancelado\')
                  ORDER BY ag.DataHoraInicio DESC LIMIT 1) AS UltimoAtendimentoTipo
         FROM Animais a
         JOIN Especies e ON e.IDEspecie = a.FKEspecie
         WHERE a.FKDono = :id AND a.Ativo = 1
         ORDER BY a.Nome ASC'
    );
    $animaisStmt->execute([':id' => $id]);
    $animais = $animaisStmt->fetchAll();

    // Agendamentos ativos dos animais desse dono — pra quem atende o telefone
    // ver na hora se ele tem algo hoje ou chegando, sem precisar abrir a
    // agenda e procurar (mesmo padrão de usuario/meus_animais.php).
    $tiposAgenda = [
        'cirurgia' => 'Cirurgia', 'consulta' => 'Consulta', 'exame' => 'Exame',
        'procedimento' => 'Procedimento', 'observacao' => 'Observação', 'outro' => 'Outro',
    ];
    $agStmt = $pdo->prepare(
        "SELECT ag.*, a.Nome AS NomeAnimal, e.Icone AS IconeEspecie, v.Nome AS NomeVeterinario
         FROM Agendamentos ag
         JOIN Animais a  ON a.IDAnimal = ag.FKAnimal
         JOIN Especies e ON e.IDEspecie = a.FKEspecie
         LEFT JOIN Usuarios v ON v.IDUsuario = ag.FKVeterinario
         WHERE a.FKDono = :id AND ag.Status IN ('pendente', 'confirmado')
         ORDER BY ag.DataHoraInicio ASC"
    );
    $agStmt->execute([':id' => $id]);
    $agendamentosAtivos = $agStmt->fetchAll();

    $hojeStr = date('Y-m-d');
    $agora   = date('Y-m-d H:i:s');
    $agendamentosHoje = array_values(array_filter(
        $agendamentosAtivos,
        fn($ag) => substr($ag['DataHoraInicio'], 0, 10) === $hojeStr
    ));
    $proximoPorAnimal = [];
    foreach ($agendamentosAtivos as $ag) {
        if ($ag['DataHoraInicio'] >= $agora && !isset($proximoPorAnimal[$ag['FKAnimal']])) {
            $proximoPorAnimal[$ag['FKAnimal']] = $ag;
        }
    }

    $especies = $pdo->query('SELECT * FROM Especies ORDER BY Ordem ASC')->fetchAll();
    $racas    = $pdo->query('SELECT IDRaca, FKEspecie, Nome FROM Racas ORDER BY Ordem ASC')->fetchAll();
} catch (PDOException $e) {
    error_log('[ClienteDetalhe] ' . $e->getMessage());
    $animais  = [];
    $especies = [];
    $racas    = [];
    $tiposAgenda = [];
    $agendamentosHoje = [];
    $proximoPorAnimal = [];
}

$souAdmin     = ($_SESSION['nivel_acesso'] ?? '') === 'admin';
$paginaTitulo = h($dono['Nome']);
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="<?= BASE ?>/painel/clientes.php" onclick="voltarInteligente(event)" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="fw-bold mb-0">
        <?= h($dono['Nome']) ?>
        <?php if (!$dono['Ativo']): ?><span class="badge bg-secondary align-middle">Inativo</span><?php endif ?>
    </h4>
</div>

<?php if (!empty($agendamentosHoje)): ?>
    <h6 class="fw-semibold text-secondary mb-2"><i class="bi bi-calendar-event me-1"></i>Hoje</h6>
    <div class="mb-4">
        <?php foreach ($agendamentosHoje as $ag) renderCardAgendamento($ag, $tiposAgenda) ?>
    </div>
<?php endif ?>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card p-4">
            <div class="text-center mb-3 position-relative">
                <?php if ($souAdmin): ?>
                    <button class="btn btn-sm btn-outline-accent position-absolute top-0 end-0" data-bs-toggle="modal" data-bs-target="#modalEditarCliente">
                        <i class="bi bi-pencil"></i>
                    </button>
                <?php endif ?>
                <div class="avatar-circle mx-auto"><?= h(mb_strtoupper(mb_substr($dono['Nome'], 0, 1))) ?></div>
                <h5 class="fw-bold mt-2 mb-0"><?= h($dono['Nome']) ?></h5>
                <p class="small text-secondary mb-0">Cliente desde <?= formatarData($dono['MomentoRegistro']) ?></p>
            </div>
            <dl class="mb-3">
                <dt class="small text-secondary">E-mail</dt>
                <dd><?= $dono['Email'] ? h($dono['Email']) : '<span class="text-secondary">Não informado</span>' ?></dd>
                <dt class="small text-secondary">WhatsApp</dt>
                <dd>
                    <?php if ($dono['Telefone']): ?>
                        <?= h(formatarTelefoneExibicao($dono['Telefone'])) ?>
                    <?php else: ?>
                        <span class="text-secondary">Não informado</span>
                    <?php endif ?>
                </dd>
                <dt class="small text-secondary">Total de animais</dt>
                <dd><?= count($animais) ?></dd>
            </dl>
            <?php if ($dono['Telefone']): ?>
                <a href="<?= h(waLink($dono['Telefone'])) ?>" target="_blank" class="btn btn-outline-accent w-100 mb-2">
                    <i class="bi bi-whatsapp me-1"></i> Conversar no WhatsApp
                </a>
            <?php endif ?>
            <?php if ($souAdmin): ?>
                <?php if ($dono['Ativo']): ?>
                    <form method="POST" data-confirm="Excluir <?= h($dono['Nome']) ?>? Os animais dele também ficam inativos e os agendamentos futuros são cancelados, mas o histórico é mantido — dá pra reativar depois.">
                        <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                        <input type="hidden" name="acao" value="excluir_cliente">
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="bi bi-trash me-1"></i> Excluir cliente
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" data-confirm="Reativar <?= h($dono['Nome']) ?>?">
                        <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                        <input type="hidden" name="acao" value="reativar_cliente">
                        <button type="submit" class="btn btn-accent w-100">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Reativar cliente
                        </button>
                    </form>
                <?php endif ?>
            <?php endif ?>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between px-4 py-3">
                <span><i class="bi bi-clipboard2-pulse me-2 text-accent"></i>Animais</span>
                <?php if ($souAdmin): ?>
                    <button class="btn btn-sm btn-accent" data-bs-toggle="modal" data-bs-target="#modalNovoAnimal">
                        <i class="bi bi-plus-lg me-1"></i> Novo animal
                    </button>
                <?php endif ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($animais)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="bi bi-emoji-smile fs-1 d-block mb-2 opacity-25"></i>
                        <p>Nenhum animal cadastrado.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead style="background:var(--bg-hover);">
                                <tr>
                                    <th class="px-4 py-3">Animal</th>
                                    <th class="d-none d-md-table-cell">Espécie</th>
                                    <th>Próximo agendamento</th>
                                    <th>Próxima vacina</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($animais as $a): $prox = $proximoPorAnimal[$a['IDAnimal']] ?? null; ?>
                                    <tr>
                                        <td class="px-4 fw-medium"><?= especieIconeHtml($a['IconeEspecie']) ?> <?= h($a['Nome']) ?></td>
                                        <td class="d-none d-md-table-cell small"><?= h($a['NomeEspecie']) ?><?= $a['Raca'] ? ' · ' . h($a['Raca']) : '' ?></td>
                                        <td class="small">
                                            <?php if ($prox): ?>
                                                <span class="badge" style="background:var(--accent-light);color:var(--accent);"><?= h($tiposAgenda[$prox['Tipo']] ?? $prox['Tipo']) ?></span>
                                                <?= substr($prox['DataHoraInicio'], 0, 10) === date('Y-m-d') ? 'Hoje' : formatarData($prox['DataHoraInicio']) ?>
                                                às <?= date('H:i', strtotime($prox['DataHoraInicio'])) ?>
                                            <?php elseif ($a['UltimoAtendimentoData']): ?>
                                                <span class="text-secondary">
                                                    Último: <?= h($tiposAgenda[$a['UltimoAtendimentoTipo']] ?? $a['UltimoAtendimentoTipo']) ?>
                                                    · <?= formatarData($a['UltimoAtendimentoData']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-secondary">Nenhum atendimento</span>
                                            <?php endif ?>
                                        </td>
                                        <td>
                                            <?php if ($a['ProximaVacina']): ?>
                                                <?= labelSituacaoVacina($a['ProximaVacina']) ?>
                                                <span class="small text-secondary ms-1"><?= formatarData($a['ProximaVacina']) ?></span>
                                            <?php elseif ($a['UltimaVacinaAplicada']): ?>
                                                <span class="badge" style="background:var(--accent-light);color:var(--accent);">
                                                    Última: <?= formatarData($a['UltimaVacinaAplicada']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Nenhuma vacina</span>
                                            <?php endif ?>
                                        </td>
                                        <td>
                                            <a href="<?= BASE ?>/painel/animal_detalhe.php?id=<?= h($a['IDAnimal']) ?>" class="btn btn-sm btn-outline-accent">
                                                <i class="bi bi-eye"></i><span class="d-none d-md-inline ms-1">Ver</span>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif ?>
            </div>
        </div>
    </div>
</div>

<?php if ($souAdmin): ?>
<div class="modal fade" id="modalEditarCliente" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="editar_cliente">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">Editar cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome completo *</label>
                        <input type="text" name="nome" class="form-control" required value="<?= h($dono['Nome']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">WhatsApp *</label>
                        <input type="tel" name="tel" class="form-control" data-mask="tel" placeholder="(11) 99999-9999" required
                            value="<?= h(preg_replace('/^55(?=\d{10,11}$)/', '', (string) $dono['Telefone'])) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">E-mail <span class="text-secondary">(opcional)</span></label>
                        <input type="email" name="email" class="form-control" value="<?= h($dono['Email']) ?>">
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Nova senha</label>
                        <input type="password" name="nova_senha" class="form-control" minlength="4" maxlength="72" autocomplete="new-password">
                        <div class="form-text">Deixe em branco pra manter a senha atual. Preenchendo, troca na hora — sem e-mail nem confirmação do cliente.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-accent">
                        <i class="bi bi-check2 me-1"></i> Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
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
var NA_ESPECIES = <?= json_encode(array_map(fn($e) => [
    'id' => $e['IDEspecie'], 'nome' => $e['Nome'], 'icone' => $e['Icone'],
], $especies), JSON_UNESCAPED_UNICODE) ?>;
var NA_RACAS = <?= json_encode(array_map(fn($r) => [
    'especie' => $r['FKEspecie'], 'nome' => $r['Nome'],
], $racas), JSON_UNESCAPED_UNICODE) ?>;

initAnimalPickers('na', NA_ESPECIES, NA_RACAS);

// O campo de WhatsApp do modal de editar já vem preenchido direto pelo PHP
// (não por JS, como no de equipe) — vsMascaraTel() só formata a partir de um
// evento "input", então sem isso o número aparecia sem máscara até a pessoa
// mexer no campo.
var telEditarCliente = document.querySelector('#modalEditarCliente [name="tel"]');
if (telEditarCliente && telEditarCliente.value) {
    telEditarCliente.dispatchEvent(new Event('input'));
}
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
