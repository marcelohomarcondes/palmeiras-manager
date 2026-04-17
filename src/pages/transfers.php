<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo    = db();
$userId = require_user_id();
$club   = (string) app_club();

/**
 * Regras aplicadas nesta versão:
 * - Passa a existir:
 *   - negotiation_date = data de negociação
 *   - effective_date   = data efetiva
 *     * chegada  -> data de apresentação
 *     * saída    -> data de liberação
 * - transaction_date permanece por compatibilidade e recebe negotiation_date
 * - Ativação/inativação automática do elenco só ocorre quando:
 *   negotiation_date === effective_date
 *   (ex.: promovido da base / chegada imediata)
 */

$SEASONS = range(2026, 2040);

$TYPE_OPTIONS = [
  'PROMOVIDO DA BASE',
  'CONTRATADO (DEFINITIVO)',
  'VENDIDO',
  'CHEGOU POR EMPRÉSTIMO',
  'FIM DE EMPRÉSTIMO (RETORNO AO CLUBE PROPRIETÁRIO)',
  'SAIU POR EMPRÉSTIMO',
  'VOLTOU DE EMPRÉSTIMO',
  'TROCA',
  'APOSENTADORIA',
];

$ARRIVAL_TYPES = [
  'PROMOVIDO DA BASE',
  'CONTRATADO (DEFINITIVO)',
  'CHEGOU POR EMPRÉSTIMO',
  'VOLTOU DE EMPRÉSTIMO',
];

$DEPARTURE_TYPES = [
  'VENDIDO',
  'SAIU POR EMPRÉSTIMO',
  'FIM DE EMPRÉSTIMO (RETORNO AO CLUBE PROPRIETÁRIO)',
  'APOSENTADORIA',
];

$ACTIVATE_TYPES = [
  'PROMOVIDO DA BASE',
  'CONTRATADO (DEFINITIVO)',
  'CHEGOU POR EMPRÉSTIMO',
  'VOLTOU DE EMPRÉSTIMO',
];

$DEACTIVATE_TYPES = [
  'VENDIDO',
  'SAIU POR EMPRÉSTIMO',
  'FIM DE EMPRÉSTIMO (RETORNO AO CLUBE PROPRIETÁRIO)',
  'APOSENTADORIA',
];

if (!function_exists('table_columns')) {
  function table_columns(PDO $pdo, string $table): array
  {
    $cols = [];
    $st = $pdo->query("PRAGMA table_info($table)");
    foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
      $cols[] = (string)($r['name'] ?? '');
    }
    return $cols;
  }
}

if (!function_exists('table_has_column')) {
  function table_has_column(PDO $pdo, string $table, string $column): bool
  {
    return in_array($column, table_columns($pdo, $table), true);
  }
}

