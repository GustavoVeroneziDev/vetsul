<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('admin');

$cargos = [
    'veterinario' => 'Veterinário',
    'vendedor'    => 'Vendedor',
    'atendente'   => 'Atendente',
    'auxiliar'    => 'Auxiliar',
    'outro'       => 'Outro',
];

// "Dev" = admin sem cargo — não é dono/veterinário atendendo, é quem
// mantém o sistema. Só esse perfil pode editar nome/telefone/cargo/senha
// de qualquer outro membro direto, sem passar pelo fluxo de e-mail com
// link de redefinição (o resto, incluindo outros admins, não pode).
$souDev = ($_SESSION['nivel_acesso'] ?? '') === 'admin' && ($_SESSION['cargo'] ?? '') === '';

// Cadastro de funcionário via POST — sempre nível "funcionario" (só vê,
// não escreve/edita/exclui). Nível "admin" é reservado pros donos, setado
// direto no banco, não por aqui.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastrar') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'Token inválido.', 'danger');
    }
    $nome        = trim($_POST['nome']  ?? '');
    $email       = trim($_POST['email'] ?? '');
    $tel         = trim($_POST['tel']   ?? '');
    $cargo       = trim($_POST['cargo'] ?? '');
    $senhaManual = trim($_POST['senha'] ?? '');

    // WhatsApp é o dado essencial — e-mail vira opcional (mesma lógica do
    // cadastro de cliente).
    if ($nome === '' || $tel === '') {
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'Nome e WhatsApp são obrigatórios.', 'warning');
    }
    $telSanitizado = sanitizarTelefone($tel);
    if (!$telSanitizado) {
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'WhatsApp inválido.', 'warning');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'E-mail inválido.', 'warning');
    }
    if ($email === '' && $senhaManual === '') {
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'Sem e-mail cadastrado, defina uma senha manualmente.', 'warning');
    }
    if ($senhaManual !== '' && strlen($senhaManual) < 4) {
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'A senha deve ter pelo menos 4 caracteres.', 'warning');
    }
    if ($cargo !== '' && !isset($cargos[$cargo])) {
        $cargo = '';
    }

    try {
        if ($email !== '') {
            $chk = $pdo->prepare('SELECT IDUsuario FROM Usuarios WHERE Email = :e LIMIT 1');
            $chk->execute([':e' => $email]);
            if ($chk->fetch()) {
                redirecionarComMensagem(BASE . '/painel/equipe.php', 'E-mail já cadastrado.', 'warning');
            }
        }
        $chkTel = $pdo->prepare('SELECT IDUsuario FROM Usuarios WHERE Telefone = :t LIMIT 1');
        $chkTel->execute([':t' => $telSanitizado]);
        if ($chkTel->fetch()) {
            redirecionarComMensagem(BASE . '/painel/equipe.php', 'Esse WhatsApp já está cadastrado.', 'warning');
        }

        $senha = $senhaManual !== '' ? $senhaManual : bin2hex(random_bytes(8));

        $novoId = gerarUuid();
        $stmt = $pdo->prepare(
            'INSERT INTO Usuarios (IDUsuario, Nome, Email, Telefone, Senha, NivelAcesso, Cargo)
             VALUES (:id,:nome,:email,:tel,:senha,\'funcionario\',:cargo)'
        );
        $stmt->execute([
            ':id'    => $novoId,
            ':nome'  => $nome,
            ':email' => $email !== '' ? $email : null,
            ':tel'   => $telSanitizado,
            ':senha' => password_hash($senha, PASSWORD_DEFAULT),
            ':cargo' => $cargo !== '' ? $cargo : null,
        ]);
        registrarAuditoria($pdo, 'funcionario', $novoId, 'criado', $nome);

        if ($email !== '' && $senhaManual === '') {
            $token = criarTokenResetSenha($pdo, $novoId);
            $link  = urlAbsoluta('/usuario/redefinir_senha.php?id=' . $token['id'] . '&t=' . $token['token']);
            $corpo = '<p>Olá, ' . h($nome) . '!</p>'
                   . '<p>Uma conta foi criada para você em ' . h(APP_NOME) . '. Clique no botão abaixo para definir sua senha de acesso:</p>'
                   . '<p style="text-align:center;margin:24px 0;">'
                   . '<a href="' . h($link) . '" style="background:#0d9488;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">Definir senha</a>'
                   . '</p>'
                   . '<p style="font-size:13px;color:#6b7c78;">Esse link expira em 24 horas.</p>';
            $enviou = enviarEmail($email, 'Defina sua senha — ' . APP_NOME, emailHtml('Defina sua senha', $corpo));

            $msg = $enviou
                ? 'Funcionário cadastrado com sucesso! Enviamos um e-mail para ele definir a senha.'
                : 'Funcionário cadastrado, mas não conseguimos enviar o e-mail de definição de senha — confira o endereço.';
            redirecionarComMensagem(BASE . '/painel/equipe.php', $msg, $enviou ? 'success' : 'warning');
        }

        redirecionarComMensagem(BASE . '/painel/equipe.php', 'Funcionário cadastrado com sucesso! Repasse a senha combinada pra ele.', 'success');
    } catch (PDOException $e) {
        error_log('[CadastroFuncionario] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'Erro ao cadastrar.', 'danger');
    }
}

// Edição direta (nome/telefone/cargo/senha) — só o dev, sem confirmação
// da pessoa dona da conta. Não mexe em NivelAcesso/Email por aqui.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_membro') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'Token inválido.', 'danger');
    }
    if (!$souDev) {
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'Só o desenvolvedor do sistema pode editar outros membros direto.', 'danger');
    }
    $idAlvo    = trim($_POST['id']   ?? '');
    $nome      = trim($_POST['nome'] ?? '');
    $tel       = trim($_POST['tel']  ?? '');
    $cargo     = trim($_POST['cargo'] ?? '');
    $novaSenha = $_POST['nova_senha'] ?? '';

    if ($idAlvo === '' || $nome === '') {
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'Nome é obrigatório.', 'warning');
    }
    if ($cargo !== '' && !isset($cargos[$cargo])) {
        $cargo = '';
    }
    if ($novaSenha !== '' && strlen($novaSenha) < 4) {
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'A nova senha deve ter pelo menos 4 caracteres.', 'warning');
    }

    try {
        // Só mexe em quem é equipe de verdade (admin/funcionario) — não
        // deixa esse endpoint ser usado em cima de conta de cliente.
        $chkStmt = $pdo->prepare("SELECT NivelAcesso FROM Usuarios WHERE IDUsuario = :id LIMIT 1");
        $chkStmt->execute([':id' => $idAlvo]);
        $nivelAlvo = $chkStmt->fetchColumn();
        if (!in_array($nivelAlvo, ['admin', 'funcionario'], true)) {
            redirecionarComMensagem(BASE . '/painel/equipe.php', 'Membro não encontrado.', 'warning');
        }

        $params = [
            ':nome'  => $nome,
            ':tel'   => $tel !== '' ? sanitizarTelefone($tel) : null,
            ':cargo' => $cargo !== '' ? $cargo : null,
            ':id'    => $idAlvo,
        ];
        $sql = 'UPDATE Usuarios SET Nome=:nome, Telefone=:tel, Cargo=:cargo';
        if ($novaSenha !== '') {
            $sql .= ', Senha=:senha';
            $params[':senha'] = password_hash($novaSenha, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE IDUsuario=:id';
        $pdo->prepare($sql)->execute($params);
        registrarAuditoria($pdo, 'funcionario', $idAlvo, 'editado', $novaSenha !== '' ? 'Dados e senha atualizados' : 'Dados atualizados');

        redirecionarComMensagem(BASE . '/painel/equipe.php', 'Membro atualizado com sucesso!', 'success');
    } catch (PDOException $e) {
        error_log('[EditarMembroEquipe] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'Erro ao atualizar.', 'danger');
    }
}

// Excluir membro = desativação (Ativo=0) — bloqueia o login (já checado em
// processa_login.php) e some das listas/pickers de veterinário, sem apagar
// nada do histórico (quem registrou o quê continua intacto). Deletar outro
// admin exige ser "dev" (mesma trava de editar_membro) e ninguém se
// desativa sozinho por engano.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['acao'] ?? '', ['desativar_membro', 'reativar_membro'], true)) {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'Token inválido.', 'danger');
    }
    $idAlvo = trim($_POST['id'] ?? '');
    $ligar  = ($_POST['acao'] ?? '') === 'reativar_membro';

    if ($idAlvo === $_SESSION['usuario_id']) {
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'Você não pode excluir sua própria conta.', 'warning');
    }

    try {
        $chkStmt = $pdo->prepare('SELECT NivelAcesso FROM Usuarios WHERE IDUsuario = :id LIMIT 1');
        $chkStmt->execute([':id' => $idAlvo]);
        $nivelAlvo = $chkStmt->fetchColumn();
        if (!in_array($nivelAlvo, ['admin', 'funcionario'], true)) {
            redirecionarComMensagem(BASE . '/painel/equipe.php', 'Membro não encontrado.', 'warning');
        }
        if ($nivelAlvo === 'admin' && !$souDev) {
            redirecionarComMensagem(BASE . '/painel/equipe.php', 'Só o desenvolvedor do sistema pode excluir outro admin.', 'danger');
        }

        $pdo->prepare('UPDATE Usuarios SET Ativo = :ativo WHERE IDUsuario = :id')
            ->execute([':ativo' => $ligar ? 1 : 0, ':id' => $idAlvo]);
        registrarAuditoria($pdo, 'funcionario', $idAlvo, $ligar ? 'reativado' : 'excluido');

        redirecionarComMensagem(BASE . '/painel/equipe.php', $ligar ? 'Membro reativado com sucesso!' : 'Membro excluído — o login fica bloqueado, mas o histórico é mantido.', 'success');
    } catch (PDOException $e) {
        error_log('[DesativarMembroEquipe] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/painel/equipe.php', 'Erro ao salvar.', 'danger');
    }
}

