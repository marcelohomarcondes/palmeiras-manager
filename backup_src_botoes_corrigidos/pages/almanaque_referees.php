<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$pdo = db();

/*
|--------------------------------------------------------------------------
| Helpers locais (sem conflitar com o projeto)
|--------------------------------------------------------------------------
*/
function alm_ref_build_url(array $overrides = []): string
{
    $params = array_merge($_GET, ['page' => 'almanaque_referees'], $overrides);

    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        }
    }

    return 'index.php?' . http_build_query($params);
}

function alm_ref_fmt_pct($v): string
{
    return number_format((float)$v, 2, ',', '.') . '%';
}

function alm_ref_fmt_date_br(?string $date): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : h((string)$date);
}

function alm_ref_upper(string $s): string
{
    $s = trim($s);

    if ($s === '') {
        return '';
    }

    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($s, 'UTF-8');
    }

    return strtoupper($s);
}

function alm_ref_team_side(array $m): ?string
{
    $home = alm_ref_upper((string)($m['home'] ?? ''));
    $away = alm_ref_upper((string)($m['away'] ?? ''));

    if ($home === 'PALMEIRAS') return 'home';
    if ($away === 'PALMEIRAS') return 'away';

    return null;
}

function alm_ref_result_code(array $m): string
{
    $side = alm_ref_team_side($m);
    $hs   = (int)($m['home_score'] ?? 0);
    $as   = (int)($m['away_score'] ?? 0);

    if ($side === 'home') {
        if ($hs > $as) return 'W';
        if ($hs < $as) return 'L';
        return 'D';
    }

    if ($side === 'away') {
        if ($as > $hs) return 'W';
        if ($as < $hs) return 'L';
        return 'D';
    }

    return '-';
}

function alm_ref_gf_ga(array $m): array
{
    $side = alm_ref_team_side($m);
    $hs   = (int)($m['home_score'] ?? 0);
    $as   = (int)($m['away_score'] ?? 0);

    if ($side === 'home') return [$hs, $as];
    if ($side === 'away') return [$as, $hs];

    return [0, 0];
}

function alm_ref_table_exists(PDO $pdo, string $table): bool
{
    return (bool) scalar(
        $pdo,
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
        [$table]
    );
}

function alm_ref_columns(PDO $pdo, string $table): array
{
    $cols = [];
    $rows = q($pdo, "PRAGMA table_info($table)")->fetchAll();

    foreach ($rows as $r) {
        $cols[] = (string)$r['name'];
    }

    return $cols;
}

function alm_ref_first_existing_column(array $available, array $candidates): ?string
{
    foreach ($candidates as $c) {
        if (in_array($c, $available, true)) {
            return $c;
        }
    }

    return null;
}

function alm_ref_sort_link(string $column, string $label, string $currentSort, string $currentDir): string
{
    $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
    $arrow   = '';

    if ($currentSort === $column) {
        $arrow = $currentDir === 'asc' ? ' ▼' : ' ▲';
    }

    $url = alm_ref_build_url([
        'sort' => $column,
        'dir'  => $nextDir,
    ]);

    return '<a class="text-decoration-none" href="' . h($url) . '">' . h($label . $arrow) . '</a>';
}

/*
|--------------------------------------------------------------------------
| Parâmetros
|--------------------------------------------------------------------------
*/
$referee     = trim((string)($_GET['referee'] ?? ''));
$season      = trim((string)($_GET['season'] ?? ''));
$competition = trim((string)($_GET['competition'] ?? ''));
$qSearch     = trim((string)($_GET['q'] ?? ''));
$sort        = trim((string)($_GET['sort'] ?? 'games'));
$dir         = strtolower(trim((string)($_GET['dir'] ?? 'desc')));

if ($dir !== 'asc' && $dir !== 'desc') {
    $dir = 'desc';
}

/*
|--------------------------------------------------------------------------
| Detecta colunas de cartões em match_player_stats
|--------------------------------------------------------------------------
*/
$yellowCol = null;
$redCol    = null;