function ensure_transfer_columns(PDO $pdo): void
{
  $cols = table_columns($pdo, 'transfers');

  if (!in_array('negotiation_date', $cols, true)) {
    $pdo->exec("ALTER TABLE transfers ADD COLUMN negotiation_date TEXT");
  }

  if (!in_array('effective_date', $cols, true)) {
    $pdo->exec("ALTER TABLE transfers ADD COLUMN effective_date TEXT");
  }

  // Backfill do legado
  if (in_array('transaction_date', $cols, true)) {
    $pdo->exec("
      UPDATE transfers
         SET negotiation_date = COALESCE(NULLIF(TRIM(negotiation_date), ''), transaction_date)
       WHERE COALESCE(TRIM(negotiation_date), '') = ''
    ");

    $pdo->exec("
      UPDATE transfers
         SET effective_date = COALESCE(NULLIF(TRIM(effective_date), ''), transaction_date)
       WHERE COALESCE(TRIM(effective_date), '') = ''
    ");
  }
}

ensure_transfer_columns($pdo);

function is_arrival_type(string $type): bool
{
  global $ARRIVAL_TYPES;
  return in_array(trim($type), $ARRIVAL_TYPES, true);
}

function is_departure_type(string $type): bool
{
  global $DEPARTURE_TYPES;
  return in_array(trim($type), $DEPARTURE_TYPES, true);
}

function is_promoted(string $type): bool
{
  return trim($type) === 'PROMOVIDO DA BASE';
}

function effective_date_label(string $type): string
{
  $t = trim($type);

  if (is_arrival_type($t)) {
    return 'Data de apresentação';
  }

  if (is_departure_type($t)) {
    return 'Data de liberação';
  }

  return 'Data efetiva';
}

function apply_player_active_from_transfer(PDO $pdo, int $userId, ?int $playerId, string $type): void
{
  global $ACTIVATE_TYPES, $DEACTIVATE_TYPES;

  if ($playerId === null || $playerId <= 0) {
    return;
  }

  $t = trim($type);

  if (in_array($t, $ACTIVATE_TYPES, true)) {
    q(
      $pdo,
      "UPDATE players
          SET is_active = 1,
              updated_at = datetime('now')
        WHERE id = :id
          AND user_id = :user_id",
      [
        ':id'      => $playerId,
        ':user_id' => $userId,
      ]
    );
    return;
  }

  if (in_array($t, $DEACTIVATE_TYPES, true)) {
    q(
      $pdo,
      "UPDATE players
          SET is_active = 0,
              updated_at = datetime('now')
        WHERE id = :id
          AND user_id = :user_id",
      [
        ':id'      => $playerId,
        ':user_id' => $userId,
      ]
    );
  }
}

function apply_player_shirt_from_transfer(PDO $pdo, int $userId, ?int $playerId, string $type, ?int $shirtNumber, string $effectiveDate): void
{
  if ($playerId === null || $playerId <= 0) {
    return;
  }

  if (!is_arrival_type($type)) {
    return;
  }

  if ($shirtNumber === null || $shirtNumber <= 0) {
    return;
  }

  q(
    $pdo,
    "UPDATE players
        SET shirt_number = :shirt_number,
            updated_at = datetime('now')
      WHERE id = :id
        AND user_id = :user_id",
    [
      ':shirt_number' => $shirtNumber,
      ':id'           => $playerId,
      ':user_id'      => $userId,
    ]
  );

  pm_sync_player_shirt_history(
    $pdo,
    $userId,
    $playerId,
    $shirtNumber,
    $effectiveDate,
    'Movimentação registrada em transfers.php'
  );
}

function eur_to_cents(string $raw): ?int
{
  $raw = trim($raw);
  if ($raw === '') return null;

  if (preg_match('/^\d+$/', $raw)) {
    $n = (int)$raw;
    return $n >= 0 ? $n : null;
  }

  $s = str_replace(['R$', ' '], '', $raw);
  $s = str_replace('.', '', $s);
  $s = str_replace(',', '.', $s);

  if (!is_numeric($s)) return null;

  $f = (float)$s;
  $c = (int)round($f * 100);

  return $c >= 0 ? $c : null;
}

function cents_to_eur_label(?int $cents): string
{
  if ($cents === null) return '';
  $v = $cents / 100;
  return 'R$ ' . number_format($v, 2, ',', '.');
}

function fmt_date_br(?string $iso): string
{
  $iso = trim((string)($iso ?? ''));
  if ($iso === '') return '';

  $dt = DateTime::createFromFormat('Y-m-d', $iso);
  if ($dt instanceof DateTime) return $dt->format('d/m/Y');

  $iso10 = substr($iso, 0, 10);
  $dt2 = DateTime::createFromFormat('Y-m-d', $iso10);
  if ($dt2 instanceof DateTime) return $dt2->format('d/m/Y');

  return $iso;
}

function like_escape(string $v): string
{
  return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
}

function build_qs(array $overrides = [], array $remove = []): string
{
  $q = $_GET;
  foreach ($remove as $k) unset($q[$k]);
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  $q['page'] = 'transfers';
  return '/?' . http_build_query($q);
}

function sort_link(string $col, string $label, string $currentSort, string $currentDir): string
{
  $dir = 'ASC';
  if ($currentSort === $col) {
    $dir = ($currentDir === 'ASC') ? 'DESC' : 'ASC';
  }

  $icon = '';
  if ($currentSort === $col) {
    $icon = ($currentDir === 'ASC') ? ' ▲' : ' ▼';
  }

  return '<a href="' . h(build_qs(['sort' => $col, 'dir' => $dir, 'edit' => null])) . '" class="text-decoration-none">' . h($label) . h($icon) . '</a>';
}

/**
 * Atletas do clube do usuário
 */
$players = q(
  $pdo,
  "SELECT id, name, shirt_number, is_active
     FROM players
    WHERE user_id = :user_id
      AND TRIM(club_name) = TRIM(:club) COLLATE NOCASE
 ORDER BY is_active DESC, name ASC",
  [
    ':user_id' => $userId,
    ':club'    => $club,
  ]
)->fetchAll();

$playerOptions = [];
foreach ($players as $p) {
  $pid = (int)$p['id'];
  $nm = (string)$p['name'];
  $sn = $p['shirt_number'] === null ? '' : (string)$p['shirt_number'];
  $active = (int)$p['is_active'] === 1;

  $label = $nm;
  if ($sn !== '') $label .= " (#{$sn})";
  if (!$active) $label .= ' [inativo]';

  $playerOptions[] = [
    'id'    => $pid,
    'name'  => $nm,
    'label' => $label,
  ];
}

/** Edição */
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = $editId > 0;
$editRow = null;

if ($editing) {
  $editRow = q(
    $pdo,
    "SELECT *
       FROM transfers
      WHERE id = :id
        AND user_id = :user_id
      LIMIT 1",
    [
      ':id'      => $editId,
      ':user_id' => $userId,
    ]
  )->fetch();

  if (!$editRow) {
    redirect('/?page=transfers&err=notfound');
  }
}

$err = trim((string)($_GET['err'] ?? ''));
$formErr = '';

$form = [
  'id'                    => $editRow['id'] ?? 0,
  'season'                => $editRow['season'] ?? '',
  'type'                  => $editRow['type'] ?? '',
  'player_id'             => $editRow['player_id'] ?? '',
  'athlete_name'          => $editRow['athlete_name'] ?? '',
  'club_origin'           => $editRow['club_origin'] ?? '',
  'club_destination'      => $editRow['club_destination'] ?? '',
  'value'                 => $editRow['value'] ?? '',
  'term'                  => $editRow['term'] ?? '',
  'grade'                 => $editRow['grade'] ?? '',
  'extra_player_name'     => $editRow['extra_player_name'] ?? '',
  'shirt_number_assigned' => $editRow['shirt_number_assigned'] ?? '',
  'negotiation_date'      => $editRow['negotiation_date'] ?? ($editRow['transaction_date'] ?? ''),
  'effective_date'        => $editRow['effective_date'] ?? ($editRow['transaction_date'] ?? ''),
  'notes'                 => $editRow['notes'] ?? '',
];

/** Salvar / Deletar */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim((string)($_POST['action'] ?? 'save'));
  $id = (int)($_POST['id'] ?? 0);

  if ($action === 'delete') {
    if ($id > 0) {
      q(
        $pdo,
        "DELETE FROM transfers
          WHERE id = :id
            AND user_id = :user_id",
        [
          ':id'      => $id,
          ':user_id' => $userId,
        ]
      );
    }
    redirect('/?page=transfers');
  }

  $season = trim((string)($_POST['season'] ?? ''));
  $type = trim((string)($_POST['type'] ?? ''));
  $playerIdRaw = trim((string)($_POST['player_id'] ?? ''));
  $playerId = ($playerIdRaw === '') ? null : (int)$playerIdRaw;

  $athleteName = trim((string)($_POST['athlete_name'] ?? ''));
  if ($playerId !== null && $playerId > 0) {
    $p = q(
      $pdo,
      "SELECT name
         FROM players
        WHERE id = :id
          AND user_id = :user_id
        LIMIT 1",
      [
        ':id'      => $playerId,
        ':user_id' => $userId,
      ]
    )->fetch();

    if ($p && isset($p['name'])) {
      $athleteName = (string)$p['name'];
    } else {
      $formErr = 'invalid_player';
    }
  }

  $clubOrigin = trim((string)($_POST['club_origin'] ?? ''));
  $clubDestination = trim((string)($_POST['club_destination'] ?? ''));
  $term = trim((string)($_POST['term'] ?? ''));
  $grade = trim((string)($_POST['grade'] ?? ''));
  $extra = trim((string)($_POST['extra_player_name'] ?? ''));
  $shirt = (trim((string)($_POST['shirt_number_assigned'] ?? '')) === '') ? null : (int)$_POST['shirt_number_assigned'];
  $negotiationDate = trim((string)($_POST['negotiation_date'] ?? ''));
  $effectiveDate   = trim((string)($_POST['effective_date'] ?? ''));
  $notes = trim((string)($_POST['notes'] ?? ''));

  $valueCentsRaw = trim((string)($_POST['value_cents'] ?? ''));
  $valueTextRaw  = trim((string)($_POST['value'] ?? ''));
  $valueCents = eur_to_cents($valueCentsRaw !== '' ? $valueCentsRaw : $valueTextRaw);

  if (is_promoted($type)) {
    $clubOrigin = 'CRIA DA ACADEMIA';
  }

  if (is_arrival_type($type) && ($shirt === null || $shirt <= 0)) {
    $formErr = $formErr ?: 'camisa_required';
  }

  if ($season === '' || $type === '' || $athleteName === '' || $negotiationDate === '' || $effectiveDate === '') {
    $formErr = $formErr ?: 'required';
  }

  if ($formErr === '' && !in_array($type, $TYPE_OPTIONS, true)) {
    $formErr = 'invalid_type';
  }

  if ($formErr === '' && !in_array((int)$season, $SEASONS, true)) {
    $formErr = 'invalid_season';
  }

  if ($formErr === '' && $valueTextRaw !== '' && $valueCents === null) {
    $formErr = 'invalid_value';
  }

  if ($formErr === '' && $negotiationDate !== '' && $effectiveDate !== '' && $effectiveDate < $negotiationDate) {
    $formErr = 'invalid_effective_date';
  }

  $form = [
    'id'                    => $id,
    'season'                => $season,
    'type'                  => $type,
    'player_id'             => $playerIdRaw,
    'athlete_name'          => $athleteName,
    'club_origin'           => $clubOrigin,
    'club_destination'      => $clubDestination,
    'value'                 => ($valueCents === null) ? $valueTextRaw : (string)$valueCents,
    'term'                  => $term,
    'grade'                 => $grade,
    'extra_player_name'     => $extra,
    'shirt_number_assigned' => $shirt === null ? '' : (string)$shirt,
    'negotiation_date'      => $negotiationDate,
    'effective_date'        => $effectiveDate,
    'notes'                 => $notes,
  ];

  if ($formErr === '') {
    if ($id > 0) {
      q(
        $pdo,
        "UPDATE transfers
            SET season = :season,
                type = :type,
                player_id = :player_id,
                athlete_name = :athlete_name,
                club_origin = :club_origin,
                club_destination = :club_destination,
                value = :value,
                term = :term,
                grade = :grade,
                extra_player_name = :extra_player_name,
                shirt_number_assigned = :shirt_number_assigned,
                negotiation_date = :negotiation_date,
                effective_date = :effective_date,
                transaction_date = :transaction_date,
                notes = :notes
          WHERE id = :id
            AND user_id = :user_id",
        [
          ':season'                => $season,
          ':type'                  => $type,
          ':player_id'             => $playerId,
          ':athlete_name'          => $athleteName,
          ':club_origin'           => $clubOrigin,
          ':club_destination'      => $clubDestination,
          ':value'                 => $valueCents,
          ':term'                  => $term,
          ':grade'                 => $grade,
          ':extra_player_name'     => $extra,
          ':shirt_number_assigned' => $shirt,
          ':negotiation_date'      => $negotiationDate,
          ':effective_date'        => $effectiveDate,
          ':transaction_date'      => $negotiationDate,
          ':notes'                 => $notes,
          ':id'                    => $id,
          ':user_id'               => $userId,
        ]
      );
    } else {
      q(
        $pdo,
        "INSERT INTO transfers(
            user_id,
            season,
            type,
            player_id,
            athlete_name,
            club_origin,
            club_destination,
            value,
            term,
            grade,
            extra_player_name,
            shirt_number_assigned,
            negotiation_date,
            effective_date,
            transaction_date,
            notes
          ) VALUES (
            :user_id,
            :season,
            :type,
            :player_id,
            :athlete_name,
            :club_origin,
            :club_destination,
            :value,
            :term,
            :grade,
            :extra_player_name,
            :shirt_number_assigned,
            :negotiation_date,
            :effective_date,
            :transaction_date,
            :notes
          )",
        [
          ':user_id'               => $userId,
          ':season'                => $season,
          ':type'                  => $type,
          ':player_id'             => $playerId,
          ':athlete_name'          => $athleteName,
          ':club_origin'           => $clubOrigin,
          ':club_destination'      => $clubDestination,
          ':value'                 => $valueCents,
          ':term'                  => $term,
          ':grade'                 => $grade,
          ':extra_player_name'     => $extra,
          ':shirt_number_assigned' => $shirt,
          ':negotiation_date'      => $negotiationDate,
          ':effective_date'        => $effectiveDate,
          ':transaction_date'      => $negotiationDate,
          ':notes'                 => $notes,
        ]
      );
    }

    // Aplica status automático somente em movimentos imediatos
    if ($negotiationDate !== '' && $effectiveDate !== '' && $negotiationDate === $effectiveDate) {
      apply_player_active_from_transfer($pdo, $userId, $playerId, $type);
      apply_player_shirt_from_transfer($pdo, $userId, $playerId, $type, $shirt, $effectiveDate);
    }

    redirect('/?page=transfers');
  }
}

