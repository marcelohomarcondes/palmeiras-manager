<?php
session_start();

/**
 * ============================
 * REQUIRE CORRETO DO BANCO
 * ============================
 * create_match.php está em: src/pages/
 * db.php está em: src/
 */
require_once __DIR__ . '/../bootstrap.php';

/**
 * ============================
 * BASE DO ROUTER (public/index.php)
 * ============================
 * Garante que o redirect funcione mesmo se o app estiver em subpasta.
 * Ex:
 *  - /index.php?page=matches
 *  - /palmeiras_manager/public/index.php?page=matches
 */
$routerBase = dirname($_SERVER['PHP_SELF']);
$routerBase = str_replace('\\', '/', $routerBase);
$routerBase = rtrim($routerBase, '/');
$routerBase = $routerBase . '/index.php?page=';

/**
 * ============================
 * SISTEMA DE LOG
 * ============================
 */
function pm_log($level, $message)
{
    $logDir = 'D:\\Projetos\\palmeiras_manager\\logs';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    if (!is_dir($logDir)) {
        // fallback se estiver em outro ambiente
        $logDir = __DIR__ . '/../logs';
        @mkdir($logDir, 0777, true);
    }

    $logFile = $logDir . '/app.log';
    $date = date('Y-m-d H:i:s');
    $line = "[$date] [$level] $message" . PHP_EOL;

    file_put_contents($logFile, $line, FILE_APPEND);
}

// Se for GET, a página deve EXIBIR o formulário.
// (Não redirecione. Deixe o restante do arquivo renderizar a tela.)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // opcional: limpar erros antigos, etc.
    // $err = $_GET['err'] ?? null;
} else {
    // Aqui embaixo ficará SOMENTE o processamento do POST (salvar no banco)
}

try {
    $pdo->beginTransaction();

    $season_id      = $_POST['season_id'] ?? null;
    $competition_id = $_POST['competition_id'] ?? null;
    $match_date     = $_POST['match_date'] ?? null;
    $stadium        = $_POST['stadium'] ?? null;
    $home_away      = $_POST['home_away'] ?? null;
    $pal_goals      = $_POST['pal_goals'] ?? 0;
    $opp_goals      = $_POST['opp_goals'] ?? 0;

    /**
     * ==========================================================
     * VALIDAÇÃO: NÃO PERMITIR MESMO JOGADOR DO PALMEIRAS 2x
     * ==========================================================
     * NÃO se aplica ao adversário (nome pode repetir).
     */
    $pal_players = [];

    // Titulares: 0..10
    for ($i = 0; $i <= 10; $i++) {
        $key = "pal_pid_starter_$i";
        if (!empty($_POST[$key])) {
            $pid = (string)$_POST[$key];

            if (in_array($pid, $pal_players, true)) {
                $pdo->rollBack();
                pm_log('WARN', "Jogador duplicado detectado no Palmeiras (titular). Player ID: $pid");
                header('Location: ' . $routerBase . 'create_match&err=dup_player');
                exit;
            }

            $pal_players[] = $pid;
        }
    }

    // Reservas: 0..8
    for ($i = 0; $i <= 8; $i++) {
        $key = "pal_pid_bench_$i";
        if (!empty($_POST[$key])) {
            $pid = (string)$_POST[$key];

            if (in_array($pid, $pal_players, true)) {
                $pdo->rollBack();
                pm_log('WARN', "Jogador duplicado detectado no Palmeiras (reserva). Player ID: $pid");
                header('Location: ' . $routerBase . 'create_match&err=dup_player');
                exit;
            }

            $pal_players[] = $pid;
        }
    }

    /**
     * ============================
     * INSERT MATCH
     * ============================
     */
    $stmt = $pdo->prepare("
        INSERT INTO matches 
        (season_id, competition_id, match_date, stadium, home_away, pal_goals, opp_goals)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $season_id,
        $competition_id,
        $match_date,
        $stadium,
        $home_away,
        $pal_goals,
        $opp_goals
    ]);

    $match_id = $pdo->lastInsertId();

    /**
     * ============================
     * INSERT LINEUPS
     * ============================
     */

    // Titulares
    for ($i = 0; $i <= 10; $i++) {
        $pidKey = "pal_pid_starter_$i";
        $posKey = "pal_pos_starter_$i";

        if (!empty($_POST[$pidKey])) {
            $stmt = $pdo->prepare("
                INSERT INTO match_lineups 
                (match_id, player_id, position, is_starter)
                VALUES (?, ?, ?, 1)
            ");

            $stmt->execute([
                $match_id,
                $_POST[$pidKey],
                $_POST[$posKey] ?? null
            ]);
        }
    }

    // Reservas
    for ($i = 0; $i <= 8; $i++) {
        $pidKey = "pal_pid_bench_$i";
        $posKey = "pal_pos_bench_$i";

        if (!empty($_POST[$pidKey])) {
            $stmt = $pdo->prepare("
                INSERT INTO match_lineups 
                (match_id, player_id, position, is_starter)
                VALUES (?, ?, ?, 0)
            ");

            $stmt->execute([
                $match_id,
                $_POST[$pidKey],
                $_POST[$posKey] ?? null
            ]);
        }
    }

    $pdo->commit();

    pm_log('INFO', "Partida cadastrada com sucesso. Match ID: $match_id");

    header('Location: ' . $routerBase . 'matches&success=1');
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    pm_log('ERROR', 'Erro ao cadastrar partida: ' . $e->getMessage());

    header('Location: ' . $routerBase . 'create_match&err=exception');
    exit;
}
