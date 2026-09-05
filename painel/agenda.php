<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('admin', 'funcionario');

$tiposAgenda = tiposAgendaMap();

// Verifica sobreposição de horário pro mesmo veterinário — mesma checagem
// de verdade tanto no cadastro quanto (se precisar) numa futura remarcação.
function agendamentoConflita(PDO $pdo, string $fkVet, string $inicio, string $fim, string $ignorarId = ''): bool
{
    $sql = "SELECT COUNT(*) FROM Agendamentos
            WHERE FKVeterinario = :vet AND Status != 'cancelado'
              AND DataHoraInicio < :fim AND DataHoraFim > :inicio";
    $params = [':vet' => $fkVet, ':inicio' => $inicio, ':fim' => $fim];
    if ($ignorarId !== '') {
        $sql .= ' AND IDAgendamento != :ignorar';
        $params[':ignorar'] = $ignorarId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return ((int) $stmt->fetchColumn()) > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/agenda.php', 'Token inválido.', 'danger');
    }
    exigirAdmin(BASE . '/painel/agenda.php');
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'novo_agendamento') {
        $fkAnimal = trim($_POST['animal'] ?? '');
        $fkVet    = trim($_POST['veterinario'] ?? '');
        $tipo     = trim($_POST['tipo'] ?? '');
        $titulo   = trim($_POST['titulo'] ?? '');
        $data     = trim($_POST['data'] ?? '');
        $hora     = trim($_POST['hora'] ?? '');
        $duracao  = (int) ($_POST['duracao'] ?? 30);
        $obs      = trim($_POST['observacoes'] ?? '');

        // Volta reabrindo o mesmo modal, com o mesmo animal pré-selecionado
        // se veio de um — sem isso, um erro (conflito de horário, campo
        // faltando) jogava de volta pra agenda em branco e a pessoa tinha
        // que reabrir o modal e escolher o animal nde novo do zero.
        $voltarNovoAg = BASE . '/painel/agenda.php?acao=novo' . ($fkAnimal !== '' ? '&animal=' . urlencode($fkAnimal) : '');

        if ($fkAnimal === '' || $titulo === '' || $data === '' || $hora === '' || !isset($tiposAgenda[$tipo])) {
            redirecionarComMensagem($voltarNovoAg, 'Animal, tipo, título, data e hora são obrigatórios.', 'warning');
        }

        $inicio = $data . ' ' . $hora . ':00';
        $ts     = strtotime($inicio);
        if (!$ts) {
            redirecionarComMensagem($voltarNovoAg, 'Data/hora inválida.', 'warning');
        }
        $fim = date('Y-m-d H:i:s', $ts + $duracao * 60);

        if ($fkVet !== '' && agendamentoConflita($pdo, $fkVet, $inicio, $fim)) {
            redirecionarComMensagem($voltarNovoAg, 'Esse veterinário já tem outro agendamento nesse horário.', 'warning');
        }

        try {
            $novoAgId = gerarUuid();
            $pdo->prepare(
                'INSERT INTO Agendamentos (IDAgendamento, FKAnimal, FKVeterinario, Tipo, Titulo, DataHoraInicio, DataHoraFim, Observacoes)
                 VALUES (:id, :animal, :vet, :tipo, :titulo, :inicio, :fim, :obs)'
            )->execute([
                ':id'     => $novoAgId,
                ':animal' => $fkAnimal,
                ':vet'    => $fkVet ?: null,
                ':tipo'   => $tipo,
                ':titulo' => $titulo,
                ':inicio' => $inicio,
                ':fim'    => $fim,
                ':obs'    => $obs ?: null,
            ]);

            registrarEventoAgendamento($pdo, $novoAgId, 'criado');

            // Avisa o dono do animal pelo WhatsApp — sem isso, ele só ficava
            // sabendo do agendamento se entrasse no site por conta própria.
            $donoStmt = $pdo->prepare(
                'SELECT u.Nome AS NomeCliente, u.Telefone, a.Nome AS NomeAnimal FROM Animais a JOIN Usuarios u ON u.IDUsuario = a.FKDono WHERE a.IDAnimal = :id'
            );
            $donoStmt->execute([':id' => $fkAnimal]);
            $dono = $donoStmt->fetch();
            if ($dono && $dono['Telefone']) {
                $msg = montarMensagemNovoAgendamento($pdo, $dono['NomeCliente'], $dono['NomeAnimal'], $tipo, $titulo, $inicio);
                enviarWhatsApp(waNumero($dono['Telefone']), $msg);
            }

            redirecionarComMensagem(BASE . '/painel/agenda.php', 'Agendamento criado com sucesso!', 'success');
        } catch (PDOException $e) {
            error_log('[NovoAgendamento] ' . $e->getMessage());
            redirecionarComMensagem($voltarNovoAg, 'Erro ao criar agendamento.', 'danger');
        }
    }

    if ($acao === 'concluir') {
        $id      = trim($_POST['id'] ?? '');
        $obsPos  = trim($_POST['observacoes_pos'] ?? '');
        $criarRc = !empty($_POST['criar_clinico']);
        $valorStr = trim($_POST['valor'] ?? '');
        $pago     = !empty($_POST['pago']);

        if ($id === '') {
            redirecionarComMensagem(BASE . '/painel/agenda.php', 'Agendamento não encontrado.', 'warning');
        }

        // Vírgula ou ponto, tanto faz — o campo já vem mascarado tipo dinheiro
        // (mesmo padrão de peso), mas aceita os dois formatos por segurança.
        $valor = null;
        if ($valorStr !== '') {
            $valorNum = (float) str_replace(',', '.', $valorStr);
            if ($valorNum > 0) {
                $valor = $valorNum;
            }
        }

        try {
            $stmt = $pdo->prepare('SELECT * FROM Agendamentos WHERE IDAgendamento = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $ag = $stmt->fetch();
            if (!$ag) {
                redirecionarComMensagem(BASE . '/painel/agenda.php', 'Agendamento não encontrado.', 'warning');
            }

            $fkRegistroClinico = null;
            if ($criarRc) {
                $fkRegistroClinico = gerarUuid();
                $pdo->prepare(
                    'INSERT INTO RegistrosClinicos (IDRegistro, FKAnimal, FKVeterinario, Tipo, Titulo, Anotacoes, DataRegistro)
                     VALUES (:id, :animal, :vet, :tipo, :titulo, :anot, :data)'
                )->execute([
                    ':id'     => $fkRegistroClinico,
                    ':animal' => $ag['FKAnimal'],
                    ':vet'    => $ag['FKVeterinario'],
                    ':tipo'   => $ag['Tipo'],
                    ':titulo' => $ag['Titulo'],
                    ':anot'   => $obsPos ?: null,
                    ':data'   => date('Y-m-d'),
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
                            ':reg'     => $fkRegistroClinico,
                            ':caminho' => $caminho,
                            ':nome'    => $_FILES['imagens']['name'][$i] ?? null,
                        ]);
                    }
                }
            }

            $pdo->prepare(
                "UPDATE Agendamentos SET Status = 'concluido', ObservacoesPos = :obs, FKRegistroClinico = :rc,
                    Valor = :valor, StatusPagamento = :statuspag
                 WHERE IDAgendamento = :id"
            )->execute([
                ':obs'       => $obsPos ?: null,
                ':rc'        => $fkRegistroClinico,
                ':valor'     => $valor,
                ':statuspag' => $valor !== null ? ($pago ? 'pago' : 'pendente') : null,
                ':id'        => $id,
            ]);
            registrarEventoAgendamento($pdo, $id, 'concluido');

            // Se esse compromisso nasceu de uma vacina planejada (data futura
            // ou sequência manual), concluir aqui é a aplicação acontecendo
            // de verdade — sem isso a vacina ficava marcada "Planejada" pra
            // sempre, mesmo depois de já ter sido feita.
            $pdo->prepare(
                "UPDATE RegistrosVacinas SET DataAplicacao = :hoje
                 WHERE FKAgendamento = :ag AND (DataAplicacao IS NULL OR DataAplicacao > :hoje2)"
            )->execute([':hoje' => date('Y-m-d'), ':hoje2' => date('Y-m-d'), ':ag' => $id]);

            // Retorno agendado automaticamente a partir daqui (ex: retirada de
            // pontos após uma cirurgia) — reaproveita animal/veterinário/duração
            // do agendamento original, só desloca a data.
            $mensagemFinal = 'Agendamento concluído com sucesso!';
            $retornoDias   = (int) ($_POST['retorno_dias'] ?? 0);
            if (!empty($_POST['agendar_retorno']) && $retornoDias > 0) {
                $retornoTitulo = trim($_POST['retorno_titulo'] ?? '') ?: ('Retorno — ' . $ag['Titulo']);
                $duracaoSegundos = strtotime($ag['DataHoraFim']) - strtotime($ag['DataHoraInicio']);
                $retornoInicio   = date('Y-m-d H:i:s', strtotime($ag['DataHoraInicio']) + $retornoDias * 86400);
                $retornoFim      = date('Y-m-d H:i:s', strtotime($retornoInicio) + $duracaoSegundos);
                $retornoId       = gerarUuid();

                $pdo->prepare(
                    'INSERT INTO Agendamentos (IDAgendamento, FKAnimal, FKVeterinario, FKAgendamentoOrigem, Tipo, Titulo, DataHoraInicio, DataHoraFim)
                     VALUES (:id, :animal, :vet, :origem, :tipo, :titulo, :inicio, :fim)'
                )->execute([
                    ':id'     => $retornoId,
                    ':animal' => $ag['FKAnimal'],
                    ':vet'    => $ag['FKVeterinario'],
                    ':origem' => $id,
                    ':tipo'   => $ag['Tipo'],
                    ':titulo' => $retornoTitulo,
                    ':inicio' => $retornoInicio,
                    ':fim'    => $retornoFim,
                ]);
                registrarEventoAgendamento($pdo, $retornoId, 'criado', 'Retorno de ' . formatarDataHora($ag['DataHoraInicio']));

                $donoStmt = $pdo->prepare(
                    'SELECT u.Nome AS NomeCliente, u.Telefone, a.Nome AS NomeAnimal FROM Animais a JOIN Usuarios u ON u.IDUsuario = a.FKDono WHERE a.IDAnimal = :id'
                );
                $donoStmt->execute([':id' => $ag['FKAnimal']]);
                $dono = $donoStmt->fetch();
                if ($dono && $dono['Telefone']) {
                    $msg = montarMensagemRetorno($pdo, $dono['NomeCliente'], $dono['NomeAnimal'], $ag['Tipo'], $retornoTitulo, $retornoInicio);
                    enviarWhatsApp(waNumero($dono['Telefone']), $msg);
                }

                $mensagemFinal = 'Agendamento concluído com sucesso! Retorno marcado para ' . formatarData($retornoInicio) . '.';
            }

            redirecionarComMensagem(BASE . '/painel/agenda.php', $mensagemFinal, 'success');
        } catch (PDOException $e) {
            error_log('[ConcluirAgendamento] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/agenda.php', 'Erro ao concluir agendamento.', 'danger');
        }
    }

    if ($acao === 'remarcar') {
        $id   = trim($_POST['id'] ?? '');
        $data = trim($_POST['data'] ?? '');
        $hora = trim($_POST['hora'] ?? '');

        if ($id === '' || $data === '' || $hora === '') {
            redirecionarComMensagem(BASE . '/painel/agenda.php', 'Data e hora são obrigatórias pra remarcar.', 'warning');
        }

        $novoInicio = $data . ' ' . $hora . ':00';
        $ts = strtotime($novoInicio);
        if (!$ts) {
            redirecionarComMensagem(BASE . '/painel/agenda.php', 'Data/hora inválida.', 'warning');
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT ag.*, a.Nome AS NomeAnimal, u.Nome AS NomeCliente, u.Telefone
                 FROM Agendamentos ag
                 JOIN Animais a  ON a.IDAnimal = ag.FKAnimal
                 JOIN Usuarios u ON u.IDUsuario = a.FKDono
                 WHERE ag.IDAgendamento = :id LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $ag = $stmt->fetch();
            if (!$ag) {
                redirecionarComMensagem(BASE . '/painel/agenda.php', 'Agendamento não encontrado.', 'warning');
            }

            // Preserva a duração original — só muda quando vai acontecer, não
            // quanto tempo dura.
            $duracaoSegundos = strtotime($ag['DataHoraFim']) - strtotime($ag['DataHoraInicio']);
            $novoFim = date('Y-m-d H:i:s', $ts + $duracaoSegundos);

            if ($ag['FKVeterinario'] && agendamentoConflita($pdo, $ag['FKVeterinario'], $novoInicio, $novoFim, $id)) {
                redirecionarComMensagem(BASE . '/painel/agenda.php', 'Esse veterinário já tem outro agendamento nesse horário.', 'warning');
            }

            // Remarcar volta pro estado inicial de um agendamento novo —
            // precisa ser confirmado de novo, mesmo que já tivesse sido
            // confirmado antes de faltar/cancelar/remarcar.
            $pdo->prepare(
                "UPDATE Agendamentos SET DataHoraInicio = :inicio, DataHoraFim = :fim, Status = 'pendente' WHERE IDAgendamento = :id"
            )->execute([':inicio' => $novoInicio, ':fim' => $novoFim, ':id' => $id]);
            registrarEventoAgendamento($pdo, $id, 'remarcado',
                'De ' . formatarDataHora($ag['DataHoraInicio']) . ' para ' . formatarDataHora($novoInicio));

            if ($ag['Telefone']) {
                $msg = montarMensagemRemarcacao($pdo, $ag['NomeCliente'], $ag['NomeAnimal'], $ag['Tipo'], $ag['Titulo'], $novoInicio);
                enviarWhatsApp(waNumero($ag['Telefone']), $msg);
            }

            redirecionarComMensagem(BASE . '/painel/agenda.php', 'Agendamento remarcado com sucesso!', 'success');
        } catch (PDOException $e) {
            error_log('[RemarcarAgendamento] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/agenda.php', 'Erro ao remarcar agendamento.', 'danger');
        }
    }
}

