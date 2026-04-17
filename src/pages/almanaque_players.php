<?php
declare(strict_types=1);

/**
 * almanaque_players.php
 *
 * Regras:
 * - Lista TODOS os jogadores cadastrados em players, tenham jogado ou não
 * - Consolida: jogos, vitórias, empates, derrotas, gols, assistências,
 *   amarelos, vermelhos, % aproveitamento e títulos
 * - No detalhe do atleta, mostra SOMENTE partidas em que ele realmente entrou em campo
 * - Reserva não utilizado NÃO conta
 * - Clique na partida redireciona para match.php?id=...
 *
 * Correções aplicadas:
 * - Títulos contam APENAS se o jogador estava ativo NA DATA em que o título foi conquistado
 * - Não conta título para atleta que chegou depois da conquista
 * - A linha do tempo de transferências usa a DATA EFETIVA da movimentação
 *   (effective_date / presentation_date / release_date), e não mais a data de negociação
 * - Histórico de camisas exibido no perfil do jogador em ordem cronológica
 * - Lista de jogos do atleta mostra a camisa usada na partida quando houver snapshot salvo
 */

$pdo    = db();
$userId = require_user_id();
$club   = function_exists('app_club') ? (string)app_club() : 'PALMEIRAS';

if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool {
        $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('table_columns')) {
    function table_columns(PDO $pdo, string $table): array {
        $cols = [];
        $st = $pdo->query("PRAGMA table_info($table)");
        foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
            $cols[] = (string)($r['name'] ?? '');
        }
        return $cols;
    }
}

if (!function_exists('table_has_user_id')) {
    function table_has_user_id(PDO $pdo, string $table): bool {
        static $cache = [];
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = in_array('user_id', table_columns($pdo, $table), true);
        }
        return $cache[$table];
    }
}

if (!function_exists('alm_players_fmt_pct')) {
    function alm_players_fmt_pct($v): string {
        return number_format((float)$v, 2, ',', '.') . '%';
    }
}

if (!function_exists('alm_players_fmt_date_br')) {
    function alm_players_fmt_date_br(?string $date): string {
        if (!$date) return '-';
        $ts = strtotime($date);
        return $ts ? date('d/m/Y', $ts) : h($date);
    }
}

if (!function_exists('alm_players_norm_date')) {
    function alm_players_norm_date(?string $date): ?string {
        $date = trim((string)$date);
        if ($date === '') {
            return null;
        }
        $ts = strtotime($date);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }
}

if (!function_exists('alm_players_upper')) {
    function alm_players_upper(string $value): string {
        $value = trim($value);
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($value, 'UTF-8');
        }
        return strtoupper($value);
    }
}

if (!function_exists('alm_players_is_activate_transfer')) {
    function alm_players_is_activate_transfer(string $type): bool {
        return in_array(alm_players_upper($type), [
            'PROMOVIDO DA BASE',
            'CONTRATADO (DEFINITIVO)',
            'CHEGOU POR EMPRÉSTIMO',
            'VOLTOU DE EMPRÉSTIMO',
        ], true);
    }
}

if (!function_exists('alm_players_is_deactivate_transfer')) {
    function alm_players_is_deactivate_transfer(string $type): bool {
        return in_array(alm_players_upper($type), [
            'VENDIDO',
            'SAIU POR EMPRÉSTIMO',
            'FIM DE EMPRÉSTIMO (RETORNO AO CLUBE PROPRIETÁRIO)',
            'APOSENTADORIA',
        ], true);
    }
}

if (!function_exists('alm_players_active_on_date')) {
    function alm_players_active_on_date(string $targetDate, ?string $baselineDate, array $timeline): bool {
        $target = alm_players_norm_date($targetDate);
        if ($target === null) {
            return false;
        }

        $baseline = alm_players_norm_date($baselineDate);

        $hasArrival = false;
        foreach ($timeline as $event) {
            if (($event['kind'] ?? '') === 'activate') {
                $hasArrival = true;
                break;
            }
        }

        $active = !$hasArrival;

        if ($baseline !== null && $target < $baseline) {
            $active = false;
        }

        foreach ($timeline as $event) {
            $eventDate = (string)($event['date'] ?? '');
            if ($eventDate === '' || $eventDate > $target) {
                break;
            }
            $active = (($event['kind'] ?? '') === 'activate');
        }

        return $active;
    }
}

if (!function_exists('alm_players_build_url')) {
    function alm_players_build_url(array $overrides = []): string {
        $params = array_merge($_GET, ['page' => 'almanaque_players'], $overrides);
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                unset($params[$k]);
            }
        }
        return 'index.php?' . http_build_query($params);
    }
}

if (!function_exists('alm_players_sort_link')) {
    function alm_players_sort_link(string $column, string $label, string $currentSort, string $currentDir): string {
        $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
        $arrow = '';
        if ($currentSort === $column) {
            $arrow = $currentDir === 'asc' ? ' ▲' : ' ▼';
        }
        $url = alm_players_build_url([
            'sort' => $column,
            'dir'  => $nextDir,
        ]);
        return '<a href="' . h($url) . '">' . h($label) . $arrow . '</a>';
    }
}

