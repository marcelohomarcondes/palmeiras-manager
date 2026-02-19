<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pdo  = db();
$club = (string)app_club(); // ex.: PALMEIRAS

$positions = ['GOL','ZAG','LD','LE','ALD','ALE','VOL','MC','ME','MD','MEI','PD','PE','SA','ATA'];

// Garante índice único pra UPSERT (SQLite)
try {
  $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_tpl_slot ON lineup_template_slots(template_id, role, sort_order);");
} catch (Throwable $e) {}

function gv(string $k, string $d=''): string { return isset($_GET[$k]) ? (string)$_GET[$k] : $d; }
function pv(string $k, string $d=''): string { return isset($_POST[$k]) ? (string)$_POST[$k] : $d; }

function select_options(array $values, string $selected=''): string {
  $out = '';
  foreach ($values as $v) {
    $v = (string)$v;
    $sel = (strcasecmp($v, $selected) === 0) ? 'selected' : '';
    $out .= '<option value="'.h($v).'" '.$sel.'>'.h($v).'</option>';
  }
  return $out;
}

$err = gv('err','');
$msg = gv('msg','');

// Templates
$templates = q($pdo, "SELECT id, template_name, formation, notes FROM lineup_templates ORDER BY template_name ASC")
  ->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Template selecionado
$tplId = (int)gv('tpl_id', '0');
if ($tplId <= 0 && !empty($templates)) $tplId = (int)$templates[0]['id'];