if (alm_ref_table_exists($pdo, 'match_player_stats')) {
    $cols = alm_ref_columns($pdo, 'match_player_stats');

    $yellowCol = alm_ref_first_existing_column($cols, [
        'yellow_cards',
        'yellow_card',
        'cartoes_amarelos',
        'cartao_amarelo',
        'amarelos'
    ]);

    $redCol = alm_ref_first_existing_column($cols, [
        'red_cards',
        'red_card',
        'cartoes_vermelhos',
        'cartao_vermelho',
        'vermelhos'
    ]);
}

/*
|--------------------------------------------------------------------------
| Cards por partida
|--------------------------------------------------------------------------
*/
$cardsByMatch = [];

if (($yellowCol || $redCol) && alm_ref_table_exists($pdo, 'match_player_stats')) {
    $select = ['match_id'];

    $select[] = $yellowCol
        ? "SUM(COALESCE($yellowCol, 0)) AS yellow_total"
        : "0 AS yellow_total";

    $select[] = $redCol
        ? "SUM(COALESCE($redCol, 0)) AS red_total"
        : "0 AS red_total";

    $sqlCards = "
        SELECT " . implode(', ', $select) . "
        FROM match_player_stats
        GROUP BY match_id
    ";

    $rowsCards = q($pdo, $sqlCards)->fetchAll();

    foreach ($rowsCards as $r) {
        $cardsByMatch[(int)$r['match_id']] = [
            'yellow' => (int)($r['yellow_total'] ?? 0),
            'red'    => (int)($r['red_total'] ?? 0),
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Opções de filtros
|--------------------------------------------------------------------------
*/
$seasonOptions = q(
    $pdo,
    "SELECT DISTINCT season
       FROM matches
      WHERE COALESCE(TRIM(season), '') <> ''
        AND COALESCE(TRIM(referee), '') <> ''
      ORDER BY season DESC"
)->fetchAll(PDO::FETCH_COLUMN);

$competitionOptions = q(
    $pdo,
    "SELECT DISTINCT competition
       FROM matches
      WHERE COALESCE(TRIM(competition), '') <> ''
        AND COALESCE(TRIM(referee), '') <> ''
      ORDER BY competition ASC"
)->fetchAll(PDO::FETCH_COLUMN);

/*
|--------------------------------------------------------------------------
| Detalhe de um árbitro
|--------------------------------------------------------------------------
*/
if ($referee !== '') {
    $where  = ["TRIM(COALESCE(referee, '')) = ?"];
    $params = [$referee];

    if ($season !== '') {
        $where[]  = "season = ?";
        $params[] = $season;
    }

    if ($competition !== '') {
        $where[]  = "competition = ?";
        $params[] = $competition;
    }

    $sqlMatches = "
        SELECT
            id,
            season,
            competition,
            match_date,
            home,
            away,
            home_score,
            away_score,
            phase,
            round,
            referee
        FROM matches
        WHERE " . implode(' AND ', $where) . "
        ORDER BY date(match_date) DESC, id DESC
    ";

    $rows = q($pdo, $sqlMatches, $params)->fetchAll();

    $summary = [
        'games'          => 0,
        'wins'           => 0,
        'draws'          => 0,
        'losses'         => 0,
        'goals_for'      => 0,
        'goals_against'  => 0,
        'yellow_cards'   => 0,
        'red_cards'      => 0,
    ];

    $bySeason = [];

    foreach ($rows as $m) {
        $mid = (int)$m['id'];
        $ssn = trim((string)($m['season'] ?? 'Sem temporada'));
        [$gf, $ga] = alm_ref_gf_ga($m);
        $res = alm_ref_result_code($m);

        $yellow = (int)($cardsByMatch[$mid]['yellow'] ?? 0);
        $red    = (int)($cardsByMatch[$mid]['red'] ?? 0);

        $summary['games']++;
        $summary['goals_for'] += $gf;
        $summary['goals_against'] += $ga;
        $summary['yellow_cards'] += $yellow;
        $summary['red_cards'] += $red;

        if ($res === 'W') $summary['wins']++;
        elseif ($res === 'D') $summary['draws']++;
        elseif ($res === 'L') $summary['losses']++;

        if (!isset($bySeason[$ssn])) {
            $bySeason[$ssn] = [
                'season' => $ssn,
                'stats'  => [
                    'games'          => 0,
                    'wins'           => 0,
                    'draws'          => 0,
                    'losses'         => 0,
                    'goals_for'      => 0,
                    'goals_against'  => 0,
                    'yellow_cards'   => 0,
                    'red_cards'      => 0,
                ],
                'matches' => [],
            ];
        }

        $bySeason[$ssn]['stats']['games']++;
        $bySeason[$ssn]['stats']['goals_for'] += $gf;
        $bySeason[$ssn]['stats']['goals_against'] += $ga;
        $bySeason[$ssn]['stats']['yellow_cards'] += $yellow;
        $bySeason[$ssn]['stats']['red_cards'] += $red;

        if ($res === 'W') $bySeason[$ssn]['stats']['wins']++;
        elseif ($res === 'D') $bySeason[$ssn]['stats']['draws']++;
        elseif ($res === 'L') $bySeason[$ssn]['stats']['losses']++;

        $bySeason[$ssn]['matches'][] = [
            'id'          => $mid,
            'match_date'  => (string)($m['match_date'] ?? ''),
            'competition' => (string)($m['competition'] ?? ''),
            'phase'       => (string)($m['phase'] ?? ''),
            'round'       => (string)($m['round'] ?? ''),
            'home'        => (string)($m['home'] ?? ''),
            'away'        => (string)($m['away'] ?? ''),
            'home_score'  => (int)($m['home_score'] ?? 0),
            'away_score'  => (int)($m['away_score'] ?? 0),
            'result'      => $res,
            'yellow'      => $yellow,
            'red'         => $red,
        ];
    }

    uksort($bySeason, fn($a, $b) => strnatcmp((string)$b, (string)$a));

    render_header('Almanaque • Árbitros');

    echo '<div class="d-flex justify-content-between align-items-center mb-3">';
    echo '  <div>';
    echo '    <h2 class="mb-1">Almanaque de Árbitros</h2>';
    echo '    <div class="text-muted">Histórico de partidas apitadas por cada árbitro.</div>';
    echo '  </div>';
    echo '</div>';

    echo '<div class="card mb-3"><div class="card-body">';
    echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">';
    echo '<div>';
    echo '<h4 class="mb-1">' . h($referee) . '</h4>';
    echo '<div class="text-muted">Resumo detalhado do árbitro</div>';
    echo '</div>';
    echo '<a class="btn btn-outline-secondary btn-sm" href="' . h(alm_ref_build_url(['referee' => null, 'season' => null, 'competition' => null])) . '">Voltar ao consolidado</a>';
    echo '</div>';
    echo '</div></div>';

    echo '<div class="card mb-3"><div class="card-body">';
    echo '<form method="get" class="row g-2 align-items-end">';
    echo '<input type="hidden" name="page" value="almanaque_referees">';
    echo '<input type="hidden" name="referee" value="' . h($referee) . '">';

    echo '<div class="col-md-3">';
    echo '<label class="form-label">Temporada</label>';
    echo '<select name="season" class="form-select">';
    echo '<option value="">Todas</option>';
    foreach ($seasonOptions as $opt) {
        $opt = (string)$opt;
        echo '<option value="' . h($opt) . '"' . ($season === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="col-md-4">';
    echo '<label class="form-label">Campeonato</label>';
    echo '<select name="competition" class="form-select">';
    echo '<option value="">Todos</option>';
    foreach ($competitionOptions as $opt) {
        $opt = (string)$opt;
        echo '<option value="' . h($opt) . '"' . ($competition === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="col-auto"><button class="btnbtn-primary" type="submit">Aplicar</button></div>';
    echo '<div class="col-auto"><a class="btn btn-outline-secondary" href="' . h(alm_ref_build_url(['season' => null, 'competition' => null])) . '">Limpar</a></div>';
    echo '</form>';
    echo '</div></div>';

    if (!$rows) {
        echo '<div class="alert alert-secondary">Nenhuma partida encontrada para este árbitro com os filtros informados.</div>';
        render_footer();
        exit;
    }

    $pct = $summary['games'] > 0
        ? (($summary['wins'] * 3 + $summary['draws']) * 100 / ($summary['games'] * 3))
        : 0;

    echo '<div class="card mb-3"><div class="card-body">';
    echo '<div class="table-responsive">';
    echo '<table class="table table-striped align-middle mb-0">';
    echo '<thead><tr>';
    echo '<th>J</th><th>V</th><th>E</th><th>D</th><th>GP</th><th>GC</th><th>% AP</th><th>Amarelos</th><th>Vermelhos</th>';
    echo '</tr></thead><tbody><tr>';
    echo '<td>' . (int)$summary['games'] . '</td>';
    echo '<td>' . (int)$summary['wins'] . '</td>';
    echo '<td>' . (int)$summary['draws'] . '</td>';
    echo '<td>' . (int)$summary['losses'] . '</td>';
    echo '<td>' . (int)$summary['goals_for'] . '</td>';
    echo '<td>' . (int)$summary['goals_against'] . '</td>';
    echo '<td>' . alm_ref_fmt_pct($pct) . '</td>';
    echo '<td>' . (int)$summary['yellow_cards'] . '</td>';
    echo '<td>' . (int)$summary['red_cards'] . '</td>';
    echo '</tr></tbody></table>';
    echo '</div></div></div>';

    foreach ($bySeason as $ssn => $pack) {
        $st = $pack['stats'];
        $pctS = $st['games'] > 0
            ? (($st['wins'] * 3 + $st['draws']) * 100 / ($st['games'] * 3))
            : 0;

        echo '<div class="card mb-3">';
        echo '<div class="card-header"><strong>Temporada ' . h((string)$ssn) . '</strong></div>';
        echo '<div class="card-body">';

        echo '<div class="table-responsive mb-3">';
        echo '<table class="table table-striped align-middle mb-0">';
        echo '<thead><tr>';
        echo '<th>J</th><th>V</th><th>E</th><th>D</th><th>GP</th><th>GC</th><th>% AP</th><th>Amarelos</th><th>Vermelhos</th>';
        echo '</tr></thead><tbody><tr>';
        echo '<td>' . (int)$st['games'] . '</td>';
        echo '<td>' . (int)$st['wins'] . '</td>';
        echo '<td>' . (int)$st['draws'] . '</td>';
        echo '<td>' . (int)$st['losses'] . '</td>';
        echo '<td>' . (int)$st['goals_for'] . '</td>';
        echo '<td>' . (int)$st['goals_against'] . '</td>';
        echo '<td>' . alm_ref_fmt_pct($pctS) . '</td>';
        echo '<td>' . (int)$st['yellow_cards'] . '</td>';
        echo '<td>' . (int)$st['red_cards'] . '</td>';
        echo '</tr></tbody></table>';
        echo '</div>';

        echo '<div class="table-responsive">';
        echo '<table class="table table-hover align-middle mb-0">';
        echo '<thead><tr>';
        echo '<th>Data</th><th>Campeonato</th><th>Fase</th><th>Rodada</th><th>Jogo</th><th>Placar</th><th>Resultado</th><th>Amarelos</th><th>Vermelhos</th><th>Ações</th>';
        echo '</tr></thead><tbody>';

        foreach ($pack['matches'] as $m) {
            $resultado = match ((string)$m['result']) {
                'W' => 'Vitória',
                'D' => 'Empate',
                'L' => 'Derrota',
                default => '-',
            };

            echo '<tr>';
            echo '<td>' . alm_ref_fmt_date_br((string)$m['match_date']) . '</td>';
            echo '<td>' . h((string)$m['competition']) . '</td>';
            echo '<td>' . h((string)($m['phase'] !== '' ? $m['phase'] : '-')) . '</td>';
            echo '<td>' . h((string)($m['round'] !== '' ? $m['round'] : '-')) . '</td>';
            echo '<td>' . h((string)$m['home']) . ' x ' . h((string)$m['away']) . '</td>';
            echo '<td>' . (int)$m['home_score'] . ' x ' . (int)$m['away_score'] . '</td>';
            echo '<td>' . h($resultado) . '</td>';
            echo '<td>' . (int)$m['yellow'] . '</td>';
            echo '<td>' . (int)$m['red'] . '</td>';
            echo '<td><a class="btn btn-sm btn-primary" href="index.php?page=match&id=' . (int)$m['id'] . '">Abrir</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        echo '</div></div>';
    }

    render_footer();
    exit;
}

/*
|--------------------------------------------------------------------------
| Consolidado
|--------------------------------------------------------------------------
*/
$where  = ["TRIM(COALESCE(referee, '')) <> ''"];
$params = [];

if ($qSearch !== '') {
    $where[]  = "referee LIKE ?";
    $params[] = '%' . $qSearch . '%';
}

if ($season !== '') {
    $where[]  = "season = ?";
    $params[] = $season;
}

if ($competition !== '') {
    $where[]  = "competition = ?";
    $params[] = $competition;
}

$sqlAll = "
    SELECT
        id,
        season,
        competition,
        match_date,
        home,
        away,
        home_score,
        away_score,
        referee
    FROM matches
    WHERE " . implode(' AND ', $where) . "
";

$matches = q($pdo, $sqlAll, $params)->fetchAll();

$rows = [];

foreach ($matches as $m) {
    $ref = trim((string)($m['referee'] ?? ''));
    if ($ref === '') continue;

    if (!isset($rows[$ref])) {
        $rows[$ref] = [
            'referee'         => $ref,
            'games'           => 0,
            'wins'            => 0,
            'draws'           => 0,
            'losses'          => 0,
            'goals_for'       => 0,
            'goals_against'   => 0,
            'yellow_cards'    => 0,
            'red_cards'       => 0,
            'pct'             => 0.0,
        ];
    }

    $mid = (int)$m['id'];
    [$gf, $ga] = alm_ref_gf_ga($m);
    $res = alm_ref_result_code($m);

    $rows[$ref]['games']++;
    $rows[$ref]['goals_for'] += $gf;
    $rows[$ref]['goals_against'] += $ga;
    $rows[$ref]['yellow_cards'] += (int)($cardsByMatch[$mid]['yellow'] ?? 0);
    $rows[$ref]['red_cards'] += (int)($cardsByMatch[$mid]['red'] ?? 0);

    if ($res === 'W') $rows[$ref]['wins']++;
    elseif ($res === 'D') $rows[$ref]['draws']++;
    elseif ($res === 'L') $rows[$ref]['losses']++;
}

foreach ($rows as &$r) {
    $r['pct'] = $r['games'] > 0
        ? (($r['wins'] * 3 + $r['draws']) * 100 / ($r['games'] * 3))
        : 0;
}
unset($r);

$sortMap = [
    'referee'       => 'referee',
    'name'          => 'referee',
    'games'         => 'games',
    'wins'          => 'wins',
    'draws'         => 'draws',
    'losses'        => 'losses',
    'goals_for'     => 'goals_for',
    'goals_against' => 'goals_against',
    'yellow_cards'  => 'yellow_cards',
    'red_cards'     => 'red_cards',
    'pct'           => 'pct',
];

$orderCol = $sortMap[$sort] ?? 'games';

usort($rows, function ($a, $b) use ($orderCol, $dir) {
    $va = $a[$orderCol];
    $vb = $b[$orderCol];

    if ($va == $vb) {
        return strcasecmp((string)$a['referee'], (string)$b['referee']);
    }

    if ($dir === 'asc') {
        return ($va <=> $vb);
    }

    return ($vb <=> $va);
});

render_header('Almanaque • Árbitros');

echo '<div class="d-flex justify-content-between align-items-center mb-3">';
echo '  <div>';
echo '    <h2 class="mb-1">Almanaque de Árbitros</h2>';
echo '    <div class="text-muted">Histórico de partidas apitadas por cada árbitro.</div>';
echo '  </div>';
echo '</div>';

echo '<div class="card mb-3"><div class="card-body">';
echo '<form method="get" class="row g-2 align-items-end">';
echo '<input type="hidden" name="page" value="almanaque_referees">';

echo '<div class="col-md-4">';
echo '<label class="form-label">Buscar árbitro</label>';
echo '<input type="text" name="q" value="' . h($qSearch) . '" class="form-control">';
echo '</div>';

echo '<div class="col-md-3">';
echo '<label class="form-label">Temporada</label>';
echo '<select name="season" class="form-select">';
echo '<option value="">Todas</option>';
foreach ($seasonOptions as $opt) {
    $opt = (string)$opt;
    echo '<option value="' . h($opt) . '"' . ($season === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="col-md-3">';
echo '<label class="form-label">Campeonato</label>';
echo '<select name="competition" class="form-select">';
echo '<option value="">Todos</option>';
foreach ($competitionOptions as $opt) {
    $opt = (string)$opt;
    echo '<option value="' . h($opt) . '"' . ($competition === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="col-auto"><button class="btnbtn-primary" type="submit">Aplicar</button></div>';
echo '<div class="col-auto"><a class="btn btn-outline-secondary" href="' . h(alm_ref_build_url(['q' => null, 'season' => null, 'competition' => null, 'sort' => null, 'dir' => null])) . '">Limpar</a></div>';

echo '</form>';
echo '</div></div>';

if (!$rows) {
    echo '<div class="alert alert-secondary">Sem resultados para os filtros informados.</div>';
    render_footer();
    exit;
}

echo '<div class="card">';
echo '<div class="card-body">';
echo '<div class="table-responsive">';
echo '<table class="table table-hover align-middle mb-0">';
echo '<thead><tr>';
echo '<th>' . alm_ref_sort_link('referee', 'Árbitro', $sort, $dir) . '</th>';
echo '<th>' . alm_ref_sort_link('games', 'J', $sort, $dir) . '</th>';
echo '<th>' . alm_ref_sort_link('wins', 'V', $sort, $dir) . '</th>';
echo '<th>' . alm_ref_sort_link('draws', 'E', $sort, $dir) . '</th>';
echo '<th>' . alm_ref_sort_link('losses', 'D', $sort, $dir) . '</th>';
echo '<th>' . alm_ref_sort_link('goals_for', 'GP', $sort, $dir) . '</th>';
echo '<th>' . alm_ref_sort_link('goals_against', 'GC', $sort, $dir) . '</th>';
echo '<th>' . alm_ref_sort_link('pct', '% AP', $sort, $dir) . '</th>';
echo '<th>' . alm_ref_sort_link('yellow_cards', 'Amarelos', $sort, $dir) . '</th>';
echo '<th>' . alm_ref_sort_link('red_cards', 'Vermelhos', $sort, $dir) . '</th>';
echo '</tr></thead><tbody>';

foreach ($rows as $r) {
    $url = alm_ref_build_url([
        'referee' => $r['referee'],
    ]);

    echo '<tr>';
    echo '<td><a href="' . h($url) . '">' . h((string)$r['referee']) . '</a></td>';
    echo '<td>' . (int)$r['games'] . '</td>';
    echo '<td>' . (int)$r['wins'] . '</td>';
    echo '<td>' . (int)$r['draws'] . '</td>';
    echo '<td>' . (int)$r['losses'] . '</td>';
    echo '<td>' . (int)$r['goals_for'] . '</td>';
    echo '<td>' . (int)$r['goals_against'] . '</td>';
    echo '<td>' . alm_ref_fmt_pct($r['pct']) . '</td>';
    echo '<td>' . (int)$r['yellow_cards'] . '</td>';
    echo '<td>' . (int)$r['red_cards'] . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</div>';
echo '</div>';

render_footer();
