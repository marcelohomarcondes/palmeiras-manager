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
 */

$club = function_exists('app_club') ? (string)app_club() : 'PALMEIRAS';

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
    function pm_alm_real_participation_sql(string $clubNormExpr): string {
        return "
            SELECT DISTINCT
                mp.match_id,
                mp.player_id
            FROM match_players mp
            WHERE mp.player_id IS NOT NULL
              AND UPPER(TRIM(COALESCE(mp.club_name, ''))) = {$clubNormExpr}
              AND UPPER(TRIM(COALESCE(mp.role, ''))) = 'STARTER'

            UNION

            SELECT DISTINCT
                ms.match_id,
                ms.player_in_id AS player_id
            FROM match_substitutions ms
            JOIN matches mx
              ON mx.id = ms.match_id
            WHERE ms.player_in_id IS NOT NULL
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
$statsCols      = table_exists($pdo, 'match_player_stats') ? table_columns($pdo, 'match_player_stats') : [];
$trophiesExists = table_exists($pdo, 'trophies');
$trophiesCols   = $trophiesExists ? table_columns($pdo, 'trophies') : [];

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
$st = $pdo->prepare("
    SELECT DISTINCT TRIM(m.season) AS season
    FROM matches m
    WHERE {$isClubInMatch}
      AND TRIM(COALESCE(m.season, '')) <> ''
    ORDER BY CAST(TRIM(m.season) AS INTEGER) DESC, TRIM(m.season) DESC
");
$st->execute([':club' => $club]);
$seasonOptions = array_map(static fn(array $r): string => (string)$r['season'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);

$compSql = "
    SELECT DISTINCT TRIM(m.competition) AS competition
    FROM matches m
    WHERE {$isClubInMatch}
      AND TRIM(COALESCE(m.competition, '')) <> ''
";
$compParams = [':club' => $club];
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
$realParticipationSql = pm_alm_real_participation_sql($clubNorm);

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
        COALESCE(SUM({$redExpr}), 0) AS red_cards
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

$whereOuter = [];
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
 * Títulos
 * -------------------------------------------------------------------------- */
$titleCounts = [];
if ($trophiesExists && $trophySeasonCol !== null) {
    $sqlPlayerSeasons = "
        SELECT DISTINCT
            fp.player_id,
            TRIM(COALESCE(fp.season, '')) AS season
        FROM (
            {$filteredParticipationSql}
        ) fp
        WHERE TRIM(COALESCE(fp.season, '')) <> ''
    ";
    $playerSeasons = q($pdo, $sqlPlayerSeasons, $filteredParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $seasonsByPlayer = [];
    foreach ($playerSeasons as $ps) {
        $pid = (int)$ps['player_id'];
        $ss  = trim((string)$ps['season']);
        if ($pid <= 0 || $ss === '') continue;
        $seasonsByPlayer[$pid][$ss] = true;
    }

    $allTrophies = q($pdo, "SELECT * FROM trophies")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($seasonsByPlayer as $pid => $seasonMap) {
        $count = 0;
        foreach ($allTrophies as $tr) {
            $tSeason = trim((string)($tr[$trophySeasonCol] ?? ''));
            if ($tSeason !== '' && isset($seasonMap[$tSeason])) {
                $count++;
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
        'yellow_cards', 'red_cards', 'pct', 'titles'
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
    $player = q($pdo, "SELECT * FROM players WHERE id = ?", [$playerId])->fetch(PDO::FETCH_ASSOC);

    render_header('Almanaque de Jogadores');

    echo '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">';
    echo '  <div>';
    echo '    <h2 class="mb-1">Resumo do jogador</h2>';
    echo '    <div class="text-muted">Estatísticas consolidadas e partidas efetivamente disputadas.</div>';
    echo '  </div>';
    echo '  <div>';
    echo '    <a class="btn btn-outline-secondary" href="' . h(alm_players_build_url(['player_id' => null])) . '">Voltar ao consolidado</a>';
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

    /**
     * Aqui ficam SOMENTE as partidas em que o atleta realmente participou.
     * Não há join com match_players para banco relacionado; somente a subconsulta
     * de participação real (titular OU entrou no jogo).
     */
    $detailSql = "
        SELECT
            fp.match_id AS id,
            fp.match_date,
            fp.season,
            fp.competition,
            fp.home,
            fp.away,
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
echo '        <a class="btn w-100 btn-outline-secondary" href="index.php?page=almanaque_players">Limpar</a>';
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
    echo '        <td>' . (int)$r['assists'] . '</td>';
    echo '        <td>' . (int)$r['yellow_cards'] . '</td>';
    echo '        <td>' . (int)$r['red_cards'] . '</td>';
    echo '        <td>' . alm_players_fmt_pct($r['pct']) . '</td>';
    echo '        <td>' . (int)$r['titles'] . '</td>';
    echo '        <td><a class="btn btn-sm btn-outline-primary" href="' . h(alm_players_build_url([
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
