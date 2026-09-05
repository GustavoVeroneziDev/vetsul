<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('admin', 'funcionario');

// Cadastro rápido via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastrar') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/clientes.php', 'Token inválido.', 'danger');
    }
    exigirAdmin(BASE . '/painel/clientes.php');
    $nome        = trim($_POST['nome']  ?? '');
    $email       = trim($_POST['email'] ?? '');
    $tel         = trim($_POST['tel']   ?? '');
    $senhaManual = trim($_POST['senha'] ?? '');

    // WhatsApp é o dado essencial — muito cliente de clínica não confere
    // e-mail nunca, mas sempre tem WhatsApp. E-mail vira opcional.
    if ($nome === '' || $tel === '') {
        redirecionarComMensagem(BASE . '/painel/clientes.php', 'Nome e WhatsApp são obrigatórios.', 'warning');
    }
    $telSanitizado = sanitizarTelefone($tel);
    if (!$telSanitizado) {
        redirecionarComMensagem(BASE . '/painel/clientes.php', 'WhatsApp inválido.', 'warning');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirecionarComMensagem(BASE . '/painel/clientes.php', 'E-mail inválido.', 'warning');
    }
    // Sem e-mail não tem como mandar link de "definir senha" — a senha
    // precisa vir definida na hora, direto pelo admin.
    if ($email === '' && $senhaManual === '') {
        redirecionarComMensagem(BASE . '/painel/clientes.php', 'Sem e-mail cadastrado, defina uma senha manualmente.', 'warning');
    }
    if ($senhaManual !== '' && strlen($senhaManual) < 4) {
        redirecionarComMensagem(BASE . '/painel/clientes.php', 'A senha deve ter pelo menos 4 caracteres.', 'warning');
    }

    try {
        if ($email !== '') {
            $chk = $pdo->prepare('SELECT IDUsuario FROM Usuarios WHERE Email = :e LIMIT 1');
            $chk->execute([':e' => $email]);
            if ($chk->fetch()) {
                redirecionarComMensagem(BASE . '/painel/clientes.php', 'E-mail já cadastrado.', 'warning');
            }
        }
        $chkTel = $pdo->prepare('SELECT IDUsuario FROM Usuarios WHERE Telefone = :t LIMIT 1');
        $chkTel->execute([':t' => $telSanitizado]);
        if ($chkTel->fetch()) {
            redirecionarComMensagem(BASE . '/painel/clientes.php', 'Esse WhatsApp já está cadastrado.', 'warning');
        }

        // Só gera senha aleatória (pra ir junto do e-mail de definição) se
        // ninguém digitou uma na hora — senha manual sempre tem prioridade.
        $senha = $senhaManual !== '' ? $senhaManual : bin2hex(random_bytes(8));

        $novoId = gerarUuid();
        $stmt = $pdo->prepare(
            'INSERT INTO Usuarios (IDUsuario, Nome, Email, Telefone, Senha, NivelAcesso)
             VALUES (:id,:nome,:email,:tel,:senha,\'cliente\')'
        );
        $stmt->execute([
            ':id'    => $novoId,
            ':nome'  => $nome,
            ':email' => $email !== '' ? $email : null,
            ':tel'   => $telSanitizado,
            ':senha' => password_hash($senha, PASSWORD_DEFAULT),
        ]);
        registrarAuditoria($pdo, 'cliente', $novoId, 'criado', $nome);

        // Só manda o e-mail de "definir senha" quando tem e-mail E a senha
        // não foi definida na mão — senha manual já resolve tudo sozinha,
        // mandar o link por cima só ia confundir com uma segunda senha.
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
                ? 'Cliente cadastrado com sucesso! Enviamos um e-mail para ele definir a senha.'
                : 'Cliente cadastrado, mas não conseguimos enviar o e-mail de definição de senha — confira o endereço.';
            redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $novoId, $msg, $enviou ? 'success' : 'warning');
        }

        redirecionarComMensagem(BASE . '/painel/cliente_detalhe.php?id=' . $novoId, 'Cliente cadastrado com sucesso! Repasse a senha combinada pra ele.', 'success');
    } catch (PDOException $e) {
        error_log('[CadastroDono] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/painel/clientes.php', 'Erro ao cadastrar.', 'danger');
    }
}

$busca   = trim($_GET['q'] ?? '');
$statusF = in_array($_GET['status'] ?? '', ['ativos', 'inativos', 'todos'], true) ? $_GET['status'] : 'ativos';
$pag     = max(1, (int) ($_GET['pag'] ?? 1));
$por     = 20;
$off     = ($pag - 1) * $por;

$statusLabels = ['ativos' => 'Ativos', 'inativos' => 'Excluídos', 'todos' => 'Todos'];

try {
    $where  = match ($statusF) {
        'inativos' => "WHERE u.NivelAcesso = 'cliente' AND u.Ativo = 0",
        'todos'    => "WHERE u.NivelAcesso = 'cliente'",
        default    => "WHERE u.NivelAcesso = 'cliente' AND u.Ativo = 1",
    };
    $params = [];
    if ($busca !== '') {
        // Prepare nativo (EMULATE_PREPARES=false) não aceita o mesmo
        // placeholder nomeado repetido — precisa de um por ocorrência,
        // mesmo repetindo o valor.
        $where .= ' AND (u.Nome LIKE :q1 OR u.Email LIKE :q2 OR u.Telefone LIKE :q3)';
        $curinga = '%' . $busca . '%';
        $params[':q1'] = $curinga;
        $params[':q2'] = $curinga;
        $params[':q3'] = $curinga;
    }

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM Usuarios u {$where}");
    $cntStmt->execute($params);
    $total = (int) $cntStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT u.IDUsuario, u.Nome, u.Email, u.Telefone, u.MomentoRegistro, u.Ativo,
                COUNT(a.IDAnimal) AS TotalAnimais
         FROM Usuarios u
         LEFT JOIN Animais a ON a.FKDono = u.IDUsuario AND a.Ativo = 1
         {$where}
         GROUP BY u.IDUsuario
         ORDER BY u.Nome ASC
         LIMIT :lim OFFSET :off"
    );
    $params[':lim'] = $por;
    $params[':off'] = $off;
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, in_array($k, [':lim', ':off']) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $donos = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[Clientes] ' . $e->getMessage());
    $donos = [];
    $total = 0;
}