if (!function_exists('pm_alm_real_participation_sql')) {
    /**
     * Participação real do atleta:
     * - titular em match_players
     * - OU entrou no jogo em match_substitutions (player_in_id)
     *
     * Não inclui banco não utilizado.
     */
    function pm_alm_real_participation_sql(string $clubNormExpr, bool $mpHasUserId, bool $msHasUserId, bool $mxHasUserId): string {
        $mpUser = $mpHasUserId ? " AND mp.user_id = :user_id " : '';
        $msUser = $msHasUserId ? " AND ms.user_id = :user_id " : '';
        $mxUser = $mxHasUserId ? " AND mx.user_id = :user_id " : '';

        return "
            SELECT DISTINCT
                mp.match_id,
                mp.player_id
            FROM match_players mp
            WHERE mp.player_id IS NOT NULL
              AND UPPER(TRIM(COALESCE(mp.club_name, ''))) = {$clubNormExpr}
              AND UPPER(TRIM(COALESCE(mp.role, ''))) = 'STARTER'
              {$mpUser}

            UNION

            SELECT DISTINCT
                ms.match_id,
                ms.player_in_id AS player_id
            FROM match_substitutions ms
            JOIN matches mx
              ON mx.id = ms.match_id
            WHERE ms.player_in_id IS NOT NULL
              {$msUser}
              {$mxUser}
              AND (
                    (UPPER(TRIM(COALESCE(mx.home, ''))) = {$clubNormExpr} AND UPPER(TRIM(COALESCE(ms.side, ''))) = 'HOME')
                 OR (UPPER(TRIM(COALESCE(mx.away, ''))) = {$clubNormExpr} AND UPPER(TRIM(COALESCE(ms.side, ''))) = 'AWAY')
              )
        ";
    }
}

/* -----------------------------------------------------------------------------
 * Filtros
 * -------------------------------------------------------------------------- */
$qPlayer     = trim((string)($_GET['q'] ?? ''));
$playerId    = (int)($_GET['player_id'] ?? 0);
$season      = trim((string)($_GET['season'] ?? ''));
$competition = trim((string)($_GET['competition'] ?? ''));
$sort        = trim((string)($_GET['sort'] ?? 'games'));
$dir         = strtolower(trim((string)($_GET['dir'] ?? 'desc')));

$sortMap = [
    'name'          => 'player_name',
    'player_name'   => 'player_name',
    'games'         => 'games',
    'wins'          => 'wins',
    'draws'         => 'draws',
    'losses'        => 'losses',
    'goals'         => 'goals',
    'penalty_goals' => 'penalty_goals',
    'assists'       => 'assists',
    'yellow_cards'  => 'yellow_cards',
    'red_cards'     => 'red_cards',
    'pct'           => 'pct',
    'titles'        => 'titles',
    'j'             => 'games',
    'v'             => 'wins',
    'e'             => 'draws',
    'd'             => 'losses',
];
$orderCol = $sortMap[$sort] ?? 'games';
$orderDir = ($dir === 'asc') ? 'ASC' : 'DESC';

/* -----------------------------------------------------------------------------
 * Estrutura dinâmica
 * -------------------------------------------------------------------------- */
$playersHasUserId      = table_has_user_id($pdo, 'players');
$matchesHasUserId      = table_has_user_id($pdo, 'matches');
$matchPlayersHasUserId = table_exists($pdo, 'match_players') && table_has_user_id($pdo, 'match_players');
$matchSubsHasUserId    = table_exists($pdo, 'match_substitutions') && table_has_user_id($pdo, 'match_substitutions');
$statsHasUserId        = table_exists($pdo, 'match_player_stats') && table_has_user_id($pdo, 'match_player_stats');
$trophiesHasUserId     = table_exists($pdo, 'trophies') && table_has_user_id($pdo, 'trophies');

$statsCols      = table_exists($pdo, 'match_player_stats') ? table_columns($pdo, 'match_player_stats') : [];
$trophiesExists = table_exists($pdo, 'trophies');
$trophiesCols   = $trophiesExists ? table_columns($pdo, 'trophies') : [];
$matchPlayersCols = table_exists($pdo, 'match_players') ? table_columns($pdo, 'match_players') : [];
$shirtHistoryExists = table_exists($pdo, 'player_shirt_history');
$shirtHistoryHasUserId = $shirtHistoryExists && table_has_user_id($pdo, 'player_shirt_history');
$matchPlayersHasShirtSnapshot = in_array('shirt_number_snapshot', $matchPlayersCols, true);

$goalsCol = null;
foreach (['goals_for', 'goals'] as $c) {
    if (in_array($c, $statsCols, true)) {
        $goalsCol = $c;
        break;
    }
}
$assistsCol = in_array('assists', $statsCols, true) ? 'assists' : null;
$yellowCol  = in_array('yellow_cards', $statsCols, true) ? 'yellow_cards' : null;
$redCol     = in_array('red_cards', $statsCols, true) ? 'red_cards' : null;

$trophySeasonCol = null;
foreach (['season', 'temporada'] as $c) {
    if (in_array($c, $trophiesCols, true)) {
        $trophySeasonCol = $c;
        break;
    }
}

$trophyDateCol = null;
foreach (['achieved_at', 'title_date'] as $c) {
    if (in_array($c, $trophiesCols, true)) {
        $trophyDateCol = $c;
        break;
    }
}

$trophyCompetitionCol = null;
foreach (['competition_name', 'competition', 'championship', 'title_name'] as $c) {
    if (in_array($c, $trophiesCols, true)) {
        $trophyCompetitionCol = $c;
        break;
    }
}

/* -----------------------------------------------------------------------------
 * Expressões auxiliares
 * -------------------------------------------------------------------------- */