/** Filtros e ordenação */
$f = [
  'neg_from'  => trim((string)($_GET['f_neg_from'] ?? '')),
  'neg_to'    => trim((string)($_GET['f_neg_to'] ?? '')),
  'eff_from'  => trim((string)($_GET['f_eff_from'] ?? '')),
  'eff_to'    => trim((string)($_GET['f_eff_to'] ?? '')),
  'season'    => trim((string)($_GET['f_season'] ?? '')),
  'type'      => trim((string)($_GET['f_type'] ?? '')),
  'athlete'   => trim((string)($_GET['f_athlete'] ?? '')),
  'origin'    => trim((string)($_GET['f_origin'] ?? '')),
  'dest'      => trim((string)($_GET['f_dest'] ?? '')),
  'value_min' => trim((string)($_GET['f_value_min'] ?? '')),
  'value_max' => trim((string)($_GET['f_value_max'] ?? '')),
];

$valueMinRaw = trim((string)($_GET['f_value_min_cents'] ?? ''));
$valueMaxRaw = trim((string)($_GET['f_value_max_cents'] ?? ''));

$sort = trim((string)($_GET['sort'] ?? 'negotiation_date'));
$dir  = strtoupper(trim((string)($_GET['dir'] ?? 'DESC')));
$dir  = ($dir === 'ASC') ? 'ASC' : 'DESC';

