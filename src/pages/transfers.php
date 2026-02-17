<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo = db();

/**
 * Requisitos:
 * - value armazenado como INTEGER (centavos EUR)
 * - players: colunas existentes (confirmadas): id, name, shirt_number, primary_position, secondary_positions, is_active, updated_at, created_at
 * - transfers: esperado conter ao menos: id, season, type, athlete_name, club_origin, club_destination, value, term, grade, extra_player_name, shirt_number_assigned, transaction_date, notes
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

function is_arrival_type(string $type): bool {
  global $ARRIVAL_TYPES;
  return in_array(trim($type), $ARRIVAL_TYPES, true);
}

function is_promoted(string $type): bool {
  return trim($type) === 'PROMOVIDO DA BASE';
}

function normalize_digits(string $s): string {
  return preg_replace('/\D+/', '', $s) ?? '';
}

/**
 * Converte string de entrada (somente dígitos / com separadores) para centavos.
 * Ex: "1.234,56" -> 123456
 * Ex: "123456" -> 123456 (assumindo já centavos se vier via hidden)
 */
function eur_to_cents(string $raw): ?int {
  $raw = trim($raw);
  if ($raw === '') return null;

  // Se vier apenas dígitos, assumimos que já é centavos (por causa do hidden value_cents)
  if (preg_match('/^\d+$/', $raw)) {
    $n = (int)$raw;
    return $n >= 0 ? $n : null;
  }

  // Fallback: tenta interpretar "pt-BR"
  // remove € e espaços
  $s = str_replace(['€', ' '], '', $raw);
  // troca . milhar -> remove, troca , decimal -> .
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
  // formato pt-BR com símbolo €
  return '€ ' . number_format($v, 2, ',', '.');
}

/** Carrega atletas do ELENCO (players) */
$players = q($pdo, "SELECT id, name, shirt_number, is_active FROM players ORDER BY is_active DESC, name ASC")->fetchAll();
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
 * Preservar inputs se der erro:
 * - Se POST falhar validação, não redireciona; usa $form com POST.
 * - Se GET edit, usa $editRow.
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

  // Nome do atleta: preferencialmente do dropdown (players.name)
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

  // Valor: vem de hidden (centavos) ou fallback do text
  $value_cents_raw = trim((string)($_POST['value_cents'] ?? ''));
  $value_text_raw = trim((string)($_POST['value'] ?? ''));
  $value_cents = eur_to_cents($value_cents_raw !== '' ? $value_cents_raw : $value_text_raw);

  // regra: promovido -> origem automática
  if (is_promoted($type)) {
    $club_origin = 'CRIA DA ACADEMIA';
  }

  // arrivals -> camisa obrigatória
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

  // valor: se preenchido, deve virar centavos
  // (se quiser obrigatório sempre, troque a condição para exigir $value_cents !== null)
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

    redirect('/?page=transfers');
  }
}

/** Listagem */
$rows = q($pdo, "SELECT * FROM transfers ORDER BY transaction_date DESC, id DESC")->fetchAll();

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
echo '<div class="col-lg-5">';
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

// Atleta (dropdown)
echo '<div>';
echo '<label class="form-label">Atleta</label>';
echo '<select class="form-select" name="player_id" id="playerSelect" required>';
echo '<option value="">-- selecione --</option>';
foreach ($playerOptions as $opt) {
  $sel = ((string)$opt['id'] === (string)($form['player_id'] ?? '')) ? 'selected' : '';
  echo '<option value="' . (int)$opt['id'] . '" ' . $sel . '>' . h($opt['label']) . '</option>';
}
echo '</select>';
// fallback hidden para manter athlete_name consistente no POST
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
echo '<input class="form-control" name="club_destination" value="' . h((string)($form['club_destination'] ?? '')) . '" placeholder="ex: Barcelona">';
echo '</div>';

// Camisa (só chegadas)
$showShirt = is_arrival_type((string)($form['type'] ?? ''));
echo '<div id="shirtWrap" style="' . ($showShirt ? '' : 'display:none;') . '">';
echo '<label class="form-label">Camisa associada</label>';
echo '<input class="form-control" name="shirt_number_assigned" id="shirtInput" inputmode="numeric" placeholder="ex: 9" value="' . h((string)($form['shirt_number_assigned'] ?? '')) . '">';
echo '<div class="form-text">Obrigatória nas chegadas (Contratado / Empréstimo / Promovido / Voltou de empréstimo).</div>';
echo '</div>';

