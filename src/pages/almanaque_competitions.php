<?php
declare(strict_types=1);

$pdo    = db();
$userId = require_user_id();
$club   = function_exists('app_club') ? (string)app_club() : 'PALMEIRAS';

if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('table_columns')) {
    function table_columns(PDO $pdo, string $table): array
    {
        $cols = [];
        $st = $pdo->query("PRAGMA table_info($table)");
        foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
            $cols[] = (string)($r['name'] ?? '');
        }
        return $cols;
    }
}

if (!function_exists('table_has_user_id')) {
    function table_has_user_id(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = in_array('user_id', table_columns($pdo, $table), true);
        }
        return $cache[$table];
    }
}

if (!function_exists('alm_comp_build_url')) {
    function alm_comp_build_url(array $overrides = []): string
    {
        $params = array_merge([
            'page'        => 'almanaque_competitions',
            'competition' => $_GET['competition'] ?? null,
            'season'      => $_GET['season'] ?? null,
            'sort'        => $_GET['sort'] ?? null,
            'dir'         => $_GET['dir'] ?? null,
        ], $overrides);

        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                unset($params[$k]);
            }
        }

        return 'index.php?' . http_build_query($params);
    }
}

if (!function_exists('alm_comp_sort_link')) {
    function alm_comp_sort_link(string $column, string $label, string $currentSort, string $currentDir, array $extra = []): string
    {
        $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
        $arrow = '';

        if ($currentSort === $column) {
            $arrow = $currentDir === 'asc' ? ' ▲' : ' ▼';
        }

        $url = alm_comp_build_url(array_merge($extra, [
            'sort' => $column,
            'dir'  => $nextDir,
        ]));

        return '<a class="text-decoration-none" href="' . h($url) . '">' . h($label) . $arrow . '</a>';
    }
}

if (!function_exists('alm_comp_fmt_pct')) {
    function alm_comp_fmt_pct($v): string
    {
        return number_format((float)$v, 2, ',', '.') . '%';
    }
}

if (!function_exists('alm_comp_fmt_date_br')) {
    function alm_comp_fmt_date_br(?string $date): string
    {
        if (!$date) return '-';
        $ts = strtotime($date);
        return $ts ? date('d/m/Y', $ts) : h($date);
    }
}

if (!function_exists('alm_comp_result_label')) {
    function alm_comp_result_label(int $gf, int $ga): string
    {
        if ($gf > $ga) return 'Vitória';
        if ($gf < $ga) return 'Derrota';
        return 'Empate';
    }
}

if (!function_exists('alm_comp_match_label')) {
    function alm_comp_match_label(array $m): string
    {
        return h((string)$m['home']) . ' x ' . h((string)$m['away']);
    }
}

if (!function_exists('alm_comp_norm')) {
    function alm_comp_norm(string $v): string
    {
        return function_exists('mb_strtoupper')
            ? mb_strtoupper(trim($v), 'UTF-8')
            : strtoupper(trim($v));
    }
}

render_header('Almanaque • Campeonatos');

$competition = trim((string)($_GET['competition'] ?? ''));
$season      = trim((string)($_GET['season'] ?? ''));
$sort        = strtolower(trim((string)($_GET['sort'] ?? 'games')));
$dir         = strtolower(trim((string)($_GET['dir'] ?? 'desc')));

$allowedSort = [
    'competition'   => 'competition',
    'season'        => 'season',
    'games'         => 'games',
    'wins'          => 'wins',
    'draws'         => 'draws',
    'losses'        => 'losses',
    'goals_for'     => 'goals_for',
    'goals_against' => 'goals_against',
    'pct'           => 'pct',
    'titles'        => 'titles',
    'j'             => 'games',
    'v'             => 'wins',
    'e'             => 'draws',
    'd'             => 'losses',
    'gp'            => 'goals_for',
    'gc'            => 'goals_against',
    'ap'            => 'pct',
    'titulos'       => 'titles',
];

$orderCol = $allowedSort[$sort] ?? 'games';
$orderDir = $dir === 'asc' ? 'ASC' : 'DESC';

$matchesHasUserId  = table_has_user_id($pdo, 'matches');
$trophiesExists    = table_exists($pdo, 'trophies');
$trophiesHasUserId = $trophiesExists && table_has_user_id($pdo, 'trophies');
$trophiesCols      = $trophiesExists ? table_columns($pdo, 'trophies') : [];

