<?php
declare(strict_types=1);

function render_header(string $title): void
{
    $page = (string)($_GET['page'] ?? 'dashboard');

    if (in_array($page, ['match', 'create_match', 'edit_match'], true)) {
        $page = 'matches';
    }

    $items = [
        'dashboard' => ['Dashboard', 'bi-speedometer2'],
        'matches'   => ['Partidas', 'bi-calendar3'],
        'players'   => ['Elenco', 'bi-people'],
        'crias'     => ['Crias Da Academia', 'bi-mortarboard'],
        'templates' => ['Templates', 'bi-layout-text-window'],
        'transfers' => ['Transferências', 'bi-arrow-left-right'],
        'injuries'  => ['Lesões', 'bi-bandaid'],
        'trophies'  => ['Troféus', 'bi-trophy'],
        'stats'     => ['Relatórios', 'bi-graph-up'],
        'almanaque' => ['Almanaque', 'bi-book'],
    ];

    echo '<!doctype html><html lang="pt-br"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>';

    echo '<script>
(function(){
  var theme = "dark";
  try { theme = localStorage.getItem("pm_theme") || "dark"; } catch(e) {}
  if (theme !== "dark" && theme !== "light") theme = "dark";
  var html = document.documentElement;
  html.setAttribute("data-theme", theme);
  html.setAttribute("data-bs-theme", theme);
  html.style.colorScheme = theme;
})();
</script>';

    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">';
    echo '<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">';

    echo '<style>
:root{
  --bg0:#f4f7fb;
  --bg1:#eef3fb;
  --surface:#ffffff;
  --surface-2:#f7f9fd;
  --surface-3:#eef3fb;
  --border:rgba(15,23,42,.10);
  --border-strong:rgba(15,23,42,.16);
  --text:#0f172a;
  --muted:rgba(15,23,42,.68);
  --heading:#0b1220;
  --shadow:0 14px 34px rgba(15,23,42,.08);

  --primary:#2f6fff;
  --primary-hover:#215ee0;
  --success:#22c55e;
  --success-hover:#16a34a;
  --danger:#ef4444;
  --danger-hover:#dc2626;
  --warning:#f97316;
  --warning-hover:#ea580c;
  --secondary:#64748b;
  --secondary-hover:#475569;

  --btn-text-on-solid:#ffffff;
  --btn-warning-text:#1f2937;

  --input-bg:#ffffff;
  --input-text:#0f172a;
  --placeholder:rgba(15,23,42,.45);
  --focus:0 0 0 .25rem rgba(47,111,255,.18);

  --table-bg:#ffffff;
  --table-head:#eaf0fb;
  --table-row-hover:#eef4ff;

  --radius-xs:10px;
  --radius-sm:12px;
  --radius-md:14px;
  --radius-lg:18px;
  --radius-xl:22px;

  --btn-height:36px;
  --btn-font-weight:600;
  --section-header-bg:#f4f7fd;
  --sticky-head-bg:#243753;
}

html[data-theme="dark"]{
  --bg0:#061224;
  --bg1:#081a33;
  --surface:#0f2139;
  --surface-2:#132744;
  --surface-3:#10233d;
  --border:rgba(255,255,255,.12);
  --border-strong:rgba(255,255,255,.18);
  --text:rgba(255,255,255,.92);
  --muted:rgba(255,255,255,.66);
  --heading:#ffffff;
  --shadow:0 16px 38px rgba(0,0,0,.40);

  --primary:#2f7bff;
  --primary-hover:#1f6cf0;
  --success:#22c55e;
  --success-hover:#16a34a;
  --danger:#f43f5e;
  --danger-hover:#e11d48;
  --warning:#f97316;
  --warning-hover:#ea580c;
  --secondary:#94a3b8;
  --secondary-hover:#cbd5e1;

  --btn-text-on-solid:#ffffff;
  --btn-warning-text:#1f2937;

  --input-bg:#20324c;
  --input-text:rgba(255,255,255,.92);
  --placeholder:rgba(255,255,255,.45);
  --focus:0 0 0 .25rem rgba(47,123,255,.22);

  --table-bg:#10233d;
  --table-head:#263955;
  --table-row-hover:#173255;

  --section-header-bg:#122744;
  --sticky-head-bg:#263955;
}

*{ box-sizing:border-box; }

html, body{
  min-height:100%;
}

body{
  margin:0;
  color:var(--text);
  background:
    radial-gradient(1200px 700px at 15% 8%, rgba(47,123,255,.16), transparent 60%),
    radial-gradient(900px 580px at 85% 25%, rgba(34,197,94,.10), transparent 60%),
    linear-gradient(180deg, var(--bg0), var(--bg1));
}

a{
  color:var(--primary);
  text-decoration:none;
}
a:hover{
  text-decoration:underline;
}

.app-shell{
  width:100%;
  max-width:none;
  margin:0 auto;
  padding-left:clamp(12px, 2vw, 28px);
  padding-right:clamp(12px, 2vw, 28px);
}

@media (min-width: 2200px){
  .app-shell{
    padding-left:clamp(18px, 3vw, 44px);
    padding-right:clamp(18px, 3vw, 44px);
  }
}

.page-title,
h1, h2, h3, h4, h5, h6{
  color:var(--heading);
}

.page-title{
  font-size:clamp(2rem, 2.8vw, 3rem);
  font-weight:700;
  line-height:1.1;
  margin-bottom:.8rem;
}

.section-title{
  font-size:1rem;
  font-weight:700;
  color:var(--heading);
  margin:0;
}

.section-subtitle,
.section-total,
.card-counter{
  font-size:.9rem;
  color:var(--muted);
  margin:0;
}

/* ===== Cards ===== */
.card-soft,
.pm-card,
.card{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:var(--radius-lg);
  box-shadow:var(--shadow);
  color:var(--text);
}

.card-header{
  background:var(--section-header-bg);
  border-bottom:1px solid var(--border);
  border-top-left-radius:var(--radius-lg) !important;
  border-top-right-radius:var(--radius-lg) !important;
  color:var(--heading);
  padding:.7rem .9rem;
}

.card-body{
  padding:.85rem .9rem;
}

.card-footer{
  background:transparent;
  border-top:1px solid var(--border);
  padding:.7rem .9rem;
}

.pm-panel{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:var(--radius-lg);
  box-shadow:var(--shadow);
  overflow:hidden;
}

.pm-panel-header,
.pm-section-header,
.section-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:.75rem;
  padding:10px 14px;
  background:var(--section-header-bg);
  border-bottom:1px solid var(--border);
  border-top-left-radius:var(--radius-lg);
  border-top-right-radius:var(--radius-lg);
}