// Vista salva em cookie — igual padrão da referência (Belos Cílios): lembra a
// última visão escolhida entre visitas, só re-grava quando vem explícita na URL.
if (isset($_GET['vista'])) {
    $vista = $_GET['vista'] === 'mes' ? 'mes' : 'semana';
    setcookie('agenda_vista', $vista, time() + 60 * 60 * 24 * 365, '/');
} else {
    $vista = ($_COOKIE['agenda_vista'] ?? '') === 'mes' ? 'mes' : 'semana';
}

$filtroStatus = trim($_GET['status'] ?? '');
$animalPreId  = trim($_GET['animal'] ?? '');
$souAdmin     = ($_SESSION['nivel_acesso'] ?? '') === 'admin';

$mesFiltro = trim($_GET['mes'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $mesFiltro)) {
    $mesFiltro = date('Y-m');
}

$statusValidos = ['pendente' => 1, 'confirmado' => 1, 'concluido' => 1, 'cancelado' => 1, 'faltou' => 1];
$statusFiltroLabels = [
    ''          => 'Sem cancelados',
    'pendente'  => 'Pendentes',
    'confirmado' => 'Confirmados',
    'concluido' => 'Concluídos',
    'cancelado' => 'Cancelados',
    'faltou'    => 'Faltas',
];

