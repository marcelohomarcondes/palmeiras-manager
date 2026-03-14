<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo    = db();
$userId = require_user_id();
$club   = app_club(); // ex: PALMEIRAS

/* =========================================================
   Helpers
   ========================================================= */

if (!function_exists('pm_log')) {
  function pm_log(string $level, string $message): void {
    $dir = 'D:\\Projetos\\palmeiras_manager\\logs';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $file = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . 'app.log';
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($file, "[$ts][$level] $message\n", FILE_APPEND);
  }
}

if (!function_exists('postv')) {
  function postv(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    if (is_array($v)) $v = $v[0] ?? $default;
    return trim((string)$v);
  }
}

if (!function_exists('fval')) {
  function fval(string $key, string $default = ''): string {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') return postv($key, $default);
    return trim((string)($_GET[$key] ?? $default));
  }
}

if (!function_exists('to_int')) {
  function to_int(string $v, int $default = 0): int {
    $v = trim($v);
    return ($v === '') ? $default : (int)$v;
  }
}

if (!function_exists('to_float')) {
  function to_float(string $v, float $default = 0.0): float {
    $v = trim((string)$v);
    if ($v === '') return $default;
    $v = str_replace(',', '.', $v);
    return (float)$v;
  }
}

if (!function_exists('table_exists')) {
  function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('table_info')) {
  function table_info(PDO $pdo, string $table): array {
    $map = [];
    try {
      $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as $r) {
        $name = (string)$r['name'];
        $map[$name] = [
          'notnull' => (int)($r['notnull'] ?? 0),
          'dflt'    => $r['dflt_value'] ?? null,
          'type'    => (string)($r['type'] ?? ''),
        ];
      }
    } catch (Throwable $e) {}
    return $map;
  }
}

if (!function_exists('pick_col')) {
  function pick_col(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) {
      if (isset($cols[$c])) return $c;
    }
    return null;
  }
}

if (!function_exists('select_options')) {
  function select_options(array $items, string $selected): string {
    $out = '';
    foreach ($items as $k => $v) {
      $value = is_int($k) ? (string)$v : (string)$k;
      $label = (string)$v;
      $sel = (strcasecmp($value, (string)$selected) === 0) ? ' selected' : '';
      $out .= '<option value="'.h($value).'"'.$sel.'>'.h($label).'</option>';
    }
    return $out;
  }
}

function has_user_id_col(PDO $pdo, string $table): bool
{
  return isset(table_info($pdo, $table)['user_id']);
}

function sql_user_clause(PDO $pdo, string $table, string $alias, string $param = ':user_id'): string
{
  return has_user_id_col($pdo, $table) ? " AND {$alias}.user_id = {$param} " : ' ';
}

function sql_user_where(PDO $pdo, string $table, string $alias, string $param = ':user_id'): string
{
  return has_user_id_col($pdo, $table) ? " {$alias}.user_id = {$param} " : ' 1=1 ';
}

function add_user_id_if_exists(PDO $pdo, string $table, array &$data, int $userId): void
{
  if (has_user_id_col($pdo, $table)) {
    $data['user_id'] = $userId;
  }
}

function insert_dynamic(PDO $pdo, string $table, array $data): void
{
  $cols = array_keys($data);
  $ph   = array_fill(0, count($cols), '?');
  $sql  = "INSERT INTO {$table}(".implode(',', $cols).") VALUES(".implode(',', $ph).")";
  $pdo->prepare($sql)->execute(array_values($data));
}

function update_dynamic(PDO $pdo, string $table, array $data, string $whereSql, array $whereParams = []): void
{
  $set = implode(',', array_map(fn($c) => $c.'=?', array_keys($data)));
  $sql = "UPDATE {$table} SET {$set} WHERE {$whereSql}";
  $pdo->prepare($sql)->execute(array_merge(array_values($data), $whereParams));
}

function current_match_form_url(string $extra = ''): string
{
  $page = (string)($_GET['page'] ?? 'create_match');
  $page = ($page === 'edit_match') ? 'edit_match' : 'create_match';

  $matchId = 0;
  if (isset($_POST['match_id'])) {
    $matchId = (int)($_POST['match_id'] ?? 0);
  } elseif (isset($_GET['id'])) {
    $matchId = (int)($_GET['id'] ?? 0);
  }

  $url = '?page=' . $page;
  if ($page === 'edit_match' && $matchId > 0) {
    $url .= '&id=' . $matchId;
  }

  if ($extra !== '') {
    $url .= '&' . ltrim($extra, '&');
  }

  return $url;
}

/* =========================================================
   Listas
   ========================================================= */

$competitions = [
  'Paulistão Casas Bahia',
  'Brasileirão Betano',
  'Copa Betano do Brasil',
  'CONMEBOL Libertadores',
  'CONMEBOL Sul-Americana',
  'CONMEBOL Recopa',
  'Supercopa do Brasil',
  'Intercontinental FIFA',
  'Copa do Mundo de Clubes da FIFA',
];

$seasons = [];
for ($y = 2026; $y <= 2040; $y++) $seasons[] = (string)$y;

$kits      = ['Home','Away','Third','Alternativo 1','Alternativo 2','Alternativo 3'];
$weathers  = ['Limpo','Parcialmente limpo','Nublado','Chuva','Neve'];
$positions = ['GOL','ZAG','LD','LE','ALD','ALE','VOL','MC','ME','MD','MEI','PD','PE','SA','ATA'];

$MAX_STARTERS = 11;
$MAX_BENCH    = 9;

$err = trim((string)($_GET['err'] ?? ''));
$msg = trim((string)($_GET['msg'] ?? ''));

/* =========================================================
   EDIT MODE
   ========================================================= */
