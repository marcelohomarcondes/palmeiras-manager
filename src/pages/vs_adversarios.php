<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

$pdo = db();
render_header('Vs Adversários');

$rows = q($pdo, "
SELECT opponent, games, wins, draws, losses,
       goals_for, goals_against, goal_diff, pct
FROM v_pm_vs_opponents
ORDER BY pct DESC, games DESC, goal_diff DESC
")->fetchAll();

echo '<div class="card-soft">';

if (!$rows) {
    echo '<div class="muted">Nenhuma partida cadastrada ainda.</div>';
    echo '</div>';
    render_footer();
    return;
}

echo '<div class="table-responsive">';
echo '<table class="table align-middle">';
echo '<thead>
<tr>
<th>Adversário</th>
<th class="text-end">J</th>
<th class="text-end">V</th>
<th class="text-end">E</th>
<th class="text-end">D</th>
<th class="text-end">GP</th>
<th class="text-end">GC</th>
<th class="text-end">SG</th>
<th class="text-end">% AP</th>
</tr>
</thead><tbody>';

foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . h($r['opponent']) . '</td>';
    echo '<td class="text-end">' . $r['games'] . '</td>';
    echo '<td class="text-end">' . $r['wins'] . '</td>';
    echo '<td class="text-end">' . $r['draws'] . '</td>';
    echo '<td class="text-end">' . $r['losses'] . '</td>';
    echo '<td class="text-end">' . $r['goals_for'] . '</td>';
    echo '<td class="text-end">' . $r['goals_against'] . '</td>';
    echo '<td class="text-end">' . $r['goal_diff'] . '</td>';
    echo '<td class="text-end">' . number_format($r['pct'], 2, ',', '.') . '%</td>';
    echo '</tr>';
}

echo '</tbody></table></div></div>';
render_footer();