// Se veio de um clique num dia da vista mensal, pula pra semana que contém esse dia
$semanaOffset = (int) ($_GET['semana'] ?? 0);
if ($vista === 'semana' && !empty($_GET['dia']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['dia'])) {
    $segAlvo  = strtotime('monday this week', strtotime($_GET['dia']));
    $segAtual = strtotime('monday this week');
    $semanaOffset = (int) round(($segAlvo - $segAtual) / (7 * 86400));
}

try {
    $procedimentos = $pdo->query(
        "SELECT IDTipo, Categoria, Nome, DuracaoPadraoMinutos FROM TiposProcedimento
         WHERE Ativo = 1 ORDER BY Ordem ASC, Nome ASC"
    )->fetchAll();

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

    $porDia   = [];
    $mesGrade = [];

    if ($vista === 'semana') {
        // ── Vista semanal: sempre os 7 dias, de segunda a domingo ──
        $inicioPeriodo = strtotime("monday this week +{$semanaOffset} week");
        $fimPeriodo    = strtotime("sunday this week +{$semanaOffset} week");
        $iniSQL        = date('Y-m-d', $inicioPeriodo);
        $fimSQL        = date('Y-m-d', $fimPeriodo);
        $fimSQLNext    = date('Y-m-d', strtotime($fimSQL . ' +1 day'));

        $where  = 'WHERE ag.DataHoraInicio >= :ini AND ag.DataHoraInicio < :fim';
        $params = [':ini' => $iniSQL, ':fim' => $fimSQLNext];
        if (isset($statusValidos[$filtroStatus])) {
            $where .= ' AND ag.Status = :status';
            $params[':status'] = $filtroStatus;
        } else {
            $where .= " AND ag.Status != 'cancelado'";
        }

        $stmt = $pdo->prepare(
            "SELECT ag.*, a.Nome AS NomeAnimal, e.Icone AS IconeEspecie,
                    u.Nome AS NomeDono, v.Nome AS NomeVeterinario
             FROM Agendamentos ag
             JOIN Animais a  ON a.IDAnimal = ag.FKAnimal
             JOIN Especies e ON e.IDEspecie = a.FKEspecie
             JOIN Usuarios u ON u.IDUsuario = a.FKDono
             LEFT JOIN Usuarios v ON v.IDUsuario = ag.FKVeterinario
             {$where}
             ORDER BY ag.DataHoraInicio ASC"
        );
        $stmt->execute($params);
        $agendamentos = $stmt->fetchAll();

        foreach ($agendamentos as $ag) {
            $dia = substr($ag['DataHoraInicio'], 0, 10);
            $porDia[$dia][] = $ag;
        }
    } else {
        // ── Vista mensal: grade de calendário, cada dia com seus agendamentos
        // de verdade (não só contagem) — alimenta os pontinhos de status e o
        // painel de detalhe que abre inline ao clicar num dia.
        $inicioMes = $mesFiltro . '-01';
        $fimMes    = date('Y-m-d', strtotime('+1 month', strtotime($inicioMes)));

        $stmt = $pdo->prepare(
            "SELECT ag.IDAgendamento, ag.Tipo, ag.Titulo, ag.DataHoraInicio, ag.Status, ag.FKAgendamentoOrigem,
                    a.Nome AS NomeAnimal, e.Icone AS IconeEspecie,
                    u.Nome AS NomeDono, v.Nome AS NomeVeterinario
             FROM Agendamentos ag
             JOIN Animais a  ON a.IDAnimal = ag.FKAnimal
             JOIN Especies e ON e.IDEspecie = a.FKEspecie
             JOIN Usuarios u ON u.IDUsuario = a.FKDono
             LEFT JOIN Usuarios v ON v.IDUsuario = ag.FKVeterinario
             WHERE ag.DataHoraInicio >= :inicio AND ag.DataHoraInicio < :fim
               AND ag.Status != 'cancelado'
             ORDER BY ag.DataHoraInicio ASC"
        );
        $stmt->execute([':inicio' => $inicioMes, ':fim' => $fimMes]);

        $porDiaMes = [];
        $mesJson   = [];
        foreach ($stmt->fetchAll() as $ag) {
            $dia = substr($ag['DataHoraInicio'], 0, 10);
            $porDiaMes[$dia][] = $ag;
            $mesJson[$dia][] = [
                'id'     => $ag['IDAgendamento'],
                'hora'   => date('H:i', strtotime($ag['DataHoraInicio'])),
                'tipo'   => $tiposAgenda[$ag['Tipo']] ?? $ag['Tipo'],
                'titulo' => $ag['Titulo'],
                'animal' => $ag['NomeAnimal'],
                'icone'  => $ag['IconeEspecie'],
                'dono'   => $ag['NomeDono'],
                'vet'    => $ag['NomeVeterinario'],
                'status' => $ag['Status'],
                'origem' => !empty($ag['FKAgendamentoOrigem']),
            ];
        }

        $primeiroDiaSemana = (int) date('w', strtotime($inicioMes)); // 0 = domingo
        $diasNoMes         = (int) date('t', strtotime($inicioMes));

        $celulas = array_fill(0, $primeiroDiaSemana, null);
        for ($d = 1; $d <= $diasNoMes; $d++) {
            $dataStr   = sprintf('%s-%02d', $mesFiltro, $d);
            $celulas[] = ['data' => $dataStr, 'dia' => $d, 'ags' => $porDiaMes[$dataStr] ?? []];
        }
        while (count($celulas) % 7 !== 0) {
            $celulas[] = null;
        }
        $mesGrade = array_chunk($celulas, 7);
    }

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
    error_log('[Agenda] ' . $e->getMessage());
    $animais = $vets = $agendamentos = $porDia = $mesGrade = $mesJson = $procedimentos = [];
    $animalPre = null;
    // Garante que a vista semanal sempre tem um período pra renderizar,
    // mesmo se a query tiver falhado antes de calculá-lo.
    $inicioPeriodo = $inicioPeriodo ?? strtotime("monday this week +{$semanaOffset} week");
    $fimPeriodo    = $fimPeriodo    ?? strtotime("sunday this week +{$semanaOffset} week");
}

