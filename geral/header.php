<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/versao.php';

// conexao.php é gitignored — reforço aqui pro deploy nunca quebrar por defasagem
// entre o git push (auto) e o reenvio manual desse arquivo (FTP)
if (!defined('APP_NOME')) {
    define('APP_NOME', 'Agro Life');
}

$paginaTitulo = $paginaTitulo ?? APP_NOME;
$areaAtual    = $areaAtual    ?? '';
$ehPainel     = $areaAtual === 'painel';

// Auto-login por cookie lembrar-me em páginas públicas (protegidas já tratam em exigirLogin)
if (!estaLogado() && !empty($_COOKIE['vs_lembrar']) && isset($pdo)) {
    tentarLoginLembrado($pdo);
}

$_nomeSession = $_SESSION['usuario_nome'] ?? '';
$nivelAcesso  = $_SESSION['nivel_acesso'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($paginaTitulo) ?> — <?= APP_NOME ?></title>
    <meta name="robots" content="<?= h($metaRobots ?? 'noindex, nofollow') ?>">
    <?php if (!empty($metaDescricao)): ?>
    <meta name="description" content="<?= h($metaDescricao) ?>">
    <?php endif ?>
    <meta name="theme-color" content="#0d9488">

    <link rel="icon" href="<?= BASE ?>/assets/img/icone.ico">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= BASE ?>/assets/img/icon-192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= BASE ?>/assets/img/apple-touch-icon.png">
    <link rel="manifest" href="<?= BASE ?>/manifest.php">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= h(APP_NOME) ?>">

    <?php
    // Bootstrap CSS/JS e Icons ficam servidos do próprio domínio (ver
    // assets/vendor/) em vez de vir do jsdelivr — nessa hospedagem o site
    // roda em HTTP/1.1 (sem multiplexação), então cada origem externa é uma
    // negociação de DNS+TLS a mais em toda visita nova, sem o benefício de
    // cache compartilhado entre sites que os navegadores atuais não dão mais
    // (cache já vem particionado por site de qualquer forma).
    ?>
    <link href="<?= BASE ?>/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE ?>/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE ?>/assets/css/paleta.css?v=<?= APP_VERSAO ?>">
    <link rel="stylesheet" href="<?= BASE ?>/assets/css/estrutura.css?v=<?= APP_VERSAO ?>">
    <?php foreach ($paginaCssExtra ?? [] as $_css): ?>
    <link rel="stylesheet" href="<?= BASE ?>/assets/css/<?= h($_css) ?>?v=<?= APP_VERSAO ?>">
    <?php endforeach ?>
    <script>
    var BASE = '<?= BASE ?>';

    // Compartilhado entre TODOS os pickers da página (não é local de cada
    // instância) — marca a última vez que qualquer um deles processou uma
    // seleção. Existe por causa de um comportamento do navegador: ao
    // selecionar um item, o elemento clicado (mousedown) pode sumir da
    // tela antes do "click" nativo desse mesmo clique terminar de
    // disparar — o navegador então redireciona esse clique pro ancestral
    // visível mais próximo (o modal, tipicamente), um clique "órfão" que
    // cai fora de QUALQUER picker e fecharia (errado) o próximo que o
    // encadeamento acabou de abrir. Não dá pra prever o alvo nem o
    // timing exatos desse clique fantasma, então em vez de tentar timing
    // fino, todo clickFora ignora clique-fora por uma janela curta depois
    // de qualquer seleção — nesse intervalo, quase certeza que um clique
    // "de fora" é esse fantasma, não uma intenção real do usuário.
    var ultimaSelecaoPickerEm = -Infinity;

    // ── Picker de busca (dropdown com campo de busca) ──────────
    // Fica no <head> (não no footer) de propósito: páginas podem chamar
    // initPicker() no próprio <script> antes do footer.php ser incluído,
    // então a função precisa existir antes de qualquer conteúdo da página.
    // Uso: initPicker({ pickerId, triggerId, dropdownId, searchId, listId,
    //   hiddenId, labelId, items, chave(item), renderItem(item) -> {title, sub},
    //   matches(item, queryLower) -> bool, vazioMsg, onSelect(item) })
    function escHtmlPicker(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    // r.icon vem sempre de dado confiável (classe do bootstrap-icons, ou nome de
    // arquivo fixo em assets/img/especies/) — nunca texto de usuário.
    function iconeHtmlPicker(icon) {
        if (!icon) return '';
        if (/\.(png|svg)$/i.test(icon)) {
            return '<span class="especie-icone me-1" style="width:1.1em;height:1.1em;--especie-icone-url:url(\'' + BASE + '/assets/img/especies/' + icon + '\')"></span>';
        }
        return '<i class="bi ' + icon + ' me-1"></i>';
    }
    function initPicker(opts) {
        var aberto = false;
        var selecionado = null;
        var picker, trigger, dropdown, search, list, hidden, label, erroObrigatorio;

        function mostrarErroObrigatorio() {
            if (!erroObrigatorio) return;
            erroObrigatorio.textContent = 'Campo obrigatório.';
            erroObrigatorio.style.display = '';
            trigger.style.borderColor = 'var(--cor-perigo)';
        }
        function limparErroObrigatorio() {
            if (!erroObrigatorio) return;
            erroObrigatorio.style.display = 'none';
            trigger.style.borderColor = '';
        }

        var abertoEm = 0;
        function abrir() {
            aberto = true;
            abertoEm = performance.now();
            trigger.classList.add('open');
            dropdown.classList.remove('d-none');
            renderizar(opts.items);
            if (search) {
                search.value = '';
                setTimeout(function () { search.focus(); }, 40);
            }
            document.addEventListener('click', clickFora, true);
        }
        function fechar() {
            aberto = false;
            trigger.classList.remove('open');
            dropdown.classList.add('d-none');
            document.removeEventListener('click', clickFora, true);
        }
        function clickFora(e) {
            // Ignora um clique "fantasma" de uma seleção recente — ver
            // comentário de ultimaSelecaoPickerEm lá em cima. O alvo desse
            // clique pode ser QUALQUER ancestral (o navegador redireciona
            // pra onde estiver visível), então não dá pra filtrar pelo
            // alvo — só pela proximidade no tempo com a última seleção.
            if (performance.now() - ultimaSelecaoPickerEm < 200) return;
            if (e.timeStamp < abertoEm) return;
            if (!picker.contains(e.target)) fechar();
        }
        function toggle() {
            if (trigger.classList.contains('disabled')) return;
            aberto ? fechar() : abrir();
        }
        function filtrar(q) {
            q = q.toLowerCase();
            renderizar(opts.items.filter(function (it) { return opts.matches(it, q); }));
        }
        function renderizar(lista) {
            list.innerHTML = '';
            if (!lista.length) {
                list.innerHTML = '<div class="picker-empty">' + escHtmlPicker(opts.vazioMsg || 'Nada encontrado.') + '</div>';
                return;
            }
            lista.forEach(function (it) {
                var r = opts.renderItem(it);
                var icone = iconeHtmlPicker(r.icon);
                var div = document.createElement('div');
                div.className = 'picker-item' + (selecionado && opts.chave(selecionado) === opts.chave(it) ? ' picker-active' : '');
                div.innerHTML = '<div class="picker-item-titulo">' + icone + escHtmlPicker(r.title) + '</div>'
                    + (r.sub ? '<div class="picker-item-sub">' + escHtmlPicker(r.sub) + '</div>' : '');
                div.addEventListener('mousedown', function (e) { e.preventDefault(); selecionar(it); });
                list.appendChild(div);
            });
        }
        function selecionar(it) {
            selecionado = it;
            hidden.value = opts.chave(it);
            limparErroObrigatorio();
            var r = opts.renderItem(it);
            var icone = iconeHtmlPicker(r.icon);
            // r.title/r.sub podem vir de dado do usuário (nome de dono, animal…) —
            // sempre escapa antes de jogar em innerHTML, só o ícone é HTML confiável
            // (vem sempre de uma classe fixa escrita no próprio renderItem()).
            label.innerHTML = icone + escHtmlPicker(r.title) + (r.sub ? ' — ' + escHtmlPicker(r.sub) : '');
            label.className = 'picker-selected';
            ultimaSelecaoPickerEm = performance.now();
            fechar();
            if (opts.onSelect) opts.onSelect(it);
        }
        function iniciar() {
            picker   = document.getElementById(opts.pickerId);
            trigger  = document.getElementById(opts.triggerId);
            dropdown = document.getElementById(opts.dropdownId);
            search   = document.getElementById(opts.searchId);
            list     = document.getElementById(opts.listId);
            hidden   = document.getElementById(opts.hiddenId);
            label    = document.getElementById(opts.labelId);
            if (!picker || !trigger) return;

            trigger.addEventListener('click', toggle);
            trigger.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
            });
            if (search) search.addEventListener('input', function () { filtrar(this.value); });

            // "required" em input[hidden] é ignorado pelo navegador (não existe
            // validação nativa pra campo hidden) — sem isso, um picker obrigatório
            // vazio ia até o servidor, voltava com erro e apagava tudo que já
            // tinha sido preenchido no resto do formulário. Valida aqui, antes de
            // sair da página — igual pra todo picker com required, sem precisar
            // repetir em cada tela.
            if (hidden && hidden.hasAttribute('required')) {
                erroObrigatorio = document.createElement('div');
                erroObrigatorio.className = 'small mt-1';
                erroObrigatorio.style.color = 'var(--cor-perigo)';
                erroObrigatorio.style.display = 'none';
                picker.insertAdjacentElement('afterend', erroObrigatorio);

                var form = picker.closest('form');
                if (form) {
                    form.addEventListener('submit', function (e) {
                        if (!hidden.value) {
                            e.preventDefault();
                            mostrarErroObrigatorio();
                        }
                    });
                }
            }
        }

        // initPicker() sempre roda num <script> que vem DEPOIS do HTML do
        // campo no documento (é assim em todo lugar que ela é chamada) —
        // então os elementos já existem, mesmo com document.readyState ainda
        // "loading" (o resto da página abaixo pode nao ter carregado, mas
        // isso não importa aqui). Roda direto: adiar pro DOMContentLoaded
        // quebrava desabilitar()/setItems() chamados logo após a criação,
        // porque "trigger" etc. só seriam preenchidos depois, no futuro.
        iniciar();

        function limpar() {
            selecionado = null;
            if (hidden) hidden.value = '';
            if (label) { label.textContent = opts.placeholder || ''; label.className = 'picker-placeholder'; }
        }

        return {
            selecionar: selecionar,
            limpar: limpar,
            abrir: function () { if (!trigger.classList.contains('disabled')) abrir(); },
            getSelecionado: function () { return selecionado; },
            // Troca a lista de itens (ex: raças mudam conforme a espécie escolhida).
            // Limpa a seleção atual — o item selecionado pode não existir na lista nova.
            setItems: function (novosItems, novoPlaceholder) {
                opts.items = novosItems;
                if (novoPlaceholder !== undefined) opts.placeholder = novoPlaceholder;
                limpar();
            },
            desabilitar: function (motivoPlaceholder) {
                if (trigger) trigger.classList.add('disabled');
                limpar();
                if (motivoPlaceholder !== undefined && label) label.textContent = motivoPlaceholder;
            },
            habilitar: function () {
                if (trigger) trigger.classList.remove('disabled');
            },
        };
    }

    // Amarra Espécie + Raça (raça filtra pela espécie escolhida) + Sexo, com
    // os ícones de gênero do Bootstrap Icons. Espera campos gerados por
    // campoPicker() em funcoes.php com prefixo <base>Especie / <base>Raca / <base>Sexo.
    // especies: [{id, nome, icone}] · racas: [{especie, nome}]
    // especieInicialId: em tela de edição, a espécie já selecionada — filtra
    // a raça de cara sem precisar reabrir o picker de espécie.
    //
    // Encadeamento (Espécie -> Sexo -> Raça, na ordem visual do formulário —
    // Espécie e Sexo ficam lado a lado na mesma linha, Raça vem embaixo):
    // escolher um já abre o próximo campo vazio sozinho, sem precisar clicar
    // pra abrir de novo. Sexo não depende da espécie (lista sempre igual),
    // mas encadeia do mesmo jeito por ser o próximo campo obrigatório vazio.
    // Cada abertura só acontece se o campo alvo ainda estiver vazio — assim
    // não reabre um valor que já tava certo (ex: só corrigindo a raça numa
    // edição, sem mexer no sexo que já tava preenchido).
    function initAnimalPickers(base, especies, racas, especieInicialId) {
        var SEXOS = [
            { id: 'macho', label: 'Macho', icon: 'bi-gender-male' },
            { id: 'femea', label: 'Fêmea', icon: 'bi-gender-female' },
            { id: 'indeterminado', label: 'Indeterminado', icon: '' },
        ];

        var racaHidden = document.getElementById('inp' + base + 'RacaId');
        var sexoHidden = document.getElementById('inp' + base + 'SexoId');

        var racaPk = initPicker({
            pickerId: base + 'RacaPicker', triggerId: base + 'RacaTrigger', dropdownId: base + 'RacaDropdown',
            searchId: base + 'RacaSearch', listId: base + 'RacaList', hiddenId: 'inp' + base + 'RacaId', labelId: base + 'RacaLabel',
            items: especieInicialId ? racas.filter(function (r) { return r.especie === especieInicialId; }) : [],
            chave: function (r) { return r.nome; },
            renderItem: function (r) { return { title: r.nome }; },
            matches: function (r, q) { return r.nome.toLowerCase().indexOf(q) !== -1; },
            vazioMsg: 'Nenhuma raça encontrada.',
        });
        if (racaPk && !especieInicialId) racaPk.desabilitar('Selecione a espécie primeiro');

        var sexoPk = initPicker({
            pickerId: base + 'SexoPicker', triggerId: base + 'SexoTrigger', dropdownId: base + 'SexoDropdown',
            searchId: base + 'SexoSearch', listId: base + 'SexoList', hiddenId: 'inp' + base + 'SexoId', labelId: base + 'SexoLabel',
            items: SEXOS,
            chave: function (s) { return s.id; },
            renderItem: function (s) { return { title: s.label, icon: s.icon }; },
            matches: function (s, q) { return s.label.toLowerCase().indexOf(q) !== -1; },
            vazioMsg: 'Nada encontrado.',
            onSelect: function () {
                // abrir() já ignora sozinho se a raça ainda tiver desabilitada
                // (espécie não escolhida ainda) — não precisa checar aqui.
                if (racaHidden && !racaHidden.value) {
                    setTimeout(function () { racaPk.abrir(); }, 50);
                }
            },
        });

        var especiePk = initPicker({
            pickerId: base + 'EspeciePicker', triggerId: base + 'EspecieTrigger', dropdownId: base + 'EspecieDropdown',
            searchId: base + 'EspecieSearch', listId: base + 'EspecieList', hiddenId: 'inp' + base + 'EspecieId', labelId: base + 'EspecieLabel',
            items: especies,
            chave: function (e) { return e.id; },
            renderItem: function (e) { return { title: e.nome, icon: e.icone }; },
            matches: function (e, q) { return e.nome.toLowerCase().indexOf(q) !== -1; },
            vazioMsg: 'Nenhuma espécie encontrada.',
            onSelect: function (e) {
                if (!racaPk) return;
                var filtradas = racas.filter(function (r) { return r.especie === e.id; });
                racaPk.habilitar();
                racaPk.setItems(filtradas, 'Selecione a raça…');
                if (sexoHidden && !sexoHidden.value) {
                    setTimeout(function () { sexoPk.abrir(); }, 50);
                }
            },
        });

        return { especiePk: especiePk, racaPk: racaPk, sexoPk: sexoPk };
    }
    </script>
