<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['nivel_acesso'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Acesso não permitido.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método não permitido.']);
    exit;
}

$dados = json_decode(file_get_contents('php://input'), true) ?? [];

if (!validarTokenCSRF($dados['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'Token inválido.']);
    exit;
}

$acao = $dados['acao'] ?? '';
$id   = trim($dados['id'] ?? '');

if ($acao === 'excluir' && $id) {
    try {
        // Se essa vacina tinha um compromisso vinculado na Agenda (data
        // futura planejada), cancela ele também — sem isso ficava um
        // agendamento órfão de "Vacina: X" sem nenhum registro por trás.
        $vinculo = $pdo->prepare('SELECT FKAgendamento FROM RegistrosVacinas WHERE IDRegistro = :id LIMIT 1');
        $vinculo->execute([':id' => $id]);
        $fkAgendamento = $vinculo->fetchColumn();

        $pdo->prepare('DELETE FROM RegistrosVacinas WHERE IDRegistro = :id')->execute([':id' => $id]);
        cancelarAgendamentoVacina($pdo, $fkAgendamento ?: null);

        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        error_log('[ApiVacina] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erro ao excluir registro.']);
    }
    exit;
}

// Define/agenda manualmente a próxima aplicação de uma vacina já registrada
// — tanto pra ajustar uma data única quanto pra ligar o modo cíclico (repete
// sozinha a cada N meses sem precisar reaplicar de verdade a cada ciclo).
if ($acao === 'editar_proxima' && $id) {
    $proximaData      = trim($dados['proxima_data'] ?? '');
    $ciclica          = !empty($dados['ciclica']);
    $intervaloValor   = (int) ($dados['intervalo_valor'] ?? 0);
    $intervaloUnidade = trim($dados['intervalo_unidade'] ?? '');
    $unidadesValidas  = ['semana', 'mes', 'ano'];

    if ($proximaData === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $proximaData)) {
        echo json_encode(['ok' => false, 'msg' => 'Data inválida.']);
        exit;
    }
    if ($proximaData < '2000-01-01' || $proximaData > date('Y-m-d', strtotime('+10 years'))) {
        echo json_encode(['ok' => false, 'msg' => 'Data fora do intervalo permitido (confira o ano).']);
        exit;
    }

    // Cíclica precisa de um intervalo válido pra ter por quanto tempo avançar
    // a data sozinha depois — a pessoa escolhe livremente, não depende mais
    // do intervalo do catálogo da vacina. Trava entre 1 e 120 (mesmo limite
    // do campo na tela) pra um valor absurdo não estourar o DATE_ADD do cron.
    $intervaloValor = max(1, min(120, $intervaloValor));
    $ciclica = $ciclica && in_array($intervaloUnidade, $unidadesValidas, true);

    try {
        $existe = $pdo->prepare(
            'SELECT rv.DataAplicacao, rv.FKAnimal, rv.FKVeterinario, rv.FKAgendamento, tv.Nome AS NomeVacina
             FROM RegistrosVacinas rv
             JOIN TiposVacina tv ON tv.IDTipo = rv.FKTipoVacina
             WHERE rv.IDRegistro = :id LIMIT 1'
        );
        $existe->execute([':id' => $id]);
        $atual = $existe->fetch();
        if (!$atual) {
            echo json_encode(['ok' => false, 'msg' => 'Registro não encontrado.']);
            exit;
        }
        // Mesma checagem de sanidade do cadastro: próxima dose antes da
        // aplicação é normalmente sinal de ano digitado errado.
        if ($atual['DataAplicacao'] && $proximaData < $atual['DataAplicacao']) {
            echo json_encode(['ok' => false, 'msg' => 'Essa data é anterior à aplicação — confira o ano.']);
            exit;
        }

        // Troca o compromisso vinculado — cancela o antigo (a data mudou, o
        // horário marcado antes não faz mais sentido) e cria um novo pra
        // data nova, sempre como retorno (chegou até aqui, a aplicação
        // original já é passado).
        cancelarAgendamentoVacina($pdo, $atual['FKAgendamento']);
        $novoAgendamento = criarAgendamentoVacina($pdo, $atual['FKAnimal'], $atual['NomeVacina'], $atual['FKVeterinario'], $proximaData, retorno: true);

        $pdo->prepare(
            "UPDATE RegistrosVacinas
             SET ProximaData = :proxima, Ciclica = :ciclica,
                 IntervaloCiclicoValor = :intvalor, IntervaloCiclicoUnidade = :intunidade,
                 FKAgendamento = :agendamento,
                 NotificacaoSemanaEnviada = 0, NotificacaoDiaEnviada = 0
             WHERE IDRegistro = :id"
        )->execute([
            ':proxima'    => $proximaData,
            ':ciclica'    => $ciclica ? 1 : 0,
            ':intvalor'   => $ciclica ? $intervaloValor : null,
            ':intunidade' => $ciclica ? $intervaloUnidade : null,
            ':agendamento' => $novoAgendamento,
            ':id'         => $id,
        ]);
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        error_log('[ApiVacina] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erro ao salvar.']);
    }
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Ação inválida.']);