$clubNorm = "UPPER(TRIM(:club))";
$homeNorm = "UPPER(TRIM(COALESCE(m.home, '')))";
$awayNorm = "UPPER(TRIM(COALESCE(m.away, '')))";
$isClubInMatch = "($homeNorm = $clubNorm OR $awayNorm = $clubNorm)";

$goalsExpr   = $goalsCol   ? "COALESCE(s.$goalsCol, 0)" : "0";
$assistsExpr = $assistsCol ? "COALESCE(s.$assistsCol, 0)" : "0";
$yellowExpr  = $yellowCol  ? "COALESCE(s.$yellowCol, 0)" : "0";
$redExpr     = $redCol     ? "COALESCE(s.$redCol, 0)" : "0";

/* -----------------------------------------------------------------------------
 * Opções de filtro
 * -------------------------------------------------------------------------- */
$seasonSql = "
    SELECT DISTINCT TRIM(m.season) AS season
    FROM matches m
    WHERE {$isClubInMatch}
";
$seasonParams = [':club' => $club];
if ($matchesHasUserId) {
    $seasonSql .= " AND m.user_id = :user_id";
    $seasonParams[':user_id'] = $userId;
}
$seasonSql .= "
      AND TRIM(COALESCE(m.season, '')) <> ''
    ORDER BY CAST(TRIM(m.season) AS INTEGER) DESC, TRIM(m.season) DESC
