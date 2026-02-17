<?php
declare(strict_types=1);

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('/?page=matches');

$match = q($pdo, "SELECT * FROM matches WHERE id = ?", [$id])->fetch(PDO::FETCH_ASSOC);
if (!$match) exit('Partida não encontrada.');

$PAL = app_club();
$HOME = (string)$match['home'];
$AWAY = (string)$match['away'];

$clubs = [$HOME => true, $AWAY => true];

$players = q($pdo, "SELECT * FROM players ORDER BY is_active DESC, name ASC")
  ->fetchAll(PDO::FETCH_ASSOC);

$mpRows = q($pdo,"
  SELECT mp.*, p.name AS player_name
  FROM match_players mp
  LEFT JOIN players p ON p.id = mp.player_id
  WHERE mp.match_id = ?
  ORDER BY mp.club_name,
    CASE mp.role WHEN 'STARTER' THEN 0 ELSE 1 END,
    mp.sort_order
",[$id])->fetchAll(PDO::FETCH_ASSOC);

$statsRows = q($pdo,"SELECT * FROM match_player_stats WHERE match_id = ?",[$id])
  ->fetchAll(PDO::FETCH_ASSOC);

$statsMap = [];
foreach($statsRows as $s){
  $statsMap[$s['club_name'].'#'.$s['player_id']] = $s;
}

$lineup = [
  $HOME => ['starter'=>[], 'bench'=>[]],
  $AWAY => ['starter'=>[], 'bench'=>[]]
];

foreach($mpRows as $r){
  $club = $r['club_name'];
  $role = strtolower($r['role']);
  if(!isset($lineup[$club])) continue;

  $pid = (int)$r['player_id'];
  $key = $club.'#'.$pid;
  $st = $statsMap[$key] ?? [];

  $lineup[$club][$role][] = [
    'player_id'=>$pid,
    'position'=>$r['position'] ?? '',
    'goals_for'=>$st['goals_for'] ?? 0,
    'assists'=>$st['assists'] ?? 0,
    'goals_against'=>$st['goals_against'] ?? 0,
    'yellow_cards'=>$st['yellow_cards'] ?? 0,
    'red_cards'=>$st['red_cards'] ?? 0,
    'rating'=>$st['rating'] ?? '',
    'motm'=>$st['motm'] ?? 0,
  ];
}

foreach([$HOME,$AWAY] as $club){
  $lineup[$club]['starter'] = array_slice($lineup[$club]['starter'],0,11);
  $lineup[$club]['bench']   = array_slice($lineup[$club]['bench'],0,9);
}

function ensure_placeholders(&$clubData){
  while(count($clubData['starter']) < 11) $clubData['starter'][] = [];
  while(count($clubData['bench']) < 9)   $clubData['bench'][] = [];
}

ensure_placeholders($lineup[$HOME]);
ensure_placeholders($lineup[$AWAY]);

/* ================= SAVE ================= */
if($_SERVER['REQUEST_METHOD']==='POST'){
  $rows = $_POST['rows'] ?? [];
  $pdo->beginTransaction();

  q($pdo,"DELETE FROM match_player_stats WHERE match_id=?",[$id]);
  q($pdo,"DELETE FROM match_players WHERE match_id=?",[$id]);

  $insertMp = $pdo->prepare("
    INSERT INTO match_players(match_id,club_name,player_id,role,position,sort_order,entered)
    VALUES(?,?,?,?,?,?,?)
  ");

  $insertSt = $pdo->prepare("
    INSERT INTO match_player_stats(match_id,club_name,player_id,goals_for,goals_against,assists,yellow_cards,red_cards,rating,motm)
    VALUES(?,?,?,?,?,?,?,?,?,?)
  ");

  $count = [];

  foreach($rows as $i=>$r){
    $club=$r['club'];
    $role=$r['role'];
    if(!isset($clubs[$club])) continue;

    if(!isset($count[$club])) $count[$club]=['starter'=>0,'bench'=>0];

    $limit = ($role==='starter')?11:9;
    if($count[$club][$role] >= $limit) continue;
    $count[$club][$role]++;

    $pid=(int)$r['player_id'];
    if($pid<=0) continue;

    $insertMp->execute([$id,$club,$pid,strtoupper($role),$r['position'],$i,0]);
    $insertSt->execute([
      $id,$club,$pid,
      $r['goals_for'],$r['goals_against'],$r['assists'],
      $r['yellow_cards'],$r['red_cards'],
      $r['rating'] ?? null,
      isset($r['motm'])?1:0
    ]);
  }

  $pdo->commit();
  redirect('/?page=match&id='.$id);
}

/* ================= UI ================= */
render_header("Partida");

echo '<style>
table td, table th { vertical-align: middle; }
table input[type="number"] { text-align: center; }
</style>';

echo '<form method="post">';
echo '<div class="row g-4">';

function render_table($club,$data,$players){

echo '<div class="col-12 col-xl-6">';
echo '<div class="card-soft p-3">';
echo '<h5 class="mb-3">'.$club.'</h5>';

foreach(['starter'=>'Titulares (11)','bench'=>'Reservas (9)'] as $type=>$label){

echo '<h6 class="mt-3">'.$label.'</h6>';
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

foreach($data[$type] as $i=>$row){

$idx = $club.'_'.$type.'_'.$i;
$motm = !empty($row['motm']) ? 'checked' : '';

echo '<tr>';

echo '<td>
<input class="form-control form-control-sm text-center px-1"
style="max-width:70px;"
name="rows['.$idx.'][position]"
value="'.($row['position']??'').'">
</td>';

echo '<td>
<select class="form-select form-select-sm w-100"
name="rows['.$idx.'][player_id]">
<option value="0">--</option>';
foreach($players as $p){
$sel = ($p['id']==($row['player_id']??0))?'selected':'';
echo '<option value="'.$p['id'].'" '.$sel.'>'.$p['name'].'</option>';
}
echo '</select>
</td>';

$smallInput = 'class="form-control form-control-sm text-center px-1" style="max-width:60px;"';

echo '<td><input type="number" name="rows['.$idx.'][goals_for]" value="'.($row['goals_for']??0).'" '.$smallInput.'></td>';
echo '<td><input type="number" name="rows['.$idx.'][assists]" value="'.($row['assists']??0).'" '.$smallInput.'></td>';
echo '<td><input type="number" name="rows['.$idx.'][goals_against]" value="'.($row['goals_against']??0).'" '.$smallInput.'></td>';
echo '<td><input type="number" name="rows['.$idx.'][yellow_cards]" value="'.($row['yellow_cards']??0).'" '.$smallInput.'></td>';
echo '<td><input type="number" name="rows['.$idx.'][red_cards]" value="'.($row['red_cards']??0).'" '.$smallInput.'></td>';

echo '<td>
<input type="number" step="0.1" min="0" max="10"
name="rows['.$idx.'][rating]"
value="'.($row['rating']??'').'"
class="form-control form-control-sm text-center px-1"
style="max-width:65px;">
</td>';

echo '<td class="text-center">
<input type="checkbox" name="rows['.$idx.'][motm]" value="1" '.$motm.'>
</td>';

echo '<input type="hidden" name="rows['.$idx.'][club]" value="'.$club.'">';
echo '<input type="hidden" name="rows['.$idx.'][role]" value="'.$type.'">';

echo '</tr>';
}

echo '</tbody></table>';
}

echo '</div></div>';
}

render_table($HOME,$lineup[$HOME],$players);
render_table($AWAY,$lineup[$AWAY],$players);

echo '</div>';
echo '<div class="text-end mt-3">
<button class="btn btn-success">Salvar</button>
</div>';
echo '</form>';

render_footer();
