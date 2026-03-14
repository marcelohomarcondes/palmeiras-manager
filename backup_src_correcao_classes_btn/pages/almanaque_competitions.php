<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('q')) {
    function q(PDO $pdo, string $sql, array $params = []): PDOStatement
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }
}

if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('render_header')) {
    function render_header_fallback(string $title = 'Página'): void
    {
        echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . h($title) . '</title>';
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
        echo '</head><body><div class="container py-4">';
        echo '<h1 class="h3 mb-4">' . h($title) . '</h1>';
    }

    function render_footer_fallback(): void
    {
        echo '</div></body></html>';
    }
}

if (function_exists('render_header')) {
    render_header('Almanaque • Campeonatos');
} else {
    render_header_fallback('Almanaque • Campeonatos');
}

$pdo = db();

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

function alm_fmt_pct($v): string
{
    return number_format((float)$v, 2, ',', '.') . '%';
}

function alm_fmt_date_br(?string $date): string
{
    if (!$date) {
        return '-';
    }

    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : h($date);
}

function alm_match_result_label(?string $result): string
{
    return match ((string)$result) {
        'W' => 'Vitória',
        'D' => 'Empate',
        'L' => 'Derrota',
        default => '-',
    };
}

function alm_match_label(array $m): string
{
    return h((string)$m['home']) . ' x ' . h((string)$m['away']);
}

