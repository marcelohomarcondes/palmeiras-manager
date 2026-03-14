<?php
declare(strict_types=1);

require_once __DIR__ . '/../layout.php';

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

if (!function_exists('table_columns')) {
    function table_columns(PDO $pdo, string $table): array
    {
        $cols = [];
        $st = $pdo->query("PRAGMA table_info($table)");
        foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
            $cols[] = strtolower((string)($r['name'] ?? ''));
        }
        return $cols;
    }
}

if (!function_exists('fmt_date_br')) {
    function fmt_date_br(?string $date): string
    {
        $date = trim((string)$date);
        if ($date === '') {
            return '-';
        }

        $ts = strtotime($date);
        if ($ts === false) {
            return h($date);
        }

        return date('d/m/Y', $ts);
    }
}

if (!function_exists('fmt_pct')) {
    function fmt_pct(float $value): string
    {
        return number_format($value, 1, ',', '.') . '%';
    }
}

if (!function_exists('pm_lower')) {
    function pm_lower(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($text, 'UTF-8');
        }

        return strtolower($text);
    }
}

if (!function_exists('normalize_team_name')) {
    function normalize_team_name(?string $name): string
    {
        $name = pm_lower((string)$name);
        $name = preg_replace('/\s+/', ' ', $name ?? '');
        return trim((string)$name);
    }
}

if (!function_exists('alm_detect_year')) {
    function alm_detect_year(array $m): ?int
    {
        $matchDate = trim((string)($m['match_date'] ?? ''));
        if ($matchDate !== '') {
            $ts = strtotime($matchDate);
            if ($ts !== false) {
                return (int)date('Y', $ts);
            }
            if (preg_match('/^\d{4}/', $matchDate, $mm)) {
                return (int)$mm[0];
            }
        }

        $season = trim((string)($m['season'] ?? ''));
        if (preg_match('/^\d{4}$/', $season)) {
            return (int)$season;
        }

        return null;
    }
}

if (!function_exists('alm_detect_decade')) {
    function alm_detect_decade(int $year): int
    {
        return (int)(floor($year / 10) * 10);
    }
}

if (!function_exists('alm_result_badge_class')) {
    function alm_result_badge_class(string $r): string
    {
        if ($r === 'V') {
            return 'bg-success-subtle text-success border border-success-subtle';
        }
        if ($r === 'E') {
            return 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
        }
        return 'bg-danger-subtle text-danger border border-danger-subtle';
    }
}

if (!function_exists('alm_main_club_name')) {
    function alm_main_club_name(array $matches): string
    {
        if (function_exists('app_club')) {
            $club = trim((string)app_club());
            if ($club !== '') {
                return normalize_team_name($club);
            }
        }

        $counts = [];
        foreach ($matches as $m) {
            $home = normalize_team_name($m['home'] ?? '');
            $away = normalize_team_name($m['away'] ?? '');

            if ($home !== '') {
                $counts[$home] = ($counts[$home] ?? 0) + 1;
            }
            if ($away !== '') {
                $counts[$away] = ($counts[$away] ?? 0) + 1;
            }
        }

        foreach (array_keys($counts) as $name) {
            if (strpos($name, 'palmeiras') !== false) {
                return $name;
            }
        }

        arsort($counts);
        return (string)(array_key_first($counts) ?? 'palmeiras');
    }
}

if (!function_exists('alm_match_perspective')) {
    function alm_match_perspective(array $m, string $clubNorm): array
    {
        $home = normalize_team_name($m['home'] ?? '');
        $away = normalize_team_name($m['away'] ?? '');

        $homeScore = (int)($m['home_score'] ?? 0);
        $awayScore = (int)($m['away_score'] ?? 0);

        if ($home === $clubNorm) {
            $gf = $homeScore;
            $ga = $awayScore;
        } elseif ($away === $clubNorm) {
            $gf = $awayScore;
            $ga = $homeScore;
        } else {
            $gf = $homeScore;
            $ga = $awayScore;
        }

        $result = 'E';
        if ($gf > $ga) {
            $result = 'V';
        } elseif ($gf < $ga) {
            $result = 'D';
        }

        return [
            'gf' => $gf,
            'ga' => $ga,
            'result' => $result,
        ];
    }
}

