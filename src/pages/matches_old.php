<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

$pdo = db();

$editId  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = $editId > 0;
$editRow = null;

if ($editing) {
  $editRow = q($pdo, "SELECT * FROM matches WHERE id = ?", [$editId])->fetch();
  if (!$editRow) {
    redirect('/?page=matches&err=notfound');
  }
}

$err = trim((string)($_GET['err'] ?? ''));

// Form "sticky" (mantém valores no erro)
$form = [
  'id' => 0,
  'season' => '',
  'competition' => '',
  'match_date' => '',
  'phase' => '',
  'round' => '',
  'match_time' => '',
  'stadium' => '',
  'referee' => '',
  'home' => app_club(),
  'away' => '',
  'kit_used' => '',
  'weather' => '',
  'home_score' => '',
  'away_score' => '',
];

if ($editRow) {
  $form = array_merge($form, $editRow);
  // normaliza placar nulo -> string vazia
  $form['home_score'] = ($editRow['home_score'] === null) ? '' : (string)$editRow['home_score'];
  $form['away_score'] = ($editRow['away_score'] === null) ? '' : (string)$editRow['away_score'];
}

// Ações do formulário (salvar / excluir)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? 'save');
  $id     = (int)($_POST['id'] ?? 0);

  // Excluir partida
  if ($action === 'delete' && $id > 0) {
    q($pdo, "DELETE FROM match_player_stats WHERE match_id = ?", [$id]);
    q($pdo, "DELETE FROM substitutions      WHERE match_id = ?", [$id]);
    q($pdo, "DELETE FROM match_players      WHERE match_id = ?", [$id]);
    q($pdo, "DELETE FROM matches            WHERE id       = ?", [$id]);
    redirect('/?page=matches');
  }

  // captura POST para manter no form em caso de erro
  $form['id']          = $id;
  $form['season']      = trim((string)($_POST['season'] ?? ''));
  $form['competition'] = trim((string)($_POST['competition'] ?? ''));
  $form['match_date']  = trim((string)($_POST['match_date'] ?? ''));
  $form['phase']       = trim((string)($_POST['phase'] ?? ''));
  $form['round']       = trim((string)($_POST['round'] ?? ''));
  $form['match_time']  = trim((string)($_POST['match_time'] ?? ''));
  $form['stadium']     = trim((string)($_POST['stadium'] ?? ''));
  $form['referee']     = trim((string)($_POST['referee'] ?? ''));
  $form['home']        = trim((string)($_POST['home'] ?? ''));
  $form['away']        = trim((string)($_POST['away'] ?? ''));
  $form['kit_used']    = trim((string)($_POST['kit_used'] ?? ''));
  $form['weather']     = trim((string)($_POST['weather'] ?? ''));

  $form['home_score']  = trim((string)($_POST['home_score'] ?? ''));
  $form['away_score']  = trim((string)($_POST['away_score'] ?? ''));

  // placar pode ficar vazio (partida futura)
  $home_score = ($form['home_score'] === '') ? null : (int)$form['home_score'];
  $away_score = ($form['away_score'] === '') ? null : (int)$form['away_score'];

  // Palmeiras-only
  if ($form['home'] !== app_club() && $form['away'] !== app_club()) {
    $err = 'palmeiras_only';
  }

  // validação mínima
  if ($err === '') {
    if ($form['season'] === '' || $form['competition'] === '' || $form['match_date'] === '' || $form['home'] === '' || $form['away'] === '' || $form['home'] === $form['away']) {
      $err = 'invalid';
    }
  }

  // temporada x ano da data
  if ($err === '') {
    $year = (int)substr($form['match_date'], 0, 4);
    if ($year > 0 && (string)$year !== $form['season']) {
      $err = 'season_date_mismatch';
    }
  }

  if ($err === '') {
    if ($id > 0) {
      q($pdo, "UPDATE matches SET
        season=?, competition=?, phase=?, round=?,
        match_date=?, match_time=?,
        stadium=?, referee=?,
        home=?, away=?,
        kit_used=?, weather=?,
        home_score=?, away_score=?
        WHERE id=?",
        [
          $form['season'], $form['competition'], $form['phase'], $form['round'],
          $form['match_date'], $form['match_time'],
          $form['stadium'], $form['referee'],
          $form['home'], $form['away'],
          $form['kit_used'], $form['weather'],
          $home_score, $away_score,
          $id
        ]
      );
    } else {
      q($pdo, "INSERT INTO matches(
        season, competition, phase, round,
        match_date, match_time,
        stadium, referee,
        home, away,
        kit_used, weather,
        home_score, away_score
      ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [
          $form['season'], $form['competition'], $form['phase'], $form['round'],
          $form['match_date'], $form['match_time'],
          $form['stadium'], $form['referee'],
          $form['home'], $form['away'],
          $form['kit_used'], $form['weather'],
          $home_score, $away_score
        ]
      );
    }

    redirect('/?page=matches');
  }
}