$trophyCompetitionCol = null;
foreach (['competition', 'competition_name', 'championship', 'title_name'] as $c) {
    if (in_array($c, $trophiesCols, true)) {
        $trophyCompetitionCol = $c;
        break;
    }
}

$trophySeasonCol = null;
foreach (['season', 'temporada'] as $c) {
    if (in_array($c, $trophiesCols, true)) {
        $trophySeasonCol = $c;
        break;
    }
}

/* -----------------------------------------------------------------------------
 * Carrega partidas válidas do clube atual e do usuário logado
 * -------------------------------------------------------------------------- */
$sqlMatches = "
    SELECT
        m.id,
        TRIM(COALESCE(m.season, '')) AS season,
        TRIM(COALESCE(m.competition, '')) AS competition,
        m.match_date,
        COALESCE(m.phase, '') AS phase,
        COALESCE(m.round, '') AS round,
        m.home,
        m.away,
        COALESCE(m.home_score, 0) AS home_score,
        COALESCE(m.away_score, 0) AS away_score
    FROM matches m
    WHERE (
        UPPER(TRIM(COALESCE(m.home, ''))) = UPPER(TRIM(:club))
        OR
        UPPER(TRIM(COALESCE(m.away, ''))) = UPPER(TRIM(:club))
    )
";
$paramsMatches = [':club' => $club];

if ($matchesHasUserId) {
    $sqlMatches .= " AND m.user_id = :user_id";
    $paramsMatches[':user_id'] = $userId;
}

$sqlMatches .= " ORDER BY date(m.match_date) ASC, m.id ASC";

$allMatches = q($pdo, $sqlMatches, $paramsMatches)->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* -----------------------------------------------------------------------------
 * Pré-processamento
 * -------------------------------------------------------------------------- */
$allCompetitions = [];
$allSeasonsByCompetition = [];
$matchesByCompetitionSeason = [];

foreach ($allMatches as $m) {
    $comp = trim((string)$m['competition']);
    if ($comp === '') {
        continue;
    }

    $sea = trim((string)$m['season']);
    $home = (string)$m['home'];
    $away = (string)$m['away'];
    $gf = alm_comp_norm($home) === alm_comp_norm($club) ? (int)$m['home_score'] : (int)$m['away_score'];
    $ga = alm_comp_norm($home) === alm_comp_norm($club) ? (int)$m['away_score'] : (int)$m['home_score'];
    $result = $gf > $ga ? 'W' : ($gf < $ga ? 'L' : 'D');

    $row = $m;
    $row['gf'] = $gf;
    $row['ga'] = $ga;
    $row['result'] = $result;

    $allCompetitions[$comp] = true;

    if ($sea !== '') {
        $allSeasonsByCompetition[$comp][$sea] = true;
        $matchesByCompetitionSeason[$comp][$sea][] = $row;
    }

    $matchesByCompetitionSeason[$comp]['__ALL__'][] = $row;
}

$competitionOptions = array_keys($allCompetitions);
sort($competitionOptions, SORT_NATURAL | SORT_FLAG_CASE);

$seasonOptions = [];
if ($competition !== '' && isset($allSeasonsByCompetition[$competition])) {
    $seasonOptions = array_keys($allSeasonsByCompetition[$competition]);
    usort($seasonOptions, static function ($a, $b) {
        if (is_numeric($a) && is_numeric($b)) return (int)$b <=> (int)$a;
        return strcasecmp((string)$b, (string)$a);
    });
}

/* -----------------------------------------------------------------------------
 * Títulos do usuário logado
 * -------------------------------------------------------------------------- */
$titleByCompetition = [];
$titleByCompetitionSeason = [];

if ($trophiesExists && $trophyCompetitionCol !== null) {
    $sqlT = "SELECT * FROM trophies";
    $paramsT = [];
    if ($trophiesHasUserId) {
        $sqlT .= " WHERE user_id = ?";
        $paramsT[] = $userId;
    }

    $trophies = q($pdo, $sqlT, $paramsT)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($trophies as $t) {
        $comp = trim((string)($t[$trophyCompetitionCol] ?? ''));
        if ($comp === '') continue;

        $titleByCompetition[$comp] = ($titleByCompetition[$comp] ?? 0) + 1;

        if ($trophySeasonCol !== null) {
            $sea = trim((string)($t[$trophySeasonCol] ?? ''));
            if ($sea !== '') {
                $titleByCompetitionSeason[$comp][$sea] = ($titleByCompetitionSeason[$comp][$sea] ?? 0) + 1;
            }
        }
    }
}