// Valor (EUR)
$valueCents = null;
if (($form['value'] ?? '') !== '') {
  // se já estiver em centavos (string numérica) convert
  $valueCents = eur_to_cents((string)$form['value']);
}
echo '<div>';
echo '<label class="form-label">Valor (EUR)</label>';
echo '<input class="form-control" name="value" id="valueDisplay" inputmode="numeric" placeholder="ex: 12.000.000,00" value="' . h(cents_to_eur_label($valueCents)) . '">';
echo '<input type="hidden" name="value_cents" id="valueCents" value="' . h($valueCents === null ? '' : (string)$valueCents) . '">';
echo '<div class="form-text">Digite só números. O sistema formata e salva em centavos (EUR).</div>';
echo '</div>';

// Prazo
echo '<div>';
echo '<label class="form-label">Prazo</label>';
echo '<input class="form-control" name="term" value="' . h((string)($form['term'] ?? '')) . '" placeholder="ex: 12 meses / definitivo">';
echo '</div>';

// Nota 1-10
echo '<div>';
echo '<label class="form-label">Nota (1 a 10)</label>';
echo '<select class="form-select" name="grade">';
echo '<option value="">(sem)</option>';
for ($i=1; $i<=10; $i++) {
  $sel = ((string)$i === (string)($form['grade'] ?? '')) ? 'selected' : '';
  echo '<option value="' . $i . '" ' . $sel . '>' . $i . '</option>';
}
echo '</select>';
echo '</div>';

// Extra (troca)
echo '<div>';
echo '<label class="form-label">Atleta extra (troca)</label>';
echo '<input class="form-control" name="extra_player_name" value="' . h((string)($form['extra_player_name'] ?? '')) . '" placeholder="opcional">';
echo '</div>';

// Data
echo '<div>';
echo '<label class="form-label">Data</label>';
echo '<input class="form-control" type="date" name="transaction_date" required value="' . h((string)($form['transaction_date'] ?? '')) . '">';
echo '</div>';

// Observações
echo '<div>';
echo '<label class="form-label">Observações</label>';
echo '<textarea class="form-control" name="notes" rows="3" placeholder="observações...">' . h((string)($form['notes'] ?? '')) . '</textarea>';
echo '</div>';

echo '<div class="d-flex gap-2 mt-2">';
echo '<button class="btn btn-success flex-fill" type="submit">Salvar</button>';

if ((int)($form['id'] ?? 0) > 0) {
  echo '<button class="btn btn-outline-danger" type="button" id="btnDelete">Excluir</button>';
}
echo '</div>';

echo '</form>';

// form delete separado (POST)
if ((int)($form['id'] ?? 0) > 0) {
  echo '<form method="post" id="deleteForm" style="display:none">';
  echo '<input type="hidden" name="action" value="delete">';
  echo '<input type="hidden" name="id" value="' . (int)$form['id'] . '">';
  echo '</form>';
}

echo '</div>';
echo '</div>';

/** TABELA */
echo '<div class="col-lg-7">';
echo '<div class="card card-soft p-3">';
echo '<div class="fw-bold mb-2">Histórico</div>';