.pm-panel-body,
.pm-section-body{
  padding:12px 14px;
}

.pm-stat-card{
  min-height:82px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:1rem;
  padding:14px 16px;
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:var(--radius-lg);
  box-shadow:var(--shadow);
}

.pm-stat-card .label{
  font-size:.9rem;
  color:var(--muted);
  margin-bottom:.15rem;
}

.pm-stat-card .value{
  font-size:1.85rem;
  font-weight:700;
  line-height:1;
  color:var(--heading);
}

.pm-stat-card .icon{
  font-size:1.85rem;
  color:var(--primary);
}

/* ===== Navbar ===== */
.pillbar .nav-link{
  display:flex;
  align-items:center;
  gap:.45rem;
  padding:.58rem .9rem;
  border-radius:12px;
  border:1px solid transparent;
  color:var(--text);
  font-weight:500;
  transition:all .15s ease;
}

.pillbar .nav-link:hover{
  background:var(--surface-2);
  border-color:var(--border);
  text-decoration:none;
}

.pillbar .nav-link.active{
  background:rgba(47,123,255,.18) !important;
  border-color:rgba(47,123,255,.28) !important;
  color:var(--text) !important;
}

/* ===== Form ===== */
label.form-label,
.form-label{
  font-weight:500;
  color:var(--heading);
  margin-bottom:.32rem;
}

.form-control,
.form-select{
  min-height:38px;
  background:var(--input-bg) !important;
  color:var(--input-text) !important;
  border:1px solid var(--border) !important;
  border-radius:12px !important;
  box-shadow:none !important;
  padding:.42rem .72rem;
}

.form-control::placeholder{
  color:var(--placeholder) !important;
}

.form-control:focus,
.form-select:focus{
  border-color:rgba(47,123,255,.38) !important;
  box-shadow:var(--focus) !important;
}