$paginaTitulo = 'Agenda';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<?php
    $mesesPt = [1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];
    if ($vista === 'mes') {
        $periodoAnteriorHref = '?vista=mes&mes=' . date('Y-m', strtotime($mesFiltro . '-01 -1 month'));
        $periodoProximoHref  = '?vista=mes&mes=' . date('Y-m', strtotime($mesFiltro . '-01 +1 month'));
        $periodoLabel        = $mesesPt[(int) date('n', strtotime($mesFiltro . '-01'))] . ' de ' . date('Y', strtotime($mesFiltro . '-01'));
        $noPeriodoAtual       = $mesFiltro === date('Y-m');
        $hojeHref             = '?vista=mes&mes=' . date('Y-m');
    } else {
        $periodoAnteriorHref = '?vista=semana&semana=' . ($semanaOffset - 1);
        $periodoProximoHref  = '?vista=semana&semana=' . ($semanaOffset + 1);
        $periodoLabel        = date('d/m', $inicioPeriodo) . ' – ' . date('d/m', $fimPeriodo);
        $noPeriodoAtual       = $semanaOffset === 0;
        $hojeHref             = '?vista=semana&semana=0';
    }
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <h4 class="fw-bold mb-0 d-flex align-items-center gap-2">
        <i class="bi bi-calendar-week" style="color:var(--accent);"></i> Agenda
    </h4>

    <div class="d-flex align-items-center gap-2 flex-wrap">
        <div class="vista-switch" role="group" aria-label="Visão da agenda">
            <a href="?vista=semana" class="vista-btn <?= $vista === 'semana' ? 'ativo' : '' ?>">
                <i class="bi bi-list-ul me-1"></i> Semana
            </a>
            <a href="?vista=mes&mes=<?= h($mesFiltro) ?>" class="vista-btn <?= $vista === 'mes' ? 'ativo' : '' ?>">
                <i class="bi bi-grid-3x3-gap me-1"></i> Mês
            </a>
        </div>

        <div class="nav-periodo">
            <a href="<?= $periodoAnteriorHref ?>" class="nav-btn" title="Anterior"><i class="bi bi-chevron-left"></i></a>
            <span class="nav-label"><?= h($periodoLabel) ?></span>
            <a href="<?= $periodoProximoHref ?>" class="nav-btn" title="Próximo"><i class="bi bi-chevron-right"></i></a>
        </div>

        <?php if (!$noPeriodoAtual): ?>
            <a href="<?= $hojeHref ?>" class="btn btn-outline-accent btn-sm">Hoje</a>
        <?php endif ?>

        <?php if ($vista === 'semana'): ?>
            <div style="width:170px;">
                <?= campoPicker('agStatusFiltro', 'status_filtro', 'Sem cancelados', '', $filtroStatus, $statusFiltroLabels[$filtroStatus] ?? 'Sem cancelados', obrigatorio: false, comBusca: false) ?>
            </div>
        <?php endif ?>

        <?php if ($souAdmin): ?>
            <button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoAgendamento">
                <i class="bi bi-calendar-plus me-1"></i> Novo agendamento
            </button>
        <?php endif ?>
    </div>
</div>

<?php if ($vista === 'mes'): ?>
    <?php
        $nomesDias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        $hojeStr   = date('Y-m-d');
    ?>
    <div class="card p-3 mb-3">
        <div class="d-flex flex-wrap gap-3 mb-3">
            <?php foreach (['pendente', 'confirmado', 'concluido', 'faltou'] as $st): ?>
                <span class="small d-flex align-items-center gap-1">
                    <span class="cal-dot cal-dot-<?= $st ?>"></span> <?= h(ucfirst($st === 'concluido' ? 'Concluído' : $st)) ?>
                </span>
            <?php endforeach ?>
        </div>

        <div class="calendario-grade mb-1">
            <?php foreach ($nomesDias as $nd): ?>
                <div class="calendario-cabecalho"><?= $nd ?></div>
            <?php endforeach ?>
        </div>
        <div class="calendario-grade">
            <?php foreach ($mesGrade as $semana): foreach ($semana as $cel): ?>
                <?php if ($cel === null): ?>
                    <div class="calendario-dia calendario-dia-vazio"></div>
                <?php else: ?>
                    <?php $temAgs = !empty($cel['ags']); ?>
                    <div class="calendario-dia <?= $cel['data'] === $hojeStr ? 'calendario-dia-hoje' : '' ?> <?= !$temAgs ? 'calendario-dia-sem-ag' : '' ?>"
                         role="button" tabindex="0"
                         onclick="mostrarDiaMes('<?= $cel['data'] ?>', <?= $cel['dia'] ?>)"
                         onkeydown="if(event.key==='Enter')mostrarDiaMes('<?= $cel['data'] ?>', <?= $cel['dia'] ?>)">
                        <span class="calendario-dia-numero"><?= $cel['dia'] ?></span>
                        <?php if ($temAgs): ?>
                            <div class="cal-dots">
                                <?php foreach (array_slice($cel['ags'], 0, 4) as $ag): ?>
                                    <span class="cal-dot cal-dot-<?= h($ag['Status']) ?>"></span>
                                <?php endforeach ?>
                            </div>
                            <span class="calendario-dia-badge"><?= count($cel['ags']) ?></span>
                        <?php endif ?>
                    </div>
                <?php endif ?>
            <?php endforeach; endforeach ?>
        </div>
    </div>

    <div id="painelDiaMes" class="card mb-4" style="display:none;border-color:var(--accent) !important;">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 px-3 py-2">
            <h6 class="fw-bold mb-0" id="painelDiaMesTitulo"></h6>
            <div class="d-flex gap-2">
                <?php if ($souAdmin): ?>
                    <a href="#" id="painelDiaMesNovo" class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoAgendamento">
                        <i class="bi bi-plus-lg me-1"></i> Novo
                    </a>
                <?php endif ?>
                <button class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('painelDiaMes').style.display='none';">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
        <div id="painelDiaMesConteudo"></div>
    </div>
