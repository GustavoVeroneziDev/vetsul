<?php

date_default_timezone_set('America/Sao_Paulo');

function gerarUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// Valida, move e renomeia um upload de imagem (item de $_FILES) pra dentro de
// uploads/{$subpasta}/. Retorna o caminho web (a partir da raiz do app, sem
// BASE) ou null se não veio arquivo válido — quem chama decide se isso é erro.
// $permitirPdf só é usado nos anexos de registro clínico (laudo, exame em
// PDF) — foto de perfil de animal/cliente continua só imagem.
function salvarImagemEnviada(array $arquivo, string $subpasta, bool $permitirPdf = false): ?string
{
    if (empty($arquivo['tmp_name']) || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (!is_uploaded_file($arquivo['tmp_name'])) {
        return null;
    }
    if ($arquivo['size'] > 5 * 1024 * 1024) {
        return null;
    }

    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if ($permitirPdf) {
        $permitidos['application/pdf'] = 'pdf';
    }
    $tipo = mime_content_type($arquivo['tmp_name']);
    if (!isset($permitidos[$tipo])) {
        return null;
    }

    $pastaFisica = __DIR__ . '/../uploads/' . $subpasta;
    if (!is_dir($pastaFisica) && !mkdir($pastaFisica, 0755, true) && !is_dir($pastaFisica)) {
        return null;
    }

    $nomeArquivo = gerarUuid() . '.' . $permitidos[$tipo];
    if (!move_uploaded_file($arquivo['tmp_name'], $pastaFisica . '/' . $nomeArquivo)) {
        return null;
    }

    return '/uploads/' . $subpasta . '/' . $nomeArquivo;
}

function sanitizarTelefone(string $tel): ?string
{
    $tel = preg_replace('/\D/', '', $tel);
    $tel = ltrim($tel, '0');

    if (strlen($tel) === 13 && str_starts_with($tel, '55')) {
        return $tel;
    }
    if (strlen($tel) === 11) {
        return '55' . $tel;
    }
    if (strlen($tel) === 10) {
        return '55' . substr($tel, 0, 2) . '9' . substr($tel, 2);
    }

    return null;
}

function waNumero(string $tel): string
{
    return sanitizarTelefone($tel) ?? preg_replace('/\D/', '', $tel);
}

function waLink(string $tel, string $mensagem = ''): string
{
    $num = waNumero($tel);
    if (!$num) return '#';
    $url = 'https://wa.me/' . $num;
    if ($mensagem !== '') {
        $url .= '?text=' . urlencode($mensagem);
    }
    return $url;
}

function enviarWhatsApp(string $numero, string $mensagem): bool
{
    if (!defined('EVOLUTION_URL') || !defined('EVOLUTION_INSTANCE') || !defined('EVOLUTION_KEY')) {
        error_log('[WhatsApp] Evolution API não configurada.');
        return false;
    }

    // Modo de teste (ligado/desligado em Configurações): redireciona TODO
    // envio pro número de teste, não importa quem o código ia mandar — dá
    // pra validar o fluxo inteiro (agendamento, cancelamento, cron de
    // vacina...) em produção sem risco de mensagem cair em cliente de
    // verdade enquanto ainda em teste. Ponto único de controle — nenhum
    // dos call sites (agenda.php, api_agendamento.php, cron/...) precisa
    // saber que esse modo existe.
    global $pdo;
    if (isset($pdo) && getConfig($pdo, 'whatsapp_modo_teste', '') === '1') {
        $numeroTeste = getConfig($pdo, 'whatsapp_numero_teste', '');
        if ($numeroTeste === '') {
            error_log('[WhatsApp] Modo de teste ligado mas sem número de teste configurado — envio cancelado.');
            return false;
        }
        $numero = waNumero($numeroTeste);
    }

    $url     = rtrim(EVOLUTION_URL, '/') . '/message/sendText/' . EVOLUTION_INSTANCE;
    $payload = json_encode(['number' => $numero, 'text' => $mensagem], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=UTF-8',
            'apikey: ' . EVOLUTION_KEY,
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        $dec    = json_decode($response, true);
        $status = $dec['status'] ?? ($dec[0]['status'] ?? 'sem-status');
        error_log("[WhatsApp] Enviado para {$numero} — Evolution status={$status}");
        return true;
    }

    error_log("[WhatsApp] HTTP {$httpCode} para {$numero}: " . substr((string) $response, 0, 500));
    return false;
}

function registrarLogWhatsApp(
    PDO $pdo,
    string $numero,
    string $mensagem,
    string $tipo,
    string $status,
    ?string $fkRegistroVacina = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO LogsWhatsApp (IDLog, FKRegistroVacina, Numero, Mensagem, TipoMensagem, StatusEnvio)
             VALUES (:id, :fkr, :num, :msg, :tipo, :status)'
        );
        $stmt->execute([
            ':id'     => gerarUuid(),
            ':fkr'    => $fkRegistroVacina,
            ':num'    => $numero,
            ':msg'    => $mensagem,
            ':tipo'   => $tipo,
            ':status' => $status,
        ]);
    } catch (PDOException $e) {
        error_log('[LogWA] ' . $e->getMessage());
    }
}

