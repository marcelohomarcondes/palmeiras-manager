<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo = db();

/**
 * Requisitos:
 * - value armazenado como INTEGER (centavos EUR)
 * - players: colunas existentes (confirmadas): id, name, shirt_number, primary_position, secondary_positions, is_active, updated_at, created_at
 * - transfers: esperado conter ao menos: id, season, type, athlete_name, club_origin, club_destination, value, term, grade, extra_player_name, shirt_number_assigned, transaction_date, notes, (player_id)
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

function is_arrival_type(string $type): bool {
  global $ARRIVAL_TYPES;
  return in_array(trim($type), $ARRIVAL_TYPES, true);
}

function is_promoted(string $type): bool {
  return trim($type) === 'PROMOVIDO DA BASE';
}

/**
 * Atualiza is_active com base no tipo de transferência.
 * - Não apaga nada (histórico/stats ficam intactas)
 * - Template e create_match já filtram is_active=1
 */
function apply_player_active_from_transfer(PDO $pdo, ?int $player_id_int, string $type): void {
  global $ACTIVATE_TYPES, $DEACTIVATE_TYPES;

  if ($player_id_int === null || $player_id_int <= 0) return;

  $t = trim($type);

  if (in_array($t, $ACTIVATE_TYPES, true)) {
    q($pdo, "UPDATE players SET is_active = 1 WHERE id = ?", [$player_id_int]);
    return;
  }
  if (in_array($t, $DEACTIVATE_TYPES, true)) {
    q($pdo, "UPDATE players SET is_active = 0 WHERE id = ?", [$player_id_int]);
    return;
  }
  // Tipos neutros (ex.: TROCA) não alteram is_active automaticamente.
}

/**
 * Converte string de entrada (somente dígitos / com separadores) para centavos.
 * Ex: "1.234,56" -> 123456
 * Ex: "123456" -> 123456 (assumindo já centavos se vier via hidden)
 */
function eur_to_cents(string $raw): ?int {
  $raw = trim($raw);
  if ($raw === '') return null;

  // Se vier apenas dígitos, assumimos que já é centavos (por causa do hidden)
  if (preg_match('/^\d+$/', $raw)) {
    $n = (int)$raw;
    return $n >= 0 ? $n : null;
  }

  // remove € e espaços
  $s = str_replace(['€', ' '], '', $raw);
  // remove separador milhar "." e troca decimal "," por "."
  $s = str_replace('.', '', $s);
  $s = str_replace(',', '.', $s);

  if (!is_numeric($s)) return null;
  $f = (float)$s;
  $c = (int)round($f * 100);
  return $c >= 0 ? $c : null;
}

function cents_to_eur_label(?int $cents): string {
  if ($cents === null) return '';
  $v = $cents / 100;
  return '€ ' . number_format($v, 2, ',', '.');
}

/**
 * Exibição dd/mm/aaaa (mantém armazenamento YYYY-MM-DD)
 */
function fmt_date_br(?string $iso): string {
  $iso = trim((string)($iso ?? ''));
  if ($iso === '') return '';
  $dt = DateTime::createFromFormat('Y-m-d', $iso);
  if ($dt instanceof DateTime) return $dt->format('d/m/Y');

  $iso10 = substr($iso, 0, 10);
  $dt2 = DateTime::createFromFormat('Y-m-d', $iso10);
  if ($dt2 instanceof DateTime) return $dt2->format('d/m/Y');

  return $iso;
}

/**
 * Dropdown de atletas: somente do seu clube.
 * Regra prática do projeto:
 * - atletas do seu clube possuem shirt_number > 0
 */
$players = q(
  $pdo,
  "SELECT id, name, shirt_number, is_active
     FROM players
    WHERE shirt_number IS NOT NULL AND CAST(shirt_number AS INTEGER) > 0
    ORDER BY is_active DESC, name ASC"
)->fetchAll();

$playerOptions = [];
foreach ($players as $p) {
  $pid = (int)$p['id'];
  $nm = (string)$p['name'];
  $sn = $p['shirt_number'] === null ? '' : (string)$p['shirt_number'];
  $active = (int)$p['is_active'] === 1;
  $label = $nm;
  if ($sn !== '') $label .= " (#{$sn})";
  if (!$active) $label .= " [inativo]";
  $playerOptions[] = ['id' => $pid, 'name' => $nm, 'label' => $label];
}