<?php else: ?>
    <?php
        $diasSemanaPt = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];
        for ($d = 0; $d < 7; $d++):
            $ts    = strtotime("+{$d} days", $inicioPeriodo);
            $dia   = date('Y-m-d', $ts);
            $lista = $porDia[$dia] ?? [];
            $eHoje = $dia === date('Y-m-d');
    ?>
        <div class="card mb-3<?= $eHoje ? ' agenda-dia-hoje' : '' ?>">
            <div class="card-header d-flex flex-wrap align-items-center gap-2 px-3 py-2<?= $eHoje ? ' agenda-dia-hoje-header' : '' ?>">
                <span class="fw-semibold<?= $eHoje ? ' text-accent' : '' ?>"><?= $diasSemanaPt[$d] ?></span>
                <span class="text-secondary small"><?= date('d/m', $ts) ?></span>
                <?php if ($eHoje): ?><span class="badge" style="background:var(--accent);">Hoje</span><?php endif ?>
                <span class="badge bg-secondary ms-auto"><?= count($lista) ?> ag.</span>
            </div>
            <?php if (empty($lista)): ?>
                <div class="text-center py-3 text-secondary small">Sem agendamentos</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($lista as $ag): ?>
                        <li class="list-group-item px-3 py-2" data-id-agendamento="<?= h($ag['IDAgendamento']) ?>">
                            <div class="d-flex align-items-center gap-2 gap-md-3 flex-wrap">
                                <span class="fw-bold text-accent" style="min-width:42px;"><?= date('H:i', strtotime($ag['DataHoraInicio'])) ?></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex align-items-center gap-1 flex-wrap">
                                        <span class="badge" style="background:var(--accent-light);color:var(--accent);"><?= h($tiposAgenda[$ag['Tipo']] ?? $ag['Tipo']) ?></span>
                                        <?php if (!empty($ag['FKAgendamentoOrigem'])): ?>
                                            <span class="badge bg-secondary"><i class="bi bi-arrow-return-right"></i> Retorno</span>
                                        <?php endif ?>
                                        <?= labelStatusAgendamento($ag['Status']) ?>
                                        <?php if ($ag['Valor'] !== null): ?>
                                            <button type="button"
                                                class="badge border-0 btn-alternar-pagamento bg-<?= $ag['StatusPagamento'] === 'pago' ? 'success' : 'warning' ?>"
                                                data-id="<?= h($ag['IDAgendamento']) ?>" style="cursor:pointer;" title="Clique pra alternar pago/pendente">
                                                R$ <?= number_format((float) $ag['Valor'], 2, ',', '.') ?> · <?= $ag['StatusPagamento'] === 'pago' ? 'Pago' : 'Pendente' ?>
                                            </button>
                                        <?php endif ?>
                                        <span class="fw-medium"><?= especieIconeHtml($ag['IconeEspecie']) ?> <?= h($ag['NomeAnimal']) ?></span>
                                        <span class="text-secondary small">— <?= h($ag['NomeDono']) ?></span>
                                    </div>
                                    <span class="text-secondary small d-block">
                                        <?= h($ag['Titulo']) ?><?= $ag['NomeVeterinario'] ? ' · ' . h($ag['NomeVeterinario']) : ' · sem veterinário definido' ?>
                                    </span>
                                    <?php if ($ag['Status'] === 'concluido' && $ag['ObservacoesPos']): ?>
                                        <span class="text-secondary small d-block mt-1"><strong>Pós-consulta:</strong> <?= nl2br(h($ag['ObservacoesPos'])) ?></span>
                                    <?php endif ?>
                                </div>
                                <div class="d-flex gap-1 flex-wrap flex-shrink-0">
                                    <?php
                                        $remarcarData = substr($ag['DataHoraInicio'], 0, 10);
                                        $remarcarHora = substr($ag['DataHoraInicio'], 11, 5);
                                    ?>
                                    <?php if ($souAdmin): ?>
                                        <?php if ($ag['Status'] === 'pendente'): ?>
                                            <button class="btn btn-sm btn-outline-info btn-acao-agendamento" data-acao="confirmar" data-id="<?= h($ag['IDAgendamento']) ?>">Confirmar</button>
                                            <button class="btn btn-sm btn-outline-secondary btn-remarcar" data-id="<?= h($ag['IDAgendamento']) ?>" data-titulo="<?= h($ag['Titulo']) ?>" data-data="<?= h($remarcarData) ?>" data-hora="<?= h($remarcarHora) ?>">Remarcar</button>
                                            <button class="btn btn-sm btn-outline-danger btn-acao-agendamento" data-acao="cancelar" data-id="<?= h($ag['IDAgendamento']) ?>" data-confirm="Cancelar esse agendamento?">Cancelar</button>
                                        <?php elseif ($ag['Status'] === 'confirmado'): ?>
                                            <button class="btn btn-sm btn-accent btn-concluir"
                                                data-id="<?= h($ag['IDAgendamento']) ?>" data-tipo="<?= h($ag['Tipo']) ?>" data-titulo="<?= h($ag['Titulo']) ?>">
                                                Concluir
                                            </button>
                                            <button class="btn btn-sm btn-outline-warning btn-acao-agendamento" data-acao="marcar_falta" data-id="<?= h($ag['IDAgendamento']) ?>" data-confirm="Marcar falta nesse agendamento?">Faltou</button>
                                            <button class="btn btn-sm btn-outline-secondary btn-remarcar" data-id="<?= h($ag['IDAgendamento']) ?>" data-titulo="<?= h($ag['Titulo']) ?>" data-data="<?= h($remarcarData) ?>" data-hora="<?= h($remarcarHora) ?>">Remarcar</button>
                                            <button class="btn btn-sm btn-outline-danger btn-acao-agendamento" data-acao="cancelar" data-id="<?= h($ag['IDAgendamento']) ?>" data-confirm="Cancelar esse agendamento?">Cancelar</button>
                                        <?php elseif (in_array($ag['Status'], ['concluido', 'cancelado', 'faltou'], true)): ?>
                                            <button class="btn btn-sm btn-outline-secondary btn-remarcar" data-id="<?= h($ag['IDAgendamento']) ?>" data-titulo="<?= h($ag['Titulo']) ?>" data-data="<?= h($remarcarData) ?>" data-hora="<?= h($remarcarHora) ?>">Remarcar</button>
                                            <button class="btn btn-sm btn-outline-secondary btn-acao-agendamento" data-acao="reabrir" data-id="<?= h($ag['IDAgendamento']) ?>" data-confirm="Reabrir esse agendamento?">Reabrir</button>
                                        <?php endif ?>
                                    <?php endif ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>
    <?php endfor ?>