$SORT_MAP = [
  'negotiation_date' => 'negotiation_date',
  'effective_date'   => 'effective_date',
  'season'           => 'season',
  'type'             => 'type',
  'athlete_name'     => 'athlete_name',
  'club_origin'      => 'club_origin',
  'club_destination' => 'club_destination',
  'value'            => 'CAST(value AS INTEGER)',
];

if (!array_key_exists($sort, $SORT_MAP)) {
  $sort = 'negotiation_date';
}

$where = ['user_id = ?'];
$params = [$userId];

if ($f['neg_from'] !== '') {
  $where[] = "negotiation_date >= ?";
  $params[] = $f['neg_from'];
}
if ($f['neg_to'] !== '') {
  $where[] = "negotiation_date <= ?";
  $params[] = $f['neg_to'];
}
if ($f['eff_from'] !== '') {
  $where[] = "effective_date >= ?";
  $params[] = $f['eff_from'];
}
if ($f['eff_to'] !== '') {
  $where[] = "effective_date <= ?";
  $params[] = $f['eff_to'];
}
if ($f['season'] !== '') {
  $where[] = "season = ?";
  $params[] = $f['season'];
}
if ($f['type'] !== '') {
  $where[] = "type = ?";
  $params[] = $f['type'];
}
if ($f['athlete'] !== '') {
  $where[] = "LOWER(athlete_name) LIKE LOWER(?) ESCAPE '\\'";
  $params[] = '%' . like_escape($f['athlete']) . '%';
}
if ($f['origin'] !== '') {
  $where[] = "LOWER(club_origin) LIKE LOWER(?) ESCAPE '\\'";
  $params[] = '%' . like_escape($f['origin']) . '%';
}
if ($f['dest'] !== '') {
  $where[] = "LOWER(club_destination) LIKE LOWER(?) ESCAPE '\\'";
  $params[] = '%' . like_escape($f['dest']) . '%';
}

if ($valueMinRaw !== '') {
  $c = eur_to_cents($valueMinRaw);
  if ($c !== null) {
    $where[] = "CAST(value AS INTEGER) >= ?";
    $params[] = $c;
  }
} elseif ($f['value_min'] !== '') {
  $c = eur_to_cents($f['value_min']);
  if ($c !== null) {
    $where[] = "CAST(value AS INTEGER) >= ?";
    $params[] = $c;
  }
}