/** Edição */
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = $editId > 0;
$editRow = null;

if ($editing) {
  $editRow = q($pdo, "SELECT * FROM transfers WHERE id = ?", [$editId])->fetch();
  if (!$editRow) {
    redirect('/?page=transfers&err=notfound');
  }
}

$err = trim((string)($_GET['err'] ?? ''));
$formErr = '';

/**
 * Preserva inputs:
 * - POST com erro -> usa $form do POST
 * - GET edit -> usa $editRow
 */
$form = [
  'id' => $editRow['id'] ?? 0,
  'season' => $editRow['season'] ?? '',
  'type' => $editRow['type'] ?? '',
  'player_id' => $editRow['player_id'] ?? '',
  'athlete_name' => $editRow['athlete_name'] ?? '',
  'club_origin' => $editRow['club_origin'] ?? '',
  'club_destination' => $editRow['club_destination'] ?? '',
  'value' => $editRow['value'] ?? '',
  'term' => $editRow['term'] ?? '',
  'grade' => $editRow['grade'] ?? '',
  'extra_player_name' => $editRow['extra_player_name'] ?? '',
  'shirt_number_assigned' => $editRow['shirt_number_assigned'] ?? '',
  'transaction_date' => $editRow['transaction_date'] ?? '',
  'notes' => $editRow['notes'] ?? '',
];

/** Salvar / Deletar */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim((string)($_POST['action'] ?? 'save'));
  $id = (int)($_POST['id'] ?? 0);

  if ($action === 'delete') {
    if ($id > 0) {
      q($pdo, "DELETE FROM transfers WHERE id = ?", [$id]);
    }
    redirect('/?page=transfers');
  }

  // SAVE
  $season = trim((string)($_POST['season'] ?? ''));
  $type = trim((string)($_POST['type'] ?? ''));
  $player_id = trim((string)($_POST['player_id'] ?? ''));
  $player_id_int = ($player_id === '') ? null : (int)$player_id;

  // Nome do atleta: preferencialmente do dropdown
  $athlete_name = trim((string)($_POST['athlete_name'] ?? ''));
  if ($player_id_int !== null && $player_id_int > 0) {
    $p = q($pdo, "SELECT name FROM players WHERE id = ? LIMIT 1", [$player_id_int])->fetch();
    if ($p && isset($p['name'])) $athlete_name = (string)$p['name'];
  }

  $club_origin = trim((string)($_POST['club_origin'] ?? ''));
  $club_destination = trim((string)($_POST['club_destination'] ?? ''));
  $term = trim((string)($_POST['term'] ?? ''));
  $grade = trim((string)($_POST['grade'] ?? ''));

  $extra = trim((string)($_POST['extra_player_name'] ?? ''));
  $shirt = (trim((string)($_POST['shirt_number_assigned'] ?? '')) === '') ? null : (int)$_POST['shirt_number_assigned'];

  $date = trim((string)($_POST['transaction_date'] ?? ''));
  $notes = trim((string)($_POST['notes'] ?? ''));

  // Valor: hidden (centavos) ou fallback do text
  $value_cents_raw = trim((string)($_POST['value_cents'] ?? ''));
  $value_text_raw  = trim((string)($_POST['value'] ?? ''));
  $value_cents = eur_to_cents($value_cents_raw !== '' ? $value_cents_raw : $value_text_raw);

  // promovido -> origem automática
  if (is_promoted($type)) {
    $club_origin = 'CRIA DA ACADEMIA';
  }

  // chegadas -> camisa obrigatória
  if (is_arrival_type($type) && ($shirt === null || $shirt <= 0)) {
    $formErr = 'camisa_required';
  }

  // validação mínima
  if ($season === '' || $type === '' || $athlete_name === '' || $date === '') {
    $formErr = $formErr ?: 'required';
  }

  // tipo válido
  if ($formErr === '' && !in_array($type, $TYPE_OPTIONS, true)) {
    $formErr = 'invalid_type';
  }

  // temporada válida
  if ($formErr === '' && !in_array((int)$season, $SEASONS, true)) {
    $formErr = 'invalid_season';
  }

  // valor: se texto preenchido, deve virar centavos
  if ($formErr === '' && $value_text_raw !== '' && $value_cents === null) {
    $formErr = 'invalid_value';
  }

  // Preservar form em caso de erro
  $form = [
    'id' => $id,
    'season' => $season,
    'type' => $type,
    'player_id' => $player_id,
    'athlete_name' => $athlete_name,
    'club_origin' => $club_origin,
    'club_destination' => $club_destination,
    'value' => ($value_cents === null) ? $value_text_raw : (string)$value_cents,
    'term' => $term,
    'grade' => $grade,
    'extra_player_name' => $extra,
    'shirt_number_assigned' => $shirt === null ? '' : (string)$shirt,
    'transaction_date' => $date,
    'notes' => $notes,
  ];

  if ($formErr === '') {
    if ($id > 0) {
      q(
        $pdo,
        "UPDATE transfers
            SET season=?,
                type=?,
                player_id=?,
                athlete_name=?,
                club_origin=?,
                club_destination=?,
                value=?,
                term=?,
                grade=?,
                extra_player_name=?,
                shirt_number_assigned=?,
                transaction_date=?,
                notes=?
          WHERE id=?",
        [
          $season,
          $type,
          $player_id_int,
          $athlete_name,
          $club_origin,
          $club_destination,
          $value_cents,
          $term,
          $grade,
          $extra,
          $shirt,
          $date,
          $notes,
          $id
        ]
      );
    } else {
      q(
        $pdo,
        "INSERT INTO transfers(
            season,type,player_id,athlete_name,club_origin,club_destination,value,term,grade,extra_player_name,shirt_number_assigned,transaction_date,notes
          ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [
          $season,
          $type,
          $player_id_int,
          $athlete_name,
          $club_origin,
          $club_destination,
          $value_cents,
          $term,
          $grade,
          $extra,
          $shirt,
          $date,
          $notes
        ]
      );
    }

    // ✅ atualiza status no elenco
    apply_player_active_from_transfer($pdo, $player_id_int, $type);

    redirect('/?page=transfers');
  }
}