render_header('Partidas');

if ($err === 'palmeiras_only') {
  echo '<div class="alert alert-warning card-soft">Este sistema aceita apenas partidas onde o <b>PALMEIRAS</b> participa.</div>';
} elseif ($err === 'invalid') {
  echo '<div class="alert alert-warning card-soft">Preencha: temporada, campeonato, data, mandante e visitante (e não podem ser iguais).</div>';
} elseif ($err === 'season_date_mismatch') {
  echo '<div class="alert alert-warning card-soft">A <b>Temporada</b> deve ser igual ao <b>ano da Data</b> da partida.</div>';
} elseif ($err === 'notfound') {
  echo '<div class="alert alert-warning card-soft">Partida não encontrada.</div>';
}

echo '<div class="card-soft mb-3">';
echo '<h5 class="mb-3">'.($editing ? 'Editar partida' : 'Cadastrar partida').'</h5>';

echo '<form method="post" class="row g-2">';
echo '<input type="hidden" name="id" value="'.(int)($form['id'] ?? 0).'">';
echo '<input type="hidden" name="action" value="save">';

// Temporada (dropdown)
$seasonVal = (string)($form['season'] ?? '');
echo '<div class="col-md-2"><label class="form-label">Temporada</label>';
echo '<select class="form-control" name="season" required>';
foreach (range(2026, 2040) as $year) {
  $sel = ($seasonVal === (string)$year) ? ' selected' : '';
  echo '<option value="'.$year.'"'.$sel.'>'.$year.'</option>';
}
echo '</select></div>';

// Campeonato (dropdown)
$compVal = (string)($form['competition'] ?? '');
echo '<div class="col-md-4"><label class="form-label">Campeonato</label>';
echo '<select class="form-control" name="competition" required>';
$comps = [
  'PAULISTÃO CASAS BAHIA',
  'BRASILEIRÃO BETANO',
  'COPA BETANO DO BRASIL',
  'SUPERCOPA REI SUPERBET',
  'CONMEBOL LIBERTADORES',
  'CONMEBOL SULAMERICANA',
  'CONMEBOL RECOPA',
  'COPA INTERCONTINENTAL DA FIFA',
  'COPA DO MUNDO DE CLUBES DA FIFA',
];
foreach ($comps as $opt) {
  $sel = ($compVal === $opt) ? ' selected' : '';
  echo '<option value="'.h($opt).'"'.$sel.'>'.h($opt).'</option>';
}
echo '</select></div>';

// Data (max 2040-12-31)
$dateVal = (string)($form['match_date'] ?? '');
echo '<div class="col-md-3"><label class="form-label">Data</label><input class="form-control" type="date" name="match_date" max="2040-12-31" value="'.h($dateVal).'" required></div>';

echo '<div class="col-md-3"></div>';

// Fase / Rodada / Horário
echo '<div class="col-md-3"><label class="form-label">Fase</label><input class="form-control" name="phase" value="'.h((string)($form['phase'] ?? '')).'" placeholder="Fase de grupos / Quartas / Final"></div>';
echo '<div class="col-md-2"><label class="form-label">Rodada</label><input class="form-control" name="round" value="'.h((string)($form['round'] ?? '')).'" placeholder="1ª rodada"></div>';
echo '<div class="col-md-2"><label class="form-label">Horário</label><input class="form-control" type="time" name="match_time" value="'.h((string)($form['match_time'] ?? '')).'"></div>';

echo '<div class="col-md-5"></div>';