function tiposAgendaMap(): array
{
    return [
        'cirurgia'     => 'Cirurgia',
        'consulta'     => 'Consulta',
        'exame'        => 'Exame',
        'procedimento' => 'Procedimento',
        'observacao'   => 'Observação',
        'outro'        => 'Outro',
    ];
}

// Texto padrão de cada template de WhatsApp — usado tanto como fallback na
// hora de mandar (campo vazio em Configurações) quanto pra pré-preencher o
// campo na tela de Configurações, pra editar em cima do texto real em vez
// de começar do zero ou só ver um placeholder genérico. Um lugar só pros
// dois usos não saírem de sincronia.
function templatesWhatsAppPadrao(): array
{
    return [
        'msg_agendamento_criado' => 'Olá, {nome_cliente}! Foi realizado um agendamento de {tipo} ({titulo}) para {nome_animal}, '
            . 'no dia {data} às {hora}. Não esqueça de chegar alguns minutos antes do horário, '
            . 'para uma melhor organização 😉 Muito obrigado!',
        'msg_cancelamento' => 'O agendamento de {nome_animal} ({tipo} — {titulo}) em {data} às {hora} foi cancelado. '
            . 'Qualquer dúvida, é só chamar por aqui.',
        'msg_remarcacao' => 'Olá, {nome_cliente}! O agendamento de {tipo} ({titulo}) para {nome_animal} foi remarcado '
            . 'para o dia {data} às {hora}. Qualquer dúvida, é só chamar por aqui.',
        'msg_retorno' => 'Olá, {nome_cliente}! Já deixamos agendado o retorno de {nome_animal} ({titulo}) '
            . 'para o dia {data} às {hora}. Nos vemos lá!',
        'msg_vacina_semana' => "Olá, {nome_dono}! 🐾 Passando para lembrar que a vacina *{vacina}* d(a) *{nome_animal}* "
            . "vence em uma semana, no dia *{data}*.\n\nAgende um horário com antecedência para não perder a data!",
        'msg_vacina_dia' => "Olá, {nome_dono}! 🐾 Hoje, *{data}*, vence a vacina *{vacina}* d(a) *{nome_animal}*.\n\n"
            . "Entre em contato para agendar a aplicação o quanto antes.",
    ];
}

function montarMensagemNovoAgendamento(PDO $pdo, string $nomeCliente, string $nomeAnimal, string $tipo, string $titulo, string $dataHoraInicio): string
{
    $tpl = getConfig($pdo, 'msg_agendamento_criado', '') ?: templatesWhatsAppPadrao()['msg_agendamento_criado'];

    return strtr($tpl, [
        '{nome_cliente}' => $nomeCliente,
        '{nome_animal}'  => $nomeAnimal,
        '{tipo}'         => tiposAgendaMap()[$tipo] ?? $tipo,
        '{titulo}'       => $titulo,
        '{data}'         => formatarData($dataHoraInicio),
        '{hora}'         => date('H:i', strtotime($dataHoraInicio)),
    ]);
}

// Separado de montarMensagemNovoAgendamento() de propósito — um retorno
// (ex: retirada de pontos) tem um tom diferente de uma primeira marcação,
// e a clínica pode querer personalizar cada um separadamente.
function montarMensagemRetorno(PDO $pdo, string $nomeCliente, string $nomeAnimal, string $tipo, string $titulo, string $dataHoraInicio): string
{
    $tpl = getConfig($pdo, 'msg_retorno', '') ?: templatesWhatsAppPadrao()['msg_retorno'];

    return strtr($tpl, [
        '{nome_cliente}' => $nomeCliente,
        '{nome_animal}'  => $nomeAnimal,
        '{tipo}'         => tiposAgendaMap()[$tipo] ?? $tipo,
        '{titulo}'       => $titulo,
        '{data}'         => formatarData($dataHoraInicio),
        '{hora}'         => date('H:i', strtotime($dataHoraInicio)),
    ]);
}

// Usada só quando um cadastro de vacina gera MAIS de um compromisso de
// uma vez (ex: sequência manual com várias datas extras) — sem isso, cada
// data virava um WhatsApp separado, um atrás do outro, pro mesmo cliente.
function montarMensagemVariosRetornos(string $nomeCliente, string $nomeAnimal, string $nomeVacina, array $dataHoraLista): string
{
    $linhas = array_map(
        fn(string $dh) => '📅 ' . formatarData($dh) . ' às ' . date('H:i', strtotime($dh)),
        $dataHoraLista
    );

    return "Olá, {$nomeCliente}! Ficaram agendados os seguintes retornos de *{$nomeVacina}* para {$nomeAnimal}:\n"
        . implode("\n", $linhas)
        . "\n\nNão esqueça de chegar alguns minutos antes do horário, para uma melhor organização 😉 Muito obrigado!";
}

function montarMensagemCancelamento(PDO $pdo, string $nomeAnimal, string $tipo, string $titulo, string $dataHoraInicio): string
{
    $tpl = getConfig($pdo, 'msg_cancelamento', '') ?: templatesWhatsAppPadrao()['msg_cancelamento'];

    return strtr($tpl, [
        '{nome_animal}' => $nomeAnimal,
        '{tipo}'        => tiposAgendaMap()[$tipo] ?? $tipo,
        '{titulo}'      => $titulo,
        '{data}'        => formatarData($dataHoraInicio),
        '{hora}'        => date('H:i', strtotime($dataHoraInicio)),
    ]);
}