<?php endif ?>

<div class="modal fade" id="modalNovoAgendamento" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="novo_agendamento">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">Novo agendamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
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
                            <?= campoPicker('agTipo', 'tipo', '—', '', 'consulta', 'Consulta', obrigatorio: true, comBusca: false) ?>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Procedimento</label>
                            <?= campoPicker('agProc', 'procedimento_ref', 'Personalizado', '', obrigatorio: false, comBusca: false) ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" name="titulo" id="inpTituloAgendamento" class="form-control" placeholder="Ex: Consulta de rotina, Castração…" required maxlength="150">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Duração</label>
                        <?= campoPicker('agDur', 'duracao', '30 min', '', '30', '30 min', obrigatorio: true, comBusca: false) ?>
                        <div class="form-text">Escolher um procedimento acima já preenche isso — pode ajustar se precisar. <a href="<?= BASE ?>/painel/tipos_procedimento.php">Gerenciar procedimentos</a></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Data *</label>
                            <input type="date" name="data" class="form-control" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Hora *</label>
                            <input type="time" name="hora" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Veterinário responsável</label>
                        <?= campoPicker('vetResp', 'veterinario', 'Selecione…', 'Buscar veterinário…') ?>
                        <?php if (empty($vets)): ?>
                            <div class="form-text">Nenhum veterinário cadastrado — <a href="<?= BASE ?>/painel/equipe.php">cadastre um primeiro</a>.</div>
                        <?php endif ?>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Observações</label>
                        <textarea name="observacoes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-accent"><i class="bi bi-calendar-plus me-1"></i> Agendar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalConcluir" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="concluir">
                <input type="hidden" name="id" id="concluirId">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">Concluir: <span id="concluirTitulo"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Observações pós-consulta</label>
                        <textarea name="observacoes_pos" class="form-control" rows="3" placeholder="Como foi, conduta, retorno…"></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="criar_clinico" id="concluirCriarClinico" value="1" checked>
                        <label class="form-check-label" for="concluirCriarClinico">
                            Criar registro no histórico clínico do animal com essas observações
                        </label>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Imagens ou PDF <span class="text-secondary">(opcional, vai junto no registro clínico)</span></label>
                        <input type="file" name="imagens[]" class="form-control" accept="image/png,image/jpeg,image/webp,application/pdf" multiple>
                    </div>
                    <hr>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="agendar_retorno" id="concluirAgendarRetorno" value="1">
                        <label class="form-check-label" for="concluirAgendarRetorno">
                            Agendar retorno <span class="text-secondary">(ex: retirada de pontos)</span>
                        </label>
                    </div>
                    <div id="concluirRetornoCampos" class="row g-2 mb-1" style="display:none;">
                        <div class="col-5">
                            <label class="form-label small">Em quantos dias</label>
                            <input type="number" name="retorno_dias" id="concluirRetornoDias" class="form-control" min="1" max="365" value="10">
                        </div>
                        <div class="col-7">
                            <label class="form-label small">Motivo do retorno</label>
                            <input type="text" name="retorno_titulo" id="concluirRetornoTitulo" class="form-control" placeholder="Ex: Retirada de pontos">
                        </div>
                    </div>
                    <hr>
                    <div class="row g-2 align-items-end">
                        <div class="col-7">
                            <label class="form-label">Valor cobrado <span class="text-secondary">(opcional)</span></label>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input type="number" name="valor" id="concluirValor" class="form-control" step="0.01" min="0" placeholder="0,00">
                            </div>
                        </div>
                        <div class="col-5">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="pago" id="concluirPago" value="1">
                                <label class="form-check-label" for="concluirPago">Já foi pago</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-accent"><i class="bi bi-check2 me-1"></i> Concluir</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalRemarcar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="remarcar">
                <input type="hidden" name="id" id="remarcarId">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">Remarcar: <span id="remarcarTitulo"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary">Escolhe a nova data e hora — o agendamento volta pra pendente, precisando ser confirmado de novo. O cliente recebe um aviso no WhatsApp com o novo horário.</p>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Nova data *</label>
                            <input type="date" name="data" id="remarcarData" class="form-control" required min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Nova hora *</label>
                            <input type="time" name="hora" id="remarcarHora" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-accent"><i class="bi bi-calendar2-week me-1"></i> Remarcar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var SOU_ADMIN = <?= $souAdmin ? 'true' : 'false' ?>;
var ANIMAIS = <?= json_encode(array_map(fn($a) => [
    'id' => $a['IDAnimal'], 'nome' => $a['Nome'], 'dono' => $a['NomeDono'],
    'especie' => $a['FKEspecie'], 'icone' => $a['IconeEspecie'],
], $animais), JSON_UNESCAPED_UNICODE) ?>;
var VETS = <?= json_encode(array_map(fn($v) => [
    'id' => $v['IDUsuario'], 'nome' => $v['Nome'],
], $vets), JSON_UNESCAPED_UNICODE) ?>;
var PROCEDIMENTOS = <?= json_encode(array_map(fn($p) => [
    'id' => $p['IDTipo'], 'categoria' => $p['Categoria'], 'nome' => $p['Nome'], 'duracao' => (int) $p['DuracaoPadraoMinutos'],
], $procedimentos), JSON_UNESCAPED_UNICODE) ?>;

// Tipo -> filtra os procedimentos disponíveis; escolher um procedimento
// preenche duração e título automaticamente (mas continuam editáveis).
var TIPOS_AGENDA = <?= json_encode(array_map(fn($valor, $label) => [
    'id' => $valor, 'nome' => $label,
], array_keys($tiposAgenda), $tiposAgenda), JSON_UNESCAPED_UNICODE) ?>;

var inpTituloAgendamento  = document.getElementById('inpTituloAgendamento');

// Rótulo de uma duração em minutos — cobre valores fora da lista fixa (ex:
// 20min de algum procedimento) sem precisar de opção pré-cadastrada.
function labelDuracao(min) {
    if (min === 60) return '1 hora';
    if (min > 60 && min % 60 === 0) return (min / 60) + ' horas';
    if (min > 60) return Math.floor(min / 60) + 'h' + (min % 60);
    return min + ' min';
}

var DURACOES = [15, 30, 45, 60, 90, 120].map(function (m) { return { id: m, nome: labelDuracao(m) }; });

var agDurPk = initPicker({
    pickerId: 'agDurPicker', triggerId: 'agDurTrigger', dropdownId: 'agDurDropdown',
    searchId: 'agDurSearch', listId: 'agDurList', hiddenId: 'inpagDurId', labelId: 'agDurLabel',
    items: DURACOES,
    chave: function (d) { return d.id; },
    renderItem: function (d) { return { title: d.nome }; },
    matches: function (d, q) { return d.nome.toLowerCase().indexOf(q) !== -1; },
    vazioMsg: 'Nada encontrado.',
});