textarea.form-control{
  min-height:84px;
}

.form-check-input{
  border-color:var(--border-strong);
}

.form-check-input:checked{
  background-color:var(--primary);
  border-color:var(--primary);
}

.form-text,
.text-muted{
  color:var(--muted) !important;
}

/* ===== Tabelas ===== */
.table{
  color:var(--text) !important;
  margin-bottom:0;
  --bs-table-bg: transparent;
  --bs-table-border-color: var(--border);
  --bs-table-color: var(--text);
  background:var(--table-bg);
}

.table thead,
.table thead tr,
.table thead th{
  background:var(--table-head) !important;
  color:var(--heading) !important;
  border-bottom:1px solid var(--border) !important;
  border-top:none !important;
  font-weight:700;
  white-space:nowrap;
  opacity:1 !important;
  backdrop-filter:none !important;
  line-height:1.15;
}

.table th,
.table td{
  padding:.52rem .65rem;
  vertical-align:middle;
  border-color:var(--border) !important;
  background-clip:padding-box;
  line-height:1.2;
}

.table tbody td{
  background:var(--table-bg);
}

.table-hover tbody tr:hover td{
  background:var(--table-row-hover) !important;
}

.table-responsive{
  border-radius:0 0 var(--radius-lg) var(--radius-lg);
  overflow:auto;
}

.pm-table-wrap{
  overflow:hidden;
  border-radius:var(--radius-lg);
  border:1px solid var(--border);
  background:var(--surface);
  box-shadow:var(--shadow);
}

.pm-table-wrap .table{
  margin-bottom:0;
}

.pm-table-wrap .table thead th{
  position:sticky;
  top:0;
  z-index:3;
  background:var(--sticky-head-bg) !important;
}

.pm-actions-col,
th.actions-col,
td.actions-col{
  width:1%;
  white-space:nowrap;
}

.table-actions{
  display:flex;
  align-items:center;
  gap:.35rem;
  flex-wrap:wrap;
  min-width:170px;
}

@media (max-width: 768px){
  .table-actions{
    min-width:auto;
  }
}

/* ===== BOTÕES PADRONIZADOS ===== */
.btn{
  min-height:36px;
  padding:.38rem .82rem;
  border-radius:12px !important;
  font-weight:600;
  font-size:.92rem;
  line-height:1.15;
  box-shadow:none !important;
  transition:all .15s ease;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:.35rem;
}

.btn-sm{
  min-height:30px;
  padding:.28rem .65rem;
  border-radius:10px !important;
  font-size:.84rem;
}

.btn-lg{
  min-height:42px;
  border-radius:14px !important;
}

.btn-primary,
.btn-success,
.btn-danger,
.btn-warning,
.btn-secondary{
  color:var(--btn-text-on-solid) !important;
}

.btn-warning{
  color:var(--btn-warning-text) !important;
}

.btn-primary{
  background:var(--primary) !important;
  border:1px solid var(--primary) !important;
}
.btn-primary:hover,
.btn-primary:focus,
.btn-primary:active{
  background:var(--primary-hover) !important;
  border-color:var(--primary-hover) !important;
  color:var(--btn-text-on-solid) !important;
}

.btn-success{
  background:var(--success) !important;
  border:1px solid var(--success) !important;
}
.btn-success:hover,
.btn-success:focus,
.btn-success:active{
  background:var(--success-hover) !important;
  border-color:var(--success-hover) !important;
  color:var(--btn-text-on-solid) !important;
}

.btn-danger{
  background:var(--danger) !important;
  border:1px solid var(--danger) !important;
}
.btn-danger:hover,
.btn-danger:focus,
.btn-danger:active{
  background:var(--danger-hover) !important;
  border-color:var(--danger-hover) !important;
  color:var(--btn-text-on-solid) !important;
}

.btn-warning{
  background:var(--warning) !important;
  border:1px solid var(--warning) !important;
}
.btn-warning:hover,
.btn-warning:focus,
.btn-warning:active{
  background:var(--warning-hover) !important;
  border-color:var(--warning-hover) !important;
  color:var(--btn-warning-text) !important;
}

.btn-secondary{
  background:var(--secondary) !important;
  border:1px solid var(--secondary) !important;
}
.btn-secondary:hover,
.btn-secondary:focus,
.btn-secondary:active{
  background:var(--secondary-hover) !important;
  border-color:var(--secondary-hover) !important;
  color:#ffffff !important;
}