";
$st = $pdo->prepare($seasonSql);
$st->execute($seasonParams);
$seasonOptions = array_map(static fn(array $r): string => (string)$r['season'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);

$compSql = "
    SELECT DISTINCT TRIM(m.competition) AS competition
    FROM matches m
    WHERE {$isClubInMatch}
";
$compParams = [':club' => $club];
if ($matchesHasUserId) {
    $compSql .= " AND m.user_id = :user_id";
    $compParams[':user_id'] = $userId;
}
$compSql .= " AND TRIM(COALESCE(m.competition, '')) <> ''";
if ($season !== '') {
    $compSql .= " AND UPPER(TRIM(COALESCE(m.season, ''))) = UPPER(TRIM(:season))";
    $compParams[':season'] = $season;
}
$compSql .= " ORDER BY TRIM(m.competition) ASC";

$st = $pdo->prepare($compSql);
$st->execute($compParams);
$competitionOptions = array_map(static fn(array $r): string => (string)$r['competition'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);

/* -----------------------------------------------------------------------------
 * Participação real filtrada
 * -------------------------------------------------------------------------- */
$realParticipationSql = pm_alm_real_participation_sql(
    $clubNorm,
    $matchPlayersHasUserId,
    $matchSubsHasUserId,
    $matchesHasUserId
);

$filteredParticipationSql = "
    SELECT
        part.player_id,
        part.match_id,
        m.match_date,
        m.season,
        m.competition,
        m.home,
        m.away,
        m.home_score,
        m.away_score
    FROM (
        {$realParticipationSql}
    ) part
    JOIN matches m
      ON m.id = part.match_id
    WHERE {$isClubInMatch}
";

$filteredParams = [':club' => $club];
if ($matchPlayersHasUserId || $matchSubsHasUserId || $matchesHasUserId) {
    $filteredParams[':user_id'] = $userId;
}
if ($matchesHasUserId) {
    $filteredParticipationSql .= " AND m.user_id = :user_id";
}

if ($season !== '') {
    $filteredParticipationSql .= " AND UPPER(TRIM(COALESCE(m.season, ''))) = UPPER(TRIM(:season))";
    $filteredParams[':season'] = $season;
}
if ($competition !== '') {
    $filteredParticipationSql .= " AND UPPER(TRIM(COALESCE(m.competition, ''))) = UPPER(TRIM(:competition))";
    $filteredParams[':competition'] = $competition;
}

/* -----------------------------------------------------------------------------
 * Consolidação principal
 * -------------------------------------------------------------------------- */
$sql = "
    SELECT
        p.id AS player_id,
        p.name AS player_name,
        COALESCE(COUNT(fp.match_id), 0) AS games,
        COALESCE(SUM(CASE
            WHEN fp.match_id IS NOT NULL AND
                 (CASE
                    WHEN UPPER(TRIM(COALESCE(fp.home, ''))) = UPPER(TRIM(:club))
                    THEN COALESCE(fp.home_score, 0)
                    ELSE COALESCE(fp.away_score, 0)
                  END) >
                 (CASE
                    WHEN UPPER(TRIM(COALESCE(fp.home, ''))) = UPPER(TRIM(:club))
                    THEN COALESCE(fp.away_score, 0)
                    ELSE COALESCE(fp.home_score, 0)
                  END)
            THEN 1 ELSE 0 END), 0) AS wins,
        COALESCE(SUM(CASE
            WHEN fp.match_id IS NOT NULL AND
                 (CASE
                    WHEN UPPER(TRIM(COALESCE(fp.home, ''))) = UPPER(TRIM(:club))
                    THEN COALESCE(fp.home_score, 0)
                    ELSE COALESCE(fp.away_score, 0)
                  END) =
                 (CASE
                    WHEN UPPER(TRIM(COALESCE(fp.home, ''))) = UPPER(TRIM(:club))
                    THEN COALESCE(fp.away_score, 0)
                    ELSE COALESCE(fp.home_score, 0)
                  END)
            THEN 1 ELSE 0 END), 0) AS draws,
        COALESCE(SUM(CASE
            WHEN fp.match_id IS NOT NULL AND
                 (CASE
                    WHEN UPPER(TRIM(COALESCE(fp.home, ''))) = UPPER(TRIM(:club))
                    THEN COALESCE(fp.home_score, 0)
                    ELSE COALESCE(fp.away_score, 0)
                  END) <
                 (CASE
                    WHEN UPPER(TRIM(COALESCE(fp.home, ''))) = UPPER(TRIM(:club))
                    THEN COALESCE(fp.away_score, 0)
                    ELSE COALESCE(fp.home_score, 0)
                  END)
            THEN 1 ELSE 0 END), 0) AS losses,
        COALESCE(SUM({$goalsExpr}), 0) AS goals,
        COALESCE(SUM({$assistsExpr}), 0) AS assists,
        COALESCE(SUM({$yellowExpr}), 0) AS yellow_cards,
        COALESCE(SUM({$redExpr}), 0) AS red_cards,
        0 AS penalty_goals
    FROM players p
    LEFT JOIN (
        {$filteredParticipationSql}
    ) fp
      ON fp.player_id = p.id
    LEFT JOIN match_player_stats s
      ON s.match_id = fp.match_id
     AND s.player_id = fp.player_id
";

$params = $filteredParams;
if ($statsHasUserId) {
    $sql .= " AND s.user_id = :user_id";
}

$whereOuter = [];
if ($playersHasUserId) {
    $whereOuter[] = "p.user_id = :user_id";
}
if ($qPlayer !== '') {
    $whereOuter[] = "UPPER(TRIM(COALESCE(p.name, ''))) LIKE UPPER(TRIM(:q))";
    $params[':q'] = '%' . $qPlayer . '%';
}
if ($whereOuter) {
    $sql .= "\nWHERE " . implode(' AND ', $whereOuter);
}

$sql .= "\nGROUP BY p.id, p.name";

$rows = q($pdo, $sql, $params)->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* -----------------------------------------------------------------------------
 * Gols de Pênaltis (Separado)
 * -------------------------------------------------------------------------- */
$penaltyGoals = [];
if (table_exists($pdo, 'match_penalties')) {
    $penaltiesHasUserId = table_has_user_id($pdo, 'match_penalties');
    
    $penSql = "
        SELECT
            TRIM(pen.player_name) AS player_name,
            SUM(CASE WHEN pen.scored = 1 THEN 1 ELSE 0 END) AS penalty_goals
        FROM match_penalties pen
        INNER JOIN matches m ON m.id = pen.match_id
        WHERE {$isClubInMatch}
    ";
    $penParams = [':club' => $club];
    
    if ($penaltiesHasUserId) {
        $penSql .= " AND pen.user_id = :user_id";
        $penParams[':user_id'] = $userId;
    }
    
    if ($matchesHasUserId) {
        $penSql .= " AND m.user_id = :user_id";
        if (!isset($penParams[':user_id'])) {
            $penParams[':user_id'] = $userId;
        }
    }
    
    if ($season !== '') {
        $penSql .= " AND UPPER(TRIM(COALESCE(m.season, ''))) = UPPER(TRIM(:season))";
        $penParams[':season'] = $season;
    }
    
    if ($competition !== '') {
        $penSql .= " AND UPPER(TRIM(COALESCE(m.competition, ''))) = UPPER(TRIM(:competition))";
        $penParams[':competition'] = $competition;
    }
    
    $penSql .= " GROUP BY TRIM(pen.player_name)";
    
    $penRows = q($pdo, $penSql, $penParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    foreach ($penRows as $pr) {
        $pname = trim((string)($pr['player_name'] ?? ''));
        if ($pname !== '') {
            $penaltyGoals[strtoupper($pname)] = (int)($pr['penalty_goals'] ?? 0);
        }
    }
}

// Adicionar gols de pênaltis aos jogadores
foreach ($rows as &$r) {
    $playerName = strtoupper(trim((string)($r['player_name'] ?? '')));
    $r['penalty_goals'] = $penaltyGoals[$playerName] ?? 0;
}
unset($r);

/* -----------------------------------------------------------------------------
 * Títulos
 * -------------------------------------------------------------------------- */
$titleCounts = [];
if ($trophiesExists && ($trophyDateCol !== null || $trophySeasonCol !== null)) {
    $playerSql = "SELECT id, created_at FROM players";
    $playerParams = [];
    if ($playersHasUserId) {
        $playerSql .= " WHERE user_id = ?";
        $playerParams[] = $userId;
    }
    $playerMetaRows = q($pdo, $playerSql, $playerParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $playerBaselineDates = [];
    foreach ($playerMetaRows as $pr) {
        $playerBaselineDates[(int)$pr['id']] = alm_players_norm_date((string)($pr['created_at'] ?? ''));
    }

    // OBS: Para títulos com data (achieved_at/title_date), a elegibilidade deve ser baseada no elenco ativo
    // na data do título (ex.: via transfers). Não use 'estreia/participação em jogo' como proxy de chegada.

    $transferTimelineByPlayer = [];
    if (table_exists($pdo, 'transfers')) {
        $transfersHasUserId = table_has_user_id($pdo, 'transfers');
        $transferCols = table_columns($pdo, 'transfers');

        $transferTypeCol = null;
        foreach (['type', 'transfer_type', 'movement_type'] as $c) {
            if (in_array($c, $transferCols, true)) {
                $transferTypeCol = $c;
                break;
            }
        }

        $transferEffectiveDateCol = null;
        foreach ([
            'effective_date',
            'presentation_date',
            'release_date',
            'transfer_date',
            'date',
            'transaction_date',
            'created_at',
        ] as $c) {
            if (in_array($c, $transferCols, true)) {
                $transferEffectiveDateCol = $c;
                break;
            }
        }

        if ($transferTypeCol !== null && $transferEffectiveDateCol !== null) {
            $transferSql = "
                SELECT
                    player_id,
                    {$transferTypeCol} AS type,
                    {$transferEffectiveDateCol} AS effective_date
                FROM transfers
                WHERE player_id IS NOT NULL
            ";
            $transferParams = [];
            if ($transfersHasUserId) {
                $transferSql .= " AND user_id = ?";
                $transferParams[] = $userId;
            }
            $transferSql .= " ORDER BY date({$transferEffectiveDateCol}) ASC, id ASC";

            $transferRows = q($pdo, $transferSql, $transferParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($transferRows as $tr) {
                $pid  = (int)($tr['player_id'] ?? 0);
                $type = trim((string)($tr['type'] ?? ''));
                $date = alm_players_norm_date((string)($tr['effective_date'] ?? ''));
                if ($pid <= 0 || $date === null || $type === '') {
                    continue;
                }

                if (alm_players_is_activate_transfer($type)) {
                    $transferTimelineByPlayer[$pid][] = ['date' => $date, 'kind' => 'activate'];
                } elseif (alm_players_is_deactivate_transfer($type)) {
                    $transferTimelineByPlayer[$pid][] = ['date' => $date, 'kind' => 'deactivate'];
                }
            }
        }
    }

    foreach ($transferTimelineByPlayer as &$timeline) {
        usort($timeline, static function (array $a, array $b): int {
            $cmp = strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            $wa = (($a['kind'] ?? '') === 'activate') ? 1 : 2;
            $wb = (($b['kind'] ?? '') === 'activate') ? 1 : 2;
            return $wa <=> $wb;
        });
    }
    unset($timeline);

    $trophySql = "SELECT * FROM trophies";
    $trophyParams = [];
    if ($trophiesHasUserId) {
        $trophySql .= " WHERE user_id = ?";
        $trophyParams[] = $userId;
    }
    $allTrophies = q($pdo, $trophySql, $trophyParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($playerBaselineDates as $pid => $baselineDate) {
        $timeline = $transferTimelineByPlayer[$pid] ?? [];
        $count = 0;

        foreach ($allTrophies as $tr) {
            $trophySeason = trim((string)($trophySeasonCol !== null ? ($tr[$trophySeasonCol] ?? '') : ''));
            $trophyDate   = trim((string)($trophyDateCol !== null ? ($tr[$trophyDateCol] ?? '') : ''));
            $trophyComp   = trim((string)($trophyCompetitionCol !== null ? ($tr[$trophyCompetitionCol] ?? '') : ''));

            if ($season !== '' && $trophySeasonCol !== null && strcasecmp($trophySeason, $season) !== 0) {
                continue;
            }
            if ($competition !== '' && $trophyCompetitionCol !== null && strcasecmp($trophyComp, $competition) !== 0) {
                continue;
            }

            if ($trophyDate !== '') {
                if (alm_players_active_on_date($trophyDate, $baselineDate, $timeline)) {
                    $count++;
                }
                continue;
            }

            if ($trophySeason !== '') {
                $baselineSeason = null;
                if ($baselineDate !== null) {
                    $baselineSeason = date('Y', strtotime($baselineDate));
                }
                if ($baselineSeason !== null && $baselineSeason <= $trophySeason) {
                    $count++;
                }
            }
        }

        $titleCounts[$pid] = $count;
    }
}

foreach ($rows as &$r) {
    $games = (int)($r['games'] ?? 0);
    $wins  = (int)($r['wins'] ?? 0);
    $draws = (int)($r['draws'] ?? 0);
    $points = ($wins * 3) + $draws;
    $pct = $games > 0 ? round(($points * 100) / ($games * 3), 2) : 0.0;

    $r['pct'] = $pct;
    $r['titles'] = (int)($titleCounts[(int)$r['player_id']] ?? 0);
}
unset($r);

usort($rows, static function(array $a, array $b) use ($orderCol, $orderDir): int {
    $av = $a[$orderCol] ?? null;
    $bv = $b[$orderCol] ?? null;

    $isNumeric = in_array($orderCol, [
        'games', 'wins', 'draws', 'losses', 'goals', 'assists',
        'yellow_cards', 'red_cards', 'pct', 'titles', 'penalty_goals'
    ], true);

    if ($isNumeric) {
        $cmp = (float)$av <=> (float)$bv;
    } else {
        $cmp = strcasecmp((string)$av, (string)$bv);
    }

    if ($cmp === 0) {
        $cmp = strcasecmp((string)($a['player_name'] ?? ''), (string)($b['player_name'] ?? ''));
    }

    return $orderDir === 'ASC' ? $cmp : -$cmp;
});

/* -----------------------------------------------------------------------------
 * DETALHE DO JOGADOR
 * -------------------------------------------------------------------------- */
if ($playerId > 0) {
    $playerSql = "SELECT * FROM players WHERE id = ?";
    $playerParams = [$playerId];
    if ($playersHasUserId) {
        $playerSql .= " AND user_id = ?";
        $playerParams[] = $userId;
    }
    $player = q($pdo, $playerSql, $playerParams)->fetch(PDO::FETCH_ASSOC);

    render_header('Almanaque de Jogadores');

    echo '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">';
    echo '  <div>';
    echo '    <h2 class="mb-1">Resumo do jogador</h2>';
    echo '    <div class="text-muted">Estatísticas consolidadas e partidas efetivamente disputadas.</div>';
    echo '  </div>';
    echo '  <div>';
    echo '    <a class="btn btn-secondary" href="' . h(alm_players_build_url(['player_id' => null])) . '">Voltar ao consolidado</a>';
    echo '  </div>';
    echo '</div>';

    if (!$player) {
        echo '<div class="alert alert-warning">Jogador não encontrado.</div>';
        render_footer();
        exit;
    }

    $summary = null;
    foreach ($rows as $r) {
        if ((int)$r['player_id'] === $playerId) {
            $summary = $r;
            break;
        }
    }

    if (!$summary) {
        $summary = [
            'player_id'     => $playerId,
            'player_name'   => (string)($player['name'] ?? '—'),
            'games'         => 0,
            'wins'          => 0,
            'draws'         => 0,
            'losses'        => 0,
            'goals'         => 0,
            'assists'       => 0,
            'yellow_cards'  => 0,
            'red_cards'     => 0,
            'pct'           => 0,
            'titles'        => 0,
        ];
    }

    echo '<div class="card mb-3">';
    echo '  <div class="card-body">';
    echo '    <h3 class="mb-3">' . h((string)$summary['player_name']) . '</h3>';
    echo '    <div class="table-responsive">';
    echo '      <table class="table table-sm align-middle mb-0">';
    echo '        <thead>';
    echo '          <tr>';
    echo '            <th>J</th>';
    echo '            <th>V</th>';
    echo '            <th>E</th>';
    echo '            <th>D</th>';
    echo '            <th>Gols</th>';
    echo '            <th>Assistências</th>';
    echo '            <th>Amarelos</th>';
    echo '            <th>Vermelhos</th>';
    echo '            <th>% AP</th>';
    echo '            <th>Títulos</th>';
    echo '          </tr>';
    echo '        </thead>';
    echo '        <tbody>';
    echo '          <tr>';
    echo '            <td>' . (int)$summary['games'] . '</td>';
    echo '            <td>' . (int)$summary['wins'] . '</td>';
    echo '            <td>' . (int)$summary['draws'] . '</td>';
    echo '            <td>' . (int)$summary['losses'] . '</td>';
    echo '            <td>' . (int)$summary['goals'] . '</td>';
    echo '            <td>' . (int)$summary['assists'] . '</td>';
    echo '            <td>' . (int)$summary['yellow_cards'] . '</td>';
    echo '            <td>' . (int)$summary['red_cards'] . '</td>';
    echo '            <td>' . alm_players_fmt_pct($summary['pct']) . '</td>';
    echo '            <td>' . (int)$summary['titles'] . '</td>';
    echo '          </tr>';
    echo '        </tbody>';
    echo '      </table>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    $shirtHistoryRows = [];
    if ($shirtHistoryExists) {
        $shirtSql = "
            SELECT
                shirt_number,
                start_date,
                end_date,
                notes
            FROM player_shirt_history
            WHERE player_id = :player_id
        ";
        $shirtParams = [
            ':player_id' => $playerId,
        ];
        if ($shirtHistoryHasUserId) {
            $shirtSql .= " AND user_id = :user_id";
            $shirtParams[':user_id'] = $userId;
        }
        $shirtSql .= " ORDER BY date(start_date) ASC, id ASC";

        $shirtHistoryRows = q($pdo, $shirtSql, $shirtParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    echo '<div class="card mb-3">';
    echo '  <div class="card-body">';
    echo '    <h4 class="mb-3">Histórico de camisas</h4>';

    if (!$shirtHistoryRows) {
        echo '<div class="alert alert-secondary mb-0">Nenhum histórico de camisa registrado para este atleta.</div>';
    } else {
        echo '<div class="table-responsive">';
        echo '  <table class="table table-striped table-hover align-middle mb-0">';
        echo '    <thead>';
        echo '      <tr>';
        echo '        <th style="width:120px">Camisa</th>';
        echo '        <th style="width:160px">Início</th>';
        echo '        <th style="width:160px">Fim</th>';
        echo '        <th>Observações</th>';
        echo '      </tr>';
        echo '    </thead>';
        echo '    <tbody>';

        foreach ($shirtHistoryRows as $sh) {
            $shirtLabel = trim((string)($sh['shirt_number'] ?? ''));
            if ($shirtLabel === '') {
                $shirtLabel = '—';
            }

            $endDate = trim((string)($sh['end_date'] ?? ''));
            $endLabel = $endDate !== '' ? alm_players_fmt_date_br($endDate) : 'Atual';

            echo '      <tr>';
            echo '        <td>' . h($shirtLabel) . '</td>';
            echo '        <td>' . alm_players_fmt_date_br((string)($sh['start_date'] ?? '')) . '</td>';
            echo '        <td>' . h($endLabel) . '</td>';
            echo '        <td>' . h((string)($sh['notes'] ?? '')) . '</td>';
            echo '      </tr>';
        }

        echo '    </tbody>';
        echo '  </table>';
        echo '</div>';
    }

    echo '  </div>';
    echo '</div>';

    $shirtMatchExpr = $matchPlayersHasShirtSnapshot
        ? "COALESCE(mp.shirt_number_snapshot, p.shirt_number)"
        : "p.shirt_number";

    $detailSql = "
        SELECT
            fp.match_id AS id,
            fp.match_date,
            fp.season,
            fp.competition,
            fp.home,
            fp.away,
            {$shirtMatchExpr} AS shirt_number,
            CASE
                WHEN UPPER(TRIM(COALESCE(fp.home, ''))) = UPPER(TRIM(:club))
                THEN COALESCE(fp.home_score, 0)
                ELSE COALESCE(fp.away_score, 0)
            END AS gf,
            CASE
                WHEN UPPER(TRIM(COALESCE(fp.home, ''))) = UPPER(TRIM(:club))
                THEN COALESCE(fp.away_score, 0)
                ELSE COALESCE(fp.home_score, 0)
            END AS ga,
            {$goalsExpr} AS goals,
            {$assistsExpr} AS assists,
            {$yellowExpr} AS yellow_cards,
            {$redExpr} AS red_cards,
            CASE
                WHEN
                    (CASE
                        WHEN UPPER(TRIM(COALESCE(fp.home, ''))) = UPPER(TRIM(:club))
                        THEN COALESCE(fp.home_score, 0)
                        ELSE COALESCE(fp.away_score, 0)
                     END)
                    >
                    (CASE
                        WHEN UPPER(TRIM(COALESCE(fp.home, ''))) = UPPER(TRIM(:club))
                        THEN COALESCE(fp.away_score, 0)
                        ELSE COALESCE(fp.home_score, 0)
                     END)
                THEN 'Vitória'
                WHEN
                    (CASE
                        WHEN UPPER(TRIM(COALESCE(fp.home, ''))) = UPPER(TRIM(:club))
                        THEN COALESCE(fp.home_score, 0)
                        ELSE COALESCE(fp.away_score, 0)
                     END)
                    =
                    (CASE
                        WHEN UPPER(TRIM(COALESCE(fp.home, ''))) = UPPER(TRIM(:club))
                        THEN COALESCE(fp.away_score, 0)
                        ELSE COALESCE(fp.home_score, 0)
                     END)
                THEN 'Empate'
                ELSE 'Derrota'
            END AS result_text
        FROM (
            {$filteredParticipationSql}
        ) fp
        LEFT JOIN match_player_stats s
          ON s.match_id = fp.match_id
         AND s.player_id = fp.player_id
    ";

    if ($statsHasUserId) {
        $detailSql .= " AND s.user_id = :user_id";
    }

    $detailSql .= "
        LEFT JOIN match_players mp
          ON mp.match_id = fp.match_id
         AND mp.player_id = fp.player_id
         AND UPPER(TRIM(COALESCE(mp.club_name, ''))) = UPPER(TRIM(:club))
    ";

    if ($matchPlayersHasUserId) {
        $detailSql .= " AND mp.user_id = :user_id";
    }

    $detailSql .= "
        LEFT JOIN players p
          ON p.id = fp.player_id
    ";

    if ($playersHasUserId) {
        $detailSql .= " AND p.user_id = :user_id";
    }

    $detailSql .= "
        WHERE fp.player_id = :player_id
        ORDER BY date(fp.match_date) DESC, fp.match_id DESC
    ";

    $detailParams = $filteredParams;
    $detailParams[':player_id'] = $playerId;

    $matches = q($pdo, $detailSql, $detailParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo '<div class="card">';
    echo '  <div class="card-body">';
    echo '    <h4 class="mb-3">Lista de jogos</h4>';
    echo '    <div class="text-muted mb-3">Somente partidas em que o atleta realmente entrou em campo.</div>';

    if (!$matches) {
        echo '<div class="alert alert-secondary mb-0">Este atleta não possui partidas registradas com os filtros aplicados.</div>';
    } else {
        echo '<div class="table-responsive">';
        echo '  <table class="table table-striped table-hover align-middle">';
        echo '    <thead>';
        echo '      <tr>';
        echo '        <th>Data</th>';
        echo '        <th>Temporada</th>';
        echo '        <th>Campeonato</th>';
        echo '        <th>Jogo</th>';
        echo '        <th>#</th>';
        echo '        <th>GF</th>';
        echo '        <th>GC</th>';
        echo '        <th>Resultado</th>';
        echo '        <th>Gols</th>';
        echo '        <th>Assist.</th>';
        echo '        <th>Amarelos</th>';
        echo '        <th>Vermelhos</th>';
        echo '        <th>Ações</th>';
        echo '      </tr>';
        echo '    </thead>';
        echo '    <tbody>';

        foreach ($matches as $m) {
            echo '      <tr>';
            echo '        <td>' . alm_players_fmt_date_br((string)$m['match_date']) . '</td>';
            echo '        <td>' . h((string)$m['season']) . '</td>';
            echo '        <td>' . h((string)$m['competition']) . '</td>';
            echo '        <td>' . h((string)$m['home']) . ' x ' . h((string)$m['away']) . '</td>';
            echo '        <td>' . h((string)($m['shirt_number'] ?? '')) . '</td>';
            echo '        <td>' . (int)$m['gf'] . '</td>';
            echo '        <td>' . (int)$m['ga'] . '</td>';
            echo '        <td>' . h((string)$m['result_text']) . '</td>';
            echo '        <td>' . (int)$m['goals'] . '</td>';
            echo '        <td>' . (int)$m['assists'] . '</td>';
            echo '        <td>' . (int)$m['yellow_cards'] . '</td>';
            echo '        <td>' . (int)$m['red_cards'] . '</td>';
            echo '        <td><a class="btn btn-sm btn-primary" href="index.php?page=match&id=' . (int)$m['id'] . '">Abrir</a></td>';
            echo '      </tr>';
        }

        echo '    </tbody>';
        echo '  </table>';
        echo '</div>';
    }

    echo '  </div>';
    echo '</div>';

    render_footer();
    exit;
}

/* -----------------------------------------------------------------------------
 * CONSOLIDADO
 * -------------------------------------------------------------------------- */
render_header('Almanaque de Jogadores');

echo '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">';
echo '  <div>';
echo '    <h2 class="mb-1">Almanaque de Jogadores</h2>';
echo '    <div class="text-muted">Todos os jogadores que já passaram pelo elenco profissional, tenham jogado ou não.</div>';
echo '  </div>';
echo '</div>';

echo '<div class="card mb-3">';
echo '  <div class="card-body">';
echo '    <form method="get" class="row g-3 align-items-end">';
echo '      <input type="hidden" name="page" value="almanaque_players">';

echo '      <div class="col-lg-4">';
echo '        <label class="form-label">Buscar jogador</label>';
echo '        <input type="text" name="q" class="form-control" value="' . h($qPlayer) . '">';
echo '      </div>';

echo '      <div class="col-lg-3">';
echo '        <label class="form-label">Temporada</label>';
echo '        <select name="season" class="form-select">';
echo '          <option value="">Todas</option>';
foreach ($seasonOptions as $opt) {
    echo '      <option value="' . h($opt) . '"' . ($season === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
}
echo '        </select>';
echo '      </div>';

echo '      <div class="col-lg-3">';
echo '        <label class="form-label">Campeonato</label>';
echo '        <select name="competition" class="form-select">';
echo '          <option value="">Todos</option>';
foreach ($competitionOptions as $opt) {
    echo '      <option value="' . h($opt) . '"' . ($competition === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
}
echo '        </select>';
echo '      </div>';

echo '      <div class="col-lg-2 d-flex gap-2">';
echo '        <button type="submit" class="btn w-100 btn-primary">Aplicar</button>';
echo '        <a class="btn w-100 btn-secondary" href="index.php?page=almanaque_players">Limpar</a>';
echo '      </div>';

echo '    </form>';
echo '  </div>';
echo '</div>';

if (!$rows) {
    echo '<div class="alert alert-secondary">Sem resultados para os filtros informados.</div>';
    render_footer();
    exit;
}

echo '<div class="card">';
echo '  <div class="card-body">';
echo '    <div class="table-responsive">';
echo '      <table class="table table-striped table-hover align-middle">';
echo '        <thead>';
echo '          <tr>';
echo '            <th>' . alm_players_sort_link('name', 'Jogador', $sort, $dir) . '</th>';
echo '            <th>' . alm_players_sort_link('games', 'J', $sort, $dir) . '</th>';
echo '            <th>' . alm_players_sort_link('wins', 'V', $sort, $dir) . '</th>';
echo '            <th>' . alm_players_sort_link('draws', 'E', $sort, $dir) . '</th>';
echo '            <th>' . alm_players_sort_link('losses', 'D', $sort, $dir) . '</th>';
echo '            <th>' . alm_players_sort_link('goals', 'Gols', $sort, $dir) . '</th>';
echo '            <th>' . alm_players_sort_link('penalty_goals', 'Gols (Pên)', $sort, $dir) . '</th>';
echo '            <th>' . alm_players_sort_link('assists', 'Assist.', $sort, $dir) . '</th>';
echo '            <th>' . alm_players_sort_link('yellow_cards', 'Amarelos', $sort, $dir) . '</th>';
echo '            <th>' . alm_players_sort_link('red_cards', 'Vermelhos', $sort, $dir) . '</th>';
echo '            <th>' . alm_players_sort_link('pct', '% AP', $sort, $dir) . '</th>';
echo '            <th>' . alm_players_sort_link('titles', 'Títulos', $sort, $dir) . '</th>';
echo '            <th>Ações</th>';
echo '          </tr>';
echo '        </thead>';
echo '        <tbody>';

foreach ($rows as $r) {
    echo '      <tr>';
    echo '        <td>' . h((string)$r['player_name']) . '</td>';
    echo '        <td>' . (int)$r['games'] . '</td>';
    echo '        <td>' . (int)$r['wins'] . '</td>';
    echo '        <td>' . (int)$r['draws'] . '</td>';
    echo '        <td>' . (int)$r['losses'] . '</td>';
    echo '        <td>' . (int)$r['goals'] . '</td>';
    echo '        <td>' . (int)$r['penalty_goals'] . '</td>';
    echo '        <td>' . (int)$r['assists'] . '</td>';
    echo '        <td>' . (int)$r['yellow_cards'] . '</td>';
    echo '        <td>' . (int)$r['red_cards'] . '</td>';
    echo '        <td>' . alm_players_fmt_pct($r['pct']) . '</td>';
    echo '        <td>' . (int)$r['titles'] . '</td>';
    echo '        <td><a class="btn btn-sm btn-primary" href="' . h(alm_players_build_url([
        'player_id' => (int)$r['player_id'],
    ])) . '">Abrir</a></td>';
    echo '      </tr>';
}

echo '        </tbody>';
echo '      </table>';
echo '    </div>';
echo '  </div>';
echo '</div>';

render_footer();