/* -----------------------------------------------------------------------------
 * Consolidadores
 * -------------------------------------------------------------------------- */
function alm_comp_build_summary(
    array $matches,
    int $titles = 0,
    string|int|null $competition = null,
    string|int|null $season = null
): array
{
    $games = count($matches);
    $wins = 0;
    $draws = 0;
    $losses = 0;
    $gf = 0;
    $ga = 0;

    foreach ($matches as $m) {
        $gf += (int)($m['gf'] ?? 0);
        $ga += (int)($m['ga'] ?? 0);

        if (($m['result'] ?? '') === 'W') {
            $wins++;
        } elseif (($m['result'] ?? '') === 'L') {
            $losses++;
        } else {
            $draws++;
        }
    }

    $points = ($wins * 3) + $draws;
    $pct = $games > 0 ? round(($points * 100) / ($games * 3), 2) : 0.0;

    return [
        'competition'   => $competition === null ? '' : (string)$competition,
        'season'        => $season === null ? '' : (string)$season,
        'games'         => $games,
        'wins'          => $wins,
        'draws'         => $draws,
        'losses'        => $losses,
        'goals_for'     => $gf,
        'goals_against' => $ga,
        'pct'           => $pct,
        'titles'        => $titles,
    ];
}

echo '<div class="container-fluid px-0">';
echo '  <div class="row g-3">';
echo '    <div class="col-12">';
echo '      <div class="card shadow-sm">';
echo '        <div class="card-body">';
echo '          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">';
echo '            <div>';
echo '              <h2 class="h4 mb-1">Almanaque de Campeonatos</h2>';
echo '              <p class="text-muted mb-0">Consolidado por competição, detalhamento por temporada e lista de partidas com acesso direto ao jogo.</p>';
echo '            </div>';
echo '            <div class="d-flex gap-2">';
echo '              <a class="btn btn-secondary btn-sm" href="index.php?page=almanaque">Voltar ao almanaque</a>';
if ($competition !== '') {
    echo '              <a class="btn btn-secondary btn-sm" href="' . h(alm_comp_build_url(['competition' => null, 'season' => null])) . '">Voltar ao consolidado</a>';
}
if ($competition !== '' && $season !== '') {
    echo '              <a class="btn btn-secondary btn-sm" href="' . h(alm_comp_build_url(['season' => null])) . '">Voltar às temporadas</a>';
}
echo '            </div>';
echo '          </div>';
echo '        </div>';
echo '      </div>';
echo '    </div>';
echo '  </div>';