if (!$rows) {
  echo '<div class="text-muted">Sem transferências cadastradas.</div>';
} else {
  echo '<div class="table-responsive">';
  echo '<table class="table align-middle mb-0">';
  echo '<thead><tr>';
  echo '<th>Data</th>';
  echo '<th>Temporada</th>';
  echo '<th>Tipo</th>';
  echo '<th>Atleta</th>';
  echo '<th>Origem</th>';
  echo '<th>Destino</th>';
  echo '<th class="text-end">Valor</th>';
  echo '<th class="text-end">Ações</th>';
  echo '</tr></thead><tbody>';

  foreach ($rows as $r) {
    $rid = (int)$r['id'];
    $val = $r['value'] ?? null;
    $valC = null;
    if ($val !== null && $val !== '') $valC = eur_to_cents((string)$val);
    $valLbl = $valC === null ? '' : cents_to_eur_label($valC);

    echo '<tr>';
    echo '<td>' . h((string)($r['transaction_date'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['season'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['type'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['athlete_name'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['club_origin'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['club_destination'] ?? '')) . '</td>';
    echo '<td class="text-end mono">' . h($valLbl) . '</td>';
    echo '<td class="text-end">';
    echo '<a class="btn btn-sm btn-outline-primary" href="/?page=transfers&edit=' . $rid . '">Editar</a>';
    echo '</td>';
    echo '</tr>';
  }

  echo '</tbody></table>';
  echo '</div>';
}

echo '</div>';
echo '</div>';

echo '</div>'; // row

?>
<script>
(function(){
  const typeSelect = document.getElementById('typeSelect');
  const originInput = document.getElementById('originInput');
  const shirtWrap = document.getElementById('shirtWrap');
  const shirtInput = document.getElementById('shirtInput');
  const playerSelect = document.getElementById('playerSelect');
  const athleteHidden = document.getElementById('athleteNameHidden');

  const valueDisplay = document.getElementById('valueDisplay');
  const valueCents = document.getElementById('valueCents');

  const btnDelete = document.getElementById('btnDelete');
  const deleteForm = document.getElementById('deleteForm');

  const ARRIVALS = new Set([
    'PROMOVIDO DA BASE',
    'CONTRATADO (DEFINITIVO)',
    'CHEGOU POR EMPRÉSTIMO',
    'VOLTOU DE EMPRÉSTIMO'
  ]);

  function isArrival(type){ return ARRIVALS.has((type||'').trim()); }
  function isPromoted(type){ return (type||'').trim() === 'PROMOVIDO DA BASE'; }

  function syncAthleteName(){
    const opt = playerSelect.options[playerSelect.selectedIndex];
    if (!opt) return;
    // label vem com (#) etc, então pegamos o texto e limpamos sufixos
    let t = opt.textContent || '';
    t = t.replace(/\s*\(#\d+\)\s*/g,' ').replace(/\s*\[inativo\]\s*/g,' ').trim();
    athleteHidden.value = t;
  }

  function applyTypeRules(){
    const t = (typeSelect.value || '').trim();

    // origem automática
    if (isPromoted(t)) {
      originInput.value = 'CRIA DA ACADEMIA';
      originInput.readOnly = true;
    } else {
      // se estava travado por promoted, destrava
      originInput.readOnly = false;
    }

    // camisa obrigatória nas chegadas
    if (isArrival(t)) {
      shirtWrap.style.display = '';
      shirtInput.setAttribute('required', 'required');
    } else {
      shirtWrap.style.display = 'none';
      shirtInput.removeAttribute('required');
      shirtInput.value = '';
    }
  }

  // Valor: digita só números e formata € 1.234,56
  function formatEURFromDigits(digits){
    digits = (digits||'').replace(/\D+/g,'');
    if (!digits) return {label:'', cents:''};

    // interpreta como centavos:
    // "1" => 0,01 | "10" => 0,10 | "123" => 1,23 | "123456" => 1234,56
    const cents = digits.replace(/^0+/, '') || '0';
    let n = parseInt(cents, 10);
    if (isNaN(n) || n < 0) n = 0;

    const v = n / 100;
    const label = '€ ' + v.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    return {label, cents: String(n)};
  }

  function onValueInput(){
    // pega só dígitos do que o usuário digitou
    const digits = (valueDisplay.value||'').replace(/\D+/g,'');
    const out = formatEURFromDigits(digits);
    valueDisplay.value = out.label;
    valueCents.value = out.cents;
  }

  if (typeSelect) typeSelect.addEventListener('change', applyTypeRules);
  if (playerSelect) playerSelect.addEventListener('change', syncAthleteName);
  if (valueDisplay) valueDisplay.addEventListener('input', onValueInput);

  if (btnDelete && deleteForm) {
    btnDelete.addEventListener('click', function(){
      if (confirm('Excluir esta transferência?')) deleteForm.submit();
    });
  }

  // inicial
  if (playerSelect) syncAthleteName();
  applyTypeRules();

  // se vier com valor já preenchido sem hidden, tenta inferir
  if (valueDisplay && valueCents && !valueCents.value && valueDisplay.value) {
    onValueInput();
  }
})();
</script>
<?php
render_footer();
