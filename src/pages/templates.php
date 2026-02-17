<?php
declare(strict_types=1);
$pdo = db();

$templates = q($pdo, "SELECT * FROM lineup_templates ORDER BY template_name ASC")->fetchAll();
$players = q($pdo, "SELECT id, name, shirt_number, primary_position FROM players WHERE is_active=1 ORDER BY primary_position, shirt_number, name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $tplName = (string)($_POST['template_name'] ?? 'TITULAR');
  $tpl = q($pdo, "SELECT * FROM lineup_templates WHERE template_name=? LIMIT 1", [$tplName])->fetch();
  if (!$tpl) redirect('/?page=templates');

  $tplId = (int)$tpl['id'];

  if ($action === 'save_meta') {
    $formation = trim((string)($_POST['formation'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    q($pdo, "UPDATE lineup_templates SET formation=?, notes=? WHERE id=?", [$formation,$notes,$tplId]);
    redirect('/?page=templates&tpl=' . h($tplName));
  }

  if ($action === 'set_slot') {
    $role = (string)($_POST['role'] ?? 'STARTER');
    $order = (int)($_POST['sort_order'] ?? 0);
    $playerId = ($_POST['player_id'] ?? '') === '' ? null : (int)$_POST['player_id'];
    $pos = trim((string)($_POST['position'] ?? ''));

    // upsert
    q($pdo, "INSERT INTO lineup_template_slots(template_id, role, sort_order, player_id, position)
            VALUES (?,?,?,?,?)
            ON CONFLICT(template_id, role, sort_order) DO UPDATE SET player_id=excluded.player_id, position=excluded.position",
      [$tplId,$role,$order,$playerId,$pos]);

    redirect('/?page=templates&tpl=' . h($tplName));
  }

  if ($action === 'clear') {
    q($pdo, "DELETE FROM lineup_template_slots WHERE template_id=?", [$tplId]);
    redirect('/?page=templates&tpl=' . h($tplName));
  }
}

# ensure unique constraint for upsert conflict target
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_tpl_slot ON lineup_template_slots(template_id, role, sort_order);");

$sel = (string)($_GET['tpl'] ?? 'TITULAR');
$tpl = q($pdo, "SELECT * FROM lineup_templates WHERE template_name=? LIMIT 1", [$sel])->fetch();

$slots = [];
if ($tpl) {
  $slots = q($pdo, "SELECT s.*, p.name, p.shirt_number
                   FROM lineup_template_slots s
                   LEFT JOIN players p ON p.id=s.player_id
                   WHERE s.template_id=?
                   ORDER BY s.role ASC, s.sort_order ASC", [(int)$tpl['id']])->fetchAll();
}

render_header('Templates');

echo '<div class="row g-3">';
echo '<div class="col-lg-4"><div class="card card-soft p-3">';
echo '<div class="fw-bold mb-2">Selecionar template</div>';
echo '<div class="d-flex gap-2 mb-2">';
echo '<a class="btn btn-outline-primary w-50" href="/?page=templates&tpl=TITULAR">TITULAR</a>';
echo '<a class="btn btn-outline-primary w-50" href="/?page=templates&tpl=RESERVA">RESERVA</a>';
echo '</div>';

if ($tpl) {
  echo '<form method="post" class="vstack gap-2">';
  echo '<input type="hidden" name="action" value="save_meta">';
  echo '<input type="hidden" name="template_name" value="' . h($sel) . '">';
  echo '<div><label class="form-label">Formação (opcional)</label><input class="form-control" name="formation" value="' . h($tpl['formation']) . '" placeholder="ex: 4-2-3-1"></div>';
  echo '<div><label class="form-label">Notas</label><textarea class="form-control" rows="3" name="notes">' . h($tpl['notes']) . '</textarea></div>';
  echo '<button class="btn btn-success">Salvar</button>';
  echo '</form>';

  echo '<form method="post" class="mt-3">';
  echo '<input type="hidden" name="action" value="clear">';
  echo '<input type="hidden" name="template_name" value="' . h($sel) . '">';
  echo '<button class="btn btn-outline-danger" onclick="return confirm(\'Limpar todos os slots?\')">Limpar template</button>';
  echo '</form>';
}

echo '</div></div>';

echo '<div class="col-lg-8"><div class="card card-soft p-3">';
echo '<div class="d-flex justify-content-between align-items-center mb-2">';
echo '<div class="fw-bold">Slots do template: ' . h($sel) . '</div>';
if ($tpl) echo '<div class="text-muted small">Formação: ' . h($tpl['formation']) . '</div>';
echo '</div>';

echo '<div class="text-muted small mb-2">Monte os relacionados: titulares e banco. A ordem (sort_order) define a ordem de escalação.</div>';

echo '<form method="post" class="row g-2 align-items-end mb-3">';
echo '<input type="hidden" name="action" value="set_slot">';
echo '<input type="hidden" name="template_name" value="' . h($sel) . '">';
echo '<div class="col-md-2"><label class="form-label">Role</label><select class="form-select" name="role"><option value="STARTER">Titular</option><option value="BENCH">Reserva</option></select></div>';
echo '<div class="col-md-2"><label class="form-label">Ordem</label><input class="form-control" type="number" name="sort_order" value="1" required></div>';
echo '<div class="col-md-5"><label class="form-label">Atleta</label><select class="form-select" name="player_id"><option value="">(vazio)</option>';
foreach ($players as $p) {
  $lbl = trim((string)($p['shirt_number'] ?? '')) . ' - ' . $p['name'] . ' (' . $p['primary_position'] . ')';
  echo '<option value="' . (int)$p['id'] . '">' . h($lbl) . '</option>';
}
echo '</select></div>';
echo '<div class="col-md-3"><label class="form-label">Posição no jogo</label><input class="form-control" name="position" placeholder="ex: ZAG"></div>';
echo '<div class="col-12"><button class="btn btn-outline-primary">Salvar slot</button></div>';
echo '</form>';

if (!$slots) {
  echo '<div class="text-muted">Nenhum slot definido ainda.</div>';
} else {
  echo '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
  echo '<thead><tr><th>Role</th><th class="mono">Ordem</th><th>#</th><th>Atleta</th><th>Posição</th></tr></thead><tbody>';
  foreach ($slots as $s) {
    echo '<tr>';
    echo '<td>' . ($s['role']==='STARTER' ? '<span class="badge text-bg-success">Titular</span>' : '<span class="badge text-bg-secondary">Reserva</span>') . '</td>';
    echo '<td class="mono">' . (int)$s['sort_order'] . '</td>';
    echo '<td class="mono">' . h((string)($s['shirt_number'] ?? '')) . '</td>';
    echo '<td>' . h((string)($s['name'] ?? '')) . '</td>';
    echo '<td class="text-muted">' . h($s['position']) . '</td>';
    echo '</tr>';
  }
  echo '</tbody></table></div>';
}

echo '</div></div>';
echo '</div>';

render_footer();