if (!function_exists('alm_summarize')) {
    function alm_summarize(array $matches, int $titles, string $clubNorm): array
    {
        $j = count($matches);
        $v = 0;
        $e = 0;
        $d = 0;
        $gp = 0;
        $gc = 0;

        foreach ($matches as $m) {
            $p = alm_match_perspective($m, $clubNorm);
            $gp += (int)$p['gf'];
            $gc += (int)$p['ga'];

            if ($p['result'] === 'V') {
                $v++;
            } elseif ($p['result'] === 'E') {
                $e++;
            } else {
                $d++;
            }
        }

        $pct = $j > 0 ? (($v * 3 + $e) / ($j * 3)) * 100 : 0.0;

        return [
            'jogos' => $j,
            'vitorias' => $v,
            'empates' => $e,
            'derrotas' => $d,
            'gols_pro' => $gp,
            'gols_contra' => $gc,
            'aproveitamento' => $pct,
            'titulos' => $titles,
        ];
    }
}

if (!function_exists('alm_titles_by_year')) {
    function alm_titles_by_year(PDO $pdo): array
    {
        if (!table_exists($pdo, 'trophies')) {
            return [];
        }

        $cols = table_columns($pdo, 'trophies');
        $yearCol = null;

        foreach (['season', 'year', 'ano', 'temporada'] as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $yearCol = $candidate;
                break;
            }
        }

        if ($yearCol === null) {
            return [];
        }

        $sql = "SELECT TRIM(COALESCE($yearCol,'')) AS ref_year, COUNT(*) AS total
                FROM trophies
                GROUP BY TRIM(COALESCE($yearCol,''))";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $ref = trim((string)($r['ref_year'] ?? ''));
            if (preg_match('/^\d{4}$/', $ref)) {
                $out[(int)$ref] = (int)($r['total'] ?? 0);
            }
        }

        return $out;
    }
}

if (!function_exists('alm_render_summary_cards')) {
    function alm_render_summary_cards(array $summary): void
    {
        $items = [
            'Jogos' => $summary['jogos'],
            'Vitórias' => $summary['vitorias'],
            'Empates' => $summary['empates'],
            'Derrotas' => $summary['derrotas'],
            'Gols Marcados' => $summary['gols_pro'],
            'Gols Sofridos' => $summary['gols_contra'],
            'Aproveitamento' => fmt_pct((float)$summary['aproveitamento']),
            'Títulos' => $summary['titulos'],
        ];

        echo '<div class="row g-3 mb-3">';
        foreach ($items as $label => $value) {
            echo '<div class="col-12 col-md-6 col-xl-3">';
            echo '  <div class="card h-100">';
            echo '      <div class="card-body">';
            echo '          <div class="text-muted small fw-semibold text-uppercase mb-1">' . h((string)$label) . '</div>';
            echo '          <div class="fs-4 fw-bold">' . h((string)$value) . '</div>';
            echo '      </div>';
            echo '  </div>';
            echo '</div>';
        }
        echo '</div>';
    }
}

/* ============================================================================
 * Banco / dados
 * ========================================================================== */
global $pdo;

if (!($pdo instanceof PDO)) {
    throw new RuntimeException('Conexão PDO não encontrada.');
}

if (!table_exists($pdo, 'matches')) {
    throw new RuntimeException('Tabela matches não encontrada.');
}

$matchCols = table_columns($pdo, 'matches');

$selectCols = [];
foreach ([
    'id',
    'season',
    'competition',
    'match_date',
    'home',
    'away',
    'home_score',
    'away_score',
    'phase',
    'round'
] as $col) {
    if (in_array($col, $matchCols, true)) {
        $selectCols[] = $col;
    }
}

if (!in_array('id', $selectCols, true)) {
    $selectCols[] = 'id';
}