if ($competition === '') {
    $rows = [];
    foreach ($competitionOptions as $comp) {
        $rows[] = alm_comp_build_summary(
            $matchesByCompetitionSeason[$comp]['__ALL__'] ?? [],
            (int)($titleByCompetition[$comp] ?? 0),
            $comp,
            null
        );
    }

    usort($rows, static function (array $a, array $b) use ($orderCol, $orderDir): int {
        $numeric = in_array($orderCol, ['games','wins','draws','losses','goals_for','goals_against','pct','titles'], true);

        if ($numeric) {
            $cmp = ((float)$a[$orderCol]) <=> ((float)$b[$orderCol]);
        } else {
            $cmp = strcasecmp((string)$a[$orderCol], (string)$b[$orderCol]);
        }

        if ($cmp === 0) {
            $cmp = strcasecmp((string)$a['competition'], (string)$b['competition']);
        }

        return $orderDir === 'ASC' ? $cmp : -$cmp;
    });

    echo '<div class="row g-3 mt-1">';
    echo '  <div class="col-12">';
    echo '    <div class="card shadow-sm">';
    echo '      <div class="card-body">';
    echo '        <h3 class="h5 mb-3">Campeonatos disputados</h3>';

    if (!$rows) {
        echo '<div class="alert alert-warning mb-0">Nenhum campeonato encontrado.</div>';
    } else {
        echo '<div class="table-responsive">';
        echo '  <table class="table table-striped table-hover align-middle mb-0">';
        echo '    <thead>';
        echo '      <tr>';
        echo '        <th>' . alm_comp_sort_link('competition', 'Campeonato', $sort, $dir) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('games', 'J', $sort, $dir) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('wins', 'V', $sort, $dir) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('draws', 'E', $sort, $dir) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('losses', 'D', $sort, $dir) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('goals_for', 'GP', $sort, $dir) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('goals_against', 'GC', $sort, $dir) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('pct', '% AP', $sort, $dir) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('titles', 'Títulos', $sort, $dir) . '</th>';
        echo '      </tr>';
        echo '    </thead>';
        echo '    <tbody>';

        foreach ($rows as $r) {
            $url = alm_comp_build_url([
                'competition' => (string)$r['competition'],
                'season'      => null,
            ]);

            echo '<tr>';
            echo '  <td><a class="text-decoration-none fw-semibold" href="' . h($url) . '">' . h((string)$r['competition']) . '</a></td>';
            echo '  <td class="text-center">' . (int)$r['games'] . '</td>';
            echo '  <td class="text-center">' . (int)$r['wins'] . '</td>';
            echo '  <td class="text-center">' . (int)$r['draws'] . '</td>';
            echo '  <td class="text-center">' . (int)$r['losses'] . '</td>';
            echo '  <td class="text-center">' . (int)$r['goals_for'] . '</td>';
            echo '  <td class="text-center">' . (int)$r['goals_against'] . '</td>';
            echo '  <td class="text-center">' . alm_comp_fmt_pct($r['pct']) . '</td>';
            echo '  <td class="text-center">' . (int)$r['titles'] . '</td>';
            echo '</tr>';
        }

        echo '    </tbody>';
        echo '  </table>';
        echo '</div>';
    }

    echo '      </div>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';
} elseif ($season === '') {
    $summary = alm_comp_build_summary(
        $matchesByCompetitionSeason[$competition]['__ALL__'] ?? [],
        (int)($titleByCompetition[$competition] ?? 0),
        $competition,
        null
    );

    $rows[] = alm_comp_build_summary(
        $matchesByCompetitionSeason[$competition][$sea] ?? [],
        (int)($titleByCompetitionSeason[$competition][$sea] ?? 0),
        $competition,
        $sea
    );

    usort($rows, static function (array $a, array $b) use ($orderCol, $orderDir): int {
        $numeric = in_array($orderCol, ['games','wins','draws','losses','goals_for','goals_against','pct','titles'], true);

        if ($numeric) {
            $cmp = ((float)$a[$orderCol]) <=> ((float)$b[$orderCol]);
        } else {
            if ($orderCol === 'season' && is_numeric($a['season']) && is_numeric($b['season'])) {
                $cmp = ((int)$a['season']) <=> ((int)$b['season']);
            } else {
                $cmp = strcasecmp((string)$a[$orderCol], (string)$b[$orderCol]);
            }
        }

        if ($cmp === 0) {
            $cmp = strcasecmp((string)$b['season'], (string)$a['season']);
        }

        return $orderDir === 'ASC' ? $cmp : -$cmp;
    });

    echo '<div class="row g-3 mt-1">';

    echo '  <div class="col-12">';
    echo '    <div class="card shadow-sm">';
    echo '      <div class="card-body">';
    echo '        <h3 class="h5 mb-3">Resumo do campeonato</h3>';

    if ($summary['games'] > 0) {
        echo '<div class="table-responsive">';
        echo '  <table class="table table-bordered align-middle mb-0">';
        echo '    <thead>';
        echo '      <tr>';
        echo '        <th>Campeonato</th>';
        echo '        <th class="text-center">J</th>';
        echo '        <th class="text-center">V</th>';
        echo '        <th class="text-center">E</th>';
        echo '        <th class="text-center">D</th>';
        echo '        <th class="text-center">GP</th>';
        echo '        <th class="text-center">GC</th>';
        echo '        <th class="text-center">% AP</th>';
        echo '        <th class="text-center">Títulos</th>';
        echo '      </tr>';
        echo '    </thead>';
        echo '    <tbody>';
        echo '      <tr>';
        echo '        <td class="fw-semibold">' . h((string)$summary['competition']) . '</td>';
        echo '        <td class="text-center">' . (int)$summary['games'] . '</td>';
        echo '        <td class="text-center">' . (int)$summary['wins'] . '</td>';
        echo '        <td class="text-center">' . (int)$summary['draws'] . '</td>';
        echo '        <td class="text-center">' . (int)$summary['losses'] . '</td>';
        echo '        <td class="text-center">' . (int)$summary['goals_for'] . '</td>';
        echo '        <td class="text-center">' . (int)$summary['goals_against'] . '</td>';
        echo '        <td class="text-center">' . alm_comp_fmt_pct($summary['pct']) . '</td>';
        echo '        <td class="text-center">' . (int)$summary['titles'] . '</td>';
        echo '      </tr>';
        echo '    </tbody>';
        echo '  </table>';
        echo '</div>';
    } else {
        echo '<div class="alert alert-warning mb-0">Nenhum dado encontrado para este campeonato.</div>';
    }

    echo '      </div>';
    echo '    </div>';
    echo '  </div>';

    echo '  <div class="col-12">';
    echo '    <div class="card shadow-sm">';
    echo '      <div class="card-body">';
    echo '        <h3 class="h5 mb-3">Temporadas disputadas em ' . h($competition) . '</h3>';

    if (!$rows) {
        echo '<div class="alert alert-warning mb-0">Nenhuma temporada encontrada para este campeonato.</div>';
    } else {
        echo '<div class="table-responsive">';
        echo '  <table class="table table-striped table-hover align-middle mb-0">';
        echo '    <thead>';
        echo '      <tr>';
        echo '        <th>' . alm_comp_sort_link('season', 'Ano', $sort, $dir, ['competition' => $competition]) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('games', 'J', $sort, $dir, ['competition' => $competition]) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('wins', 'V', $sort, $dir, ['competition' => $competition]) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('draws', 'E', $sort, $dir, ['competition' => $competition]) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('losses', 'D', $sort, $dir, ['competition' => $competition]) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('goals_for', 'GP', $sort, $dir, ['competition' => $competition]) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('goals_against', 'GC', $sort, $dir, ['competition' => $competition]) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('pct', '% AP', $sort, $dir, ['competition' => $competition]) . '</th>';
        echo '        <th class="text-center">' . alm_comp_sort_link('titles', 'Títulos', $sort, $dir, ['competition' => $competition]) . '</th>';
        echo '      </tr>';
        echo '    </thead>';
        echo '    <tbody>';

        foreach ($rows as $r) {
            $url = alm_comp_build_url([
                'competition' => $competition,
                'season'      => (string)$r['season'],
            ]);

            echo '<tr>';
            echo '  <td><a class="text-decoration-none fw-semibold" href="' . h($url) . '">' . h((string)$r['season']) . '</a></td>';
            echo '  <td class="text-center">' . (int)$r['games'] . '</td>';
            echo '  <td class="text-center">' . (int)$r['wins'] . '</td>';
            echo '  <td class="text-center">' . (int)$r['draws'] . '</td>';
            echo '  <td class="text-center">' . (int)$r['losses'] . '</td>';
            echo '  <td class="text-center">' . (int)$r['goals_for'] . '</td>';
            echo '  <td class="text-center">' . (int)$r['goals_against'] . '</td>';
            echo '  <td class="text-center">' . alm_comp_fmt_pct($r['pct']) . '</td>';
            echo '  <td class="text-center">' . (int)$r['titles'] . '</td>';
            echo '</tr>';
        }

        echo '    </tbody>';
        echo '  </table>';
        echo '</div>';
    }

    echo '      </div>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';
} else {
    $seasonMatches = $matchesByCompetitionSeason[$competition][$season] ?? [];
    $summary = alm_comp_build_summary(
        $seasonMatches,
        (int)($titleByCompetitionSeason[$competition][$season] ?? 0),
        $competition,
        $season
    );

    usort($seasonMatches, static function (array $a, array $b): int {
        $da = strtotime((string)$a['match_date']) ?: 0;
        $db = strtotime((string)$b['match_date']) ?: 0;
        if ($da === $db) {
            return ((int)$b['id']) <=> ((int)$a['id']);
        }
        return $db <=> $da;
    });

    echo '<div class="row g-3 mt-1">';

    echo '  <div class="col-12">';
    echo '    <div class="card shadow-sm">';
    echo '      <div class="card-body">';
    echo '        <h3 class="h5 mb-3">Resumo da temporada</h3>';

    if ($summary['games'] > 0) {
        echo '<div class="table-responsive">';
        echo '  <table class="table table-bordered align-middle mb-0">';
        echo '    <thead>';
        echo '      <tr>';
        echo '        <th>Campeonato</th>';
        echo '        <th>Temporada</th>';
        echo '        <th class="text-center">J</th>';
        echo '        <th class="text-center">V</th>';
        echo '        <th class="text-center">E</th>';
        echo '        <th class="text-center">D</th>';
        echo '        <th class="text-center">GP</th>';
        echo '        <th class="text-center">GC</th>';
        echo '        <th class="text-center">% AP</th>';
        echo '        <th class="text-center">Títulos</th>';
        echo '      </tr>';
        echo '    </thead>';
        echo '    <tbody>';
        echo '      <tr>';
        echo '        <td class="fw-semibold">' . h((string)$summary['competition']) . '</td>';
        echo '        <td class="fw-semibold">' . h((string)$summary['season']) . '</td>';
        echo '        <td class="text-center">' . (int)$summary['games'] . '</td>';
        echo '        <td class="text-center">' . (int)$summary['wins'] . '</td>';
        echo '        <td class="text-center">' . (int)$summary['draws'] . '</td>';
        echo '        <td class="text-center">' . (int)$summary['losses'] . '</td>';
        echo '        <td class="text-center">' . (int)$summary['goals_for'] . '</td>';
        echo '        <td class="text-center">' . (int)$summary['goals_against'] . '</td>';
        echo '        <td class="text-center">' . alm_comp_fmt_pct($summary['pct']) . '</td>';
        echo '        <td class="text-center">' . (int)$summary['titles'] . '</td>';
        echo '      </tr>';
        echo '    </tbody>';
        echo '  </table>';
        echo '</div>';
    } else {
        echo '<div class="alert alert-warning mb-0">Nenhum resumo encontrado para este campeonato/temporada.</div>';
    }

    echo '      </div>';
    echo '    </div>';
    echo '  </div>';

    echo '  <div class="col-12">';
    echo '    <div class="card shadow-sm">';
    echo '      <div class="card-body">';
    echo '        <h3 class="h5 mb-3">Jogos de ' . h($competition) . ' em ' . h($season) . '</h3>';

    if (!$seasonMatches) {
        echo '<div class="alert alert-warning mb-0">Nenhuma partida encontrada.</div>';
    } else {
        echo '<div class="table-responsive">';
        echo '  <table class="table table-striped table-hover align-middle mb-0">';
        echo '    <thead>';
        echo '      <tr>';
        echo '        <th>Data</th>';
        echo '        <th>Temporada</th>';
        echo '        <th>Campeonato</th>';
        echo '        <th>Jogo</th>';
        echo '        <th class="text-center">GP</th>';
        echo '        <th class="text-center">GC</th>';
        echo '        <th>Resultado</th>';
        echo '        <th class="text-center">Ações</th>';
        echo '      </tr>';
        echo '    </thead>';
        echo '    <tbody>';

        foreach ($seasonMatches as $m) {
            $matchUrl = 'index.php?page=match&id=' . urlencode((string)$m['id']);

            echo '<tr>';
            echo '  <td>' . alm_comp_fmt_date_br((string)$m['match_date']) . '</td>';
            echo '  <td>' . h((string)$m['season']) . '</td>';
            echo '  <td>' . h((string)$m['competition']) . '</td>';
            echo '  <td>' . alm_comp_match_label($m) . '</td>';
            echo '  <td class="text-center">' . (int)$m['gf'] . '</td>';
            echo '  <td class="text-center">' . (int)$m['ga'] . '</td>';
            echo '  <td>' . h(alm_comp_result_label((int)$m['gf'], (int)$m['ga'])) . '</td>';
            echo '  <td class="text-center"><a class="btn btn-sm btn-primary" href="' . h($matchUrl) . '">Abrir</a></td>';
            echo '</tr>';
        }

        echo '    </tbody>';
        echo '  </table>';
        echo '</div>';
    }

    echo '      </div>';
    echo '    </div>';
    echo '  </div>';

    echo '</div>';
}

echo '</div>';

render_footer();