// Mandante / Visitante / Estádio / Árbitro
echo '<div class="col-md-4"><label class="form-label">Mandante</label><input class="form-control" name="home" value="'.h((string)($form['home'] ?? app_club())).'" required></div>';
echo '<div class="col-md-4"><label class="form-label">Visitante</label><input class="form-control" name="away" value="'.h((string)($form['away'] ?? '')).'" placeholder="Adversário" required></div>';
echo '<div class="col-md-4"><label class="form-label">Estádio</label><input class="form-control" name="stadium" value="'.h((string)($form['stadium'] ?? '')).'" placeholder="Allianz Parque"></div>';
echo '<div class="col-md-4"><label class="form-label">Árbitro</label><input class="form-control" name="referee" value="'.h((string)($form['referee'] ?? '')).'" placeholder="Nome do árbitro"></div>';

// Uniforme (dropdown)
$kitVal = (string)($form['kit_used'] ?? '');
echo '<div class="col-md-4"><label class="form-label">Uniforme</label>';
echo '<select class="form-control" name="kit_used" required>';
foreach (['Home','Away','Third','Alternativo 1','Alternativo 2','Alternativo 3'] as $opt) {
  $sel = ($kitVal === $opt) ? ' selected' : '';
  echo '<option value="'.h($opt).'"'.$sel.'>'.h($opt).'</option>';
}
echo '</select></div>';

// Clima (dropdown)
$wVal = (string)($form['weather'] ?? '');
echo '<div class="col-md-4"><label class="form-label">Clima</label>';
echo '<select class="form-control" name="weather" required>';
foreach (['Limpo','Parcialmente limpo','Nublado','Chuva','Neve'] as $opt) {
  $sel = ($wVal === $opt) ? ' selected' : '';
  echo '<option value="'.h($opt).'"'.$sel.'>'.h($opt).'</option>';
}
echo '</select></div>';

// Placar
echo '<div class="col-md-2"><label class="form-label">Gols mandante</label><input class="form-control" name="home_score" inputmode="numeric" value="'.h((string)($form['home_score'] ?? '')).'" placeholder=""></div>';
echo '<div class="col-md-2"><label class="form-label">Gols visitante</label><input class="form-control" name="away_score" inputmode="numeric" value="'.h((string)($form['away_score'] ?? '')).'" placeholder=""></div>';

echo '<div class="col-md-12 d-grid mt-2"><button class="btn btn-success">Salvar</button></div>';
echo '</form>';

// Excluir (somente em edição)
if ($editing) {
  echo '<form method="post" class="mt-2" onsubmit="return confirm(\'Excluir esta partida? Esta ação não pode ser desfeita.\');">';
  echo '<input type="hidden" name="id" value="'.(int)($form['id'] ?? 0).'">';
  echo '<input type="hidden" name="action" value="delete">';
  echo '<button class="btn btn-outline-danger w-100">Excluir partida</button>';
  echo '</form>';
}

echo '</div>';

// Listagem
$rows = q($pdo, "SELECT id, season, competition, match_date, home, away, home_score, away_score
                FROM matches
                ORDER BY match_date DESC, id DESC
                LIMIT 200")->fetchAll();

echo '<div class="card-soft">';
echo '<h5 class="mb-3">Partidas</h5>';

if (!$rows) {
  echo '<div class="muted">Sem partidas cadastradas.</div>';
  echo '</div>';
  render_footer();
  exit;
}

echo '<div class="table-responsive"><table class="table align-middle">';
echo '<thead><tr>
  <th>Data</th><th>Temporada</th><th>Campeonato</th><th>Jogo</th><th class="text-end">Placar</th>
</tr></thead><tbody>';

foreach ($rows as $r) {
  $hs = ($r['home_score'] === null) ? '-' : (string)$r['home_score'];
  $as = ($r['away_score'] === null) ? '-' : (string)$r['away_score'];
  echo '<tr>';
  echo '<td>'.h((string)$r['match_date']).'</td>';
  echo '<td>'.h((string)$r['season']).'</td>';
  echo '<td>'.h((string)$r['competition']).'</td>';
  echo '<td>'.h((string)$r['home']).' vs '.h((string)$r['away']).'</td>';
  echo '<td class="text-end">'.$hs.' x '.$as.'</td>';
  echo '<td>
  <a class="btn btn-sm btn-primary" href="/?page=matches&edit='.$r['id'].'">Editar</a>
  <form method="post" style="display:inline">
    <input type="hidden" name="id" value="'.$r['id'].'">
    <input type="hidden" name="action" value="delete">
    <button class="btn btn-sm btn-danger" onclick="return confirm(\'Excluir esta partida?\')">Excluir</button>
  </form>
</td>';
  echo '</tr>';
}

echo '</tbody></table></div></div>';

render_footer();