function selecionarProcedimento(item) {
    if (!item) return;
    // Duração do procedimento pode não bater com nenhuma das opções fixas
    // (ex: 20min) — seleciona um item sintético direto, sem precisar de
    // opção pré-cadastrada na lista.
    agDurPk.selecionar({ id: item.duracao, nome: labelDuracao(item.duracao) });
    inpTituloAgendamento.value = item.nome;
}

var agProcPk = initPicker({
    pickerId: 'agProcPicker', triggerId: 'agProcTrigger', dropdownId: 'agProcDropdown',
    searchId: 'agProcSearch', listId: 'agProcList', hiddenId: 'inpagProcId', labelId: 'agProcLabel',
    items: PROCEDIMENTOS.filter(function (p) { return p.categoria === 'consulta'; }),
    chave: function (p) { return p.id; },
    renderItem: function (p) { return { title: p.nome + ' (' + p.duracao + ' min)' }; },
    matches: function (p, q) { return p.nome.toLowerCase().indexOf(q) !== -1; },
    vazioMsg: 'Nenhum procedimento cadastrado nesse tipo.',
    onSelect: selecionarProcedimento,
});

var agTipoPk = initPicker({
    pickerId: 'agTipoPicker', triggerId: 'agTipoTrigger', dropdownId: 'agTipoDropdown',
    searchId: 'agTipoSearch', listId: 'agTipoList', hiddenId: 'inpagTipoId', labelId: 'agTipoLabel',
    items: TIPOS_AGENDA,
    chave: function (t) { return t.id; },
    renderItem: function (t) { return { title: t.nome }; },
    matches: function (t, q) { return t.nome.toLowerCase().indexOf(q) !== -1; },
    vazioMsg: 'Nada encontrado.',
    onSelect: function (t) {
        var itens = PROCEDIMENTOS.filter(function (p) { return p.categoria === t.id; });
        agProcPk.setItems(itens, 'Personalizado');
        // Abre o próximo picker sozinho, pra fluir direto sem precisar clicar
        // de novo. Precisa do setTimeout: o clique que selecionou o Tipo ainda
        // vai disparar um "click" nativo (mousedown já rodou, click vem na
        // sequência) — abrir na hora faria o listener de "clique fora" do
        // Procedimento fechar ele de novo imediatamente.
        if (itens.length) {
            setTimeout(function () { agProcPk.abrir(); }, 50);
        }
    },
});

<?php if ($vista === 'semana'): ?>
initPicker({
    pickerId: 'agStatusFiltroPicker', triggerId: 'agStatusFiltroTrigger', dropdownId: 'agStatusFiltroDropdown',
    searchId: 'agStatusFiltroSearch', listId: 'agStatusFiltroList', hiddenId: 'inpagStatusFiltroId', labelId: 'agStatusFiltroLabel',
    items: <?= json_encode(array_map(fn($id, $nome) => ['id' => $id, 'nome' => $nome], array_keys($statusFiltroLabels), $statusFiltroLabels), JSON_UNESCAPED_UNICODE) ?>,
    chave: function (s) { return s.id; },
    renderItem: function (s) { return { title: s.nome }; },
    matches: function (s, q) { return s.nome.toLowerCase().indexOf(q) !== -1; },
    vazioMsg: 'Nada encontrado.',
    onSelect: function (s) { location.href = '?vista=semana&semana=<?= $semanaOffset ?>&status=' + s.id; },
});
<?php endif ?>

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

// Delegação no document (em vez de listener por botão) — assim funciona tanto
// pros botões já na página quanto pros que o painel de dia da vista mensal
// injeta dinamicamente via mostrarDiaMes().
document.addEventListener('click', function (e) {
    var btnConcluir = e.target.closest('.btn-concluir');
    if (btnConcluir) {
        document.getElementById('concluirId').value = btnConcluir.dataset.id;
        document.getElementById('concluirTitulo').textContent = btnConcluir.dataset.titulo;
        // Reseta o bloco de retorno a cada abertura — o modal é reaproveitado
        // pra qualquer agendamento, sem isso ficava marcado do anterior.
        document.getElementById('concluirAgendarRetorno').checked = false;
        document.getElementById('concluirRetornoCampos').style.display = 'none';
        document.getElementById('concluirRetornoDias').value = 10;
        document.getElementById('concluirRetornoTitulo').value = 'Retorno — ' + btnConcluir.dataset.titulo;
        document.getElementById('concluirValor').value = '';
        document.getElementById('concluirPago').checked = false;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalConcluir')).show();
        return;
    }

    if (e.target.id === 'concluirAgendarRetorno') {
        document.getElementById('concluirRetornoCampos').style.display = e.target.checked ? '' : 'none';
        return;
    }

    var btnRemarcar = e.target.closest('.btn-remarcar');
    if (btnRemarcar) {
        document.getElementById('remarcarId').value = btnRemarcar.dataset.id;
        document.getElementById('remarcarTitulo').textContent = btnRemarcar.dataset.titulo;
        document.getElementById('remarcarData').value = btnRemarcar.dataset.data;
        document.getElementById('remarcarHora').value = btnRemarcar.dataset.hora;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalRemarcar')).show();
        return;
    }

    var btnPagamento = e.target.closest('.btn-alternar-pagamento');
    if (btnPagamento) {
        fetch(BASE + '/painel/api_agendamento.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'alternar_pagamento', id: btnPagamento.dataset.id, csrf_token: '<?= gerarTokenCSRF() ?>' }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) {
                vsRecarregarPreservandoScroll();
            } else {
                vsToast(d.msg || 'Erro ao atualizar.', 'danger');
            }
        })
        .catch(function () { vsToast('Falha na conexão.', 'danger'); });
        return;
    }

    var btnAcao = e.target.closest('.btn-acao-agendamento');
    if (btnAcao) {
        e.preventDefault();
        // stopImmediatePropagation, não só stopPropagation: esses botões têm
        // data-confirm E a classe btn-acao-agendamento ao mesmo tempo, então
        // dois listeners de click no document (esse aqui e o genérico de
        // data-confirm no footer.php) disparavam pro MESMO clique. Os dois
        // chamavam vsConfirm() em sequência — o segundo (genérico) sobrescrevia
        // o botão "Confirmar" do modal com o próprio callback dele (que só
        // sabe submeter um <form> ou seguir um href, nenhum dos dois existe
        // aqui) — por isso Faltar/Cancelar/Reabrir pareciam não fazer nada:
        // o clique real ia pro callback errado. stopPropagation() sozinho não
        // impede outro listener no MESMO elemento de rodar — só impede a
        // propagação pra elementos ancestrais.
        e.stopImmediatePropagation();
        function executar() {
            btnAcao.disabled = true;
            fetch(BASE + '/painel/api_agendamento.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ acao: btnAcao.dataset.acao, id: btnAcao.dataset.id, csrf_token: '<?= gerarTokenCSRF() ?>' }),
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    // Se a ação veio de dentro do painel de dia (vista mensal),
                    // guarda qual dia tava aberto — sem isso o reload volta pro
                    // calendário fechado e parece que a ação não fez nada.
                    if (btnAcao.closest('#painelDiaMes') && diaMesAbertoData) {
                        try { sessionStorage.setItem('vsDiaMesAberto', diaMesAbertoData); } catch (e) {}
                    }
                    vsRecarregarPreservandoScroll();
                } else {
                    btnAcao.disabled = false;
                    vsToast(d.msg || 'Erro ao atualizar.', 'danger');
                }
            })
            .catch(function () { btnAcao.disabled = false; vsToast('Falha na conexão.', 'danger'); });
        }
        if (btnAcao.dataset.confirm) {
            vsConfirm(btnAcao.dataset.confirm, executar);
        } else {
            executar();
        }
    }
});