/** Listagem (filtros e ordenação) */
function like_escape(string $v): string {
  // escapa \, % e _ para uso em LIKE
  return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
}

// filtros (GET)
$f = [
  'date_from' => trim((string)($_GET['f_date_from'] ?? '')),
  'date_to'   => trim((string)($_GET['f_date_to'] ?? '')),
  'season'    => trim((string)($_GET['f_season'] ?? '')),
  'type'      => trim((string)($_GET['f_type'] ?? '')),
  'athlete'   => trim((string)($_GET['f_athlete'] ?? '')),
  'origin'    => trim((string)($_GET['f_origin'] ?? '')),
  'dest'      => trim((string)($_GET['f_dest'] ?? '')),
  'value_min' => trim((string)($_GET['f_value_min'] ?? '')),
  'value_max' => trim((string)($_GET['f_value_max'] ?? '')),
];

// centavos (prioritário; mas aceita texto também)
$valueMinRaw = trim((string)($_GET['f_value_min_cents'] ?? ''));
$valueMaxRaw = trim((string)($_GET['f_value_max_cents'] ?? ''));

// ordenação (GET)
$sort = trim((string)($_GET['sort'] ?? 'transaction_date'));
$dir  = strtoupper(trim((string)($_GET['dir'] ?? 'DESC')));
$dir  = ($dir === 'ASC') ? 'ASC' : 'DESC';

// whitelist: coluna -> expressão SQL
$SORT_MAP = [
  'transaction_date' => 'transaction_date',
  'season' => 'season',
  'type' => 'type',
  'athlete_name' => 'athlete_name',
  'club_origin' => 'club_origin',
  'club_destination' => 'club_destination',
  'value' => 'CAST(value AS INTEGER)',
];

if (!array_key_exists($sort, $SORT_MAP)) {
  $sort = 'transaction_date';
}

$where = [];
$params = [];

// date range (YYYY-MM-DD)
if ($f['date_from'] !== '') {
  $where[] = "transaction_date >= ?";
  $params[] = $f['date_from'];
}
if ($f['date_to'] !== '') {
  $where[] = "transaction_date <= ?";
  $params[] = $f['date_to'];
}

