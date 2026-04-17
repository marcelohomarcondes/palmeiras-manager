<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

$pdo = db();
render_header('Relatórios');

$total = (int)scalar($pdo, "SELECT COUNT(*) FROM v_pm_matches");

echo '<div class="card-soft">';

if ($total === 0) {
    echo '<div class="muted">Cadastre partidas para liberar estatísticas.</div>';
    echo '</div>';
    render_footer();
    return;
}

echo '<h5 class="mb-3">Top 10 goleadas</h5>';

$rows = q($pdo, "
SELECT match_date, competition, opponent,
       gf, ga, gd, result
FROM v_pm_top_blowouts
LIMIT 10
")->fetchAll();

echo '<div class="table-responsive">';
echo '<table class="table">';
echo '<thead>
<tr>
<th>Data</th>
<th>Campeonato</th>
<th>Adversário</th>
<th class="text-end">GF</th>
<th class="text-end">GA</th>
<th class="text-end">SG</th>
<th>Res</th>
</tr>
</thead><tbody>';

foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . h($r['match_date']) . '</td>';
    echo '<td>' . h($r['competition']) . '</td>';
    echo '<td>' . h($r['opponent']) . '</td>';
    echo '<td class="text-end">' . $r['gf'] . '</td>';
    echo '<td class="text-end">' . $r['ga'] . '</td>';
    echo '<td class="text-end">' . $r['gd'] . '</td>';
    echo '<td>' . h($r['result']) . '</td>';
    echo '</tr>';
}

echo '</tbody></table></div>';
echo '</div>';

render_footer();