// ── Painel de detalhe do dia (vista mensal) ─────────────────────
var MES_DADOS = <?= json_encode($mesJson ?? [], JSON_UNESCAPED_UNICODE) ?>;

var STATUS_LABEL = {
    pendente: 'Pendente', confirmado: 'Confirmado', concluido: 'Concluído',
    cancelado: 'Cancelado', faltou: 'Faltou',
};
var STATUS_COR = {
    pendente: 'secondary', confirmado: 'info', concluido: 'success',
    cancelado: 'danger', faltou: 'warning',
};

var diaMesAbertoData = null;

function mostrarDiaMes(data, diaNum) {
    diaMesAbertoData = data;
    var itens = MES_DADOS[data] || [];
    document.getElementById('painelDiaMesTitulo').textContent =
        'Dia ' + diaNum + ' — ' + itens.length + ' agendamento' + (itens.length === 1 ? '' : 's');
    var btnNovo = document.getElementById('painelDiaMesNovo');
    if (btnNovo) {
        btnNovo.addEventListener('click', function () {
            var campoData = document.querySelector('#modalNovoAgendamento input[name="data"]');
            if (campoData) campoData.value = data;
        }, { once: true });
    }

    var html;
    if (!itens.length) {
        html = '<div class="text-center py-4 text-secondary small">Nenhum agendamento nesse dia.</div>';
    } else {
        html = '<ul class="list-group list-group-flush">' + itens.map(function (ag) {
            var acoes = '';
            var btnRemarcar = '<button class="btn btn-sm btn-outline-secondary btn-remarcar" data-id="' + ag.id + '" data-titulo="' + escHtmlPicker(ag.titulo) + '" data-data="' + data + '" data-hora="' + ag.hora + '">Remarcar</button>';
            if (!SOU_ADMIN) {
                // funcionario só visualiza — nenhuma ação de escrita aqui
            } else if (ag.status === 'pendente') {
                acoes = '<button class="btn btn-sm btn-outline-info btn-acao-agendamento" data-acao="confirmar" data-id="' + ag.id + '">Confirmar</button>'
                      + btnRemarcar
                      + '<button class="btn btn-sm btn-outline-danger btn-acao-agendamento" data-acao="cancelar" data-id="' + ag.id + '" data-confirm="Cancelar esse agendamento?">Cancelar</button>';
            } else if (ag.status === 'confirmado') {
                acoes = '<button class="btn btn-sm btn-accent btn-concluir" data-id="' + ag.id + '" data-titulo="' + escHtmlPicker(ag.titulo) + '">Concluir</button>'
                      + '<button class="btn btn-sm btn-outline-warning btn-acao-agendamento" data-acao="marcar_falta" data-id="' + ag.id + '" data-confirm="Marcar falta nesse agendamento?">Faltou</button>'
                      + btnRemarcar
                      + '<button class="btn btn-sm btn-outline-danger btn-acao-agendamento" data-acao="cancelar" data-id="' + ag.id + '" data-confirm="Cancelar esse agendamento?">Cancelar</button>';
            } else {
                acoes = btnRemarcar
                      + '<button class="btn btn-sm btn-outline-secondary btn-acao-agendamento" data-acao="reabrir" data-id="' + ag.id + '" data-confirm="Reabrir esse agendamento?">Reabrir</button>';
            }
            return '<li class="list-group-item px-3 py-2">'
                 + '<div class="d-flex align-items-center gap-2 gap-md-3 flex-wrap">'
                 + '<span class="fw-bold text-accent" style="min-width:42px;">' + ag.hora + '</span>'
                 + '<div class="flex-grow-1 min-w-0">'
                 + '<div class="d-flex align-items-center gap-1 flex-wrap">'
                 + '<span class="badge" style="background:var(--accent-light);color:var(--accent);">' + escHtmlPicker(ag.tipo) + '</span>'
                 + (ag.origem ? '<span class="badge bg-secondary"><i class="bi bi-arrow-return-right"></i> Retorno</span>' : '')
                 + '<span class="badge bg-' + STATUS_COR[ag.status] + '">' + STATUS_LABEL[ag.status] + '</span>'
                 + '<span class="fw-medium">' + iconeHtmlPicker(ag.icone) + escHtmlPicker(ag.animal) + '</span>'
                 + '<span class="text-secondary small">— ' + escHtmlPicker(ag.dono) + '</span>'
                 + '</div>'
                 + '<span class="text-secondary small d-block">' + escHtmlPicker(ag.titulo) + (ag.vet ? ' · ' + escHtmlPicker(ag.vet) : ' · sem veterinário definido') + '</span>'
                 + '</div>'
                 + '<div class="d-flex gap-1 flex-wrap flex-shrink-0">' + acoes + '</div>'
                 + '</div></li>';
        }).join('') + '</ul>';
    }
    document.getElementById('painelDiaMesConteudo').innerHTML = html;

    var painel = document.getElementById('painelDiaMes');
    painel.style.display = '';
    painel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Reabre o painel do dia depois de recarregar, se uma ação (Faltou,
// Cancelar...) tiver sido feita de dentro dele — sem isso o reload fecha
// o painel e some com o contexto, parecendo que a ação não fez nada.
(function () {
    var diaSalvo;
    try { diaSalvo = sessionStorage.getItem('vsDiaMesAberto'); } catch (e) { diaSalvo = null; }
    if (diaSalvo) {
        try { sessionStorage.removeItem('vsDiaMesAberto'); } catch (e) {}
        var diaNum = parseInt(diaSalvo.slice(-2), 10);
        if (diaNum) mostrarDiaMes(diaSalvo, diaNum);
    }
})();
</script>

<?php if ($souAdmin && (($_GET['acao'] ?? '') === 'novo' || $animalPre)): ?>
<script>new bootstrap.Modal(document.getElementById('modalNovoAgendamento')).show();</script>
<?php endif ?>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