.btn-outline-primary{
  color:var(--primary) !important;
  border:1px solid var(--primary) !important;
  background:transparent !important;
}
.btn-outline-primary:hover,
.btn-outline-primary:focus,
.btn-outline-primary:active{
  background:rgba(47,123,255,.14) !important;
  color:var(--primary) !important;
  border-color:var(--primary) !important;
}

.btn-outline-success{
  color:var(--success) !important;
  border:1px solid var(--success) !important;
  background:transparent !important;
}
.btn-outline-success:hover,
.btn-outline-success:focus,
.btn-outline-success:active{
  background:rgba(34,197,94,.14) !important;
  color:var(--success) !important;
  border-color:var(--success) !important;
}

.btn-outline-danger{
  color:var(--danger) !important;
  border:1px solid var(--danger) !important;
  background:transparent !important;
}
.btn-outline-danger:hover,
.btn-outline-danger:focus,
.btn-outline-danger:active{
  background:rgba(244,63,94,.14) !important;
  color:var(--danger) !important;
  border-color:var(--danger) !important;
}

.btn-outline-warning{
  color:var(--warning) !important;
  border:1px solid var(--warning) !important;
  background:transparent !important;
}
.btn-outline-warning:hover,
.btn-outline-warning:focus,
.btn-outline-warning:active{
  background:rgba(250,204,21,.14) !important;
  color:var(--warning) !important;
  border-color:var(--warning) !important;
}

.btn-outline-secondary{
  color:var(--secondary) !important;
  border:1px solid var(--secondary) !important;
  background:transparent !important;
}
.btn-outline-secondary:hover,
.btn-outline-secondary:focus,
.btn-outline-secondary:active{
  background:rgba(148,163,184,.14) !important;
  color:var(--secondary) !important;
  border-color:var(--secondary) !important;
}

a.btn,
button.btn,
input.btn,
.btn{
  text-decoration:none !important;
}

.table .btn,
.table-responsive .btn,
.pm-table-wrap .btn{
  min-width:68px;
  min-height:32px;
  padding:.28rem .72rem;
  border-radius:10px !important;
  font-size:.84rem;
}

.btn-edit,
.table .btn-primary,
.pm-table-wrap .btn-primary{
  background:var(--primary) !important;
  border-color:var(--primary) !important;
  color:#ffffff !important;
}

.btn-delete,
.table .btn-danger,
.pm-table-wrap .btn-danger{
  background:transparent !important;
  border-color:var(--danger) !important;
  color:var(--danger) !important;
}

.btn-delete:hover,
.table .btn-danger:hover,
.pm-table-wrap .btn-danger:hover{
  background:rgba(244,63,94,.14) !important;
  color:var(--danger) !important;
}

.btn-promote{
  background:transparent !important;
  border-color:var(--success) !important;
  color:var(--success) !important;
}

.btn-promote:hover{
  background:rgba(34,197,94,.14) !important;
  color:var(--success) !important;
}

.btn-warning,
.btn-dispense{
  background:transparent !important;
  border-color:var(--warning) !important;
  color:var(--warning) !important;
}

.btn-warning:hover,
.btn-dispense:hover{
  background:rgba(250,204,21,.14) !important;
  color:var(--warning) !important;
}

.pm-btn-save{ min-width:108px; }
.pm-btn-action{ min-width:74px; }
.pm-btn-filter{ min-width:88px; }
.pm-btn-clear{ min-width:88px; }

.pm-action-group{
  display:flex;
  align-items:center;
  gap:.35rem;
  flex-wrap:wrap;
}

/* ===== Alerts / badges / modal ===== */
.alert{
  border-radius:var(--radius-md);
  border:1px solid var(--border);
  padding:.65rem .85rem;
}

.badge{
  border-radius:999px;
  font-weight:600;
}

.modal-content{
  background:var(--surface);
  color:var(--text);
  border:1px solid var(--border);
  border-radius:var(--radius-lg);
  box-shadow:var(--shadow);
}

.modal-header{
  border-bottom:1px solid var(--border);
  padding:.75rem .9rem;
}

.modal-body{
  padding:.85rem .9rem;
}

