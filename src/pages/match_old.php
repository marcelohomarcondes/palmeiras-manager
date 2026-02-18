<?php
declare(strict_types=1);

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('/?page=matches');

$match = q($pdo, "SELECT * FROM matches WHERE id = ?", [$id])->fetch(PDO::FETCH_ASSOC);
if (!$match) exit('Partida não encontrada.');

$PAL  = app_club();
$HOME = (string)$match['home'];
$AWAY = (string)$match['away'];

$clubs = [$HOME => true, $AWAY => true];

// Qual é o adversário (o clube que NÃO é o Palmeiras)
$OPP_CLUB = (strcasecmp($HOME, $PAL) === 0) ? $AWAY : $HOME;

// Lista de atletas do Palmeiras (tabela players)
$palPlayers = q($pdo, "
  SELECT id, name, shirt_number, is_active
  FROM players
  WHERE club_name = ? COLLATE NOCASE
  ORDER BY is_active DESC, name ASC
", [$PAL])->fetchAll(PDO::FETCH_ASSOC);

// Lista de atletas do adversário (tabela opponent_players)
$oppPlayersByClub = [];
$oppRows = q($pdo, "
  SELECT id, club_name, name, is_active
  FROM opponent_players
  WHERE club_name IN (?, ?) COLLATE NOCASE
  ORDER BY is_active DESC, name ASC
", [$HOME, $AWAY])->fetchAll(PDO::FETCH_ASSOC);
foreach ($oppRows as $r) {
  $oppPlayersByClub[$r['club_name']][] = $r;
}

// Match players + nomes (Palmeiras ou adversário)
$mpRows = q($pdo, "
  SELECT
    mp.*,
    COALESCE(p.name, op.name) AS player_name
  FROM match_players mp
  LEFT JOIN players p ON p.id = mp.player_id
  LEFT JOIN opponent_players op ON op.id = mp.opponent_player_id
  WHERE mp.match_id = ?
  ORDER BY mp.club_name,
    CASE mp.role WHEN 'STARTER' THEN 0 ELSE 1 END,
    mp.sort_order
", [$id])->fetchAll(PDO::FETCH_ASSOC);

// Stats (assumindo que match_player_stats tem player_id e opponent_player_id)
$statsRows = q($pdo, "
  SELECT *
  FROM match_player_stats
  WHERE match_id = ?
", [$id])->fetchAll(PDO::FETCH_ASSOC);

$statsMap = [];
foreach ($statsRows as $s) {
  $pid  = (int)($s['player_id'] ?? 0);
  $opid = (int)($s['opponent_player_id'] ?? 0);
  $key = (string)$s['club_name'] . '#' . ($opid > 0 ? ('O'.$opid) : ('P'.$pid));
  $statsMap[$key] = $s;
}

$lineup = [
  $HOME => ['starter'=>[], 'bench'=>[]],
  $AWAY => ['starter'=>[], 'bench'=>[]]
];

foreach ($mpRows as $r) {
  $club = (string)$r['club_name'];
  $role = strtolower((string)$r['role']);
  if (!isset($lineup[$club])) continue;

  $pid  = (int)($r['player_id'] ?? 0);
  $opid = (int)($r['opponent_player_id'] ?? 0);

  $key = $club . '#' . ($opid > 0 ? ('O'.$opid) : ('P'.$pid));
  $st = $statsMap[$key] ?? [];

  $lineup[$club][$role][] = [
    'player_id' => $pid,
    'opponent_player_id' => $opid,
    'position' => $r['position'] ?? '',
    'goals_for' => $st['goals_for'] ?? 0,
    'assists' => $st['assists'] ?? 0,
    'goals_against' => $st['goals_against'] ?? 0,
    'yellow_cards' => $st['yellow_cards'] ?? 0,
    'red_cards' => $st['red_cards'] ?? 0,
    'rating' => $st['rating'] ?? '',
    'motm' => $st['motm'] ?? 0,
  ];
}

foreach ([$HOME, $AWAY] as $club) {
  $lineup[$club]['starter'] = array_slice($lineup[$club]['starter'], 0, 11);
  $lineup[$club]['bench']   = array_slice($lineup[$club]['bench'], 0, 9);
}

function ensure_placeholders(&$clubData) : void {
  while (count($clubData['starter']) < 11) $clubData['starter'][] = [];
  while (count($clubData['bench']) < 9)   $clubData['bench'][] = [];
}

ensure_placeholders($lineup[$HOME]);
ensure_placeholders($lineup[$AWAY]);

/* ================= SAVE ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $rows = $_POST['rows'] ?? [];

  $pdo->beginTransaction();

  // Limpa e regrava o lineup/stats dessa partida
  q($pdo, "DELETE FROM match_player_stats WHERE match_id = ?", [$id]);
  q($pdo, "DELETE FROM match_players WHERE match_id = ?", [$id]);

  $insertMp = $pdo->prepare("
    INSERT INTO match_players(
      match_id, club_name, player_id, opponent_player_id,
      role, position, sort_order, entered, player_type
    )
    VALUES(?,?,?,?,?,?,?,?,?)
  ");

  $insertSt = $pdo->prepare("
    INSERT INTO match_player_stats(
      match_id, club_name, player_id, opponent_player_id,
      goals_for, goals_against, assists,
      yellow_cards, red_cards, rating, motm
    )
    VALUES(?,?,?,?,?,?,?,?,?,?,?)
  ");

  $count = [];

  foreach ($rows as $i => $r) {
    $club = (string)($r['club'] ?? '');
    $role = (string)($r['role'] ?? '');
    if (!isset($clubs[$club])) continue;
    if ($role !== 'starter' && $role !== 'bench') continue;

    if (!isset($count[$club])) $count[$club] = ['starter'=>0, 'bench'=>0];

    $limit = ($role === 'starter') ? 11 : 9;
    if ($count[$club][$role] >= $limit) continue;
    $count[$club][$role]++;

    $position = (string)($r['position'] ?? '');

    $pid = null;
    $opid = null;
    $playerType = 'HOME';

    // Palmeiras: player_id (players)
    if (strcasecmp($club, $PAL) === 0) {
      $pid = (int)($r['player_id'] ?? 0);
      if ($pid <= 0) continue;
      $playerType = 'HOME';
    } else {
      // Adversário: opponent_player_id (opponent_players)
      $opid = (int)($r['opponent_player_id'] ?? 0);
      if ($opid <= 0) continue;
      $playerType = 'AWAY';
    }

    $insertMp->execute([
      $id, $club, $pid, $opid,
      strtoupper($role), $position, (int)$i, 0, $playerType
    ]);

    $insertSt->execute([
      $id, $club, $pid, $opid,
      (int)($r['goals_for'] ?? 0),
      (int)($r['goals_against'] ?? 0),
      (int)($r['assists'] ?? 0),
      (int)($r['yellow_cards'] ?? 0),
      (int)($r['red_cards'] ?? 0),
      ($r['rating'] ?? null),
      isset($r['motm']) ? 1 : 0
    ]);
  }

  $pdo->commit();
  redirect('/?page=match&id='.$id);
}

/* ================= UI ================= */
render_header('Partida');

echo '<style>
table td, table th { vertical-align: middle; }
table input[type="number"] { text-align: center; }
</style>';

echo '<form method="post">';
echo '<div class="row g-4">';

function render_table(string $club, array $data, array $palPlayers, array $oppPlayersByClub, string $palClub) : void {
  $isPal = (strcasecmp($club, $palClub) === 0);

  echo '<div class="col-12 col-xl-6">';
  echo '<div class="card-soft p-3">';
  echo '<h5 class="mb-3">'.h($club).'</h5>';

  foreach (['starter' => 'Titulares (11)', 'bench' => 'Reservas (9)'] as $type => $label) {

    echo '<h6 class="mt-3">'.h($label).'</h6>';
    echo '<table class="table table-dark table-sm">';
    echo '<thead><tr>
      <th style="width:70px;">POS</th>
      <th>Atleta</th>
      <th style="width:55px;" class="text-center">G</th>
      <th style="width:55px;" class="text-center">A</th>
      <th style="width:60px;" class="text-center">GC</th>
      <th style="width:60px;" class="text-center">CA</th>
      <th style="width:60px;" class="text-center">CV</th>
      <th style="width:65px;" class="text-center">Nota</th>
      <th style="width:60px;" class="text-center">MVP</th>
    </tr></thead><tbody>';

    foreach ($data[$type] as $i => $row) {

      $idx = $club.'_'.$type.'_'.$i;
      $motm = !empty($row['motm']) ? 'checked' : '';

      echo '<tr>';

      echo '<td>
        <input class="form-control form-control-sm text-center px-1"
          style="max-width:70px;"
          name="rows['.h($idx).'][position]"
          value="'.h($row['position'] ?? '').'">
      </td>';

      // Select de atleta
      echo '<td>';
      echo '<select class="form-select form-select-sm w-100" ';

      if ($isPal) {
        echo 'name="rows['.h($idx).'][player_id]">';
        echo '<option value="0">--</option>';

        $current = (int)($row['player_id'] ?? 0);
        foreach ($palPlayers as $p) {
          $sel = ((int)$p['id'] === $current) ? 'selected' : '';
          $labelP = $p['name'];
          if (!empty($p['shirt_number'])) $labelP = $p['shirt_number'].' - '.$labelP;
          echo '<option value="'.(int)$p['id'].'" '.$sel.'>'.h($labelP).'</option>';
        }
      } else {
        echo 'name="rows['.h($idx).'][opponent_player_id]">';
        echo '<option value="0">--</option>';

        $current = (int)($row['opponent_player_id'] ?? 0);
        $opts = $oppPlayersByClub[$club] ?? [];
        foreach ($opts as $p) {
          $sel = ((int)$p['id'] === $current) ? 'selected' : '';
          echo '<option value="'.(int)$p['id'].'" '.$sel.'>'.h($p['name']).'</option>';
        }
      }

      echo '</select>';
      echo '</td>';

      $smallInput = 'class="form-control form-control-sm text-center px-1" style="max-width:60px;"';

      echo '<td><input type="number" name="rows['.h($idx).'][goals_for]" value="'.h((string)($row['goals_for'] ?? 0)).'" '.$smallInput.'></td>';
      echo '<td><input type="number" name="rows['.h($idx).'][assists]" value="'.h((string)($row['assists'] ?? 0)).'" '.$smallInput.'></td>';
      echo '<td><input type="number" name="rows['.h($idx).'][goals_against]" value="'.h((string)($row['goals_against'] ?? 0)).'" '.$smallInput.'></td>';
      echo '<td><input type="number" name="rows['.h($idx).'][yellow_cards]" value="'.h((string)($row['yellow_cards'] ?? 0)).'" '.$smallInput.'></td>';
      echo '<td><input type="number" name="rows['.h($idx).'][red_cards]" value="'.h((string)($row['red_cards'] ?? 0)).'" '.$smallInput.'></td>';

      echo '<td>
        <input type="number" step="0.1" min="0" max="10"
          name="rows['.h($idx).'][rating]"
          value="'.h((string)($row['rating'] ?? '')).'"
          class="form-control form-control-sm text-center px-1"
          style="max-width:65px;">
      </td>';

      echo '<td class="text-center">
        <input type="checkbox" name="rows['.h($idx).'][motm]" value="1" '.$motm.'>
      </td>';

      echo '<input type="hidden" name="rows['.h($idx).'][club]" value="'.h($club).'">';
      echo '<input type="hidden" name="rows['.h($idx).'][role]" value="'.h($type).'">';

      echo '</tr>';
    }

    echo '</tbody></table>';
  }

  echo '</div></div>';
}

render_table($HOME, $lineup[$HOME], $palPlayers, $oppPlayersByClub, $PAL);
render_table($AWAY, $lineup[$AWAY], $palPlayers, $oppPlayersByClub, $PAL);

echo '</div>';
echo '<div class="text-end mt-3">
  <button class="btn btn-success">Salvar</button>
</div>';
echo '</form>';

render_footer();