$matchesSql = "
    SELECT " . implode(', ', $selectCols) . "
    FROM matches
    ORDER BY match_date ASC, id ASC
";
$matches = $pdo->query($matchesSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$clubNorm = alm_main_club_name($matches);
$titlesByYear = alm_titles_by_year($pdo);

$timeline = [];
foreach ($matches as $m) {
    $year = alm_detect_year($m);
    if ($year === null) {
        continue;
    }

    $decade = alm_detect_decade($year);

    if (!isset($timeline[$decade])) {
        $timeline[$decade] = [];
    }
    if (!isset($timeline[$decade][$year])) {
        $timeline[$decade][$year] = [];
    }

    $timeline[$decade][$year][] = $m;
}

krsort($timeline);
foreach ($timeline as $decade => $years) {
    krsort($timeline[$decade]);
}

/* ============================================================================
 * Render
 * ========================================================================== */
render_header('Linha do Tempo');
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h2 class="mb-1">Linha do Tempo</h2>
        <div class="text-muted">Histórico do clube separado por décadas e anos.</div>
    </div>
    <a href="/?page=almanaque" class="btnbtn-outline-secondary">Voltar</a>
</div>

<?php if (!$timeline): ?>
    <div class="card">
        <div class="card-body">Nenhuma partida encontrada.</div>
    </div>
<?php else: ?>
    <div class="accordion" id="timelineDecadesAccordion">
        <?php
        $decIdx = 0;
        foreach ($timeline as $decade => $years):
            $decIdx++;

            $decadeMatches = [];
            $decadeTitles = 0;

            foreach ($years as $year => $yearMatches) {
                $decadeMatches = array_merge($decadeMatches, $yearMatches);
                $decadeTitles += (int)($titlesByYear[(int)$year] ?? 0);
            }

            $decadeSummary = alm_summarize($decadeMatches, $decadeTitles, $clubNorm);
            $decId = 'alm_tl_dec_' . (int)$decade;
            $decHeadId = 'alm_tl_dec_head_' . (int)$decade;
        ?>
            <div class="accordion-item mb-3 border-0 shadow-sm">
                <h2 class="accordion-header" id="<?= h($decHeadId) ?>">
                    <button
                        class="accordion-button <?= $decIdx !== 1 ? 'collapsed' : '' ?>"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#<?= h($decId) ?>"
                        aria-expanded="<?= $decIdx === 1 ? 'true' : 'false' ?>"
                        aria-controls="<?= h($decId) ?>"
                    >
                        <div class="w-100 d-flex justify-content-between align-items-center flex-wrap gap-2 pe-3">
                            <div class="fw-bold"><?= h((string)$decade) ?>s</div>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="badge text-bg-light border"><?= count($years) ?> <?= count($years) === 1 ? 'ano' : 'anos' ?></span>
                                <span class="badge text-bg-light border">Jogos: <?= (int)$decadeSummary['jogos'] ?></span>
                                <span class="badge text-bg-light border">Títulos: <?= (int)$decadeSummary['titulos'] ?></span>
                                <span class="badge text-bg-light border">Aprov.: <?= h(fmt_pct((float)$decadeSummary['aproveitamento'])) ?></span>
                            </div>
                        </div>
                    </button>
                </h2>

                <div
                    id="<?= h($decId) ?>"
                    class="accordion-collapse collapse <?= $decIdx === 1 ? 'show' : '' ?>"
                    aria-labelledby="<?= h($decHeadId) ?>"
                    data-bs-parent="#timelineDecadesAccordion"
                >
                    <div class="accordion-body">
                        <?php alm_render_summary_cards($decadeSummary); ?>

                        <div class="accordion" id="timelineYearsAccordion_<?= (int)$decade ?>">
                            <?php
                            $yearIdx = 0;
                            foreach ($years as $year => $yearMatches):
                                $yearIdx++;

                                $yearTitles = (int)($titlesByYear[(int)$year] ?? 0);
                                $yearSummary = alm_summarize($yearMatches, $yearTitles, $clubNorm);

                                $yearId = 'alm_tl_year_' . (int)$decade . '_' . (int)$year;
                                $yearHeadId = 'alm_tl_year_head_' . (int)$decade . '_' . (int)$year;
                            ?>
                                <div class="accordion-item mb-3 border rounded-3 overflow-hidden">
                                    <h2 class="accordion-header" id="<?= h($yearHeadId) ?>">
                                        <button
                                            class="accordion-button <?= $yearIdx !== 1 ? 'collapsed' : '' ?>"
                                            type="button"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#<?= h($yearId) ?>"
                                            aria-expanded="<?= $yearIdx === 1 ? 'true' : 'false' ?>"
                                            aria-controls="<?= h($yearId) ?>"
                                        >
                                            <div class="w-100 d-flex justify-content-between align-items-center flex-wrap gap-2 pe-3">
                                                <div class="fw-bold"><?= h((string)$year) ?></div>
                                                <div class="d-flex flex-wrap gap-2">
                                                    <span class="badge text-bg-light border">Jogos: <?= (int)$yearSummary['jogos'] ?></span>
                                                    <span class="badge text-bg-light border">V: <?= (int)$yearSummary['vitorias'] ?></span>
                                                    <span class="badge text-bg-light border">E: <?= (int)$yearSummary['empates'] ?></span>
                                                    <span class="badge text-bg-light border">D: <?= (int)$yearSummary['derrotas'] ?></span>
                                                    <span class="badge text-bg-light border">Títulos: <?= (int)$yearSummary['titulos'] ?></span>
                                                </div>
                                            </div>
                                        </button>
                                    </h2>

                                    <div
                                        id="<?= h($yearId) ?>"
                                        class="accordion-collapse collapse <?= $yearIdx === 1 ? 'show' : '' ?>"
                                        aria-labelledby="<?= h($yearHeadId) ?>"
                                        data-bs-parent="#timelineYearsAccordion_<?= (int)$decade ?>"
                                    >
                                        <div class="accordion-body">
                                            <?php alm_render_summary_cards($yearSummary); ?>

                                            <div class="card">
                                                <div class="table-responsive">
                                                    <table class="table table-hover align-middle mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th>Data</th>
                                                                <th>Campeonato</th>
                                                                <th>Fase</th>
                                                                <th>Rodada</th>
                                                                <th>Jogo</th>
                                                                <th>Placar</th>
                                                                <th>Resultado</th>
                                                                <th class="text-end">Ações</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php if (!$yearMatches): ?>
                                                                <tr>
                                                                    <td colspan="8" class="text-center text-muted py-4">Sem dados.</td>
                                                                </tr>
                                                            <?php else: ?>
                                                                <?php foreach ($yearMatches as $m): ?>
                                                                    <?php
                                                                    $p = alm_match_perspective($m, $clubNorm);
                                                                    $gameLabel = trim((string)($m['home'] ?? '-')) . ' x ' . trim((string)($m['away'] ?? '-'));
                                                                    $scoreLabel = (string)($m['home_score'] ?? 0) . ' x ' . (string)($m['away_score'] ?? 0);
                                                                    ?>
                                                                    <tr>
                                                                        <td><?= h(fmt_date_br($m['match_date'] ?? null)) ?></td>
                                                                        <td><?= h((string)($m['competition'] ?? '-')) ?></td>
                                                                        <td><?= h((string)($m['phase'] ?? '-')) ?></td>
                                                                        <td><?= h((string)($m['round'] ?? '-')) ?></td>
                                                                        <td><?= h($gameLabel) ?></td>
                                                                        <td><?= h($scoreLabel) ?></td>
                                                                        <td>
                                                                            <span class="badge rounded-pill <?= h(alm_result_badge_class((string)$p['result'])) ?>">
                                                                                <?= h((string)$p['result']) ?>
                                                                            </span>
                                                                        </td>
                                                                        <td class="text-end">
                                                                            <a href="/?page=match&id=<?= (int)$m['id'] ?>" class="btn btn-sm btn-primary">Abrir</a>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php render_footer(); ?>