if ($valueMaxRaw !== '') {
  $c = eur_to_cents($valueMaxRaw);
  if ($c !== null) {
    $where[] = "CAST(value AS INTEGER) <= ?";
    $params[] = $c;
  }
} elseif ($f['value_max'] !== '') {
  $c = eur_to_cents($f['value_max']);
  if ($c !== null) {
    $where[] = "CAST(value AS INTEGER) <= ?";
    $params[] = $c;
  }
}

$sql = "SELECT * FROM transfers";
if ($where) {
  $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY " . $SORT_MAP[$sort] . " " . $dir . ", id DESC";

$rows = q($pdo, $sql, $params)->fetchAll();

render_header('Transferências');

if ($err === 'notfound') {
  echo '<div class="alert alert-warning card-soft">Transferência não encontrada.</div>';
}

if ($formErr !== '') {
  $msg = 'Erro ao salvar.';
  if ($formErr === 'required') $msg = 'Preencha: Temporada, Tipo, Atleta, Data de negociação e Data efetiva.';
  if ($formErr === 'invalid_type') $msg = 'Tipo inválido.';
  if ($formErr === 'invalid_season') $msg = 'Temporada inválida.';
  if ($formErr === 'invalid_value') $msg = 'Valor inválido.';
  if ($formErr === 'camisa_required') $msg = 'Camisa associada é obrigatória nas chegadas.';
  if ($formErr === 'invalid_player') $msg = 'Atleta inválido para este usuário.';
  if ($formErr === 'invalid_effective_date') $msg = 'A data efetiva não pode ser anterior à data de negociação.';
  echo '<div class="alert alert-warning card-soft">' . h($msg) . '</div>';
}

echo '<div class="row g-3">';

/** FORM */
echo '<div class="col-lg-4 col-xl-3">';
echo '<div class="card card-soft p-3">';
echo '<div class="d-flex align-items-center justify-content-between mb-2">';
echo '<div class="fw-bold">' . ($editing ? 'Editar transferência' : 'Nova transferência') . '</div>';
echo '</div>';

echo '<form method="post" class="vstack gap-2" id="transferForm">';
echo '<input type="hidden" name="action" value="save">';
echo '<input type="hidden" name="id" value="' . (int)($form['id'] ?? 0) . '">';

echo '<div>';
echo '<label class="form-label">Temporada</label>';
echo '<select class="form-select" name="season" required>';
echo '<option value="">-- selecione --</option>';
foreach ($SEASONS as $s) {
  $sel = ((string)$s === (string)($form['season'] ?? '')) ? 'selected' : '';
  echo '<option value="' . h((string)$s) . '" ' . $sel . '>' . h((string)$s) . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div>';
echo '<label class="form-label">Tipo</label>';
echo '<select class="form-select" name="type" id="typeSelect" required>';
echo '<option value="">-- selecione --</option>';
foreach ($TYPE_OPTIONS as $t) {
  $sel = (($form['type'] ?? '') === $t) ? 'selected' : '';
  echo '<option value="' . h($t) . '" ' . $sel . '>' . h($t) . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div>';
echo '<label class="form-label">Atleta</label>';
echo '<select class="form-select" name="player_id" id="playerSelect" required>';
echo '<option value="">-- selecione --</option>';
foreach ($playerOptions as $opt) {
  $sel = ((string)$opt['id'] === (string)($form['player_id'] ?? '')) ? 'selected' : '';
  echo '<option value="' . (int)$opt['id'] . '" ' . $sel . '>' . h($opt['label']) . '</option>';
}
echo '</select>';
echo '<input type="hidden" name="athlete_name" id="athleteNameHidden" value="' . h((string)($form['athlete_name'] ?? '')) . '">';
echo '</div>';

$originReadonly = is_promoted((string)($form['type'] ?? ''));
$originVal = $originReadonly ? 'CRIA DA ACADEMIA' : (string)($form['club_origin'] ?? '');

echo '<div>';
echo '<label class="form-label">Clube origem</label>';
echo '<input class="form-control" name="club_origin" id="originInput" ' . ($originReadonly ? 'readonly' : '') . ' value="' . h($originVal) . '" placeholder="ex: São Paulo">';
echo '<div class="form-text">Se o tipo for <b>PROMOVIDO DA BASE</b>, a origem vira automaticamente <b>CRIA DA ACADEMIA</b>.</div>';
echo '</div>';

echo '<div>';
echo '<label class="form-label">Clube destino</label>';
echo '<input class="form-control" name="club_destination" id="destInput" value="' . h((string)($form['club_destination'] ?? '')) . '" placeholder="ex: Chelsea">';
echo '</div>';

$valueLabel = '';
if (($form['value'] ?? '') !== '') {
  $vv = eur_to_cents((string)$form['value']);
  if ($vv !== null) $valueLabel = cents_to_eur_label($vv);
}

echo '<div>';
echo '<label class="form-label">Valor</label>';
echo '<input class="form-control" name="value" id="valueInput" value="' . h($valueLabel !== '' ? $valueLabel : (string)($form['value'] ?? '')) . '" placeholder="R$ 0,00">';
echo '<input type="hidden" name="value_cents" id="valueCentsHidden" value="' . h((string)($form['value'] ?? '')) . '">';
echo '<div class="form-text">Digite números. Internamente o valor é salvo em centavos.</div>';
echo '</div>';

echo '<div>';
echo '<label class="form-label">Prazo / Termo</label>';
echo '<input class="form-control" name="term" value="' . h((string)($form['term'] ?? '')) . '" placeholder="ex: 5 anos / até 06/2028">';
echo '</div>';

echo '<div>';
echo '<label class="form-label">Nota / Grau</label>';
echo '<input class="form-control" name="grade" value="' . h((string)($form['grade'] ?? '')) . '" placeholder="ex: A / 8.5">';
echo '</div>';

echo '<div>';
echo '<label class="form-label">Atleta envolvido (troca)</label>';
echo '<input class="form-control" name="extra_player_name" value="' . h((string)($form['extra_player_name'] ?? '')) . '" placeholder="ex: jogador recebido/cedido">';
echo '<div class="form-text">Use para registrar trocas, quando houver.</div>';
echo '</div>';

echo '<div>';
echo '<label class="form-label">Camisa associada</label>';
echo '<input class="form-control" type="number" min="1" name="shirt_number_assigned" id="shirtInput" value="' . h((string)($form['shirt_number_assigned'] ?? '')) . '" placeholder="ex: 9">';
echo '<div class="form-text">Obrigatório nas chegadas.</div>';
echo '</div>';

echo '<div>';
echo '<label class="form-label">Data de negociação</label>';
echo '<input class="form-control" type="date" name="negotiation_date" required value="' . h((string)($form['negotiation_date'] ?? '')) . '">';
echo '</div>';

echo '<div>';
echo '<label class="form-label" id="effectiveDateLabel">' . h(effective_date_label((string)($form['type'] ?? ''))) . '</label>';
echo '<input class="form-control" type="date" name="effective_date" id="effectiveDateInput" required value="' . h((string)($form['effective_date'] ?? '')) . '">';
echo '<div class="form-text" id="effectiveDateHelp">Use a data em que a movimentação passa a valer de fato.</div>';
echo '</div>';

echo '<div>';
echo '<label class="form-label">Observações</label>';
echo '<textarea class="form-control" name="notes" rows="3" placeholder="Detalhes adicionais...">' . h((string)($form['notes'] ?? '')) . '</textarea>';
echo '</div>';

echo '<div class="d-grid gap-2 mt-2">';
echo '<button class="btn btn-success" type="submit">' . ($editing ? 'Salvar alterações' : 'Salvar') . '</button>';
echo '</div>';

echo '</form>';

if ($editing) {
  echo '<div class="d-grid gap-2 mt-2">';
  echo '<a class="btn btn-secondary" href="' . h(build_qs(['edit' => null])) . '">Cancelar</a>';

  echo '<form method="post" onsubmit="return confirm(\'Excluir esta transferência?\');">';
  echo '<input type="hidden" name="action" value="delete">';
  echo '<input type="hidden" name="id" value="' . (int)$editId . '">';
  echo '<button class="btn w-100 btn-danger" type="submit">Excluir</button>';
  echo '</form>';
  echo '</div>';
}

echo '</div>';
echo '</div>';

/** LISTA */
echo '<div class="col-lg-8 col-xl-9">';
echo '<div class="card card-soft p-3">';

echo '<div class="d-flex align-items-center justify-content-between mb-2">';
echo '<div class="fw-bold">Histórico</div>';
echo '</div>';

/** Filtros */
echo '<form class="row g-2 align-items-end mb-3" method="get">';
echo '<input type="hidden" name="page" value="transfers">';

echo '<div class="col-sm-6 col-md-3 col-xl-2">';
echo '<label class="form-label">Negociação de</label>';
echo '<input class="form-control" type="date" name="f_neg_from" value="' . h($f['neg_from']) . '">';
echo '</div>';

echo '<div class="col-sm-6 col-md-3 col-xl-2">';
echo '<label class="form-label">Negociação até</label>';
echo '<input class="form-control" type="date" name="f_neg_to" value="' . h($f['neg_to']) . '">';
echo '</div>';

echo '<div class="col-sm-6 col-md-3 col-xl-2">';
echo '<label class="form-label">Efetiva de</label>';
echo '<input class="form-control" type="date" name="f_eff_from" value="' . h($f['eff_from']) . '">';
echo '</div>';

echo '<div class="col-sm-6 col-md-3 col-xl-2">';
echo '<label class="form-label">Efetiva até</label>';
echo '<input class="form-control" type="date" name="f_eff_to" value="' . h($f['eff_to']) . '">';
echo '</div>';

echo '<div class="col-sm-6 col-md-3 col-xl-2">';
echo '<label class="form-label">Temporada</label>';
echo '<select class="form-select" name="f_season">';
echo '<option value="">Todas</option>';
foreach ($SEASONS as $s) {
  $sel = ((string)$s === (string)$f['season']) ? 'selected' : '';
  echo '<option value="' . h((string)$s) . '" ' . $sel . '>' . h((string)$s) . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="col-sm-6 col-md-4 col-xl-4">';
echo '<label class="form-label">Tipo</label>';
echo '<select class="form-select" name="f_type">';
echo '<option value="">Todos</option>';
foreach ($TYPE_OPTIONS as $t) {
  $sel = ($t === $f['type']) ? 'selected' : '';
  echo '<option value="' . h($t) . '" ' . $sel . '>' . h($t) . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="col-sm-6 col-md-4 col-xl-4">';
echo '<label class="form-label">Atleta</label>';
echo '<input class="form-control" name="f_athlete" value="' . h($f['athlete']) . '" placeholder="contém...">';
echo '</div>';

echo '<div class="col-sm-6 col-md-4 col-xl-4">';
echo '<label class="form-label">Origem</label>';
echo '<input class="form-control" name="f_origin" value="' . h($f['origin']) . '" placeholder="contém...">';
echo '</div>';

echo '<div class="col-sm-6 col-md-4 col-xl-4">';
echo '<label class="form-label">Destino</label>';
echo '<input class="form-control" name="f_dest" value="' . h($f['dest']) . '" placeholder="contém...">';
echo '</div>';

$minCentsPrefill = '';
$maxCentsPrefill = '';
if ($valueMinRaw !== '') {
  $minCentsPrefill = $valueMinRaw;
} elseif ($f['value_min'] !== '') {
  $tmp = eur_to_cents($f['value_min']);
  $minCentsPrefill = $tmp === null ? '' : (string)$tmp;
}
if ($valueMaxRaw !== '') {
  $maxCentsPrefill = $valueMaxRaw;
} elseif ($f['value_max'] !== '') {
  $tmp = eur_to_cents($f['value_max']);
  $maxCentsPrefill = $tmp === null ? '' : (string)$tmp;
}

echo '<div class="col-sm-6 col-md-3 col-xl-2">';
echo '<label class="form-label">Valor mín.</label>';
echo '<input class="form-control" name="f_value_min" id="valueMinInput" value="' . h($f['value_min']) . '" placeholder="R$ 0,00">';
echo '<input type="hidden" name="f_value_min_cents" id="valueMinCentsHidden" value="' . h($minCentsPrefill) . '">';
echo '</div>';

echo '<div class="col-sm-6 col-md-3 col-xl-2">';
echo '<label class="form-label">Valor máx.</label>';
echo '<input class="form-control" name="f_value_max" id="valueMaxInput" value="' . h($f['value_max']) . '" placeholder="R$ 0,00">';
echo '<input type="hidden" name="f_value_max_cents" id="valueMaxCentsHidden" value="' . h($maxCentsPrefill) . '">';
echo '</div>';

echo '<div class="col-sm-12 col-md-3 col-xl-2 d-grid">';
echo '<button class="btn btn-primary" type="submit">Filtrar</button>';
echo '</div>';

echo '<div class="col-sm-12 col-md-3 col-xl-2 d-grid">';
echo '<a class="btn btn-secondary" href="' . h(build_qs([
  'f_neg_from'         => null,
  'f_neg_to'           => null,
  'f_eff_from'         => null,
  'f_eff_to'           => null,
  'f_season'           => null,
  'f_type'             => null,
  'f_athlete'          => null,
  'f_origin'           => null,
  'f_dest'             => null,
  'f_value_min'        => null,
  'f_value_max'        => null,
  'f_value_min_cents'  => null,
  'f_value_max_cents'  => null,
  'sort'               => null,
  'dir'                => null,
  'edit'               => null,
])) . '">Limpar</a>';
echo '</div>';

echo '</form>';

/** Tabela */
echo '<div class="table-responsive">';
echo '<table class="table table-sm align-middle">';
echo '<thead>';
echo '<tr>';
echo '<th>' . sort_link('negotiation_date', 'Negociação', $sort, $dir) . '</th>';
echo '<th>' . sort_link('effective_date', 'Efetiva', $sort, $dir) . '</th>';
echo '<th>' . sort_link('season', 'Temporada', $sort, $dir) . '</th>';
echo '<th>' . sort_link('type', 'Tipo', $sort, $dir) . '</th>';
echo '<th>' . sort_link('athlete_name', 'Atleta', $sort, $dir) . '</th>';
echo '<th>' . sort_link('club_origin', 'Origem', $sort, $dir) . '</th>';
echo '<th>' . sort_link('club_destination', 'Destino', $sort, $dir) . '</th>';
echo '<th class="text-end">' . sort_link('value', 'Valor', $sort, $dir) . '</th>';
echo '<th class="text-end">Ações</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

if (!$rows) {
  echo '<tr><td colspan="9" class="text-muted">Nenhum registro.</td></tr>';
} else {
  foreach ($rows as $r) {
    $id = (int)$r['id'];
    $val = $r['value'] === null ? null : (int)$r['value'];
    $valLabel = $val === null ? '' : cents_to_eur_label($val);

    echo '<tr>';
    echo '<td>' . h(fmt_date_br((string)($r['negotiation_date'] ?? ''))) . '</td>';
    echo '<td>' . h(fmt_date_br((string)($r['effective_date'] ?? ''))) . '</td>';
    echo '<td>' . h((string)$r['season']) . '</td>';
    echo '<td>' . h((string)$r['type']) . '</td>';
    echo '<td>' . h((string)$r['athlete_name']) . '</td>';
    echo '<td>' . h((string)$r['club_origin']) . '</td>';
    echo '<td>' . h((string)$r['club_destination']) . '</td>';
    echo '<td class="text-end">' . h($valLabel) . '</td>';
    echo '<td class="text-end">';
    echo '<a class="btn btn-sm btn-primary" href="' . h(build_qs(['edit' => $id])) . '">Editar</a>';
    echo '</td>';
    echo '</tr>';
  }
}

echo '</tbody>';
echo '</table>';
echo '</div>';

echo '</div>';
echo '</div>';

echo '</div>';
?>
<script>
(function () {
  const typeSelect = document.getElementById('typeSelect');
  const originInput = document.getElementById('originInput');
  const shirtInput = document.getElementById('shirtInput');
  const effectiveDateLabel = document.getElementById('effectiveDateLabel');
  const effectiveDateHelp = document.getElementById('effectiveDateHelp');

  const playerSelect = document.getElementById('playerSelect');
  const athleteNameHidden = document.getElementById('athleteNameHidden');

  const valueInput = document.getElementById('valueInput');
  const valueCentsHidden = document.getElementById('valueCentsHidden');

  const valueMinInput = document.getElementById('valueMinInput');
  const valueMaxInput = document.getElementById('valueMaxInput');
  const valueMinCentsHidden = document.getElementById('valueMinCentsHidden');
  const valueMaxCentsHidden = document.getElementById('valueMaxCentsHidden');

  const ARRIVAL_TYPES = new Set([
    'PROMOVIDO DA BASE',
    'CONTRATADO (DEFINITIVO)',
    'CHEGOU POR EMPRÉSTIMO',
    'VOLTOU DE EMPRÉSTIMO'
  ]);

  const DEPARTURE_TYPES = new Set([
    'VENDIDO',
    'SAIU POR EMPRÉSTIMO',
    'FIM DE EMPRÉSTIMO (RETORNO AO CLUBE PROPRIETÁRIO)',
    'APOSENTADORIA'
  ]);

  function normalizeType(v) {
    return (v || '').trim();
  }

  function digitsOnly(s) {
    return (s || '').replace(/\D+/g, '');
  }

  function formatCentsToBRL(centsStr) {
    if (!centsStr) return '';
    centsStr = String(centsStr).replace(/^0+(?!$)/, '');
    let cents = parseInt(centsStr, 10);
    if (isNaN(cents)) return '';
    let value = cents / 100;
    let br = value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return 'R$ ' + br;
  }

  function applyMoneyMask(inputEl, hiddenEl) {
    if (!inputEl) return;

    function setFromDigits(d) {
      if (!d) {
        inputEl.value = '';
        if (hiddenEl) hiddenEl.value = '';
        return;
      }
      inputEl.value = formatCentsToBRL(d);
      if (hiddenEl) hiddenEl.value = d;
    }

    function onInput() {
      const d = digitsOnly(inputEl.value);
      setFromDigits(d);
    }

    function onBlur() {
      const d = digitsOnly(inputEl.value);
      setFromDigits(d);
    }

    inputEl.addEventListener('input', onInput);
    inputEl.addEventListener('blur', onBlur);

    const initialDigits = digitsOnly(inputEl.value);
    if (initialDigits) setFromDigits(initialDigits);
    else if (hiddenEl && hiddenEl.value && digitsOnly(hiddenEl.value)) setFromDigits(digitsOnly(hiddenEl.value));
  }

  applyMoneyMask(valueInput, valueCentsHidden);
  applyMoneyMask(valueMinInput, valueMinCentsHidden);
  applyMoneyMask(valueMaxInput, valueMaxCentsHidden);

  function updateEffectiveDateText(typeValue) {
    const t = normalizeType(typeValue);

    if (!effectiveDateLabel || !effectiveDateHelp) return;

    if (ARRIVAL_TYPES.has(t)) {
      effectiveDateLabel.textContent = 'Data de apresentação';
      effectiveDateHelp.textContent = 'Use a data em que o atleta passa a integrar o elenco de fato.';
      return;
    }

    if (DEPARTURE_TYPES.has(t)) {
      effectiveDateLabel.textContent = 'Data de liberação';
      effectiveDateHelp.textContent = 'Use a data em que o atleta deixa o clube de fato.';
      return;
    }

    effectiveDateLabel.textContent = 'Data efetiva';
    effectiveDateHelp.textContent = 'Use a data em que a movimentação passa a valer de fato.';
  }

  function onTypeChange() {
    const t = normalizeType(typeSelect ? typeSelect.value : '');

    if (originInput) {
      if (t === 'PROMOVIDO DA BASE') {
        originInput.value = 'CRIA DA ACADEMIA';
        originInput.setAttribute('readonly', 'readonly');
      } else {
        originInput.removeAttribute('readonly');
      }
    }

    if (shirtInput) {
      if (ARRIVAL_TYPES.has(t)) {
        shirtInput.setAttribute('required', 'required');
      } else {
        shirtInput.removeAttribute('required');
      }
    }

    updateEffectiveDateText(t);
  }

  function onPlayerChange() {
    if (!playerSelect || !athleteNameHidden) return;
    const sel = playerSelect.options[playerSelect.selectedIndex];
    if (sel && sel.text) {
      let label = sel.text;
      label = label.replace(/\s*\(#\d+\)\s*/g, ' ').trim();
      label = label.replace(/\s*\[inativo\]\s*/g, ' ').trim();
      athleteNameHidden.value = label;
    }
  }

  if (typeSelect) typeSelect.addEventListener('change', onTypeChange);
  if (playerSelect) playerSelect.addEventListener('change', onPlayerChange);

  onTypeChange();
  onPlayerChange();
})();
</script>
<?php
render_footer();