$hasTrophies = table_exists($pdo, 'trophies');

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
    $titleSql = $hasTrophies
        ? "
            SELECT
                competition_name AS competition,
                COUNT(*) AS titles
            FROM trophies
            GROUP BY competition_name
        "
        : "
            SELECT
                '' AS competition,
                0 AS titles
            WHERE 1 = 0
        ";

    $sql = "
        SELECT
            m.competition AS competition,
            COUNT(*) AS games,
            SUM(CASE WHEN m.result = 'W' THEN 1 ELSE 0 END) AS wins,
            SUM(CASE WHEN m.result = 'D' THEN 1 ELSE 0 END) AS draws,
            SUM(CASE WHEN m.result = 'L' THEN 1 ELSE 0 END) AS losses,
            SUM(COALESCE(m.gf, 0)) AS goals_for,
            SUM(COALESCE(m.ga, 0)) AS goals_against,
            ROUND(
                (
                    SUM(CASE WHEN m.result = 'W' THEN 3 WHEN m.result = 'D' THEN 1 ELSE 0 END) * 100.0
                ) / (COUNT(*) * 3.0),
                2
            ) AS pct,
            COALESCE(t.titles, 0) AS titles
        FROM v_pm_matches m
        LEFT JOIN (
            $titleSql
        ) t
            ON t.competition = m.competition
        WHERE COALESCE(TRIM(m.competition), '') <> ''
        GROUP BY m.competition
        ORDER BY {$orderCol} {$orderDir}, m.competition ASC
    ";

    $rows = q($pdo, $sql)->fetchAll(PDO::FETCH_ASSOC);

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
            echo '  <td class="text-center">' . alm_fmt_pct($r['pct']) . '</td>';
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
    $titleSql = $hasTrophies
        ? "
            SELECT
                competition_name AS competition,
                season,
                COUNT(*) AS titles
            FROM trophies
            GROUP BY competition_name, season
        "
        : "
            SELECT
                '' AS competition,
                '' AS season,
                0 AS titles
            WHERE 1 = 0
        ";

    $sqlSummary = "
        SELECT
            m.competition AS competition,
            COUNT(*) AS games,
            SUM(CASE WHEN m.result = 'W' THEN 1 ELSE 0 END) AS wins,
            SUM(CASE WHEN m.result = 'D' THEN 1 ELSE 0 END) AS draws,
            SUM(CASE WHEN m.result = 'L' THEN 1 ELSE 0 END) AS losses,
            SUM(COALESCE(m.gf, 0)) AS goals_for,
            SUM(COALESCE(m.ga, 0)) AS goals_against,
            ROUND(
                (
                    SUM(CASE WHEN m.result = 'W' THEN 3 WHEN m.result = 'D' THEN 1 ELSE 0 END) * 100.0
                ) / (COUNT(*) * 3.0),
                2
            ) AS pct,
            COALESCE(t.titles, 0) AS titles
        FROM v_pm_matches m
        LEFT JOIN (
            SELECT competition_name AS competition, COUNT(*) AS titles
            FROM trophies
            GROUP BY competition_name
        ) t
            ON t.competition = m.competition
        WHERE m.competition = ?
        GROUP BY m.competition
    ";

    $summary = $hasTrophies
        ? q($pdo, $sqlSummary, [$competition])->fetch(PDO::FETCH_ASSOC)
        : q($pdo, "
            SELECT
                m.competition AS competition,
                COUNT(*) AS games,
                SUM(CASE WHEN m.result = 'W' THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN m.result = 'D' THEN 1 ELSE 0 END) AS draws,
                SUM(CASE WHEN m.result = 'L' THEN 1 ELSE 0 END) AS losses,
                SUM(COALESCE(m.gf, 0)) AS goals_for,
                SUM(COALESCE(m.ga, 0)) AS goals_against,
                ROUND(
                    (
                        SUM(CASE WHEN m.result = 'W' THEN 3 WHEN m.result = 'D' THEN 1 ELSE 0 END) * 100.0
                    ) / (COUNT(*) * 3.0),
                    2
                ) AS pct,
                0 AS titles
            FROM v_pm_matches m
            WHERE m.competition = ?
            GROUP BY m.competition
        ", [$competition])->fetch(PDO::FETCH_ASSOC);

    $rows = q($pdo, "
        SELECT
            m.season AS season,
            COUNT(*) AS games,
            SUM(CASE WHEN m.result = 'W' THEN 1 ELSE 0 END) AS wins,
            SUM(CASE WHEN m.result = 'D' THEN 1 ELSE 0 END) AS draws,
            SUM(CASE WHEN m.result = 'L' THEN 1 ELSE 0 END) AS losses,
            SUM(COALESCE(m.gf, 0)) AS goals_for,
            SUM(COALESCE(m.ga, 0)) AS goals_against,
            ROUND(
                (
                    SUM(CASE WHEN m.result = 'W' THEN 3 WHEN m.result = 'D' THEN 1 ELSE 0 END) * 100.0
                ) / (COUNT(*) * 3.0),
                2
            ) AS pct,
            COALESCE(t.titles, 0) AS titles
        FROM v_pm_matches m
        LEFT JOIN (
            $titleSql
        ) t
            ON t.competition = m.competition
           AND t.season = m.season
        WHERE m.competition = ?
          AND COALESCE(TRIM(m.season), '') <> ''
        GROUP BY m.season
        ORDER BY
            CASE WHEN '{$orderCol}' = 'season' THEN m.season END {$orderDir},
            CASE WHEN '{$orderCol}' = 'games' THEN COUNT(*) END {$orderDir},
            CASE WHEN '{$orderCol}' = 'wins' THEN SUM(CASE WHEN m.result = 'W' THEN 1 ELSE 0 END) END {$orderDir},
            CASE WHEN '{$orderCol}' = 'draws' THEN SUM(CASE WHEN m.result = 'D' THEN 1 ELSE 0 END) END {$orderDir},
            CASE WHEN '{$orderCol}' = 'losses' THEN SUM(CASE WHEN m.result = 'L' THEN 1 ELSE 0 END) END {$orderDir},
            CASE WHEN '{$orderCol}' = 'goals_for' THEN SUM(COALESCE(m.gf, 0)) END {$orderDir},
            CASE WHEN '{$orderCol}' = 'goals_against' THEN SUM(COALESCE(m.ga, 0)) END {$orderDir},
            CASE WHEN '{$orderCol}' = 'pct' THEN ROUND(((SUM(CASE WHEN m.result = 'W' THEN 3 WHEN m.result = 'D' THEN 1 ELSE 0 END) * 100.0) / (COUNT(*) * 3.0)), 2) END {$orderDir},
            CASE WHEN '{$orderCol}' = 'titles' THEN COALESCE(t.titles, 0) END {$orderDir},
            m.season DESC
    ", [$competition])->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="row g-3 mt-1">';

    echo '  <div class="col-12">';
    echo '    <div class="card shadow-sm">';
    echo '      <div class="card-body">';
    echo '        <h3 class="h5 mb-3">Resumo do campeonato</h3>';

    if ($summary) {
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
        echo '        <td class="text-center">' . alm_fmt_pct($summary['pct']) . '</td>';
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
            echo '  <td class="text-center">' . alm_fmt_pct($r['pct']) . '</td>';
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
    $sqlSummary = $hasTrophies
        ? "
            SELECT
                m.competition AS competition,
                m.season AS season,
                COUNT(*) AS games,
                SUM(CASE WHEN m.result = 'W' THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN m.result = 'D' THEN 1 ELSE 0 END) AS draws,
                SUM(CASE WHEN m.result = 'L' THEN 1 ELSE 0 END) AS losses,
                SUM(COALESCE(m.gf, 0)) AS goals_for,
                SUM(COALESCE(m.ga, 0)) AS goals_against,
                ROUND(
                    (
                        SUM(CASE WHEN m.result = 'W' THEN 3 WHEN m.result = 'D' THEN 1 ELSE 0 END) * 100.0
                    ) / (COUNT(*) * 3.0),
                    2
                ) AS pct,
                (
                    SELECT COUNT(*)
                    FROM trophies t
                    WHERE t.competition_name = m.competition
                      AND t.season = m.season
                ) AS titles
            FROM v_pm_matches m
            WHERE m.competition = ?
              AND m.season = ?
            GROUP BY m.competition, m.season
        "
        : "
            SELECT
                m.competition AS competition,
                m.season AS season,
                COUNT(*) AS games,
                SUM(CASE WHEN m.result = 'W' THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN m.result = 'D' THEN 1 ELSE 0 END) AS draws,
                SUM(CASE WHEN m.result = 'L' THEN 1 ELSE 0 END) AS losses,
                SUM(COALESCE(m.gf, 0)) AS goals_for,
                SUM(COALESCE(m.ga, 0)) AS goals_against,
                ROUND(
                    (
                        SUM(CASE WHEN m.result = 'W' THEN 3 WHEN m.result = 'D' THEN 1 ELSE 0 END) * 100.0
                    ) / (COUNT(*) * 3.0),
                    2
                ) AS pct,
                0 AS titles
            FROM v_pm_matches m
            WHERE m.competition = ?
              AND m.season = ?
            GROUP BY m.competition, m.season
        ";

    $summary = q($pdo, $sqlSummary, [$competition, $season])->fetch(PDO::FETCH_ASSOC);

    $matches = q($pdo, "
        SELECT
            id,
            season,
            competition,
            match_date,
            home,
            away,
            gf,
            ga,
            result
        FROM v_pm_matches
        WHERE competition = ?
          AND season = ?
        ORDER BY date(match_date) DESC, id DESC
    ", [$competition, $season])->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="row g-3 mt-1">';

    echo '  <div class="col-12">';
    echo '    <div class="card shadow-sm">';
    echo '      <div class="card-body">';
    echo '        <h3 class="h5 mb-3">Resumo da temporada</h3>';

    if ($summary) {
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
        echo '        <td class="text-center">' . alm_fmt_pct($summary['pct']) . '</td>';
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

    if (!$matches) {
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

        foreach ($matches as $m) {
            $matchUrl = 'index.php?page=match&id=' . urlencode((string)$m['id']);

            echo '<tr>';
            echo '  <td>' . alm_fmt_date_br((string)$m['match_date']) . '</td>';
            echo '  <td>' . h((string)$m['season']) . '</td>';
            echo '  <td>' . h((string)$m['competition']) . '</td>';
            echo '  <td>' . alm_match_label($m) . '</td>';
            echo '  <td class="text-center">' . (int)$m['gf'] . '</td>';
            echo '  <td class="text-center">' . (int)$m['ga'] . '</td>';
            echo '  <td>' . h(alm_match_result_label((string)$m['result'])) . '</td>';
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

if (function_exists('render_footer')) {
    render_footer();
} else {
    render_footer_fallback();
}