function montarMensagemRemarcacao(PDO $pdo, string $nomeCliente, string $nomeAnimal, string $tipo, string $titulo, string $dataHoraInicio): string
{
    $tpl = getConfig($pdo, 'msg_remarcacao', '') ?: templatesWhatsAppPadrao()['msg_remarcacao'];

    return strtr($tpl, [
        '{nome_cliente}' => $nomeCliente,
        '{nome_animal}'  => $nomeAnimal,
        '{tipo}'         => tiposAgendaMap()[$tipo] ?? $tipo,
        '{titulo}'       => $titulo,
        '{data}'         => formatarData($dataHoraInicio),
        '{hora}'         => date('H:i', strtotime($dataHoraInicio)),
    ]);
}

// Registra uma linha no "Histórico de movimentações" do agendamento — sem
// isso, ações como remarcar sobrescrevem a data antiga sem deixar rastro
// nenhum de quando ou pra onde foi trocado. FKUsuario fica null quando não
// tem sessão ativa (ex: chamado de dentro de um cron).
// Trilha de auditoria genérica pra cliente/animal/equipe — mesma ideia do
// registrarEventoAgendamento() acima, mas não presa à Agenda. Sem isso,
// editar/excluir/reativar alguém não deixava rastro de quem fez o quê.
function registrarAuditoria(PDO $pdo, string $entidade, string $fkEntidade, string $acao, ?string $detalhes = null): void
{
    try {
        $pdo->prepare(
            'INSERT INTO LogAuditoria (IDLog, FKUsuario, Entidade, FKEntidade, Acao, Detalhes)
             VALUES (:id, :usuario, :entidade, :fkentidade, :acao, :detalhes)'
        )->execute([
            ':id'         => gerarUuid(),
            ':usuario'    => $_SESSION['usuario_id'] ?? null,
            ':entidade'   => $entidade,
            ':fkentidade' => $fkEntidade,
            ':acao'       => $acao,
            ':detalhes'   => $detalhes,
        ]);
    } catch (PDOException $e) {
        error_log('[LogAuditoria] ' . $e->getMessage());
    }
}

function registrarEventoAgendamento(PDO $pdo, string $fkAgendamento, string $tipo, ?string $detalhes = null): void
{
    try {
        $pdo->prepare(
            'INSERT INTO EventosAgendamento (IDEvento, FKAgendamento, FKUsuario, Tipo, Detalhes)
             VALUES (:id, :ag, :usuario, :tipo, :detalhes)'
        )->execute([
            ':id'       => gerarUuid(),
            ':ag'       => $fkAgendamento,
            ':usuario'  => $_SESSION['usuario_id'] ?? null,
            ':tipo'     => $tipo,
            ':detalhes' => $detalhes,
        ]);
    } catch (PDOException $e) {
        error_log('[EventoAgendamento] ' . $e->getMessage());
    }
}

// "Excluir" um animal é sempre desativação (Ativo=0) — mantém todo o
// histórico (vacinas, clínico, agendamentos passados) intacto, só some das
// listas e pickers. Cancela junto os agendamentos futuros que ainda
// estavam pendentes/confirmados, senão ficavam "fantasmas" na agenda pra
// um animal que não aparece em lugar nenhum.
function desativarAnimal(PDO $pdo, string $idAnimal): void
{
    $pdo->prepare('UPDATE Animais SET Ativo = 0 WHERE IDAnimal = :id')->execute([':id' => $idAnimal]);

    $stmt = $pdo->prepare(
        "SELECT IDAgendamento FROM Agendamentos WHERE FKAnimal = :id AND Status IN ('pendente', 'confirmado')"
    );
    $stmt->execute([':id' => $idAnimal]);
    foreach ($stmt->fetchAll() as $ag) {
        $pdo->prepare("UPDATE Agendamentos SET Status = 'cancelado' WHERE IDAgendamento = :id")
            ->execute([':id' => $ag['IDAgendamento']]);
        registrarEventoAgendamento($pdo, $ag['IDAgendamento'], 'cancelado', 'Cancelado — animal excluído.');
    }
}

// "Excluir" um cliente também é desativação, e arrasta os animais dele
// junto (mesma lógica acima, um por um) — sem isso, o dono some da lista
// de clientes mas os bichos dele continuam aparecendo normalmente em
// Animais, órfãos de dono "ativo".
function desativarCliente(PDO $pdo, string $idCliente): void
{
    $pdo->prepare('UPDATE Usuarios SET Ativo = 0 WHERE IDUsuario = :id')->execute([':id' => $idCliente]);

    $stmt = $pdo->prepare('SELECT IDAnimal FROM Animais WHERE FKDono = :id AND Ativo = 1');
    $stmt->execute([':id' => $idCliente]);
    foreach ($stmt->fetchAll() as $a) {
        desativarAnimal($pdo, $a['IDAnimal']);
    }
}