$statusF = in_array($_GET['status'] ?? '', ['ativos', 'inativos', 'todos'], true) ? $_GET['status'] : 'ativos';
$statusLabels = ['ativos' => 'Ativos', 'inativos' => 'Excluídos', 'todos' => 'Todos'];
$statusCondicao = match ($statusF) {
    'inativos' => 'Ativo = 0',
    'todos'    => '1=1',
    default    => 'Ativo = 1',
};

try {
    // Todo funcionario entra (independente de ter cargo definido ou não)
    // + admin, mas só quem tiver cargo (mostra os donos que também
    // atendem, ex: José/Dayvid como veterinário — sem cargo, um admin
    // "só de sistema" não precisa aparecer no quadro da equipe).
    $vets = $pdo->query(
        "SELECT IDUsuario, Nome, Email, Telefone, Cargo, NivelAcesso, MomentoRegistro, Ativo
         FROM Usuarios
         WHERE {$statusCondicao}
           AND (NivelAcesso = 'funcionario' OR (NivelAcesso = 'admin' AND Cargo IS NOT NULL))
         ORDER BY Nome ASC"
    )->fetchAll();
} catch (PDOException $e) {
    error_log('[Equipe] ' . $e->getMessage());
    $vets = [];
}

$paginaTitulo = 'Equipe';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <h4 class="fw-bold mb-0">Equipe <span class="text-secondary small">(<?= count($vets) ?>)</span></h4>
    <button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoFuncionario">
        <i class="bi bi-person-plus me-1"></i> Novo funcionário
    </button>