$tpl = null;
if ($tplId > 0) {
  $tpl = q($pdo, "SELECT id, template_name, formation, notes FROM lineup_templates WHERE id=? LIMIT 1", [$tplId])
    ->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * ✅ ATLETAS DO MEU CLUBE
 * - filtra por club_name = app_club() (TRIM + NOCASE)
 * - exclui placeholders APENAS do padrão "p" + número (p1, p10, p20...)
 *   sem excluir nomes reais como PAULINHO ou PIQUEREZ
 */
$players = q($pdo, "
  SELECT id, name, shirt_number, primary_position, club_name
  FROM players
  WHERE is_active = 1
    AND TRIM(club_name) = TRIM(?) COLLATE NOCASE
    AND NOT (LOWER(TRIM(name)) GLOB 'p[0-9]*')
  ORDER BY
    CASE WHEN primary_position IS NULL OR primary_position = '' THEN 1 ELSE 0 END,
    primary_position,
    CASE WHEN shirt_number IS NULL THEN 1 ELSE 0 END,
    shirt_number,
    name
", [$club])->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ===================== POST ===================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action = trim(pv('action'));

  if ($action === 'create_template') {
    $newName = trim(pv('new_template_name'));
    if ($newName === '') redirect('/?page=templates&err=tpl_name');

    $exists = q($pdo, "
      SELECT id
      FROM lineup_templates
      WHERE template_name=? COLLATE NOCASE
      LIMIT 1
    ", [$newName])->fetch(PDO::FETCH_ASSOC);

    if ($exists) redirect('/?page=templates&err=tpl_exists');

    q($pdo, "INSERT INTO lineup_templates(template_name, formation, notes) VALUES (?,?,?)", [$newName, '', '']);
    $newId = (int)$pdo->lastInsertId();
    redirect('/?page=templates&tpl_id='.$newId.'&msg=created');
  }

  $tplIdPost = (int)pv('template_id', '0');
  if ($tplIdPost <= 0) redirect('/?page=templates&err=tpl');

  if ($action === 'save_meta') {
    $formation = trim(pv('formation'));
    $notes     = trim(pv('notes'));
    q($pdo, "UPDATE lineup_templates SET formation=?, notes=? WHERE id=?", [$formation, $notes, $tplIdPost]);
    redirect('/?page=templates&tpl_id='.$tplIdPost.'&msg=meta');
  }

  if ($action === 'clear') {
    q($pdo, "DELETE FROM lineup_template_slots WHERE template_id=?", [$tplIdPost]);
    redirect('/?page=templates&tpl_id='.$tplIdPost.'&msg=cleared');
  }

  if ($action === 'set_slot') {
    $role  = strtoupper(trim(pv('role','STARTER')));
    $order = (int)pv('sort_order','0');

    $playerIdRaw = trim(pv('player_id','0'));
    $playerId    = ($playerIdRaw === '' || $playerIdRaw === '0') ? null : (int)$playerIdRaw;

    $pos = strtoupper(trim(pv('position','')));

    // ✅ Validações para compatibilidade create_match.php
    if ($role !== 'STARTER' && $role !== 'BENCH') redirect('/?page=templates&tpl_id='.$tplIdPost.'&err=role');
    if ($role === 'STARTER' && ($order < 0 || $order > 10)) redirect('/?page=templates&tpl_id='.$tplIdPost.'&err=order_starter');
    if ($role === 'BENCH'   && ($order < 0 || $order > 8))  redirect('/?page=templates&tpl_id='.$tplIdPost.'&err=order_bench');

    // ✅ valida posição (se preenchida)
    if ($pos !== '' && !in_array($pos, $positions, true)) {
      redirect('/?page=templates&tpl_id='.$tplIdPost.'&err=pos');
    }

    // ✅ Se selecionar atleta, valida que é do clube do topo (anti-bypass)
    if ($playerId !== null) {
      $check = q($pdo, "
        SELECT id
        FROM players
        WHERE id=?
          AND is_active=1
          AND TRIM(club_name) = TRIM(?) COLLATE NOCASE
          AND NOT (LOWER(TRIM(name)) GLOB 'p[0-9]*')
        LIMIT 1
      ", [$playerId, $club])->fetch(PDO::FETCH_ASSOC);

      if (!$check) redirect('/?page=templates&tpl_id='.$tplIdPost.'&err=player_not_allowed');
    }

    // Se não selecionou atleta e não informou posição, remove o slot
    if ($playerId === null && $pos === '') {
      q($pdo, "DELETE FROM lineup_template_slots WHERE template_id=? AND role=? AND sort_order=?", [$tplIdPost, $role, $order]);
      redirect('/?page=templates&tpl_id='.$tplIdPost.'&msg=slot_removed');
    }

    // UPSERT
    q($pdo, "
      INSERT INTO lineup_template_slots(template_id, role, sort_order, player_id, position)
      VALUES (?,?,?,?,?)
      ON CONFLICT(template_id, role, sort_order)
      DO UPDATE SET player_id=excluded.player_id, position=excluded.position
    ", [$tplIdPost, $role, $order, $playerId, $pos]);

    redirect('/?page=templates&tpl_id='.$tplIdPost.'&msg=slot_saved');
  }

  redirect('/?page=templates&tpl_id='.$tplIdPost);
}

/* ===================== Slots ===================== */
$slots = [];
if ($tpl) {
  $slots = q($pdo, "
    SELECT s.*, p.name, p.shirt_number, p.primary_position
    FROM lineup_template_slots s
    LEFT JOIN players p ON p.id=s.player_id
    WHERE s.template_id=?
    ORDER BY CASE WHEN s.role='STARTER' THEN 0 ELSE 1 END, s.sort_order
  ", [(int)$tpl['id']])->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

render_header('Templates');

/* ===================== Alerts ===================== */
if ($err === 'tpl') echo '<div class="alert alert-danger card-soft">Selecione um template válido.</div>';
if ($err === 'tpl_name') echo '<div class="alert alert-warning card-soft">Informe um nome para o novo template.</div>';
if ($err === 'tpl_exists') echo '<div class="alert alert-warning card-soft">Já existe um template com este nome.</div>';
if ($err === 'role') echo '<div class="alert alert-danger card-soft">Role inválida.</div>';
if ($err === 'order_starter') echo '<div class="alert alert-warning card-soft">Titular: sort_order deve ser de 0 a 10.</div>';
if ($err === 'order_bench') echo '<div class="alert alert-warning card-soft">Reserva: sort_order deve ser de 0 a 8.</div>';
if ($err === 'pos') echo '<div class="alert alert-warning card-soft">POS inválida.</div>';
if ($err === 'player_not_allowed') echo '<div class="alert alert-danger card-soft">Atleta inválido para o clube atual.</div>';

if ($msg === 'created') echo '<div class="alert alert-success card-soft">Template criado.</div>';
if ($msg === 'meta') echo '<div class="alert alert-success card-soft">Informações salvas.</div>';
if ($msg === 'cleared') echo '<div class="alert alert-success card-soft">Template limpo.</div>';
if ($msg === 'slot_saved') echo '<div class="alert alert-success card-soft">Slot salvo.</div>';
if ($msg === 'slot_removed') echo '<div class="alert alert-success card-soft">Slot removido.</div>';

/* ===================== UI ===================== */
echo '<div class="row g-3">';

/* LEFT */
echo '<div class="col-lg-4"><div class="card card-soft p-3">';

echo '<div class="fw-bold mb-2">Selecionar template</div>';
echo '<form method="get" class="mb-3">';
echo '<input type="hidden" name="page" value="templates">';
echo '<select class="form-select" name="tpl_id" onchange="this.form.submit()">';
if (empty($templates)) {
  echo '<option value="0">-- Nenhum template --</option>';
} else {
  foreach ($templates as $t) {
    $id = (int)$t['id'];
    $nm = (string)$t['template_name'];
    $sel = ($tpl && (int)$tpl['id'] === $id) ? 'selected' : '';
    echo '<option value="'.$id.'" '.$sel.'>'.h($nm).'</option>';
  }
}
echo '</select>';
echo '</form>';

echo '<div class="fw-bold mb-2">Criar novo template</div>';
echo '<form method="post" class="d-flex gap-2 mb-3">';
echo '<input type="hidden" name="action" value="create_template">';
echo '<input class="form-control" name="new_template_name" placeholder="ex: 4-2-3-1 Titular">';
echo '<button class="btn btn-outline-primary">Criar</button>';
echo '</form>';

if ($tpl) {
  echo '<hr class="my-3">';
  echo '<div class="fw-bold mb-2">Informações do template</div>';

  echo '<form method="post" class="vstack gap-2">';
  echo '<input type="hidden" name="action" value="save_meta">';
  echo '<input type="hidden" name="template_id" value="'.(int)$tpl['id'].'">';

  echo '<div><label class="form-label">Nome</label>';
  echo '<input class="form-control" value="'.h((string)$tpl['template_name']).'" disabled></div>';

  echo '<div><label class="form-label">Formação (opcional)</label>';
  echo '<input class="form-control" name="formation" value="'.h((string)($tpl['formation'] ?? '')).'" placeholder="ex: 4-2-3-1"></div>';

  echo '<div><label class="form-label">Notas</label>';
  echo '<textarea class="form-control" rows="3" name="notes">'.h((string)($tpl['notes'] ?? '')).'</textarea></div>';

  echo '<button class="btn btn-success">Salvar</button>';
  echo '</form>';

  echo '<form method="post" class="mt-3">';
  echo '<input type="hidden" name="action" value="clear">';
  echo '<input type="hidden" name="template_id" value="'.(int)$tpl['id'].'">';
  echo '<button class="btn btn-outline-danger" onclick="return confirm(\'Limpar todos os slots?\')">Limpar escalação</button>';
  echo '</form>';
}

echo '</div></div>';

/* RIGHT */
echo '<div class="col-lg-8"><div class="card card-soft p-3">';

if (!$tpl) {
  echo '<div class="text-muted">Crie um template para começar.</div>';
  echo '</div></div></div>';
  render_footer();
  exit;
}

echo '<div class="d-flex justify-content-between align-items-center mb-2">';
echo '<div class="fw-bold">Escalação do template: '.h((string)$tpl['template_name']).'</div>';
echo '<div class="text-muted small">Índices: Titulares 0..10 | Reservas 0..8 (compatível com create_match.php)</div>';
echo '</div>';

if (count($players) === 0) {
  echo '<div class="alert alert-warning card-soft">
          Nenhum atleta ativo encontrado para o clube <b>'.h($club).'</b>.
          Verifique o campo <code>players.club_name</code> no banco.
        </div>';
}

echo '<div class="text-muted small mb-3">
        Cadastre os slots abaixo. O <b>sort_order</b> define a posição na escalação.
      </div>';

/* form slot */
echo '<form method="post" class="row g-2 align-items-end mb-3">';
echo '<input type="hidden" name="action" value="set_slot">';
echo '<input type="hidden" name="template_id" value="'.(int)$tpl['id'].'">';

echo '<div class="col-md-2"><label class="form-label">Role</label>';
echo '<select class="form-select" name="role"><option value="STARTER">Titular</option><option value="BENCH">Reserva</option></select></div>';

echo '<div class="col-md-2"><label class="form-label">Ordem</label>';
echo '<input class="form-control" type="number" name="sort_order" value="0" min="0" required></div>';

echo '<div class="col-md-5"><label class="form-label">Atleta</label>';
echo '<select class="form-select" name="player_id"><option value="0">--</option>';
foreach ($players as $p) {
  $sn = trim((string)($p['shirt_number'] ?? ''));
  $nm = (string)($p['name'] ?? '');
  $pp = (string)($p['primary_position'] ?? '');
  $lbl = ($sn !== '' ? $sn.' - ' : '').$nm.($pp !== '' ? ' ('.$pp.')' : '');
  echo '<option value="' . (int)$p['id'] . '">' . h($lbl) . '</option>';
}
echo '</select></div>';

echo '<div class="col-md-3"><label class="form-label">POS</label>';
echo '<select class="form-select" name="position">';
echo '<option value=""></option>';
echo select_options($positions, '');
echo '</select></div>';

echo '<div class="col-12"><button class="btn btn-outline-primary">Salvar slot</button></div>';
echo '</form>';

/* list slots */
if (!$slots) {
  echo '<div class="text-muted">Nenhum slot definido ainda.</div>';
} else {
  echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
  echo '<thead><tr><th>Role</th><th class="mono">Ordem</th><th>#</th><th>Atleta</th><th>POS</th></tr></thead><tbody>';

  foreach ($slots as $s) {
    $badge = ((string)$s['role'] === 'STARTER')
      ? '<span class="badge text-bg-success">Titular</span>'
      : '<span class="badge text-bg-secondary">Reserva</span>';

    echo '<tr>';
    echo '<td>'.$badge.'</td>';
    echo '<td class="mono">'.(int)$s['sort_order'].'</td>';
    echo '<td class="mono">'.h((string)($s['shirt_number'] ?? '')).'</td>';
    echo '<td>'.h((string)($s['name'] ?? '')).'</td>';
    echo '<td class="text-muted">'.h((string)($s['position'] ?? '')).'</td>';
    echo '</tr>';
  }

  echo '</tbody></table></div>';
}

echo '</div></div>'; // right card
echo '</div>';       // row

render_footer();