.modal-footer{
  border-top:1px solid var(--border);
  padding:.75rem .9rem;
}

/* ===== Theme switch ===== */
.theme-pill{
  display:flex;
  align-items:center;
  gap:.55rem;
  padding:.32rem .52rem;
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:999px;
  box-shadow:0 10px 26px rgba(0,0,0,.12);
}

.theme-label{
  color:var(--text);
  font-size:.86rem;
  padding:.1rem .38rem;
  border-radius:999px;
  background:var(--surface-2);
  border:1px solid var(--border);
  display:flex;
  align-items:center;
  gap:.35rem;
}

.theme-switch{
  width:44px;
  height:24px;
  border-radius:999px;
  border:1px solid var(--border);
  background:var(--surface-2);
  position:relative;
  cursor:pointer;
  outline:none;
}

.theme-switch:focus{
  box-shadow:var(--focus);
}

.theme-knob{
  width:20px;
  height:20px;
  border-radius:999px;
  background:var(--heading);
  position:absolute;
  top:1px;
  left:1px;
  transition:transform .18s ease;
  opacity:.92;
}

html[data-theme="dark"] .theme-knob{
  transform:translateX(20px);
}

/* ===== Footer ===== */
.footer-crest{
  display:flex;
  justify-content:center;
  margin-top:14px;
  opacity:.92;
}

.footer-crest img{
  max-width:220px;
  width:100%;
  height:auto;
  filter:drop-shadow(0 10px 18px rgba(0,0,0,.35));
}

/* ===== Select ===== */
select.form-select,
select.form-control{
  background-color:var(--input-bg) !important;
  color:var(--input-text) !important;
}

select.form-select option,
select.form-control option{
  background-color:var(--bg1);
  color:var(--text);
}

select.form-select option:checked,
select.form-control option:checked{
  background-color:rgba(47,123,255,.35);
  color:var(--text);
}

/* ===== Tom Select ===== */
.ts-wrapper.form-select,
.ts-wrapper.single .ts-control,
.ts-control{
  background:var(--input-bg) !important;
  color:var(--input-text) !important;
  border-color:var(--border) !important;
  border-radius:12px !important;
}

.ts-wrapper.single .ts-control{
  padding:.38rem .72rem !important;
  min-height:38px !important;
  box-shadow:none !important;
}

.ts-control input{
  color:var(--input-text) !important;
}

.ts-control::placeholder,
.ts-control input::placeholder{
  color:var(--placeholder) !important;
}

.ts-dropdown{
  background:var(--bg1) !important;
  color:var(--text) !important;
  border:1px solid var(--border) !important;
  border-radius:12px !important;
  box-shadow:var(--shadow) !important;
  overflow:hidden;
}

.ts-dropdown .option{
  color:var(--text) !important;
  padding:.42rem .72rem !important;
}

.ts-dropdown .active,
.ts-dropdown .option.active{
  background:rgba(47,123,255,.22) !important;
  color:var(--text) !important;
}

.ts-dropdown .option:hover{
  background:rgba(47,123,255,.14) !important;
}

.ts-wrapper.single.focus .ts-control,
.ts-wrapper.multi.focus .ts-control{
  box-shadow:var(--focus) !important;
  border-color:rgba(47,123,255,.35) !important;
}

.ts-wrapper.single .ts-control:after{
  border-color:var(--muted) transparent transparent transparent !important;
}

select[data-pro-select="1"]{
  visibility:hidden;
  position:absolute;
  pointer-events:none;
}

/* ===== SVG ===== */
.icon-svg{
  width:40px;
  height:40px;
  margin-bottom:10px;
}

[data-theme="light"] .icon-svg{
  filter:brightness(0);
}

[data-theme="dark"] .icon-svg{
  filter:brightness(0) invert(1);
}

/* ===== Compatibilidade ===== */
.session-title,
.block-title,
.list-title{
  font-size:1rem;
  font-weight:700;
  color:var(--heading);
}

.total-label,
.total-count{
  color:var(--muted);
  font-size:.9rem;
}

.actions-nowrap{
  white-space:nowrap;
}

.rounded-soft{
  border-radius:var(--radius-lg) !important;
}

.border-soft{
  border:1px solid var(--border) !important;
}

.bg-soft{
  background:var(--surface) !important;
}