</div>
<p class="text-secondary small mb-4">
    <i class="bi bi-info-circle me-1"></i>
    Funcionário só visualiza — agenda, animais, clientes, catálogo. Criar, editar e excluir é só pra administrador.
</p>

<form class="row g-2 mb-4" method="GET">
    <div class="col-sm-4 col-md-3">
        <select name="status" class="form-select" onchange="this.form.submit()">
            <?php foreach ($statusLabels as $valor => $label): ?>
                <option value="<?= h($valor) ?>" <?= $statusF === $valor ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach ?>
        </select>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($vets)): ?>
            <div class="text-center py-5 text-secondary">
                <i class="bi bi-person-badge fs-1 d-block mb-2 opacity-25"></i>
                <p>Nenhum funcionário cadastrado.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead style="background:var(--bg-hover);">
                        <tr>
                            <th class="px-4 py-3">Nome</th>
                            <th>Nível</th>
                            <th>Cargo</th>
                            <th class="d-none d-md-table-cell email-cell">E-mail</th>
                            <th class="d-none d-md-table-cell">WhatsApp</th>
                            <th class="d-none d-md-table-cell">Cadastro</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vets as $v): ?>
                            <tr>
                                <td class="px-4 fw-medium">
                                    <?= h($v['Nome']) ?>
                                    <?php if (!$v['Ativo']): ?><span class="badge bg-secondary">Excluído</span><?php endif ?>
                                </td>
                                <td class="small">
                                    <?php if ($v['NivelAcesso'] === 'admin'): ?>
                                        <span class="badge bg-secondary">Admin</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:var(--bg-hover);color:var(--text-secondary);">Funcionário</span>
                                    <?php endif ?>
                                </td>
                                <td class="small">
                                    <?php if ($v['Cargo'] && isset($cargos[$v['Cargo']])): ?>
                                        <span class="badge" style="background:var(--accent-light);color:var(--accent);"><?= h($cargos[$v['Cargo']]) ?></span>
                                    <?php else: ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif ?>
                                </td>
                                <td class="d-none d-md-table-cell text-secondary small email-cell">
                                    <?php if ($v['Email']): ?>
                                        <span title="<?= h($v['Email']) ?>"><?= h($v['Email']) ?></span>
                                    <?php else: ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif ?>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <?php if ($v['Telefone']): ?>
                                        <a href="<?= h(waLink($v['Telefone'])) ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                            <i class="bi bi-whatsapp"></i> <?= h(formatarTelefoneExibicao($v['Telefone'])) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif ?>
                                </td>
                                <td class="d-none d-md-table-cell small text-secondary"><?= formatarData($v['MomentoRegistro']) ?></td>
                                <td class="text-end text-nowrap">
                                    <?php if ($souDev): ?>
                                        <button class="btn btn-sm btn-outline-accent"
                                            onclick='abrirModalEditarMembro(<?= json_encode($v, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    <?php endif ?>
                                    <?php
                                        // Excluir admin exige ser dev, e ninguém exclui a própria conta —
                                        // some o botão em vez de deixar clicar num caminho que só volta com erro.
                                        $podeExcluir = $v['IDUsuario'] !== $_SESSION['usuario_id']
                                            && ($v['NivelAcesso'] !== 'admin' || $souDev);
                                    ?>
                                    <?php if ($podeExcluir && $v['Ativo']): ?>
                                        <form method="POST" class="d-inline" data-confirm="Excluir <?= h($v['Nome']) ?>? O login fica bloqueado, mas o histórico é mantido — dá pra reativar depois.">
                                            <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                                            <input type="hidden" name="acao" value="desativar_membro">
                                            <input type="hidden" name="id" value="<?= h($v['IDUsuario']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($podeExcluir && !$v['Ativo']): ?>
                                        <form method="POST" class="d-inline" data-confirm="Reativar <?= h($v['Nome']) ?>?">
                                            <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                                            <input type="hidden" name="acao" value="reativar_membro">
                                            <input type="hidden" name="id" value="<?= h($v['IDUsuario']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-accent">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                        </form>
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