$totalPag = max(1, (int) ceil($total / $por));

$souAdmin     = ($_SESSION['nivel_acesso'] ?? '') === 'admin';
$paginaTitulo = 'Clientes';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <h4 class="fw-bold mb-0">Clientes <span class="text-secondary small">(<?= number_format($total) ?>)</span></h4>
    <?php if ($souAdmin): ?>
        <button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoDono">
            <i class="bi bi-person-plus me-1"></i> Novo cliente
        </button>
    <?php endif ?>
</div>

<form class="row g-2 mb-4" method="GET">
    <div class="col-sm-8">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" name="q" class="form-control" placeholder="Buscar por nome, e-mail ou telefone..."
                value="<?= h($busca) ?>">
            <button class="btn btn-accent" type="submit">Buscar</button>
            <?php if ($busca): ?>
                <a href="<?= BASE ?>/painel/clientes.php" class="btn btn-outline-secondary">Limpar</a>
            <?php endif ?>
        </div>
    </div>
    <div class="col-sm-4">
        <select name="status" class="form-select" onchange="this.form.submit()">
            <?php foreach ($statusLabels as $valor => $label): ?>
                <option value="<?= h($valor) ?>" <?= $statusF === $valor ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach ?>
        </select>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($donos)): ?>
            <div class="text-center py-5 text-secondary">
                <i class="bi bi-people fs-1 d-block mb-2 opacity-25"></i>
                <p>Nenhum cliente encontrado.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead style="background:var(--bg-hover);">
                        <tr>
                            <th class="px-4 py-3">Nome</th>
                            <th class="d-none d-md-table-cell email-cell">E-mail</th>
                            <th class="d-none d-md-table-cell">WhatsApp</th>
                            <th class="text-center">Animais</th>
                            <th class="d-none d-md-table-cell">Cadastro</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($donos as $d): ?>
                            <tr>
                                <td class="px-4 fw-medium">
                                    <?= h($d['Nome']) ?>
                                    <?php if (!$d['Ativo']): ?><span class="badge bg-secondary">Excluído</span><?php endif ?>
                                </td>
                                <td class="d-none d-md-table-cell text-secondary small email-cell">
                                    <?php if ($d['Email']): ?>
                                        <span title="<?= h($d['Email']) ?>"><?= h($d['Email']) ?></span>
                                    <?php else: ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif ?>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <?php if ($d['Telefone']): ?>
                                        <a href="<?= h(waLink($d['Telefone'])) ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                            <i class="bi bi-whatsapp"></i> <?= h(formatarTelefoneExibicao($d['Telefone'])) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?= (int) $d['TotalAnimais'] ?></span>
                                </td>
                                <td class="d-none d-md-table-cell small text-secondary"><?= formatarData($d['MomentoRegistro']) ?></td>
                                <td>
                                    <a href="<?= BASE ?>/painel/cliente_detalhe.php?id=<?= h($d['IDUsuario']) ?>"
                                        class="btn btn-sm btn-outline-accent">
                                        <i class="bi bi-eye"></i><span class="d-none d-md-inline ms-1">Ver</span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPag > 1): ?>
                <div class="d-flex justify-content-center py-3">
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($p = 1; $p <= $totalPag; $p++): ?>
                                <li class="page-item <?= $p === $pag ? 'active' : '' ?>">
                                    <a class="page-link" href="?pag=<?= $p ?>&q=<?= urlencode($busca) ?>&status=<?= urlencode($statusF) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor ?>
                        </ul>
                    </nav>
                </div>
            <?php endif ?>
        <?php endif ?>
    </div>
</div>

<div class="modal fade" id="modalNovoDono" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="cadastrar">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">Cadastrar cliente</h5>
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
                    <div class="mb-1">
                        <label class="form-label">Senha <span class="text-secondary">(opcional)</span></label>
                        <input type="password" name="senha" class="form-control" minlength="4" maxlength="72" autocomplete="new-password">
                        <div class="form-text" id="clienteSenhaAjuda">
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

<?php if ($souAdmin && ($_GET['acao'] ?? '') === 'novo'): ?>
<script>new bootstrap.Modal(document.getElementById('modalNovoDono')).show();</script>
<?php endif ?>

<script>
// Sem e-mail não tem como mandar link de "definir senha" — deixa claro que
// nesse caso a senha passa a ser obrigatória, em vez da pessoa só descobrir
// isso depois de tentar salvar.
(function () {
    var modal = document.getElementById('modalNovoDono');
    if (!modal) return;
    var campoEmail = modal.querySelector('[name="email"]');
    var ajuda = document.getElementById('clienteSenhaAjuda');
    function atualizar() {
        ajuda.textContent = campoEmail.value.trim() === ''
            ? 'Sem e-mail, defina a senha aqui — não tem como mandar link de definição por e-mail.'
            : 'Deixe em branco pra gerar uma senha aleatória e mandar por e-mail um link pra ele definir a dele.';
    }
    campoEmail.addEventListener('input', atualizar);
    atualizar();
})();
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