.bg-soft-2{
  background:var(--surface-2) !important;
}

.shadow-soft{
  box-shadow:var(--shadow) !important;
}

hr{
  border-color:var(--border);
  opacity:1;
  margin:.75rem 0;
}

.mb-4{ margin-bottom:1rem !important; }
.mt-4{ margin-top:1rem !important; }
.py-4{ padding-top:1rem !important; padding-bottom:1rem !important; }
.g-4, .gx-4{ --bs-gutter-x: 1rem; }
.g-4, .gy-4{ --bs-gutter-y: 1rem; }
</style>';

    echo '<script>
(function(){
  function applyTheme(theme){
    var html = document.documentElement;
    html.setAttribute("data-theme", theme);
    html.setAttribute("data-bs-theme", theme);
    html.style.colorScheme = theme;

    try { localStorage.setItem("pm_theme", theme); } catch(e) {}

    var label = document.getElementById("themeText");
    var icon  = document.getElementById("themeIcon");

    if (label) label.textContent = (theme === "dark") ? "Escuro" : "Claro";
    if (icon)  icon.className = "bi " + ((theme === "dark") ? "bi-moon-stars" : "bi-sun");
  }

  window.toggleTheme = function(){
    var cur = document.documentElement.getAttribute("data-theme") || "dark";
    applyTheme(cur === "dark" ? "light" : "dark");
  };

  var current = document.documentElement.getAttribute("data-theme") || "dark";
  applyTheme(current);

  window.addEventListener("DOMContentLoaded", function(){
    var sw = document.getElementById("themeSwitch");
    if (!sw) return;

    sw.addEventListener("keydown", function(e){
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        window.toggleTheme();
      }
    });
  });
})();
</script>';

    echo '</head><body>';
    echo '<div class="container-fluid app-shell py-4">';

    echo '<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">';
    echo '<div><div class="fw-bold fs-3">Palmeiras Manager</div></div>';

    echo '<div class="d-flex align-items-center flex-wrap gap-3">';
    echo '<div class="text-muted small">Clube: <span class="fw-semibold">'
        . htmlspecialchars(function_exists("app_club") ? app_club() : "PALMEIRAS", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8")
        . '</span></div>';

    echo '<div class="theme-pill">';
    echo '<span class="theme-label"><i id="themeIcon" class="bi bi-moon-stars"></i> <span id="themeText">Escuro</span></span>';
    echo '<div id="themeSwitch" class="theme-switch" role="button" tabindex="0" aria-label="Alternar tema" onclick="toggleTheme()"><div class="theme-knob"></div></div>';
    echo '</div>';

    echo '</div>';
    echo '</div>';

    echo '<ul class="nav nav-pills pillbar flex-wrap gap-2 mb-4">';
    foreach ($items as $k => [$lbl, $icon]) {
        $active = ($page === $k) ? 'active' : '';
        $href = '/?page=' . urlencode($k);

        echo '<li class="nav-item">';
        echo '<a class="nav-link ' . $active . '" href="' . $href . '">';
        echo '<i class="bi ' . htmlspecialchars($icon, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></i>';
        echo '<span>' . htmlspecialchars($lbl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        echo '</a>';
        echo '</li>';
    }
    echo '</ul>';
}

function render_footer(): void
{
    $crest = '/assets/escudos-inst_3.png';

    echo '<div class="text-center text-muted small mt-4">Palmeiras Manager • ' . date('Y') . '</div>';
    echo '<div class="footer-crest"><img src="' . htmlspecialchars($crest, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="Palmeiras"></div>';

    echo '</div>';

    echo '<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>';
    echo '<script>
(function(){
  function initProSelects(root){
    if (typeof TomSelect === "undefined") return;
    root = root || document;

    var sels = root.querySelectorAll("select[data-pro-select=\\"1\\"]");
    sels.forEach(function(sel){
      if (sel.tomselect) return;

      new TomSelect(sel, {
        create: false,
        sortField: { field: "text", direction: "asc" },
        allowEmptyOption: true,
        dropdownInput: false,
        closeAfterSelect: true,
        controlInput: null
      });
    });
  }

  window.pmInitProSelects = initProSelects;
  document.addEventListener("DOMContentLoaded", function(){
    initProSelects(document);
  });
})();
</script>';

    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>';
    echo '</body></html>';
}