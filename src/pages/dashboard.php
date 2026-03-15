<?php
declare(strict_types=1);

$pdo = db();
$userId = require_user_id();

$resetMessage = null;
$resetError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'reset_save') {
  try {
    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | Apagar somente os dados do usuário logado
    |--------------------------------------------------------------------------
    | Ordem pensada para respeitar relacionamentos entre tabelas.
    |--------------------------------------------------------------------------
    */
    $tablesToClear = [
      // Dados relacionados às partidas
      'match_player_stats',
      'match_substitutions',
      'match_players',
      'opponent_match_player_stats',
      'substitutions',
      'matches',
      'opponent_players',

      // Elenco profissional
      'players',

      // Categorias de base
      'academy_dismissed',
      'academy_players',

      // Templates
      'lineup_template_slots',
      'lineup_templates',

      // Demais módulos
      'transfers',
      'injuries',
      'trophies',
    ];

    foreach ($tablesToClear as $table) {
      q(
        $pdo,
        "DELETE FROM {$table} WHERE user_id = :user_id",
        [':user_id' => $userId]
      );
    }

    $pdo->commit();
    $resetMessage = 'Save apagado com sucesso. Apenas os dados deste usuário foram redefinidos.';
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    $resetError = 'Não foi possível apagar o save deste usuário. Verifique as relações da base e tente novamente.';
  }
}

$games = (int)scalar(
  $pdo,
  "SELECT COUNT(*) FROM matches WHERE user_id = :user_id",
  [':user_id' => $userId],
  0
);

$players = (int)scalar(
  $pdo,
  "SELECT COUNT(*)
   FROM players
   WHERE user_id = :user_id
     AND is_active = 1
     AND club_name = :club_name COLLATE NOCASE",
  [
    ':user_id'   => $userId,
    ':club_name' => app_club(),
  ],
  0
);

$inj = (int)scalar(
  $pdo,
  "SELECT COUNT(*) FROM injuries WHERE user_id = :user_id",
  [':user_id' => $userId],
  0
);

$tr = (int)scalar(
  $pdo,
  "SELECT COUNT(*) FROM transfers WHERE user_id = :user_id",
  [':user_id' => $userId],
  0
);

$troph = (int)scalar(
  $pdo,
  "SELECT COUNT(*) FROM trophies WHERE user_id = :user_id",
  [':user_id' => $userId],
  0
);

render_header('Dashboard');

echo '<style>
.pm-dash-icon{
  color: var(--accent) !important;
}

.pm-dash-icon:hover{
  color: var(--accent) !important;
}
</style>';

if ($resetMessage) {
  echo '<div class="alert alert-success" role="alert">' . h($resetMessage) . '</div>';
}

if ($resetError) {
  echo '<div class="alert alert-danger" role="alert">' . h($resetError) . '</div>';
}

echo '<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-5 g-3">';
$cards = [
  ['Partidas', $games, 'bi-calendar3', '/?page=matches'],
  ['Elenco ativo', $players, 'bi-people', '/?page=players'],
  ['Transferências', $tr, 'bi-arrow-left-right', '/?page=transfers'],
  ['Lesões', $inj, 'bi-bandaid', '/?page=injuries'],
  ['Troféus', $troph, 'bi-trophy', '/?page=trophies'],
];

foreach ($cards as [$label, $val, $icon, $href]) {
  echo '<div class="col">';
  echo '<a class="text-decoration-none" href="' . h($href) . '">';
  echo '<div class="card card-soft p-3 h-100">';
  echo '<div class="d-flex align-items-center justify-content-between">';
  echo '<div><div class="text-muted small">' . h($label) . '</div><div class="fs-3 fw-bold">' . (int)$val . '</div></div>';
  echo '<i class="bi ' . h($icon) . ' fs-2 pm-dash-icon"></i>';
  echo '</div></div></a></div>';
}
echo '</div>';

echo '<div class="row g-3 mt-1">';
echo '<div class="col-lg-7">';
echo '<div class="card card-soft p-3">';
echo '<div class="fw-bold mb-2">Últimas partidas</div>';

$rows = q(
  $pdo,
  "SELECT id, match_date, competition, home, away, home_score, away_score
   FROM matches
   WHERE user_id = :user_id
   ORDER BY match_date DESC, id DESC
   LIMIT 4",
  [':user_id' => $userId]
)->fetchAll();

if (!$rows) {
  echo '<div class="text-muted">Sem partidas cadastradas.</div>';
} else {
  echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
  echo '<thead><tr><th>Data</th><th>Competição</th><th>Jogo</th><th class="text-end">Placar</th><th></th></tr></thead><tbody>';

  foreach ($rows as $r) {
    $pl = ($r['home_score'] === null || $r['away_score'] === null)
      ? '-'
      : ((int)$r['home_score'] . ' - ' . (int)$r['away_score']);

    echo '<tr>';
    echo '<td>' . h((string)$r['match_date']) . '</td>';
    echo '<td>' . h((string)$r['competition']) . '</td>';
    echo '<td>' . h((string)$r['home']) . ' x ' . h((string)$r['away']) . '</td>';
    echo '<td class="text-end fw-bold">' . h($pl) . '</td>';
    echo '<td class="text-end"><a class="btn btn-sm btn-primary" href="/?page=match&id=' . (int)$r['id'] . '">Abrir</a></td>';
    echo '</tr>';
  }

  echo '</tbody></table></div>';
}

echo '</div></div>';

echo '<div class="col-lg-5">';
echo '<div class="card card-soft p-3">';
echo '<div class="fw-bold mb-2">Atalhos</div>';
echo '<div class="d-grid gap-2">';
echo '<a class="btn btn-success" href="/?page=create_match">Cadastrar partida</a>';
echo '<a class="btn btn-primary" href="/?page=players">Gerenciar elenco</a>';
echo '<a class="btn btn-primary" href="/?page=stats">Relatórios estatísticos</a>';
echo '<a class="btn btn-primary" href="/?page=almanaque">Almanaque</a>';

echo '<form method="post" class="m-0" onsubmit="return confirm(\'Tem certeza que deseja apagar todo o save? Esta ação removerá apenas as partidas, elenco, base, templates, transferências, lesões e troféus deste usuário de forma definitiva.\');">';
echo '<input type="hidden" name="action" value="reset_save">';
echo '<button type="submit" class="btn btn-danger w-100">Apagar Save</button>';
echo '</form>';

echo '</div>';
echo '</div></div>';

echo '</div>';

render_footer();