$editId = (int)($_GET['id'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && $editId > 0) {
  $mSql = "SELECT * FROM matches WHERE id = :id";
  $mParams = [':id' => $editId];
  if (has_user_id_col($pdo, 'matches')) {
    $mSql .= " AND user_id = :user_id";
    $mParams[':user_id'] = $userId;
  }
  $mSql .= " LIMIT 1";

  $mrow = q($pdo, $mSql, $mParams)->fetch(PDO::FETCH_ASSOC);
  if (!$mrow) {
    redirect('/?page=matches&err=not_found');
  }

  $homeName = (string)($mrow['home'] ?? ($mrow['home_team'] ?? ''));
  $awayName = (string)($mrow['away'] ?? ($mrow['away_team'] ?? ''));
  $isHomePal = (strcasecmp($homeName, $club) === 0);
  $palType   = $isHomePal ? 'HOME' : 'AWAY';
  $oppType   = $isHomePal ? 'AWAY' : 'HOME';
  $oppClub   = $isHomePal ? $awayName : $homeName;

  foreach ($mrow as $k => $v) {
    $val = ($v === null) ? '' : (string)$v;
    $_GET[$k]  = $val;
    $_POST[$k] = $val;
  }

  $_POST['match_id'] = (string)$editId;

  for ($i=0;$i<11;$i++){
    $_POST["pal_pid_starter_$i"] = $_POST["pal_pid_starter_$i"] ?? '0';
    $_POST["pal_pos_starter_$i"] = $_POST["pal_pos_starter_$i"] ?? '';
    $_POST["opp_name_starter_$i"] = $_POST["opp_name_starter_$i"] ?? '';
    $_POST["opp_pos_starter_$i"]  = $_POST["opp_pos_starter_$i"] ?? '';
  }
  for ($i=0;$i<9;$i++){
    $_POST["pal_pid_bench_$i"] = $_POST["pal_pid_bench_$i"] ?? '0';
    $_POST["pal_pos_bench_$i"] = $_POST["pal_pos_bench_$i"] ?? '';
    $_POST["opp_name_bench_$i"] = $_POST["opp_name_bench_$i"] ?? '';
    $_POST["opp_pos_bench_$i"]  = $_POST["opp_pos_bench_$i"] ?? '';
  }

  $mpSql = "
    SELECT role, sort_order, position, player_id, opponent_player_id
    FROM match_players
    WHERE match_id = :match_id
  ";
  $mpParams = [':match_id' => $editId];
  if (has_user_id_col($pdo, 'match_players')) {
    $mpSql .= " AND user_id = :user_id";
    $mpParams[':user_id'] = $userId;
  }
  $mpSql .= " ORDER BY role ASC, sort_order ASC";

  $mpr = q($pdo, $mpSql, $mpParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $needOppNames = [];
  foreach ($mpr as $r) {
    $roleU = strtoupper(trim((string)($r['role'] ?? '')));
    $roleL = ($roleU === 'STARTER') ? 'starter' : (($roleU === 'BENCH') ? 'bench' : '');
    $i = (int)($r['sort_order'] ?? -1);
    if ($roleL === '' || $i < 0) continue;

    $pos = (string)($r['position'] ?? '');

    if (!empty($r['player_id'])) {
      $_POST["pal_pid_{$roleL}_{$i}"] = (string)((int)$r['player_id']);
      $_POST["pal_pos_{$roleL}_{$i}"] = $pos;
    } elseif (!empty($r['opponent_player_id'])) {
      $oid = (int)$r['opponent_player_id'];
      $needOppNames[$oid] = true;
      $_POST["opp_pos_{$roleL}_{$i}"] = $pos;
      $_POST["_tmp_opp_id_{$roleL}_{$i}"] = (string)$oid;
    }
  }

  if ($needOppNames) {
    $ids = array_keys($needOppNames);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $opSql = "SELECT id, name FROM opponent_players WHERE id IN ($in)";
    $opParams = $ids;
    if (has_user_id_col($pdo, 'opponent_players')) {
      $opSql .= " AND user_id = ?";
      $opParams[] = $userId;
    }
    $opRows = q($pdo, $opSql, $opParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($opRows as $o) $map[(int)$o['id']] = (string)$o['name'];

    foreach (['starter'=>11,'bench'=>9] as $roleL => $max) {
      for ($i=0;$i<$max;$i++){
        $k = "_tmp_opp_id_{$roleL}_{$i}";
        if (!isset($_POST[$k])) continue;
        $oid = (int)$_POST[$k];
        $_POST["opp_name_{$roleL}_{$i}"] = $map[$oid] ?? '';
        unset($_POST[$k]);
      }
    }
  }

  if (table_exists($pdo, 'match_player_stats')) {
    $cols = table_info($pdo, 'match_player_stats');
    $sql = "SELECT * FROM match_player_stats WHERE match_id = :match_id";
    $params = [':match_id' => $editId];
    if (has_user_id_col($pdo, 'match_player_stats')) {
      $sql .= " AND user_id = :user_id";
      $params[':user_id'] = $userId;
    }

    $rows = q($pdo, $sql, $params)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byPid = [];
    foreach ($rows as $s) {
      $pid = (int)($s['player_id'] ?? 0);
      if ($pid > 0) $byPid[$pid] = $s;
    }

    foreach (['starter'=>11,'bench'=>9] as $roleL => $max) {
      for ($i=0;$i<$max;$i++){
        $pid = (int)($_POST["pal_pid_{$roleL}_{$i}"] ?? 0);
        if ($pid <= 0) continue;
        $s = $byPid[$pid] ?? null;
        if (!$s) continue;

        $gf = isset($cols['goals_for']) ? (int)($s['goals_for'] ?? 0) : (int)($s['goals'] ?? 0);
        $as = (int)($s['assists'] ?? 0);
        $gc = isset($cols['goals_against']) ? (int)($s['goals_against'] ?? 0) : (isset($cols['own_goals']) ? (int)($s['own_goals'] ?? 0) : 0);
        $yc = isset($cols['yellow_cards']) ? (int)($s['yellow_cards'] ?? 0) : (int)($s['yellow'] ?? 0);
        $rc = isset($cols['red_cards']) ? (int)($s['red_cards'] ?? 0) : (int)($s['red'] ?? 0);
        $rt = (string)($s['rating'] ?? '');
        $mvp = isset($cols['is_mvp']) ? (int)($s['is_mvp'] ?? 0) : (int)($s['motm'] ?? 0);

        $_POST["pal_g_{$roleL}_{$i}"] = (string)$gf;
        $_POST["pal_a_{$roleL}_{$i}"] = (string)$as;
        $_POST["pal_og_{$roleL}_{$i}"] = (string)$gc;
        $_POST["pal_y_{$roleL}_{$i}"] = (string)$yc;
        $_POST["pal_r_{$roleL}_{$i}"] = (string)$rc;
        $_POST["pal_rating_{$roleL}_{$i}"] = $rt;

        if ($mvp === 1) $_POST['mvp'] = "pal_{$roleL}_{$i}";
      }
    }
  }

  if (table_exists($pdo, 'opponent_match_player_stats')) {
    $cols = table_info($pdo, 'opponent_match_player_stats');
    $sql = "SELECT * FROM opponent_match_player_stats WHERE match_id = :match_id";
    $params = [':match_id' => $editId];
    if (has_user_id_col($pdo, 'opponent_match_player_stats')) {
      $sql .= " AND user_id = :user_id";
      $params[':user_id'] = $userId;
    }

    $rows = q($pdo, $sql, $params)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byOid = [];
    foreach ($rows as $s) {
      $oid = (int)($s['opponent_player_id'] ?? 0);
      if ($oid > 0) $byOid[$oid] = $s;
    }

    $oidBySlot = ['starter'=>[], 'bench'=>[]];
    foreach ($mpr as $r) {
      if (empty($r['opponent_player_id'])) continue;
      $roleU = strtoupper(trim((string)($r['role'] ?? '')));
      $roleL = ($roleU === 'STARTER') ? 'starter' : (($roleU === 'BENCH') ? 'bench' : '');
      $i = (int)($r['sort_order'] ?? -1);
      if ($roleL === '' || $i < 0) continue;
      $oidBySlot[$roleL][$i] = (int)$r['opponent_player_id'];
    }

    foreach (['starter'=>11,'bench'=>9] as $roleL => $max) {
      for ($i=0;$i<$max;$i++){
        $oid = (int)($oidBySlot[$roleL][$i] ?? 0);
        if ($oid <= 0) continue;
        $s = $byOid[$oid] ?? null;
        if (!$s) continue;

        $gf = isset($cols['goals_for']) ? (int)($s['goals_for'] ?? 0) : (int)($s['goals'] ?? 0);
        $as = (int)($s['assists'] ?? 0);
        $gc = isset($cols['goals_against']) ? (int)($s['goals_against'] ?? 0) : (isset($cols['own_goals']) ? (int)($s['own_goals'] ?? 0) : 0);
        $yc = isset($cols['yellow_cards']) ? (int)($s['yellow_cards'] ?? 0) : (int)($s['yellow'] ?? 0);
        $rc = isset($cols['red_cards']) ? (int)($s['red_cards'] ?? 0) : (int)($s['red'] ?? 0);
        $rt = (string)($s['rating'] ?? '');
        $mvp = isset($cols['is_mvp']) ? (int)($s['is_mvp'] ?? 0) : (int)($s['motm'] ?? 0);

        $_POST["opp_g_{$roleL}_{$i}"] = (string)$gf;
        $_POST["opp_a_{$roleL}_{$i}"] = (string)$as;
        $_POST["opp_og_{$roleL}_{$i}"] = (string)$gc;
        $_POST["opp_y_{$roleL}_{$i}"] = (string)$yc;
        $_POST["opp_r_{$roleL}_{$i}"] = (string)$rc;
        $_POST["opp_rating_{$roleL}_{$i}"] = $rt;

        if ($mvp === 1) $_POST['mvp'] = "opp_{$roleL}_{$i}";
      }
    }
  }

  if (table_exists($pdo, 'match_substitutions')) {
    $subsSql = "SELECT * FROM match_substitutions WHERE match_id = ? ";
    $subsParams = [$editId];
    if (has_user_id_col($pdo, 'match_substitutions')) {
      $subsSql .= " AND user_id = ? ";
      $subsParams[] = $userId;
    }
    $subsSql .= " ORDER BY sort_order ASC, id ASC ";
    $subs = q($pdo, $subsSql, $subsParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $oppAllSql = "SELECT id, name FROM opponent_players WHERE club_name=? COLLATE NOCASE";
    $oppAllParams = [$oppClub];
    if (has_user_id_col($pdo, 'opponent_players')) {
      $oppAllSql .= " AND user_id = ?";
      $oppAllParams[] = $userId;
    }
    $oppAll = q($pdo, $oppAllSql, $oppAllParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $oppNameById = [];
    foreach ($oppAll as $o) $oppNameById[(int)$o['id']] = (string)$o['name'];

    $p = 0; $o = 0;
    foreach ($subs as $s) {
      $side = strtoupper((string)($s['side'] ?? ''));
      $min  = (string)($s['minute'] ?? '');

      if ($side === $palType && $p < 5) {
        $_POST["pal_sub_min_$p"] = $min;
        $_POST["pal_sub_out_$p"] = (string)((int)($s['player_out_id'] ?? 0));
        $_POST["pal_sub_in_$p"]  = (string)((int)($s['player_in_id'] ?? 0));
        $p++;
      } elseif ($side === $oppType && $o < 5) {
        $_POST["opp_sub_min_$o"] = $min;
        $outId = (int)($s['opponent_out_id'] ?? 0);
        $inId  = (int)($s['opponent_in_id'] ?? 0);
        $_POST["opp_sub_out_$o"] = $oppNameById[$outId] ?? '';
        $_POST["opp_sub_in_$o"]  = $oppNameById[$inId] ?? '';
        $o++;
      }
    }
  }
}

/* =========================================================
   Palmeiras players
   - Em edição, mantém disponíveis também os atletas já usados
     na partida, mesmo que hoje estejam inativos/vendidos/emprestados.
   ========================================================= */

$editingMatchId = 0;
if (postv('match_id') !== '') {
  $editingMatchId = to_int(postv('match_id'), 0);
} elseif (!empty($_GET['id'])) {
  $editingMatchId = (int)($_GET['id'] ?? 0);
}

$lockedPlayerIds = [];

/**
 * 1) Captura jogadores já preenchidos no formulário
 */
foreach (['starter' => $MAX_STARTERS, 'bench' => $MAX_BENCH] as $roleL => $max) {
  for ($i = 0; $i < $max; $i++) {
    $pid = to_int(postv("pal_pid_{$roleL}_{$i}"), 0);
    if ($pid > 0) {
      $lockedPlayerIds[$pid] = true;
    }
  }
}

/**
 * 2) Captura também os jogadores já vinculados à partida salva
 */
if ($editingMatchId > 0 && table_exists($pdo, 'match_players')) {
  $sql = "
    SELECT DISTINCT player_id
    FROM match_players
    WHERE match_id = :match_id
      AND player_id IS NOT NULL
      AND player_id > 0
  ";
  $params = [':match_id' => $editingMatchId];

  if (has_user_id_col($pdo, 'match_players')) {
    $sql .= " AND user_id = :user_id";
    $params[':user_id'] = $userId;
  }

  try {
    $rows = q($pdo, $sql, $params)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
      $pid = (int)($r['player_id'] ?? 0);
      if ($pid > 0) {
        $lockedPlayerIds[$pid] = true;
      }
    }
  } catch (Throwable $e) {
    // segue normalmente
  }
}

$palPlayers = [];

try {
  $palPlayersSql = "
    SELECT id, name, shirt_number
    FROM players
    WHERE club_name = :club COLLATE NOCASE
  ";
  $palPlayersParams = [':club' => $club];

  if (has_user_id_col($pdo, 'players')) {
    $palPlayersSql .= " AND user_id = :user_id ";
    $palPlayersParams[':user_id'] = $userId;
  }

  if ($lockedPlayerIds) {
    $idList = array_values(array_map('intval', array_keys($lockedPlayerIds)));
    $placeholders = [];
    foreach ($idList as $idx => $pid) {
      $ph = ':keep_' . $idx;
      $placeholders[] = $ph;
      $palPlayersParams[$ph] = $pid;
    }

    $palPlayersSql .= " AND (is_active = 1 OR id IN (" . implode(',', $placeholders) . ")) ";
  } else {
    $palPlayersSql .= " AND is_active = 1 ";
  }

  $palPlayersSql .= " ORDER BY name ";
  $palPlayers = q($pdo, $palPlayersSql, $palPlayersParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $palPlayers = [];
}

/* =========================================================
   Templates
   ========================================================= */

function detect_templates_schema(PDO $pdo): array {
  $tplTable = null;
  if (table_exists($pdo, 'lineup_templates')) $tplTable = 'lineup_templates';
  elseif (table_exists($pdo, 'templates'))    $tplTable = 'templates';

  $slotTable = null;
  if (table_exists($pdo, 'lineup_template_slots')) $slotTable = 'lineup_template_slots';
  elseif (table_exists($pdo, 'template_players'))  $slotTable = 'template_players';

  $nameCol = null;
  $clubCol = null;

  if ($tplTable) {
    $info    = table_info($pdo, $tplTable);
    $nameCol = pick_col($info, ['template_name','name','title']);
    $clubCol = pick_col($info, ['club_name','club']);
  }

  return [
    'tplTable'  => $tplTable,
    'slotTable' => $slotTable,
    'nameCol'   => $nameCol,
    'clubCol'   => $clubCol,
  ];
}

$tplSchema = detect_templates_schema($pdo);
$templates = [];

try {
  if ($tplSchema['tplTable'] && $tplSchema['nameCol']) {
    $tTable = $tplSchema['tplTable'];
    $nCol   = $tplSchema['nameCol'];
    $cCol   = $tplSchema['clubCol'];

    $sql = "SELECT id, $nCol AS template_name FROM $tTable WHERE 1=1 ";
    $params = [];

    if ($cCol) {
      $sql .= " AND $cCol = :club COLLATE NOCASE ";
      $params[':club'] = $club;
    }
    if (has_user_id_col($pdo, $tTable)) {
      $sql .= " AND user_id = :user_id ";
      $params[':user_id'] = $userId;
    }

    $sql .= " ORDER BY $nCol ASC ";
    $templates = q($pdo, $sql, $params)->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (Throwable $e) {
  $templates = [];
}

/* =========================================================
   UI tabela
   ========================================================= */

function render_table_create(
  bool $isPal,
  string $role,
  int $maxRows,
  array $positions,
  array $palPlayers,
  string $mvpSelected
): void {
  $label = ($role === 'starter') ? 'Titulares (11)' : 'Reservas (9)';

  echo '<h6 class="mt-3">'.h($label).'</h6>';
  echo '<div class="table-responsive">';
  echo '<table class="table table-sm align-middle mb-0">';
  echo '<thead><tr class="text-center">
    <th style="width:80px;">POS</th>
    <th>Atleta</th>
    <th style="width:55px;">G</th>
    <th style="width:55px;">A</th>
    <th style="width:60px;">GC</th>
    <th style="width:60px;">CA</th>
    <th style="width:60px;">CV</th>
    <th style="width:70px;">Nota</th>
    <th style="width:60px;">MVP</th>
  </tr></thead><tbody>';

  for ($i=0; $i<$maxRows; $i++) {
    if ($isPal) {
      $pidKey = "pal_pid_{$role}_{$i}";
      $posKey = "pal_pos_{$role}_{$i}";
      $gKey   = "pal_g_{$role}_{$i}";
      $aKey   = "pal_a_{$role}_{$i}";
      $gcKey  = "pal_og_{$role}_{$i}";
      $caKey  = "pal_y_{$role}_{$i}";
      $cvKey  = "pal_r_{$role}_{$i}";
      $rtKey  = "pal_rating_{$role}_{$i}";
      $mvpVal = "pal_{$role}_{$i}";
    } else {
      $pidKey = "opp_name_{$role}_{$i}";
      $posKey = "opp_pos_{$role}_{$i}";
      $gKey   = "opp_g_{$role}_{$i}";
      $aKey   = "opp_a_{$role}_{$i}";
      $gcKey  = "opp_og_{$role}_{$i}";
      $caKey  = "opp_y_{$role}_{$i}";
      $cvKey  = "opp_r_{$role}_{$i}";
      $rtKey  = "opp_rating_{$role}_{$i}";
      $mvpVal = "opp_{$role}_{$i}";
    }

    $checkedMvp = ($mvpSelected !== '' && $mvpSelected === $mvpVal) ? 'checked' : '';
    $small = 'class="form-control form-control-sm text-center px-1" style="max-width:70px;margin:0 auto;"';

    echo '<tr class="text-center">';
    echo '<td>
      <select class="form-select form-select-sm text-center px-1" name="'.h($posKey).'">
        <option value=""></option>'.select_options($positions, postv($posKey)).'
      </select>
    </td>';

    echo '<td class="text-start">';
    if ($isPal) {
      $cur = (int)postv($pidKey, '0');
      echo '<select class="form-select form-select-sm w-100" name="'.h($pidKey).'">';
      echo '<option value="0"></option>';
      foreach ($palPlayers as $p) {
        $label2 = trim((string)$p['shirt_number']) !== '' ? '#'.$p['shirt_number'].' - '.$p['name'] : $p['name'];
        $sel = ((int)$p['id'] === $cur) ? ' selected' : '';
        echo '<option value="'.h((string)$p['id']).'"'.$sel.'>'.h($label2).'</option>';
      }
      echo '</select>';
    } else {
      echo '<input class="form-control form-control-sm" name="'.h($pidKey).'" value="'.h(postv($pidKey)).'" placeholder="Nome">';
    }
    echo '</td>';

    echo '<td><input '.$small.' type="number" min="0" name="'.h($gKey).'" value="'.h(postv($gKey)).'"></td>';
    echo '<td><input '.$small.' type="number" min="0" name="'.h($aKey).'" value="'.h(postv($aKey)).'"></td>';
    echo '<td><input '.$small.' type="number" min="0" name="'.h($gcKey).'" value="'.h(postv($gcKey)).'"></td>';
    echo '<td><input '.$small.' type="number" min="0" name="'.h($caKey).'" value="'.h(postv($caKey)).'"></td>';
    echo '<td><input '.$small.' type="number" min="0" name="'.h($cvKey).'" value="'.h(postv($cvKey)).'"></td>';
    echo '<td><input '.$small.' type="text" name="'.h($rtKey).'" value="'.h(postv($rtKey)).'"></td>';
    echo '<td><input type="radio" name="mvp" value="'.h($mvpVal).'" '.$checkedMvp.'></td>';
    echo '</tr>';
  }

  echo '</tbody></table></div>';
}

/* =========================================================
   Substituições
   ========================================================= */

function ensure_match_substitutions_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS match_substitutions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER,
      match_id INTEGER NOT NULL,
      side TEXT NOT NULL,
      minute INTEGER NULL,
      player_out_id INTEGER NULL,
      player_in_id  INTEGER NULL,
      opponent_out_id INTEGER NULL,
      opponent_in_id  INTEGER NULL,
      sort_order INTEGER NOT NULL DEFAULT 0
    )
  ");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_subs_match ON match_substitutions(match_id)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_subs_side  ON match_substitutions(match_id, side)");
}

function render_subs_block(string $prefix, string $title): void {
  echo '<h6 class="mt-3">'.h($title).'</h6>';
  echo '<div class="table-responsive">';
  echo '<table class="table table-sm align-middle mb-0">';
  echo '<thead><tr class="text-center">
    <th style="width:90px;">MIN</th>
    <th>SAI</th>
    <th>ENTRA</th>
  </tr></thead><tbody>';

  for ($i=0; $i<5; $i++) {
    $kMin = $prefix.'_sub_min_'.$i;
    $kOut = $prefix.'_sub_out_'.$i;
    $kIn  = $prefix.'_sub_in_'.$i;

    echo '<tr class="text-center">';
    echo '<td><input type="number" min="0" max="130" step="1" class="form-control form-control-sm text-center px-1" style="max-width:90px;margin:0 auto;" name="'.h($kMin).'" value="'.h(postv($kMin)).'"></td>';
    echo '<td><select class="form-select form-select-sm w-100 sub-out" data-prefix="'.h($prefix).'" data-selected="'.h(postv($kOut)).'" name="'.h($kOut).'"><option value=""></option></select></td>';
    echo '<td><select class="form-select form-select-sm w-100 sub-in"  data-prefix="'.h($prefix).'" data-selected="'.h(postv($kIn)).'" name="'.h($kIn).'"><option value=""></option></select></td>';
    echo '</tr>';
  }

  echo '</tbody></table></div>';
}

/* =========================================================
   POST
   ========================================================= */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action = postv('action');

  if ($action === 'apply_template') {
    $tplId = to_int(postv('pal_template_id'), 0);
    if ($tplId <= 0) redirect(current_match_form_url('err=tpl'));

    try {
      $tplSchema = detect_templates_schema($pdo);
      if (!$tplSchema['slotTable']) redirect(current_match_form_url('err=tpl_slots'));
      $slotTable = $tplSchema['slotTable'];

      $sql = "
        SELECT role, sort_order, player_id, position
        FROM $slotTable
        WHERE template_id = :template_id
      ";
      $params = [':template_id' => $tplId];
      if (has_user_id_col($pdo, $slotTable)) {
        $sql .= " AND user_id = :user_id ";
        $params[':user_id'] = $userId;
      }
      $sql .= " ORDER BY role ASC, sort_order ASC ";

      $rows = q($pdo, $sql, $params)->fetchAll(PDO::FETCH_ASSOC) ?: [];

      for ($i=0;$i<$MAX_STARTERS;$i++){ $_POST["pal_pid_starter_$i"]='0'; $_POST["pal_pos_starter_$i"]=''; }
      for ($i=0;$i<$MAX_BENCH;$i++){ $_POST["pal_pid_bench_$i"]='0'; $_POST["pal_pos_bench_$i"]=''; }

      foreach ($rows as $r) {
        $role = strtoupper(trim((string)($r['role'] ?? '')));
        $i    = (int)($r['sort_order'] ?? -1);
        $pid  = (int)($r['player_id'] ?? 0);
        $pos  = trim((string)($r['position'] ?? ''));

        if ($role === 'STARTER' && $i>=0 && $i<$MAX_STARTERS) {
          $_POST["pal_pid_starter_$i"] = (string)$pid;
          $_POST["pal_pos_starter_$i"] = $pos;
        }
        if ($role === 'BENCH' && $i>=0 && $i<$MAX_BENCH) {
          $_POST["pal_pid_bench_$i"] = (string)$pid;
          $_POST["pal_pos_bench_$i"] = $pos;
        }
      }

      $_POST['pal_template_id'] = (string)$tplId;
      $msg = 'tpl';
      goto RENDER_PAGE;
    } catch (Throwable $e) {
      pm_log('ERROR', 'apply_template FAIL: '.$e->getMessage());
      redirect(current_match_form_url('err=tpl'));
    }
  }

  $season      = postv('season');
  $competition = postv('competition');
  $date        = postv('match_date');
  $match_time  = postv('match_time');
  $phase       = postv('phase');
  $round       = postv('round');
  $stadium     = postv('stadium');
  $referee     = postv('referee');
  $kit_used    = postv('kit_used');
  $weather     = postv('weather');

  $home        = postv('home');
  $away        = postv('away');

  $home_score_raw = postv('home_score');
  $away_score_raw = postv('away_score');

  $isHomePal = (strcasecmp($home, $club) === 0);
  $isAwayPal = (strcasecmp($away, $club) === 0);
  if (!$isHomePal && !$isAwayPal) redirect(current_match_form_url('err=palmeiras_only'));

  $oppClub = $isHomePal ? $away : $home;

  $palType = $isHomePal ? 'HOME' : 'AWAY';
  $oppType = $isHomePal ? 'AWAY' : 'HOME';

  $mvpSelected = postv('mvp');

  $palRows = [];
  $oppRows = [];

  for ($i=0;$i<$MAX_STARTERS;$i++) {
    $pid = to_int(postv("pal_pid_starter_$i"), 0);
    $pos = postv("pal_pos_starter_$i");
    $g   = to_int(postv("pal_g_starter_$i"), 0);
    $a   = to_int(postv("pal_a_starter_$i"), 0);
    $gc  = to_int(postv("pal_og_starter_$i"), 0);
    $ca  = to_int(postv("pal_y_starter_$i"), 0);
    $cv  = to_int(postv("pal_r_starter_$i"), 0);
    $rt  = to_float(postv("pal_rating_starter_$i"), 0.0);

    if ($pid>0 && $pos!=='') {
      $palRows[] = [
        'role'=>'STARTER','sort_order'=>$i,'player_id'=>$pid,'position'=>$pos,
        'goals_for'=>$g,'assists'=>$a,'goals_against'=>$gc,'yellow_cards'=>$ca,'red_cards'=>$cv,'rating'=>$rt,
        'is_mvp'=>($mvpSelected==="pal_starter_$i")?1:0
      ];
    }
  }

  for ($i=0;$i<$MAX_BENCH;$i++) {
    $pid = to_int(postv("pal_pid_bench_$i"), 0);
    $pos = postv("pal_pos_bench_$i");
    $g   = to_int(postv("pal_g_bench_$i"), 0);
    $a   = to_int(postv("pal_a_bench_$i"), 0);
    $gc  = to_int(postv("pal_og_bench_$i"), 0);
    $ca  = to_int(postv("pal_y_bench_$i"), 0);
    $cv  = to_int(postv("pal_r_bench_$i"), 0);
    $rt  = to_float(postv("pal_rating_bench_$i"), 0.0);

    if ($pid>0 && $pos!=='') {
      $palRows[] = [
        'role'=>'BENCH','sort_order'=>$i,'player_id'=>$pid,'position'=>$pos,
        'goals_for'=>$g,'assists'=>$a,'goals_against'=>$gc,'yellow_cards'=>$ca,'red_cards'=>$cv,'rating'=>$rt,
        'is_mvp'=>($mvpSelected==="pal_bench_$i")?1:0
      ];
    }
  }

  for ($i=0;$i<$MAX_STARTERS;$i++) {
    $name = postv("opp_name_starter_$i");
    $pos  = postv("opp_pos_starter_$i");
    $g    = to_int(postv("opp_g_starter_$i"), 0);
    $a    = to_int(postv("opp_a_starter_$i"), 0);
    $gc   = to_int(postv("opp_og_starter_$i"), 0);
    $ca   = to_int(postv("opp_y_starter_$i"), 0);
    $cv   = to_int(postv("opp_r_starter_$i"), 0);
    $rt   = to_float(postv("opp_rating_starter_$i"), 0.0);

    if ($name!=='' && $pos!=='') {
      $oppRows[] = [
        'role'=>'STARTER','sort_order'=>$i,'name'=>$name,'position'=>$pos,
        'goals_for'=>$g,'assists'=>$a,'goals_against'=>$gc,'yellow_cards'=>$ca,'red_cards'=>$cv,'rating'=>$rt,
        'is_mvp'=>($mvpSelected==="opp_starter_$i")?1:0
      ];
    }
  }

  for ($i=0;$i<$MAX_BENCH;$i++) {
    $name = postv("opp_name_bench_$i");
    $pos  = postv("opp_pos_bench_$i");
    $g    = to_int(postv("opp_g_bench_$i"), 0);
    $a    = to_int(postv("opp_a_bench_$i"), 0);
    $gc   = to_int(postv("opp_og_bench_$i"), 0);
    $ca   = to_int(postv("opp_y_bench_$i"), 0);
    $cv   = to_int(postv("opp_r_bench_$i"), 0);
    $rt   = to_float(postv("opp_rating_bench_$i"), 0.0);

    if ($name!=='' && $pos!=='') {
      $oppRows[] = [
        'role'=>'BENCH','sort_order'=>$i,'name'=>$name,'position'=>$pos,
        'goals_for'=>$g,'assists'=>$a,'goals_against'=>$gc,'yellow_cards'=>$ca,'red_cards'=>$cv,'rating'=>$rt,
        'is_mvp'=>($mvpSelected==="opp_bench_$i")?1:0
      ];
    }
  }

  if (count($palRows) < 1 || count($oppRows) < 1) redirect(current_match_form_url('err=roster_required'));

  $seen = [];
  foreach ($palRows as $r) {
    $pid = (int)$r['player_id'];
    if (isset($seen[$pid])) redirect(current_match_form_url('err=dup_player'));
    $seen[$pid] = true;
  }

  $subsPal = [];
  $subsOpp = [];

  $palStarters = [];
  $palBench = [];
  for ($i=0;$i<$MAX_STARTERS;$i++) {
    $pid = to_int(postv("pal_pid_starter_$i"), 0);
    if ($pid > 0) $palStarters[$pid] = true;
  }
  for ($i=0;$i<$MAX_BENCH;$i++) {
    $pid = to_int(postv("pal_pid_bench_$i"), 0);
    if ($pid > 0) $palBench[$pid] = true;
  }

  for ($i=0; $i<5; $i++) {
    $minRaw = trim(postv("pal_sub_min_$i"));
    $outId  = to_int(postv("pal_sub_out_$i"), 0);
    $inId   = to_int(postv("pal_sub_in_$i"), 0);

    if ($minRaw === '' && $outId === 0 && $inId === 0) continue;
    if ($outId === 0 || $inId === 0) redirect(current_match_form_url('err=subs_incomplete'));
    if ($outId === $inId) redirect(current_match_form_url('err=subs_same'));
    if (!isset($palStarters[$outId])) redirect(current_match_form_url('err=subs_out_not_starter'));
    if (!isset($palBench[$inId])) redirect(current_match_form_url('err=subs_in_not_bench'));

    $subsPal[] = ['minute' => ($minRaw === '' ? null : (int)$minRaw), 'out' => $outId, 'in' => $inId, 'sort' => $i];
  }

  $oppStarters = [];
  $oppBench = [];
  for ($i=0;$i<$MAX_STARTERS;$i++) {
    $nm = trim(postv("opp_name_starter_$i"));
    if ($nm !== '') $oppStarters[strtolower($nm)] = $nm;
  }
  for ($i=0;$i<$MAX_BENCH;$i++) {
    $nm = trim(postv("opp_name_bench_$i"));
    if ($nm !== '') $oppBench[strtolower($nm)] = $nm;
  }

  for ($i=0; $i<5; $i++) {
    $minRaw = trim(postv("opp_sub_min_$i"));
    $outNm  = trim(postv("opp_sub_out_$i"));
    $inNm   = trim(postv("opp_sub_in_$i"));

    if ($minRaw === '' && $outNm === '' && $inNm === '') continue;
    if ($outNm === '' || $inNm === '') redirect(current_match_form_url('err=subs_incomplete'));
    if (strcasecmp($outNm, $inNm) === 0) redirect(current_match_form_url('err=subs_same'));

    $kOut = strtolower($outNm);
    $kIn  = strtolower($inNm);
    if (!isset($oppStarters[$kOut])) redirect(current_match_form_url('err=subs_out_not_starter'));
    if (!isset($oppBench[$kIn])) redirect(current_match_form_url('err=subs_in_not_bench'));

    $subsOpp[] = [
      'minute' => ($minRaw === '' ? null : (int)$minRaw),
      'out_name' => $oppStarters[$kOut],
      'in_name'  => $oppBench[$kIn],
      'sort' => $i,
    ];
  }

  $matchesInfo = table_info($pdo, 'matches');
  $hasPalStats = table_exists($pdo, 'match_player_stats');
  $palStatsInfo = $hasPalStats ? table_info($pdo, 'match_player_stats') : [];
  $hasOppStats = table_exists($pdo, 'opponent_match_player_stats');
  $oppStatsInfo = $hasOppStats ? table_info($pdo, 'opponent_match_player_stats') : [];

  $colHome = pick_col($matchesInfo, ['home','home_team']);
  $colAway = pick_col($matchesInfo, ['away','away_team']);
  $colHomeScore = pick_col($matchesInfo, ['home_score','home_goals']);
  $colAwayScore = pick_col($matchesInfo, ['away_score','away_goals']);

  if (!$colHome || !$colAway) redirect(current_match_form_url('err=exception'));

  try {
    $pdo->beginTransaction();

    $matchData = [];
    add_user_id_if_exists($pdo, 'matches', $matchData, $userId);

    if (isset($matchesInfo['season'])) $matchData['season'] = $season;
    if (isset($matchesInfo['competition'])) $matchData['competition'] = $competition;
    if (isset($matchesInfo['match_date'])) $matchData['match_date'] = $date;
    elseif (isset($matchesInfo['date'])) $matchData['date'] = $date;

    if (isset($matchesInfo['match_time'])) $matchData['match_time'] = $match_time;
    if (isset($matchesInfo['phase'])) $matchData['phase'] = $phase;
    if (isset($matchesInfo['round'])) $matchData['round'] = $round;
    if (isset($matchesInfo['stadium'])) $matchData['stadium'] = $stadium;
    if (isset($matchesInfo['referee'])) $matchData['referee'] = $referee;
    if (isset($matchesInfo['kit_used'])) $matchData['kit_used'] = $kit_used;
    if (isset($matchesInfo['weather'])) $matchData['weather'] = $weather;

    $matchData[$colHome] = $home;
    $matchData[$colAway] = $away;

    if ($colHomeScore) $matchData[$colHomeScore] = ($home_score_raw==='' ? null : (int)$home_score_raw);
    if ($colAwayScore) $matchData[$colAwayScore] = ($away_score_raw==='' ? null : (int)$away_score_raw);

    $now = date('Y-m-d H:i:s');
    $editMatchId = to_int(postv('match_id'), 0);
    $isEdit = ($editMatchId > 0);

    if ($isEdit) {
      $checkSql = "SELECT id FROM matches WHERE id = ?";
      $checkParams = [$editMatchId];
      if (has_user_id_col($pdo, 'matches')) {
        $checkSql .= " AND user_id = ?";
        $checkParams[] = $userId;
      }
      $exists = q($pdo, $checkSql, $checkParams)->fetchColumn();
      if (!$exists) {
        throw new RuntimeException('Partida inválida para edição.');
      }

      if (isset($matchesInfo['updated_at'])) $matchData['updated_at'] = $now;
      update_dynamic(
        $pdo,
        'matches',
        $matchData,
        has_user_id_col($pdo, 'matches') ? 'id = ? AND user_id = ?' : 'id = ?',
        has_user_id_col($pdo, 'matches') ? [$editMatchId, $userId] : [$editMatchId]
      );
      $matchId = $editMatchId;

      $delParams = [$matchId];
      $delSuffix = '';
      if (has_user_id_col($pdo, 'match_players')) { $delSuffix = ' AND user_id = ?'; $delParams[] = $userId; }
      q($pdo, "DELETE FROM match_players WHERE match_id = ?{$delSuffix}", $delParams);

      if (table_exists($pdo, 'match_player_stats')) {
        $params = [$matchId];
        $suffix = has_user_id_col($pdo, 'match_player_stats') ? ' AND user_id = ?' : '';
        if ($suffix !== '') $params[] = $userId;
        q($pdo, "DELETE FROM match_player_stats WHERE match_id = ?{$suffix}", $params);
      }

      if (table_exists($pdo, 'opponent_match_player_stats')) {
        $params = [$matchId];
        $suffix = has_user_id_col($pdo, 'opponent_match_player_stats') ? ' AND user_id = ?' : '';
        if ($suffix !== '') $params[] = $userId;
        q($pdo, "DELETE FROM opponent_match_player_stats WHERE match_id = ?{$suffix}", $params);
      }

      if (table_exists($pdo, 'match_substitutions')) {
        $params = [$matchId];
        $suffix = has_user_id_col($pdo, 'match_substitutions') ? ' AND user_id = ?' : '';
        if ($suffix !== '') $params[] = $userId;
        q($pdo, "DELETE FROM match_substitutions WHERE match_id = ?{$suffix}", $params);
      }
    } else {
      if (isset($matchesInfo['created_at'])) $matchData['created_at'] = $now;
      if (isset($matchesInfo['updated_at'])) $matchData['updated_at'] = $now;
      insert_dynamic($pdo, 'matches', $matchData);
      $matchId = (int)$pdo->lastInsertId();
    }

    $mpInfo = table_info($pdo, 'match_players');
    $oppInfo = table_info($pdo, 'opponent_players');

    foreach ($palRows as $r) {
      $data = [];
      add_user_id_if_exists($pdo, 'match_players', $data, $userId);
      if (isset($mpInfo['match_id'])) $data['match_id'] = $matchId;
      if (isset($mpInfo['club_name'])) $data['club_name'] = $club;
      if (isset($mpInfo['player_id'])) $data['player_id'] = (int)$r['player_id'];
      if (isset($mpInfo['opponent_player_id'])) $data['opponent_player_id'] = null;
      if (isset($mpInfo['role'])) $data['role'] = $r['role'];
      if (isset($mpInfo['position'])) $data['position'] = $r['position'];
      if (isset($mpInfo['sort_order'])) $data['sort_order'] = (int)$r['sort_order'];
      if (isset($mpInfo['entered'])) $data['entered'] = ($r['role']==='STARTER'?1:0);
      if (isset($mpInfo['player_type'])) $data['player_type'] = $palType;
      insert_dynamic($pdo, 'match_players', $data);

      if ($hasPalStats && isset($palStatsInfo['match_id']) && isset($palStatsInfo['player_id'])) {
        $data = [];
        add_user_id_if_exists($pdo, 'match_player_stats', $data, $userId);
        $data['match_id']  = $matchId;
        $data['player_id'] = (int)$r['player_id'];
        if (isset($palStatsInfo['club_name'])) $data['club_name'] = $club;

        $gf = (int)($r['goals_for'] ?? 0);
        $ga = (int)($r['goals_against'] ?? 0);
        $as = (int)($r['assists'] ?? 0);
        $yc = (int)($r['yellow_cards'] ?? 0);
        $rc = (int)($r['red_cards'] ?? 0);
        $rt = (float)($r['rating'] ?? 0.0);
        $motm = (int)($r['is_mvp'] ?? 0);

        if (isset($palStatsInfo['goals_for']))     $data['goals_for'] = $gf;
        if (isset($palStatsInfo['goals_against'])) $data['goals_against'] = $ga;
        if (isset($palStatsInfo['assists']))       $data['assists'] = $as;
        if (isset($palStatsInfo['yellow_cards']))  $data['yellow_cards'] = $yc;
        if (isset($palStatsInfo['red_cards']))     $data['red_cards'] = $rc;
        if (isset($palStatsInfo['rating']))        $data['rating'] = $rt;
        if (isset($palStatsInfo['motm']))          $data['motm'] = $motm;
        if (isset($palStatsInfo['is_mvp']))        $data['is_mvp'] = $motm;

        foreach ($palStatsInfo as $col => $meta) {
          if ($col === 'id') continue;
          if ((int)$meta['notnull'] === 1 && !array_key_exists($col, $data)) {
            if ($meta['dflt'] !== null) continue;
            $t = strtoupper((string)$meta['type']);
            $data[$col] = str_contains($t, 'INT') ? 0 : '';
          }
        }

        insert_dynamic($pdo, 'match_player_stats', $data);
      }
    }

    foreach ($oppRows as $r) {
      $name = trim((string)$r['name']);
      if ($name === '') continue;

      $sql = "SELECT id FROM opponent_players WHERE club_name = ? COLLATE NOCASE AND name = ? COLLATE NOCASE";
      $params = [$oppClub, $name];
      if (has_user_id_col($pdo, 'opponent_players')) {
        $sql .= " AND user_id = ?";
        $params[] = $userId;
      }
      $sql .= " LIMIT 1";
      $oppId = (int)(q($pdo, $sql, $params)->fetchColumn() ?: 0);

      if ($oppId <= 0) {
        $data = [];
        add_user_id_if_exists($pdo, 'opponent_players', $data, $userId);
        if (isset($oppInfo['club_name'])) $data['club_name'] = $oppClub;
        if (isset($oppInfo['name'])) $data['name'] = $name;
        if (isset($oppInfo['is_active'])) $data['is_active'] = 1;
        if (isset($oppInfo['primary_position'])) $data['primary_position'] = $r['position'];
        insert_dynamic($pdo, 'opponent_players', $data);
        $oppId = (int)$pdo->lastInsertId();
      }

      $data = [];
      add_user_id_if_exists($pdo, 'match_players', $data, $userId);
      if (isset($mpInfo['match_id'])) $data['match_id'] = $matchId;
      if (isset($mpInfo['club_name'])) $data['club_name'] = $oppClub;
      if (isset($mpInfo['player_id'])) $data['player_id'] = null;
      if (isset($mpInfo['opponent_player_id'])) $data['opponent_player_id'] = $oppId;
      if (isset($mpInfo['role'])) $data['role'] = $r['role'];
      if (isset($mpInfo['position'])) $data['position'] = $r['position'];
      if (isset($mpInfo['sort_order'])) $data['sort_order'] = (int)$r['sort_order'];
      if (isset($mpInfo['entered'])) $data['entered'] = ($r['role']==='STARTER'?1:0);
      if (isset($mpInfo['player_type'])) $data['player_type'] = $oppType;
      insert_dynamic($pdo, 'match_players', $data);

      if ($hasOppStats && isset($oppStatsInfo['match_id']) && isset($oppStatsInfo['opponent_player_id'])) {
        $data = [];
        add_user_id_if_exists($pdo, 'opponent_match_player_stats', $data, $userId);
        $data['match_id'] = $matchId;
        $data['opponent_player_id'] = $oppId;
        if (isset($oppStatsInfo['club_name'])) $data['club_name'] = $oppClub;

        $gf = (int)($r['goals_for'] ?? 0);
        $ga = (int)($r['goals_against'] ?? 0);
        $as = (int)($r['assists'] ?? 0);
        $yc = (int)($r['yellow_cards'] ?? 0);
        $rc = (int)($r['red_cards'] ?? 0);
        $rt = (float)($r['rating'] ?? 0.0);
        $motm = (int)($r['is_mvp'] ?? 0);

        if (isset($oppStatsInfo['goals_for']))     $data['goals_for'] = $gf;
        if (isset($oppStatsInfo['goals_against'])) $data['goals_against'] = $ga;
        if (isset($oppStatsInfo['assists']))       $data['assists'] = $as;
        if (isset($oppStatsInfo['yellow_cards']))  $data['yellow_cards'] = $yc;
        if (isset($oppStatsInfo['red_cards']))     $data['red_cards'] = $rc;
        if (isset($oppStatsInfo['rating']))        $data['rating'] = $rt;
        if (isset($oppStatsInfo['motm']))          $data['motm'] = $motm;
        if (isset($oppStatsInfo['is_mvp']))        $data['is_mvp'] = $motm;
        if (isset($oppStatsInfo['role']))          $data['role'] = $r['role'];
        if (isset($oppStatsInfo['position']))      $data['position'] = $r['position'];
        if (isset($oppStatsInfo['sort_order']))    $data['sort_order'] = (int)$r['sort_order'];
        if (isset($oppStatsInfo['entered']))       $data['entered'] = 1;
        if (isset($oppStatsInfo['player_name']))   $data['player_name'] = $name;
        if (isset($oppStatsInfo['name']))          $data['name'] = $name;

        foreach ($oppStatsInfo as $col => $meta) {
          if ($col === 'id') continue;
          if ((int)$meta['notnull'] === 1 && !array_key_exists($col, $data)) {
            if ($meta['dflt'] !== null) continue;
            $t = strtoupper((string)$meta['type']);
            $data[$col] = str_contains($t, 'INT') ? 0 : '';
          }
        }

        insert_dynamic($pdo, 'opponent_match_player_stats', $data);
      }
    }

    ensure_match_substitutions_table($pdo);
    $params = [$matchId];
    $suffix = has_user_id_col($pdo, 'match_substitutions') ? ' AND user_id = ?' : '';
    if ($suffix !== '') $params[] = $userId;
    q($pdo, "DELETE FROM match_substitutions WHERE match_id = ?{$suffix}", $params);

    foreach ($subsPal as $s) {
      $data = [];
      add_user_id_if_exists($pdo, 'match_substitutions', $data, $userId);
      $data['match_id'] = $matchId;
      $data['side'] = $palType;
      $data['minute'] = $s['minute'];
      $data['player_out_id'] = (int)$s['out'];
      $data['player_in_id'] = (int)$s['in'];
      $data['opponent_out_id'] = null;
      $data['opponent_in_id'] = null;
      $data['sort_order'] = (int)$s['sort'];
      insert_dynamic($pdo, 'match_substitutions', $data);
    }

    $oppIdByName = [];
    $oppRowsAllSql = "SELECT id, name FROM opponent_players WHERE club_name=? COLLATE NOCASE";
    $oppRowsAllParams = [$oppClub];
    if (has_user_id_col($pdo, 'opponent_players')) {
      $oppRowsAllSql .= " AND user_id = ?";
      $oppRowsAllParams[] = $userId;
    }
    $oppRowsAll = q($pdo, $oppRowsAllSql, $oppRowsAllParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($oppRowsAll as $o) {
      $nm = strtolower(trim((string)($o['name'] ?? '')));
      if ($nm !== '') $oppIdByName[$nm] = (int)($o['id'] ?? 0);
    }

    foreach ($subsOpp as $s) {
      $outKey = strtolower(trim((string)$s['out_name']));
      $inKey  = strtolower(trim((string)$s['in_name']));
      $outId  = (int)($oppIdByName[$outKey] ?? 0);
      $inId   = (int)($oppIdByName[$inKey] ?? 0);

      if ($outId <= 0 || $inId <= 0) {
        $sql = "SELECT id FROM opponent_players WHERE club_name=? COLLATE NOCASE AND name=? COLLATE NOCASE";
        $params = [$oppClub, (string)$s['out_name']];
        if (has_user_id_col($pdo, 'opponent_players')) {
          $sql .= " AND user_id=?";
          $params[] = $userId;
        }
        $sql .= " LIMIT 1";
        $outId = (int)(q($pdo, $sql, $params)->fetchColumn() ?: 0);

        $params = [$oppClub, (string)$s['in_name']];
        if (has_user_id_col($pdo, 'opponent_players')) $params[] = $userId;
        $inId = (int)(q($pdo, $sql, $params)->fetchColumn() ?: 0);
      }

      $data = [];
      add_user_id_if_exists($pdo, 'match_substitutions', $data, $userId);
      $data['match_id'] = $matchId;
      $data['side'] = $oppType;
      $data['minute'] = $s['minute'];
      $data['player_out_id'] = null;
      $data['player_in_id'] = null;
      $data['opponent_out_id'] = $outId ?: null;
      $data['opponent_in_id'] = $inId ?: null;
      $data['sort_order'] = (int)$s['sort'];
      insert_dynamic($pdo, 'match_substitutions', $data);
    }

    $pdo->commit();
    redirect('/?page=match&id='.$matchId);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    pm_log('ERROR', 'create_match FAIL: '.$e->getMessage());
    redirect(current_match_form_url('err=exception'));
  }
}

RENDER_PAGE:

render_header('Criar Partida');

if ($err !== '') {
  echo '<div class="alert alert-danger card-soft">'.h($err).'</div>';
}

if ($msg === 'tpl') {
  echo '<div class="alert alert-success card-soft">Template aplicado.</div>';
}

$mvpSelected = postv('mvp');

echo '<form method="post" autocomplete="off">';
echo '<input type="hidden" name="match_id" value="'.h((string)((postv('match_id') !== '') ? postv('match_id') : (string)($_GET['id'] ?? ''))).'">';

echo '<div class="card-soft p-3 mb-3">';
echo '<h5 class="mb-3">Dados do jogo</h5>';

echo '<div class="row g-3">';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Temporada</label>
  <select class="form-select" name="season" required>
    <option value=""></option>'.select_options($seasons, fval('season')).'
  </select>
</div>';

echo '<div class="col-12 col-md-4">
  <label class="form-label">Competição</label>
  <select class="form-select" name="competition" required>
    <option value=""></option>'.select_options($competitions, fval('competition')).'
  </select>
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Data</label>
  <input type="date" class="form-control" name="match_date" value="'.h(fval('match_date')).'" required>
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Hora</label>
  <input type="time" class="form-control" name="match_time" value="'.h(fval('match_time')).'">
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Clima</label>
  <select class="form-select" name="weather">
    <option value=""></option>'.select_options($weathers, fval('weather')).'
  </select>
</div>';

echo '<div class="col-12 col-md-3">
  <label class="form-label">Fase</label>
  <input class="form-control" name="phase" value="'.h(fval('phase')).'">
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Rodada</label>
  <input class="form-control" name="round" value="'.h(fval('round')).'">
</div>';

echo '<div class="col-12 col-md-3">
  <label class="form-label">Estádio</label>
  <input class="form-control" name="stadium" value="'.h(fval('stadium')).'">
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Árbitro</label>
  <input class="form-control" name="referee" value="'.h(fval('referee')).'">
</div>';

echo '<div class="col-12 col-md-2">
  <label class="form-label">Uniforme</label>
  <select class="form-select" name="kit_used">
    <option value=""></option>'.select_options($kits, fval('kit_used')).'
  </select>
</div>';

echo '<div class="col-12 col-md-3">
  <label class="form-label">Mandante</label>
  <input class="form-control" name="home" value="'.h(fval('home',$club)).'" required>
</div>';

echo '<div class="col-12 col-md-3">
  <label class="form-label">Visitante</label>
  <input class="form-control" name="away" value="'.h(fval('away','ADVERSÁRIO')).'" required>
</div>';

echo '<div class="col-6 col-md-1">
  <label class="form-label">Gols Mandante</label>
  <input class="form-control text-center" type="number" name="home_score" value="'.h(fval('home_score')).'">
</div>';

echo '<div class="col-6 col-md-1">
  <label class="form-label">Gols Visitante</label>
  <input class="form-control text-center" type="number" name="away_score" value="'.h(fval('away_score')).'">
</div>';

echo '</div></div>';

echo '<div class="row g-4">';

echo '<div class="col-12 col-xl-6"><div class="card-soft p-3">';
echo '<h5 class="mb-2">'.h($club).'</h5>';

echo '<div class="d-flex flex-wrap gap-2 align-items-end mb-2">';
echo '<div style="min-width:260px;max-width:420px;flex:1;">';
echo '<label class="form-label mb-1">Template de escalação</label>';
echo '<select class="form-select form-select-sm" name="pal_template_id">';
echo '<option value="0">-- selecionar --</option>';
$curTpl = to_int(postv('pal_template_id','0'), 0);
foreach ($templates as $t) {
  $tid = (int)($t['id'] ?? 0);
  $tn  = (string)($t['template_name'] ?? '');
  if ($tid <= 0) continue;
  $sel = ($tid === $curTpl) ? 'selected' : '';
  echo '<option value="'.$tid.'" '.$sel.'>'.h($tn).'</option>';
}
echo '</select></div>';
echo '<button type="submit" name="action" value="apply_template" formnovalidate class="btn btn-outline-light btn-sm btn-primary">Aplicar</button>';
echo '</div>';

render_table_create(true, 'starter', $MAX_STARTERS, $positions, $palPlayers, $mvpSelected);
render_table_create(true, 'bench',   $MAX_BENCH,    $positions, $palPlayers, $mvpSelected);
render_subs_block('pal', 'Substituições (até 5)');

echo '</div></div>';

$homeVal = fval('home',$club);
$awayVal = fval('away','ADVERSÁRIO');
$oppTitle = (strcasecmp($homeVal, $club) === 0) ? $awayVal : $homeVal;

echo '<div class="col-12 col-xl-6"><div class="card-soft p-3">';
echo '<h5 class="mb-3">'.h($oppTitle).'</h5>';
render_table_create(false, 'starter', $MAX_STARTERS, $positions, $palPlayers, $mvpSelected);
render_table_create(false, 'bench',   $MAX_BENCH,    $positions, $palPlayers, $mvpSelected);
render_subs_block('opp', 'Substituições (até 5)');
echo '</div></div>';

echo '</div>';

echo '<div class="text-end mt-3">
  <button type="submit" class="btn btn-primary">Salvar</button>
</div>';

echo '</form>';

echo '<script>
(function(){
  function clearAndKeepEmpty(sel){
    sel.innerHTML = "";
    var o = document.createElement("option");
    o.value = "";
    o.textContent = "";
    sel.appendChild(o);
  }

  function setOptions(sel, options){
    var cur = sel.getAttribute("data-selected") || sel.value || "";
    clearAndKeepEmpty(sel);
    options.forEach(function(x){
      var o = document.createElement("option");
      o.value = x.value;
      o.textContent = x.label;
      sel.appendChild(o);
    });

    if(cur){
      sel.value = cur;
      if(!sel.value){
        var curL = String(cur).toLowerCase();
        for (var i=0;i<sel.options.length;i++){
          if (String(sel.options[i].value).toLowerCase() === curL) { sel.value = sel.options[i].value; break; }
        }
      }
    }
  }

  function uniqByKey(list, keyFn){
    var seen = {};
    var out = [];
    list.forEach(function(x){
      var k = keyFn(x);
      if(seen[k]) return;
      seen[k] = true;
      out.push(x);
    });
    return out;
  }

  function buildFromSelect(names){
    var out = [];
    names.forEach(function(n){
      var s = document.querySelector("select[name=\\"" + n + "\\"]");
      if(!s) return;
      var v = s.value;
      if(!v || v === "0") return;
      var label = (s.options[s.selectedIndex] ? s.options[s.selectedIndex].text : "").trim();
      if(!label) return;
      out.push({value: v, label: label});
    });
    return uniqByKey(out, function(x){ return String(x.value); });
  }

  function buildFromInputs(names){
    var out = [];
    names.forEach(function(n){
      var i = document.querySelector("input[name=\\"" + n + "\\"]");
      if(!i) return;
      var v = (i.value || "").trim();
      if(!v) return;
      out.push({value: v, label: v});
    });
    return uniqByKey(out, function(x){ return String(x.value).toLowerCase(); });
  }

  function refresh(prefix){
    var outOpts = [];
    var inOpts  = [];

    if(prefix === "pal"){
      var starters = [];
      var bench = [];
      for (var i=0;i<11;i++) starters.push("pal_pid_starter_" + i);
      for (var j=0;j<9;j++)  bench.push("pal_pid_bench_" + j);
      outOpts = buildFromSelect(starters);
      inOpts  = buildFromSelect(bench);
    } else {
      var starters2 = [];
      var bench2 = [];
      for (var i2=0;i2<11;i2++) starters2.push("opp_name_starter_" + i2);
      for (var j2=0;j2<9;j2++)  bench2.push("opp_name_bench_" + j2);
      outOpts = buildFromInputs(starters2);
      inOpts  = buildFromInputs(bench2);
    }

    document.querySelectorAll("select.sub-out[data-prefix=\\"" + prefix + "\\"]").forEach(function(sel){
      setOptions(sel, outOpts);
    });
    document.querySelectorAll("select.sub-in[data-prefix=\\"" + prefix + "\\"]").forEach(function(sel){
      setOptions(sel, inOpts);
    });
  }

  function bind(){
    for (var i=0;i<11;i++){
      var s = document.querySelector("select[name=\\"pal_pid_starter_" + i + "\\"]");
      if(s) s.addEventListener("change", function(){ refresh("pal"); });
    }
    for (var j=0;j<9;j++){
      var b = document.querySelector("select[name=\\"pal_pid_bench_" + j + "\\"]");
      if(b) b.addEventListener("change", function(){ refresh("pal"); });
    }

    for (var i2=0;i2<11;i2++){
      var is = document.querySelector("input[name=\\"opp_name_starter_" + i2 + "\\"]");
      if(is) is.addEventListener("input", function(){ refresh("opp"); });
    }
    for (var j2=0;j2<9;j2++){
      var ib = document.querySelector("input[name=\\"opp_name_bench_" + j2 + "\\"]");
      if(ib) ib.addEventListener("input", function(){ refresh("opp"); });
    }
  }

  refresh("pal");
  refresh("opp");
  bind();
})();
</script>';

render_footer();