// season exact
if ($f['season'] !== '') {
  $where[] = "season = ?";
  $params[] = $f['season'];
}

// type exact
if ($f['type'] !== '') {
  $where[] = "type = ?";
  $params[] = $f['type'];
}

// text contains (case-insensitive) + ESCAPE de 1 caractere (SQLite)
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

// value range (EUR -> centavos)
// prioridade: hidden cents; fallback: texto
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

function build_qs(array $overrides = [], array $remove = []): string {
  $q = $_GET;
  foreach ($remove as $k) unset($q[$k]);
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  $q['page'] = 'transfers';
  return '/?' . http_build_query($q);
}

function sort_link(string $col, string $label, string $currentSort, string $currentDir): string {
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

render_header('Transferências');

if ($err === 'notfound') {
  echo '<div class="alert alert-warning card-soft">Transferência não encontrada.</div>';
}

if ($formErr !== '') {
  $msg = 'Erro ao salvar.';
  if ($formErr === 'required') $msg = 'Preencha: Temporada, Tipo, Atleta e Data.';
  if ($formErr === 'invalid_type') $msg = 'Tipo inválido.';
  if ($formErr === 'invalid_season') $msg = 'Temporada inválida.';
  if ($formErr === 'invalid_value') $msg = 'Valor inválido (use somente números).';
  if ($formErr === 'camisa_required') $msg = 'Camisa associada é obrigatória nas chegadas.';
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

// Temporada
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

// Tipo
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

// Atleta
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

// Origem
$originReadonly = is_promoted((string)($form['type'] ?? ''));
$originVal = $originReadonly ? 'CRIA DA ACADEMIA' : (string)($form['club_origin'] ?? '');
echo '<div>';
echo '<label class="form-label">Clube origem</label>';
echo '<input class="form-control" name="club_origin" id="originInput" ' . ($originReadonly ? 'readonly' : '') . ' value="' . h($originVal) . '" placeholder="ex: São Paulo">';
echo '<div class="form-text">Se o tipo for <b>PROMOVIDO DA BASE</b>, a origem vira automaticamente <b>CRIA DA ACADEMIA</b>.</div>';
echo '</div>';

// Destino
echo '<div>';
echo '<label class="form-label">Clube destino</label>';
echo '<input class="form-control" name="club_destination" id="destInput" value="' . h((string)($form['club_destination'] ?? '')) . '" placeholder="ex: Chelsea">';
echo '</div>';

// Valor (formata enquanto digita)
$valueLabel = '';
if (($form['value'] ?? '') !== '') {
  $vv = eur_to_cents((string)$form['value']);
  if ($vv !== null) $valueLabel = cents_to_eur_label($vv);
}
echo '<div>';
echo '<label class="form-label">Valor</label>';
echo '<input class="form-control" name="value" id="valueInput" value="' . h($valueLabel !== '' ? $valueLabel : (string)($form['value'] ?? '')) . '" placeholder="€ 0,00">';
echo '<input type="hidden" name="value_cents" id="valueCentsHidden" value="' . h((string)($form['value'] ?? '')) . '">';
echo '<div class="form-text">Digite números (ex.: <b>123456</b> → <b>€ 1.234,56</b>). Internamente salva em centavos.</div>';
echo '</div>';

// Prazo
echo '<div>';
echo '<label class="form-label">Prazo / Termo</label>';
echo '<input class="form-control" name="term" value="' . h((string)($form['term'] ?? '')) . '" placeholder="ex: 5 anos / até 06/2028">';
echo '</div>';

// Nota / Grau
echo '<div>';
echo '<label class="form-label">Nota / Grau</label>';
echo '<input class="form-control" name="grade" value="' . h((string)($form['grade'] ?? '')) . '" placeholder="ex: A / 8.5">';
echo '</div>';

// Jogador extra (troca)
echo '<div>';
echo '<label class="form-label">Atleta envolvido (troca)</label>';
echo '<input class="form-control" name="extra_player_name" value="' . h((string)($form['extra_player_name'] ?? '')) . '" placeholder="ex: jogador recebido/cedido">';
echo '<div class="form-text">Use para registrar trocas (opcional).</div>';
echo '</div>';

// Camisa (chegadas)
echo '<div>';
echo '<label class="form-label">Camisa associada</label>';
echo '<input class="form-control" type="number" min="1" name="shirt_number_assigned" id="shirtInput" value="' . h((string)($form['shirt_number_assigned'] ?? '')) . '" placeholder="ex: 9">';
echo '<div class="form-text">Obrigatório nas chegadas (<b>CONTRATADO</b>, <b>CHEGOU POR EMPRÉSTIMO</b>, <b>VOLTOU</b>, <b>PROMOVIDO</b>).</div>';
echo '</div>';

// Data
echo '<div>';
echo '<label class="form-label">Data</label>';
echo '<input class="form-control" type="date" name="transaction_date" required value="' . h((string)($form['transaction_date'] ?? '')) . '">';
echo '</div>';

// Observações
echo '<div>';
echo '<label class="form-label">Observações</label>';
echo '<textarea class="form-control" name="notes" rows="3" placeholder="Detalhes adicionais...">' . h((string)($form['notes'] ?? '')) . '</textarea>';
echo '</div>';

// Botões
echo '<div class="d-grid gap-2 mt-2">';
echo '<button class="btn btn-success" type="submit">' . ($editing ? 'Salvar alterações' : 'Salvar') . '</button>';
echo '</div>';

echo '</form>';

// Botões fora do form (evita nested form)
if ($editing) {
  echo '<div class="d-grid gap-2 mt-2">';
  echo '<a class="btn btn-secondary" href="' . h(build_qs(['edit' => null])) . '">Cancelar</a>';

  echo '<form method="post" onsubmit="return confirm(\'Excluir esta transferência?\');">';
  echo '<input type="hidden" name="action" value="delete">';
  echo '<input type="hidden" name="id" value="' . (int)$editId . '">';
  echo '<button class="btn w-100 btn-outline-danger" type="submit">Excluir</button>';
  echo '</form>';
  echo '</div>';
}

echo '</div>'; // card
echo '</div>'; // col form

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
echo '<label class="form-label">De</label>';
echo '<input class="form-control" type="date" name="f_date_from" value="' . h($f['date_from']) . '">';
echo '</div>';

echo '<div class="col-sm-6 col-md-3 col-xl-2">';
echo '<label class="form-label">Até</label>';
echo '<input class="form-control" type="date" name="f_date_to" value="' . h($f['date_to']) . '">';
echo '</div>';

echo '<div class="col-sm-6 col-md-2 col-xl-2">';
echo '<label class="form-label">Temporada</label>';
echo '<select class="form-select" name="f_season">';
echo '<option value="">Todas</option>';
foreach ($SEASONS as $s) {
  $sel = ((string)$s === (string)$f['season']) ? 'selected' : '';
  echo '<option value="' . h((string)$s) . '" ' . $sel . '>' . h((string)$s) . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="col-sm-6 col-md-4 col-xl-3">';
echo '<label class="form-label">Tipo</label>';
echo '<select class="form-select" name="f_type">';
echo '<option value="">Todos</option>';
foreach ($TYPE_OPTIONS as $t) {
  $sel = ($t === $f['type']) ? 'selected' : '';
  echo '<option value="' . h($t) . '" ' . $sel . '>' . h($t) . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="col-sm-6 col-md-4 col-xl-3">';
echo '<label class="form-label">Atleta</label>';
echo '<input class="form-control" name="f_athlete" value="' . h($f['athlete']) . '" placeholder="contém...">';
echo '</div>';

echo '<div class="col-sm-6 col-md-4 col-xl-3">';
echo '<label class="form-label">Origem</label>';
echo '<input class="form-control" name="f_origin" value="' . h($f['origin']) . '" placeholder="contém...">';
echo '</div>';

echo '<div class="col-sm-6 col-md-4 col-xl-3">';
echo '<label class="form-label">Destino</label>';
echo '<input class="form-control" name="f_dest" value="' . h($f['dest']) . '" placeholder="contém...">';
echo '</div>';

// Valor mín/máx com máscara ao digitar (e hidden em centavos)
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

echo '<div class="col-sm-6 col-md-2 col-xl-2">';
echo '<label class="form-label">Valor mín.</label>';
echo '<input class="form-control" name="f_value_min" id="valueMinInput" value="' . h($f['value_min']) . '" placeholder="€ 0,00">';
echo '<input type="hidden" name="f_value_min_cents" id="valueMinCentsHidden" value="' . h($minCentsPrefill) . '">';
echo '</div>';

echo '<div class="col-sm-6 col-md-2 col-xl-2">';
echo '<label class="form-label">Valor máx.</label>';
echo '<input class="form-control" name="f_value_max" id="valueMaxInput" value="' . h($f['value_max']) . '" placeholder="€ 0,00">';
echo '<input type="hidden" name="f_value_max_cents" id="valueMaxCentsHidden" value="' . h($maxCentsPrefill) . '">';
echo '</div>';

echo '<div class="col-sm-12 col-md-4 col-xl-3 d-grid">';
echo '<button class="btnbtn-primary" type="submit">Filtrar</button>';
echo '</div>';

echo '<div class="col-sm-12 col-md-4 col-xl-3 d-grid">';
echo '<a class="btn btn-secondary" href="' . h(build_qs([
  'f_date_from' => null,
  'f_date_to' => null,
  'f_season' => null,
  'f_type' => null,
  'f_athlete' => null,
  'f_origin' => null,
  'f_dest' => null,
  'f_value_min' => null,
  'f_value_max' => null,
  'f_value_min_cents' => null,
  'f_value_max_cents' => null,
  'sort' => null,
  'dir' => null,
  'edit' => null,
])) . '">Limpar</a>';
echo '</div>';

echo '</form>';

/** Tabela */
echo '<div class="table-responsive">';
echo '<table class="table table-sm align-middle">';
echo '<thead>';
echo '<tr>';
echo '<th>' . sort_link('transaction_date', 'Data', $sort, $dir) . '</th>';
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
  echo '<tr><td colspan="8" class="text-muted">Nenhum registro.</td></tr>';
} else {
  foreach ($rows as $r) {
    $id = (int)$r['id'];
    $val = $r['value'] === null ? null : (int)$r['value'];
    $valLabel = $val === null ? '' : cents_to_eur_label($val);

    echo '<tr>';
    echo '<td>' . h(fmt_date_br((string)$r['transaction_date'])) . '</td>';
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
echo '</div>'; // table-responsive

echo '</div>'; // card list
echo '</div>'; // col list

echo '</div>'; // row

?>
<script>
(function () {
  const typeSelect = document.getElementById('typeSelect');
  const originInput = document.getElementById('originInput');
  const shirtInput = document.getElementById('shirtInput');

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

  function normalizeType(v) { return (v || '').trim(); }

  // ===========================
  // ✅ Moeda pt-BR AO DIGITAR
  // - usuário digita números
  // - campo mostra "€ 1.234,56"
  // - hidden guarda centavos (ex.: 123456)
  // ===========================
  function digitsOnly(s) {
    return (s || '').replace(/\D+/g, '');
  }

  function formatCentsToEUR(centsStr) {
    if (!centsStr) return '';
    centsStr = String(centsStr).replace(/^0+(?!$)/, '');
    let cents = parseInt(centsStr, 10);
    if (isNaN(cents)) return '';
    let euros = cents / 100;
    let br = euros.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return '€ ' + br;
  }

  function applyMoneyMask(inputEl, hiddenEl) {
    if (!inputEl) return;

    function setFromDigits(d) {
      if (!d) {
        inputEl.value = '';
        if (hiddenEl) hiddenEl.value = '';
        return;
      }
      inputEl.value = formatCentsToEUR(d);
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

    // init (carregado do PHP)
    const initialDigits = digitsOnly(inputEl.value);
    if (initialDigits) setFromDigits(initialDigits);
    else if (hiddenEl && hiddenEl.value && digitsOnly(hiddenEl.value)) setFromDigits(digitsOnly(hiddenEl.value));
  }

  applyMoneyMask(valueInput, valueCentsHidden);
  applyMoneyMask(valueMinInput, valueMinCentsHidden);
  applyMoneyMask(valueMaxInput, valueMaxCentsHidden);

  // ===========================
  // Regras existentes (tipo / camisa / origem)
  // ===========================
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