// Cria o compromisso na Agenda pro "próximo evento" de uma vacina — a
// aplicação em si (se ainda não aconteceu) ou o retorno da próxima dose (se
// já aconteceu). Compartilhado entre o cadastro de vacina e a edição manual
// da próxima data, pra não duplicar essa lógica em dois lugares e correr o
// risco de um WhatsApp sair diferente do outro.
// $notificar=false deixa o WhatsApp por conta de quem chamou — usado
// quando um único cadastro pode gerar vários compromissos de uma vez
// (sequência manual), pra consolidar tudo numa mensagem só em vez de uma
// por data (ver montarMensagemVariosRetornos()).
function criarAgendamentoVacina(PDO $pdo, string $fkAnimal, string $nomeVacina, ?string $fkVet, string $data, bool $retorno = false, bool $notificar = true): string
{
    $inicio = $data . ' 09:00:00';
    $fim    = date('Y-m-d H:i:s', strtotime($inicio) + 30 * 60);
    $agId   = gerarUuid();
    $titulo = 'Vacina: ' . $nomeVacina . ($retorno ? ' (retorno)' : '');

    $pdo->prepare(
        'INSERT INTO Agendamentos (IDAgendamento, FKAnimal, FKVeterinario, Tipo, Titulo, DataHoraInicio, DataHoraFim)
         VALUES (:id, :animal, :vet, :tipo, :titulo, :inicio, :fim)'
    )->execute([
        ':id' => $agId, ':animal' => $fkAnimal, ':vet' => $fkVet ?: null,
        ':tipo' => 'procedimento', ':titulo' => $titulo, ':inicio' => $inicio, ':fim' => $fim,
    ]);
    registrarEventoAgendamento($pdo, $agId, 'criado', 'Planejado a partir do registro de vacina.');

    if ($notificar) {
        $donoStmt = $pdo->prepare(
            'SELECT u.Nome AS NomeCliente, u.Telefone, a.Nome AS NomeAnimal FROM Animais a JOIN Usuarios u ON u.IDUsuario = a.FKDono WHERE a.IDAnimal = :id'
        );
        $donoStmt->execute([':id' => $fkAnimal]);
        $dono = $donoStmt->fetch();
        if ($dono && $dono['Telefone']) {
            $msg = $retorno
                ? montarMensagemRetorno($pdo, $dono['NomeCliente'], $dono['NomeAnimal'], 'procedimento', $titulo, $inicio)
                : montarMensagemNovoAgendamento($pdo, $dono['NomeCliente'], $dono['NomeAnimal'], 'procedimento', $titulo, $inicio);
            enviarWhatsApp(waNumero($dono['Telefone']), $msg);
        }
    }

    return $agId;
}

// Cancela o compromisso vinculado a uma vacina (se existir e ainda estiver
// aberto) — usado antes de trocar a próxima data ou ao excluir o registro,
// pra não deixar "Vacina: X" órfão sobrando na Agenda.
function cancelarAgendamentoVacina(PDO $pdo, ?string $fkAgendamento): void
{
    if (!$fkAgendamento) {
        return;
    }
    $pdo->prepare("UPDATE Agendamentos SET Status = 'cancelado' WHERE IDAgendamento = :ag AND Status NOT IN ('concluido', 'cancelado')")
        ->execute([':ag' => $fkAgendamento]);
    registrarEventoAgendamento($pdo, $fkAgendamento, 'cancelado', 'Vacina replanejada ou removida.');
}

function redirecionarComMensagem(string $url, string $msg, string $tipo): never
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_tipo'] = $tipo;
    header('Location: ' . $url);
    exit;
}

