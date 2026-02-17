<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo  = db();
$club = app_club();

$action = (string)($_GET['action'] ?? '');
$msg    = (string)($_GET['msg'] ?? '');
$err    = (string)($_GET['err'] ?? '');

/**
 * EXCLUIR PARTIDA (POST)
 * Endpoint: /?page=matches&action=delete
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
  $matchId = (int)($_POST['match_id'] ?? 0);

  if ($matchId <= 0) {
    redirect('/?page=matches&err=invalid');
  }

  // Garante que a partida existe e envolve o clube atual
  $m = q($pdo, "SELECT id, home, away FROM matches WHERE id = ? LIMIT 1", [$matchId])->fetch();
  if (!$m) {
    redirect('/?page=matches&err=not_found');
  }
  if ((string)$m['home'] !== $club && (string)$m['away'] !== $club) {
    redirect('/?page=matches&err=not_allowed');
  }

  $pdo->beginTransaction();
  try {
    // Apaga dependências primeiro
    q($pdo, "DELETE FROM match_player_stats WHERE match_id = ?", [$matchId]);
    q($pdo, "DELETE FROM match_players      WHERE match_id = ?", [$matchId]);

    // Apaga a partida
    q($pdo, "DELETE FROM matches WHERE id = ?", [$matchId]);

    $pdo->commit();
    redirect('/?page=matches&msg=deleted');
  } catch (Throwable $e) {
    $pdo->rollBack();
    redirect('/?page=matches&err=delete_failed');
  }
}

render_header('Partidas');

if ($msg === 'saved') {
  echo '<div class="alert alert-success card-soft">Partida cadastrada com sucesso.</div>';
}
if ($msg === 'deleted') {
  echo '<div class="alert alert-success card-soft">Partida excluída com sucesso.</div>';
}
if ($err === 'invalid') {
  echo '<div class="alert alert-warning card-soft">Requisição inválida.</div>';
} elseif ($err === 'not_found') {
  echo '<div class="alert alert-warning card-soft">Partida não encontrada.</div>';
} elseif ($err === 'not_allowed') {
  echo '<div class="alert alert-warning card-soft">Você não pode excluir uma partida que não envolve o clube atual.</div>';
} elseif ($err === 'delete_failed') {
  echo '<div class="alert alert-danger card-soft">Falha ao excluir a partida. Tente novamente.</div>';
}

// Lista partidas (histórico)
$rows = q($pdo, "
  SELECT
    id,
    season,
    competition,
    match_date,
    home,
    away,
    home_score,
    away_score
  FROM matches
  ORDER BY match_date DESC, id DESC
")->fetchAll();

echo '<div class="d-flex justify-content-between align-items-center mb-3">';
echo '  <h4 class="mb-0">Histórico</h4>';
echo ' <a class="btn btn-success" href="index.php?page=create_match">Cadastrar partida</a>';
echo '</div>';

echo '<div class="card-soft">';
echo '<div class="table-responsive">';
echo '<table class="table table-sm mb-0">';
echo '<thead><tr>';
echo '<th>Data</th>';
echo '<th>Temporada</th>';
echo '<th>Campeonato</th>';
echo '<th>Jogo</th>';
echo '<th>Placar</th>';
echo '<th class="text-end">Ações</th>';
echo '</tr></thead><tbody>';

if (!$rows) {
  echo '<tr><td colspan="6" class="text-muted">Nenhuma partida cadastrada.</td></tr>';
} else {
  foreach ($rows as $r) {
    $id    = (int)$r['id'];
    $date  = (string)$r['match_date'];
    $seas  = (string)$r['season'];
    $comp  = (string)$r['competition'];
    $home  = (string)$r['home'];
    $away  = (string)$r['away'];

    $hs = $r['home_score'];
    $as = $r['away_score'];
    $score = (($hs === null || $hs === '') || ($as === null || $as === '')) ? '-' : ((int)$hs . ' x ' . (int)$as);

    echo '<tr>';
    echo '<td>' . h($date) . '</td>';
    echo '<td>' . h($seas) . '</td>';
    echo '<td>' . h($comp) . '</td>';
    echo '<td><b>' . h($home) . '</b> vs ' . h($away) . '</td>';
    echo '<td>' . h($score) . '</td>';

    // Ações: Abrir + Excluir
    echo '<td class="text-end" style="white-space:nowrap;">';
    echo '  <a class="btn btn-sm btn-outline-primary" href="/?page=match&id=' . $id . '">Abrir</a> ';

    echo '  <form method="post" action="/?page=matches&action=delete" style="display:inline-block;" onsubmit="return confirm(\'Excluir esta partida? Essa ação não pode ser desfeita.\')">';
    echo '    <input type="hidden" name="match_id" value="' . $id . '">';
    echo '    <button class="btn btn-sm btn-outline-danger" type="submit">Excluir</button>';
    echo '  </form>';

    echo '</td>';

    echo '</tr>';
  }
}

echo '</tbody></table>';
echo '</div></div>';

render_footer();