<div class="modal fade" id="modalNovoFuncionario" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="cadastrar">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">Cadastrar funcionário</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome completo *</label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">WhatsApp *</label>
                        <input type="tel" name="tel" class="form-control" data-mask="tel" placeholder="(11) 99999-9999" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">E-mail <span class="text-secondary">(opcional)</span></label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cargo</label>
                        <?= campoPicker('funcCargo', 'cargo', 'Selecione…', '', obrigatorio: false, comBusca: false) ?>
                        <div class="form-text">Só usado pra mostrar esse funcionário como opção de "veterinário responsável" num agendamento — não muda o que ele pode fazer no sistema.</div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Senha <span class="text-secondary">(opcional)</span></label>
                        <input type="password" name="senha" class="form-control" minlength="4" maxlength="72" autocomplete="new-password">
                        <div class="form-text" id="funcSenhaAjuda">
                            Deixe em branco pra gerar uma senha aleatória e mandar por e-mail um link pra ele definir a dele.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-accent">
                        <i class="bi bi-person-plus me-1"></i> Cadastrar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($souDev): ?>
<div class="modal fade" id="modalEditarMembro" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="editar_membro">
                <input type="hidden" name="id" id="editId">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">Editar <span id="editNomeAtual"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome completo *</label>
                        <input type="text" name="nome" id="editNome" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">WhatsApp</label>
                        <input type="tel" name="tel" id="editTel" class="form-control" data-mask="tel" placeholder="(11) 99999-9999">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cargo</label>
                        <?= campoPicker('editCargo', 'cargo', 'Selecione…', '', obrigatorio: false, comBusca: false) ?>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Nova senha</label>
                        <input type="password" name="nova_senha" id="editSenha" class="form-control" minlength="4" maxlength="72" autocomplete="new-password">
                        <div class="form-text">Deixe em branco pra manter a senha atual. Preenchendo, troca na hora — sem e-mail nem confirmação da pessoa.</div>
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