</head>

<body>

    <?php if ($ehPainel): ?>
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="fecharSidebar()"></div>
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <a href="<?= BASE ?>/index.php" class="d-flex align-items-center gap-2 text-decoration-none" style="color:inherit;">
                    <img src="<?= BASE ?>/assets/img/logo.png" alt="" width="28" height="28">
                    <span><?= APP_NOME ?></span>
                </a>
            </div>
            <?php
            $uri = $_SERVER['REQUEST_URI'];
            $menuItens = [
                ['href' => BASE . '/painel/index.php',        'icon' => 'bi-house-door',    'label' => 'Dashboard'],
                ['href' => BASE . '/painel/agenda.php',       'icon' => 'bi-calendar3',     'label' => 'Agenda'],
                ['href' => BASE . '/painel/animais.php',      'icon' => 'bi-clipboard2-pulse', 'label' => 'Animais'],
                ['href' => BASE . '/painel/clientes.php',      'icon' => 'bi-people',        'label' => 'Clientes'],
                ['href' => BASE . '/painel/relatorios.php',    'icon' => 'bi-bar-chart-line', 'label' => 'Relatórios'],
                ['href' => BASE . '/painel/tipos_vacina.php',  'icon' => 'bi-shield-plus',   'label' => 'Tipos de Vacina'],
                ['href' => BASE . '/painel/tipos_procedimento.php', 'icon' => 'bi-list-check', 'label' => 'Tipos de Procedimento'],
            ];
            // Equipe e Configurações: só o admin dono do sistema mexe nisso, não os veterinários
            if ($nivelAcesso === 'admin') {
                $menuItens[] = ['href' => BASE . '/painel/equipe.php',        'icon' => 'bi-person-badge', 'label' => 'Equipe'];
                $menuItens[] = ['href' => BASE . '/painel/auditoria.php',     'icon' => 'bi-clock-history', 'label' => 'Auditoria'];
                $menuItens[] = ['href' => BASE . '/painel/configuracoes.php', 'icon' => 'bi-gear',         'label' => 'Configurações'];
            }
            ?>
            <ul class="sidebar-nav">
                <?php foreach ($menuItens as $item): ?>
                    <li>
                        <a href="<?= $item['href'] ?>" class="<?= str_contains($uri, $item['href']) ? 'ativo' : '' ?>">
                            <i class="bi <?= $item['icon'] ?>"></i>
                            <?= $item['label'] ?>
                        </a>
                    </li>
                <?php endforeach ?>
            </ul>
            <div class="sidebar-footer">
                <div class="mb-1 d-flex align-items-center">
                    <i class="bi bi-person-circle me-1 flex-shrink-0"></i>
                    <span class="nome-truncado"><?= h($_nomeSession) ?></span>
                </div>
                <a href="<?= BASE ?>/usuario/logout.php"><i class="bi bi-box-arrow-right me-1"></i> Sair</a>
                <?php
                    // Produção: hash/data do commit já vêm gravados em config/versao.php
                    // no momento do deploy (ver .github/workflows/deploy.yml) — não faz
                    // sentido rodar `git` de novo a cada request só pra mostrar isso (dois
                    // exec() por página, e o deploy já exclui a pasta .git mesmo, então em
                    // produção isso sempre falhava silenciosamente e caía no fallback —
                    // ou, pior, lia uma .git antiga esquecida de uma cópia manual).
                    // Local: sem versao.php preenchido, só aqui vale rodar `git` de
                    // verdade pra mostrar o commit atual de quem tá desenvolvendo.
                    $gitVer = null;
                    if (APP_BUILD_DATE !== '' && preg_match('/\(([0-9a-f]{6,40})\)\s*$/', APP_BUILD_DATE, $m)) {
                        $dataSemHash = trim((string) preg_replace('/\s*\([0-9a-f]{6,40}\)\s*$/', '', APP_BUILD_DATE));
                        $gitVer = $m[1] . ' · ' . $dataSemHash;
                    } elseif ($isLocal ?? false) {
                        // Nota: cada exec() usa no máximo um "%" — no Windows, escapeshellarg()
                        // neutraliza "%" (risco de expansão de variável do cmd.exe), e uma string
                        // com dois "%" formando um par (ex: %h|||%cd) é lida como uma única
                        // variável "%h|||%" e quebra o comando.
                        $repoDir = escapeshellarg(__DIR__ . '/..');
                        $hashOut = $dateOut = [];
                        @exec("git -C {$repoDir} rev-parse --short HEAD 2>&1", $hashOut, $retHash);
                        @exec("git -C {$repoDir} log -1 --format=%cI 2>&1", $dateOut, $retData);
                        if ($retHash === 0 && $retData === 0 && !empty($hashOut[0]) && !empty($dateOut[0])) {
                            $gitVer = trim($hashOut[0]) . ' · ' . date('d/m/y H:i', strtotime(trim($dateOut[0])));
                        }
                    }
                ?>
                <div class="sidebar-version" title="Confirme aqui se o deploy já chegou">
                    <?php if ($gitVer): ?>
                        <i class="bi bi-tag-fill me-1 opacity-50"></i><?= h($gitVer) ?>
                    <?php else: ?>
                        build <?= h(APP_VERSAO) ?>
                    <?php endif ?>
                </div>
            </div>
        </nav>

        <div class="painel-content">
            <div class="d-flex d-md-none align-items-center mb-3 gap-2">
                <button class="btn btn-sm btn-outline-secondary" onclick="abrirSidebar()">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <a href="<?= BASE ?>/index.php" class="d-flex align-items-center gap-2 text-decoration-none">
                    <img src="<?= BASE ?>/assets/img/logo.png" alt="" width="26" height="26">
                    <span class="fw-bold" style="color:var(--text-main);"><?= APP_NOME ?></span>
                </a>
            </div>

            <?php flashMsg() ?>

        <?php else: ?>
            <nav class="navbar topnav sticky-top">
                <div class="container-lg">
                    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= BASE ?>/index.php">
                        <img src="<?= BASE ?>/assets/img/logo.png" alt="" width="26" height="26"> <?= APP_NOME ?>
                    </a>

                    <?php if (estaLogado()): ?>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle d-flex align-items-center" data-bs-toggle="dropdown">
                                <i class="bi bi-person-circle me-1 flex-shrink-0"></i>
                                <span class="nome-truncado"><?= h($_nomeSession) ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="<?= BASE ?>/usuario/meus_animais.php">
                                        <i class="bi bi-clipboard2-pulse me-2"></i>Meus Animais</a></li>
                                <li><a class="dropdown-item" href="<?= BASE ?>/usuario/meus_agendamentos.php">
                                        <i class="bi bi-calendar3 me-2"></i>Meus Agendamentos</a></li>
                                <li><a class="dropdown-item" href="<?= BASE ?>/usuario/perfil.php">
                                        <i class="bi bi-person me-2"></i>Meu Perfil</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="<?= BASE ?>/usuario/logout.php">
                                        <i class="bi bi-box-arrow-right me-2"></i>Sair</a></li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="d-flex gap-2">
                            <a href="<?= BASE ?>/usuario/login.php" class="btn btn-sm btn-outline-accent"><i class="bi bi-box-arrow-in-right me-1"></i>Entrar</a>
                            <a href="<?= BASE ?>/usuario/cadastro.php" class="btn btn-sm btn-accent"><i class="bi bi-person-plus me-1"></i>Cadastrar</a>
                        </div>
                    <?php endif ?>
                </div>
            </nav>

            <main<?= empty($paginaSemContainer) ? ' class="container-lg py-4"' : '' ?>>
                <?php flashMsg() ?>
            <?php endif ?>