function gerarTokenCSRF(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validarTokenCSRF(?string $token): bool
{
    return !empty($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function estaLogado(): bool
{
    return !empty($_SESSION['usuario_id']);
}

function exigirLogin(string ...$niveis): void
{
    if (!estaLogado()) {
        global $pdo;
        if (isset($pdo)) tentarLoginLembrado($pdo);
    }
    if (!estaLogado()) {
        // Guarda pra onde a pessoa ia, pra cair direto lá depois de logar em
        // vez de sempre num dashboard genérico (ex: link de WhatsApp/e-mail
        // pra um animal específico, sessão expirou no meio do caminho).
        // REQUEST_URI vem sempre do que o navegador pediu nesse mesmo
        // domínio — não dá pra injetar um destino externo por aqui — mas
        // ainda barra "//" (URL protocol-relative) por garantia extra, e só
        // aceita path dentro do próprio BASE.
        $destino = $_SERVER['REQUEST_URI'] ?? '';
        $prefixo = BASE . '/';
        if (
            $destino !== ''
            && !str_starts_with($destino, '//')
            && str_starts_with($destino, $prefixo)
            && !str_starts_with($destino, $prefixo . 'usuario/login')
        ) {
            $_SESSION['login_next'] = $destino;
        }
        redirecionarComMensagem(BASE . '/usuario/login.php', 'Faça login para continuar.', 'warning');
    }
    if ($niveis && !in_array($_SESSION['nivel_acesso'] ?? '', $niveis, true)) {
        redirecionarComMensagem(BASE . '/index.php', 'Acesso não permitido.', 'danger');
    }
}

// Equipe (nível "funcionario") pode ver tudo no painel, mas só admin pode
// criar/editar/excluir — chamar no início de cada bloco de escrita (POST),
// depois do exigirLogin() da página já ter garantido que tá logado.
// $voltar é pra onde manda de volta se barrar (mesma URL que os outros
// redirects de erro daquele mesmo bloco já usam).
function exigirAdmin(string $voltar): void
{
    if (($_SESSION['nivel_acesso'] ?? '') !== 'admin') {
        redirecionarComMensagem($voltar, 'Apenas administradores podem realizar essa ação.', 'danger');
    }
}

function criarTokenLembrarMe(PDO $pdo, string $idUsuario, int $dias = 30): void
{
    try {
        $pdo->prepare('DELETE FROM TokensLembrarMe WHERE FKUsuario = :id AND Expira < NOW()')
            ->execute([':id' => $idUsuario]);
    } catch (PDOException) {
    }

    $idToken    = gerarUuid();
    $tokenPlain = bin2hex(random_bytes(32));
    $tokenHash  = hash('sha256', $tokenPlain);
    $expira     = date('Y-m-d H:i:s', strtotime("+{$dias} days"));

    try {
        $pdo->prepare(
            'INSERT INTO TokensLembrarMe (IDToken, FKUsuario, TokenHash, Expira)
             VALUES (:id, :fku, :hash, :expira)'
        )->execute([':id' => $idToken, ':fku' => $idUsuario, ':hash' => $tokenHash, ':expira' => $expira]);
    } catch (PDOException $e) {
        error_log('[LembrarMe] Erro ao salvar token: ' . $e->getMessage());
        return;
    }

    $path = (defined('BASE') && BASE !== '') ? BASE . '/' : '/';
    setcookie('vs_lembrar', $idToken . ':' . $tokenPlain, [
        'expires'  => strtotime("+{$dias} days"),
        'path'     => $path,
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function tentarLoginLembrado(PDO $pdo): void
{
    if (estaLogado() || empty($_COOKIE['vs_lembrar'])) return;

    $partes = explode(':', $_COOKIE['vs_lembrar'], 2);
    if (count($partes) !== 2) {
        _limparCookieLembrarMe();
        return;
    }
    [$idToken, $tokenPlain] = $partes;

    try {
        $stmt = $pdo->prepare(
            'SELECT t.IDToken, t.FKUsuario, t.TokenHash,
                    u.Nome, u.NivelAcesso, u.Cargo, u.Ativo
             FROM TokensLembrarMe t
             JOIN Usuarios u ON u.IDUsuario = t.FKUsuario
             WHERE t.IDToken = :id AND t.Expira > NOW()
             LIMIT 1'
        );
        $stmt->execute([':id' => $idToken]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('[LembrarMe] ' . $e->getMessage());
        return;
    }

    if (!$row || !$row['Ativo']) {
        _limparCookieLembrarMe();
        return;
    }

    if (!hash_equals($row['TokenHash'], hash('sha256', $tokenPlain))) {
        // Hash inválido — possível roubo de cookie; invalida todos os tokens do usuário
        try {
            $pdo->prepare('DELETE FROM TokensLembrarMe WHERE FKUsuario = :id')
                ->execute([':id' => $row['FKUsuario']]);
        } catch (PDOException) {
        }
        _limparCookieLembrarMe();
        error_log('[LembrarMe] Token inválido para usuário ' . $row['FKUsuario'] . ' — todos os tokens apagados.');
        return;
    }

    // Rotação só quando o token está vencendo (< 15 dias restantes)
    $deveRotar = strtotime($row['Expira']) < strtotime('+15 days');
    if ($deveRotar) {
        try {
            $pdo->prepare('DELETE FROM TokensLembrarMe WHERE IDToken = :id')
                ->execute([':id' => $idToken]);
        } catch (PDOException) {
        }
        criarTokenLembrarMe($pdo, $row['FKUsuario']);
    }

    session_regenerate_id(true);
    $_SESSION['usuario_id']   = $row['FKUsuario'];
    $_SESSION['usuario_nome'] = $row['Nome'];
    $_SESSION['nivel_acesso'] = $row['NivelAcesso'];
    $_SESSION['cargo']        = $row['Cargo'];
}

function _limparCookieLembrarMe(): void
{
    $path = (defined('BASE') && BASE !== '') ? BASE . '/' : '/';
    setcookie('vs_lembrar', '', [
        'expires'  => time() - 3600,
        'path'     => $path,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['vs_lembrar']);
}

function urlAbsoluta(string $caminho): string
{
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https://' : 'http://';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . $host . BASE . $caminho;
}

// Gera um link de definição/redefinição de senha (mesmo mecanismo pros dois
// casos: "esqueci minha senha" e "primeira senha" de conta criada pelo admin).
function criarTokenResetSenha(PDO $pdo, string $idUsuario, int $horas = 24): array
{
    try {
        $pdo->prepare('DELETE FROM TokensResetSenha WHERE FKUsuario = :id')
            ->execute([':id' => $idUsuario]);
    } catch (PDOException) {
    }

    $idToken    = gerarUuid();
    $tokenPlain = bin2hex(random_bytes(32));
    $tokenHash  = hash('sha256', $tokenPlain);
    $expira     = date('Y-m-d H:i:s', strtotime("+{$horas} hours"));

    $pdo->prepare(
        'INSERT INTO TokensResetSenha (IDToken, FKUsuario, TokenHash, Expira)
         VALUES (:id, :fku, :hash, :expira)'
    )->execute([':id' => $idToken, ':fku' => $idUsuario, ':hash' => $tokenHash, ':expira' => $expira]);

    return ['id' => $idToken, 'token' => $tokenPlain];
}

// Retorna o IDUsuario dono do token se ele for válido e não tiver expirado, senão null.
// Não consome o token — quem chama decide quando invalidar (só depois da senha trocada de verdade).
function validarTokenResetSenha(PDO $pdo, string $idToken, string $tokenPlain): ?string
{
    if ($idToken === '' || $tokenPlain === '') return null;

    try {
        $stmt = $pdo->prepare(
            'SELECT FKUsuario, TokenHash FROM TokensResetSenha WHERE IDToken = :id AND Expira > NOW() LIMIT 1'
        );
        $stmt->execute([':id' => $idToken]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('[ResetSenha] ' . $e->getMessage());
        return null;
    }

    if (!$row || !hash_equals($row['TokenHash'], hash('sha256', $tokenPlain))) {
        return null;
    }
    return $row['FKUsuario'];
}

function h(mixed $str): string
{
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

function flashMsg(): void
{
    if (!empty($_SESSION['flash_msg'])) {
        $tipo = $_SESSION['flash_tipo'] ?? 'info';

        if ($tipo === 'success') {
            // Sucesso não precisa de banner que fica na tela — um toast
            // rápido no canto já confirma e some sozinho, sem atrapalhar.
            $msgJs = json_encode($_SESSION['flash_msg'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
            echo "<script>document.addEventListener('DOMContentLoaded', function () { vsToast({$msgJs}, 'success'); });</script>";
        } else {
            $tipoSafe = h($tipo);
            $msg      = h($_SESSION['flash_msg']);
            echo "<div class=\"alert alert-{$tipoSafe} alert-dismissible fade show mb-3\" role=\"alert\">"
                . "<i class=\"bi bi-info-circle me-2\"></i>{$msg}"
                . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
                . '</div>';
        }
        unset($_SESSION['flash_msg'], $_SESSION['flash_tipo']);
    }
}

function getConfig(PDO $pdo, string $chave, string $padrao = ''): string
{
    static $cache = [];
    if (array_key_exists($chave, $cache)) return $cache[$chave];
    try {
        $stmt = $pdo->prepare('SELECT Valor FROM ConfiguracoesSistema WHERE Chave = :chave LIMIT 1');
        $stmt->execute([':chave' => $chave]);
        $row = $stmt->fetch();
        return $cache[$chave] = $row ? (string) $row['Valor'] : $padrao;
    } catch (PDOException) {
        return $padrao;
    }
}

function setConfig(PDO $pdo, string $chave, string $valor): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO ConfiguracoesSistema (IDConfig, Chave, Valor)
         VALUES (:id, :chave, :valor)
         ON DUPLICATE KEY UPDATE Valor = :valor2, AtualizadoEm = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        ':id'     => gerarUuid(),
        ':chave'  => $chave,
        ':valor'  => $valor,
        ':valor2' => $valor,
    ]);
}

function formatarData(?string $date): string
{
    return $date ? date('d/m/Y', strtotime($date)) : '—';
}

function formatarDataHora(string $datetime): string
{
    return date('d/m/Y \à\s H:i', strtotime($datetime));
}

function formatarTelefoneExibicao(?string $tel): string
{
    if (!$tel) return '';
    $d = preg_replace('/\D/', '', $tel);
    if (strlen($d) === 13 && str_starts_with($d, '55')) {
        $d = substr($d, 2);
    }
    if (strlen($d) === 11) {
        return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7);
    }
    if (strlen($d) === 10) {
        return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6);
    }
    return $tel;
}

/**
 * Valida data de nascimento de animal: formato real, não pode ser no
 * futuro, não pode passar de 100 anos atrás. Campo vazio é válido
 * (nascimento é opcional) — quem exige preenchido valida isso à parte.
 */
function dataNascimentoValida(string $data): bool
{
    if ($data === '') return true;

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $data);
    if (!$dt || $dt->format('Y-m-d') !== $data) return false;

    // Compara só a data (string 'Y-m-d'), nunca DateTime — createFromFormat()
    // preenche a hora com o horário atual (não meia-noite), então comparar
    // objetos DateTime aqui rejeitava até a data de hoje por causa da hora.
    $hoje         = date('Y-m-d');
    $limiteAntigo = date('Y-m-d', strtotime('-100 years'));

    return $data <= $hoje && $data >= $limiteAntigo;
}

function formatarIdade(?string $dataNascimento): string
{
    if (!$dataNascimento) return '';
    $nasc  = new DateTimeImmutable($dataNascimento);
    $agora = new DateTimeImmutable();
    if ($nasc > $agora) return '';
    $diff  = $nasc->diff($agora);

    if ($diff->y >= 1) {
        $txt = $diff->y . ($diff->y === 1 ? ' ano' : ' anos');
        if ($diff->m > 0) $txt .= ' e ' . $diff->m . ($diff->m === 1 ? ' mês' : ' meses');
        return $txt;
    }
    if ($diff->m >= 1) {
        return $diff->m . ($diff->m === 1 ? ' mês' : ' meses');
    }
    return $diff->d . ($diff->d === 1 ? ' dia' : ' dias');
}

function formatarSexo(?string $sexo): string
{
    return match ($sexo) {
        'macho' => '<i class="bi bi-gender-male me-1"></i>Macho',
        'femea' => '<i class="bi bi-gender-female me-1"></i>Fêmea',
        'indeterminado' => 'Indeterminado',
        default => '',
    };
}

// Ícone de espécie: Especies.Icone guarda um nome de arquivo (assets/img/especies/),
// recolorido via CSS mask pra acompanhar a cor de destaque do tema. $arquivo vindo
// vazio ou de um registro antigo não migrado (emoji) cai pro ícone genérico da pata.
function especieIconeHtml(?string $arquivo, string $tamanho = '1.2em'): string
{
    if (!$arquivo || !preg_match('/^[a-z0-9_-]+\.(png|svg)$/i', $arquivo)) {
        $arquivo = 'paw.png';
    }
    $tam = h($tamanho);
    $url = BASE . '/assets/img/especies/' . rawurlencode($arquivo);
    return '<span class="especie-icone" style="width:' . $tam . ';height:' . $tam . ';--especie-icone-url:url(\'' . $url . '\');"></span>';
}

/**
 * Situação de vacinação a partir da ProximaData de um registro.
 * @return array{0:string,1:string,2:string} [label, cor-bootstrap, ícone]
 */
function situacaoVacina(?string $proximaData): array
{
    if (!$proximaData) return ['Dose única', 'secondary', 'bi-check2-circle'];

    $dias = (int) floor((strtotime($proximaData) - strtotime(date('Y-m-d'))) / 86400);

    if ($dias < 0)  return ['Atrasada', 'danger', 'bi-exclamation-triangle-fill'];
    if ($dias === 0) return ['Vence hoje', 'warning', 'bi-alarm-fill'];
    if ($dias <= 30) return ["Vence em {$dias} dia" . ($dias === 1 ? '' : 's'), 'warning', 'bi-clock-fill'];
    return ['Em dia', 'success', 'bi-check-circle-fill'];
}

function labelSituacaoVacina(?string $proximaData): string
{
    [$label, $cor] = situacaoVacina($proximaData);
    return '<span class="badge bg-' . $cor . '">' . h($label) . '</span>';
}

// Uma aplicação "planejada" ainda não aconteceu de verdade — seja porque não
// tem DataAplicacao nenhuma (entrada de sequência manual, onde a única data
// que existe é a ProximaData) ou porque a data marcada é no futuro (a pessoa
// moveu a "Data de aplicação" pra frente de propósito, pra já deixar
// reservado antes mesmo de acontecer). Em qualquer um dos dois casos, o
// badge sempre vem com a data — nunca "Planejada" sozinho sem dizer quando,
// que é exatamente o que ficava confuso antes. Compartilhado entre o painel
// e o portal do cliente pra não duplicar essa regra.
function labelAplicacaoVacina(?string $dataAplicacao, ?string $proximaData = null): string
{
    if ($dataAplicacao && $dataAplicacao <= date('Y-m-d')) {
        return formatarData($dataAplicacao);
    }
    $dataPlanejada = $dataAplicacao ?: $proximaData;
    $sufixo = $dataPlanejada ? ' · ' . formatarData($dataPlanejada) : '';
    return '<span class="badge bg-secondary">Planejada</span>' . $sufixo;
}

function labelStatusAgendamento(string $status): string
{
    [$label, $cor] = match ($status) {
        'pendente'   => ['Pendente', 'secondary'],
        'confirmado' => ['Confirmado', 'info'],
        'concluido'  => ['Concluído', 'success'],
        'cancelado'  => ['Cancelado', 'danger'],
        'faltou'     => ['Faltou', 'warning'],
        default      => [$status, 'secondary'],
    };
    return '<span class="badge bg-' . $cor . '">' . h($label) . '</span>';
}

// Rótulo pra cada linha do "Histórico de movimentações" (EventosAgendamento)
// — verbo no particípio, cor por gravidade. Diferente de
// labelStatusAgendamento(): esse aqui descreve o EVENTO que aconteceu, não
// o status atual (ex: "Reaberto" é seu próprio evento, mesmo o status atual
// virando "confirmado").
function labelEventoAgendamento(string $tipo): string
{
    [$label, $icone, $cor] = match ($tipo) {
        'criado'     => ['Agendado', 'bi-calendar-plus', 'secondary'],
        'confirmado' => ['Confirmado', 'bi-check-circle', 'info'],
        'remarcado'  => ['Remarcado', 'bi-calendar2-week', 'accent'],
        'faltou'     => ['Faltou', 'bi-exclamation-triangle', 'warning'],
        'cancelado'  => ['Cancelado', 'bi-x-circle', 'danger'],
        'concluido'  => ['Concluído', 'bi-check2-circle', 'success'],
        'reaberto'   => ['Reaberto', 'bi-arrow-counterclockwise', 'secondary'],
        default      => [$tipo, 'bi-dot', 'secondary'],
    };
    $classe = $cor === 'accent' ? '' : ' bg-' . $cor;
    $estilo = $cor === 'accent' ? ' style="background:var(--accent-light);color:var(--accent);"' : '';
    return '<span class="badge' . $classe . '"' . $estilo . '><i class="bi ' . $icone . ' me-1"></i>' . h($label) . '</span>';
}

/**
 * Card de um agendamento — usado em usuario/meus_agendamentos.php,
 * usuario/meus_animais.php e painel/cliente_detalhe.php (compartilhado pra
 * ficar sempre igual nos três lugares). Espera $ag com colunas de
 * Agendamentos + NomeAnimal, IconeEspecie, NomeVeterinario (LEFT JOIN).
 *
 * $permitirCancelar só deve vir true na tela do próprio cliente
 * (meus_agendamentos.php) — aí sim, se o agendamento ainda estiver
 * pendente/confirmado e no futuro, mostra um botão "Cancelar" que posta
 * pra usuario/processa_agendamento.php.
 */
function renderCardAgendamento(array $ag, array $tiposAgenda, bool $permitirCancelar = false): void
{
    $podeCancelar = $permitirCancelar
        && in_array($ag['Status'], ['pendente', 'confirmado'], true)
        && $ag['DataHoraInicio'] > date('Y-m-d H:i:s');
?>
    <div class="card p-3 mb-2">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-3">
                <div class="text-center" style="min-width:64px;">
                    <div class="fw-bold small"><?= formatarData($ag['DataHoraInicio']) ?></div>
                    <div class="small text-secondary"><?= date('H:i', strtotime($ag['DataHoraInicio'])) ?></div>
                </div>
                <div>
                    <div>
                        <span class="badge" style="background:var(--accent-light);color:var(--accent);"><?= h($tiposAgenda[$ag['Tipo']] ?? $ag['Tipo']) ?></span>
                        <?= labelStatusAgendamento($ag['Status']) ?>
                    </div>
                    <div class="fw-medium mt-1"><?= especieIconeHtml($ag['IconeEspecie']) ?> <?= h($ag['NomeAnimal']) ?> — <?= h($ag['Titulo']) ?></div>
                    <?php if ($ag['NomeVeterinario']): ?>
                        <div class="small text-secondary">Com <?= h($ag['NomeVeterinario']) ?></div>
                    <?php endif ?>
                </div>
            </div>
            <?php if ($podeCancelar): ?>
                <form method="POST" action="<?= BASE ?>/usuario/processa_agendamento.php" data-confirm="Cancelar esse agendamento?">
                    <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                    <input type="hidden" name="acao" value="cancelar">
                    <input type="hidden" name="id" value="<?= h($ag['IDAgendamento']) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Cancelar</button>
                </form>
            <?php endif ?>
        </div>
        <?php if ($ag['Status'] === 'concluido' && $ag['ObservacoesPos']): ?>
            <div class="small mt-2 pt-2 border-top" style="border-color:var(--card-border-color) !important;">
                <strong>Observações:</strong> <?= nl2br(h($ag['ObservacoesPos'])) ?>
            </div>
        <?php endif ?>
    </div>
<?php
}

/**
 * Gera o HTML de um campo picker (dropdown com busca) — ver initPicker() em
 * geral/header.php. $valorInicial/$labelInicial preenchem o campo já
 * selecionado (uso em telas de edição); deixe ambos vazios para um campo novo.
 */
function campoPicker(
    string $prefixo,
    string $nomeCampo,
    string $placeholder,
    string $placeholderBusca,
    string $valorInicial = '',
    string $labelInicial = '',
    bool $obrigatorio = false,
    bool $comBusca = true,
    string $iconeInicial = ''
): string {
    $req      = $obrigatorio ? ' required' : '';
    $temValor = $valorInicial !== '';
    $labelTxt = $temValor ? $labelInicial : $placeholder;
    $labelCls = $temValor ? 'picker-selected' : 'picker-placeholder';
    // $iconeInicial é sempre uma classe fixa escrita no PHP chamador (ex: "bi-gender-male"),
    // nunca dado de usuário — por isso entra como HTML confiável, sem passar por h().
    $iconeHtml = $iconeInicial !== '' ? '<i class="bi ' . $iconeInicial . ' me-1"></i>' : '';

    $busca = $comBusca ? '
            <div class="picker-search-wrap">
                <i class="bi bi-search picker-search-icon"></i>
                <input type="text" class="picker-search" id="' . $prefixo . 'Search" placeholder="' . h($placeholderBusca) . '" autocomplete="off">
            </div>' : '';

    return '
    <input type="hidden" name="' . h($nomeCampo) . '" id="inp' . $prefixo . 'Id" value="' . h($valorInicial) . '"' . $req . '>
    <div class="picker" id="' . $prefixo . 'Picker">
        <div class="picker-trigger" id="' . $prefixo . 'Trigger" tabindex="0">
            <span id="' . $prefixo . 'Label" class="' . $labelCls . '">' . $iconeHtml . h($labelTxt) . '</span>
            <span class="picker-caret"><i class="bi bi-chevron-down"></i></span>
        </div>
        <div class="picker-dropdown d-none" id="' . $prefixo . 'Dropdown">' . $busca . '
            <div class="picker-list" id="' . $prefixo . 'List"></div>
        </div>
    </div>';
}