<script>
var CARGOS = <?= json_encode(array_map(fn($valor, $label) => [
    'id' => $valor, 'nome' => $label,
], array_keys($cargos), $cargos), JSON_UNESCAPED_UNICODE) ?>;

// Sem e-mail não tem como mandar link de "definir senha" — deixa claro que
// nesse caso a senha passa a ser obrigatória.
(function () {
    var modal = document.getElementById('modalNovoFuncionario');
    if (!modal) return;
    var campoEmail = modal.querySelector('[name="email"]');
    var ajuda = document.getElementById('funcSenhaAjuda');
    function atualizar() {
        ajuda.textContent = campoEmail.value.trim() === ''
            ? 'Sem e-mail, defina a senha aqui — não tem como mandar link de definição por e-mail.'
            : 'Deixe em branco pra gerar uma senha aleatória e mandar por e-mail um link pra ele definir a dele.';
    }
    campoEmail.addEventListener('input', atualizar);
    atualizar();
})();

initPicker({
    pickerId: 'funcCargoPicker', triggerId: 'funcCargoTrigger', dropdownId: 'funcCargoDropdown',
    searchId: 'funcCargoSearch', listId: 'funcCargoList', hiddenId: 'inpfuncCargoId', labelId: 'funcCargoLabel',
    items: CARGOS,
    chave: function (c) { return c.id; },
    renderItem: function (c) { return { title: c.nome }; },
    matches: function (c, q) { return c.nome.toLowerCase().indexOf(q) !== -1; },
    vazioMsg: 'Nada encontrado.',
});

<?php if ($souDev): ?>
var editCargoPk = initPicker({
    pickerId: 'editCargoPicker', triggerId: 'editCargoTrigger', dropdownId: 'editCargoDropdown',
    searchId: 'editCargoSearch', listId: 'editCargoList', hiddenId: 'inpeditCargoId', labelId: 'editCargoLabel',
    items: CARGOS,
    chave: function (c) { return c.id; },
    renderItem: function (c) { return { title: c.nome }; },
    matches: function (c, q) { return c.nome.toLowerCase().indexOf(q) !== -1; },
    vazioMsg: 'Nada encontrado.',
});

function abrirModalEditarMembro(dados) {
    document.getElementById('editNomeAtual').textContent = dados.Nome;
    document.getElementById('editId').value    = dados.IDUsuario;
    document.getElementById('editNome').value  = dados.Nome;
    document.getElementById('editSenha').value = '';

    // Telefone é salvo com "55" na frente (padrão de sanitizarTelefone()) —
    // tira isso antes de preencher, senão mostra sem máscara nenhuma; o
    // "input" manual dispara o vsMascaraTel() pra formatar igual quando a
    // pessoa digita.
    var telField  = document.getElementById('editTel');
    var telDigits = (dados.Telefone || '').replace(/\D/g, '');
    if (telDigits.length === 13 && telDigits.indexOf('55') === 0) {
        telDigits = telDigits.slice(2);
    }
    telField.value = telDigits;
    telField.dispatchEvent(new Event('input'));
    var cargoAtual = CARGOS.filter(function (c) { return c.id === dados.Cargo; })[0];
    if (cargoAtual) {
        editCargoPk.selecionar(cargoAtual);
    } else {
        editCargoPk.limpar();
    }
    new bootstrap.Modal(document.getElementById('modalEditarMembro')).show();
}
<?php endif ?>
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
