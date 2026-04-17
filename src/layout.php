<?php
declare(strict_types=1);

/**
 * Layout central do projeto
 * - mantém autenticação obrigatória
 * - preserva tema claro/escuro persistente
 * - usa a tela de login como base visual global
 * - padroniza caixas, listas, tabelas e formulários
 */

if (!function_exists('pm_layout_auth_required')) {
    function pm_layout_auth_required(): bool
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $publicAuthPages = [
            '/login.php',
            '/register.php',
            '/reset_password.php',
            '/logout.php',
        ];

        foreach ($publicAuthPages as $page) {
            if (str_ends_with($script, $page)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('pm_layout_ensure_authenticated')) {
    function pm_layout_ensure_authenticated(): void
    {
        if (!pm_layout_auth_required()) {
            return;
        }

        if (function_exists('auth_start_session')) {
            auth_start_session();
        } elseif (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $loggedIn = false;

        if (function_exists('auth_check')) {
            $loggedIn = auth_check();
        } else {
            $loggedIn = isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
        }

        if (!$loggedIn) {
            header('Location: /login.php');
            exit;
        }
    }
}

if (!function_exists('pm_layout_current_username')) {
    function pm_layout_current_username(): string
    {
        if (function_exists('current_username')) {
            return trim((string) (current_username() ?? ''));
        }

        if (function_exists('auth_username')) {
            return trim((string) (auth_username() ?? ''));
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        return trim((string) ($_SESSION['username'] ?? ''));
    }
}

if (!function_exists('render_header')) {
    function render_header(string $title): void
    {
        pm_layout_ensure_authenticated();

        $page = (string) ($_GET['page'] ?? 'dashboard');
        $username = pm_layout_current_username();

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
        echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>';

        echo '<script>
(function(){
  var theme = "dark";
  try {
    theme = localStorage.getItem("pm_theme") || "dark";
  } catch(e) {}
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
  --bg0:#f2f7f3;
  --bg1:#e8efe9;
  --surface:#ffffff;
  --surface-soft:#f7faf7;
  --surface-strong:#eef5ef;
  --border:rgba(15,23,42,.10);
  --text:#0f172a;
  --muted:#5f6b7a;
  --accent:#16a34a;
  --accent-strong:#15803d;
  --accent-soft:rgba(22,163,74,.10);
  --accent-soft-2:rgba(22,163,74,.16);
  --danger:#dc2626;
  --warning:#f59e0b;
  --shadow:0 20px 50px rgba(15,23,42,.10);
  --shadow-soft:0 12px 30px rgba(15,23,42,.08);
  --input:#ffffff;
  --inputText:#0f172a;
  --placeholder:rgba(15,23,42,.42);
  --tableHead:rgba(15,23,42,.04);
  --tableRowHover:rgba(22,163,74,.05);
  --focus:0 0 0 .25rem rgba(22,163,74,.18);
  --radius-xl:18px;
  --radius-lg:16px;
  --radius-md:14px;
  --space-section:clamp(.85rem,1.3vw,1.1rem);
  --space-card-y:clamp(.8rem,1vw,.95rem);
  --space-card-x:clamp(.9rem,1.2vw,1.05rem);
  --control-h:42px;
  --control-h-sm:34px;
  --topbar-bg:rgba(255,255,255,.72);
}

html[data-theme="dark"]{
  --bg0:#081019;
  --bg1:#0f172a;
  --surface:rgba(17,24,39,.84);
  --surface-soft:rgba(255,255,255,.04);
  --surface-strong:rgba(22,163,74,.08);
  --border:rgba(255,255,255,.10);
  --text:#e5e7eb;
  --muted:#9ca3af;
  --accent:#16a34a;
  --accent-strong:#15803d;
  --accent-soft:rgba(22,163,74,.16);
  --accent-soft-2:rgba(22,163,74,.22);
  --danger:#ef4444;
  --warning:#fbbf24;
  --shadow:0 20px 50px rgba(0,0,0,.35);
  --shadow-soft:0 12px 30px rgba(0,0,0,.28);
  --input:#0b1220;
  --inputText:#e5e7eb;
  --placeholder:rgba(229,231,235,.45);
  --tableHead:rgba(255,255,255,.05);
  --tableRowHover:rgba(22,163,74,.10);
  --focus:0 0 0 .25rem rgba(22,163,74,.20);
  --topbar-bg:rgba(17,24,39,.72);
}

*{box-sizing:border-box;}
html,body{min-height:100%;}
body{
  margin:0;
  background:
    radial-gradient(circle at top, rgba(22,163,74,.18), transparent 28%),
    linear-gradient(180deg, var(--bg0) 0%, var(--bg1) 100%);
  color:var(--text);
}

a{color:var(--accent);text-decoration:none;}
a:hover{text-decoration:underline;}

.app-shell{
  width:100%;
  max-width:none;
  margin:0 auto;
  padding-left:clamp(10px,1.8vw,24px);
  padding-right:clamp(10px,1.8vw,24px);
}

@media (min-width:2200px){
  .app-shell{
    padding-left:clamp(16px,3vw,44px);
    padding-right:clamp(16px,3vw,44px);
  }
}

.card,
.card-soft,
.pm-surface{
  background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.02)), var(--surface);
  border:1px solid var(--border);
  border-radius:var(--radius-xl);
  box-shadow:var(--shadow);
  overflow:hidden;
  backdrop-filter:blur(8px);
}

.card-header,
.pm-card-header{
  background:linear-gradient(180deg, rgba(22,163,74,.14), rgba(22,163,74,.03)) !important;
  color:var(--text) !important;
  border-bottom:1px solid var(--border) !important;
  font-weight:700;
  padding:var(--space-card-y) var(--space-card-x);
}

.card-body{color:var(--text);padding:var(--space-card-y) var(--space-card-x);}
.card-body.p-0{padding:0 !important;}
.card-body.py-0{padding-top:0 !important;padding-bottom:0 !important;}
.card-body.px-0{padding-left:0 !important;padding-right:0 !important;}
.card-footer{background:transparent !important;border-top:1px solid var(--border) !important;}
.text-muted{color:var(--muted) !important;}

.form-label,
label{
  font-size:.92rem;
  font-weight:600;
  color:var(--text);
}

.form-control,
.form-select,
.ts-wrapper.single .ts-control,
.ts-control{
  min-height:var(--control-h);
  background:var(--input) !important;
  color:var(--inputText) !important;
  border:1px solid var(--border) !important;
  border-radius:12px !important;
  box-shadow:none !important;
}

textarea.form-control{min-height:96px;}

.form-control:focus,
.form-select:focus,
.ts-wrapper.single.focus .ts-control,
.ts-wrapper.multi.focus .ts-control{
  border-color:rgba(22,163,74,.52) !important;
  box-shadow:var(--focus) !important;
}

.form-control::placeholder,
.ts-control::placeholder,
.ts-control input::placeholder{color:var(--placeholder) !important;}
.form-check-input{border-color:var(--border) !important;background-color:var(--input) !important;}
.form-check-input:checked{background-color:var(--accent) !important;border-color:var(--accent) !important;}

.table-responsive,
.list-group{
  border-radius:var(--radius-lg);
  overflow:hidden;
}

.table{
  color:var(--text) !important;
  margin-bottom:0;
}

.table thead th{
  background:var(--tableHead) !important;
  color:var(--text) !important;
  border-bottom:1px solid var(--border) !important;
  vertical-align:middle;
  white-space:nowrap;
  font-weight:700;
}

.table td,
.table th{
  border-color:var(--border) !important;
  vertical-align:middle;
  padding:.48rem .8rem;
}

.table tbody tr:hover{background:var(--tableRowHover);}

.list-group-item{
  background:transparent !important;
  color:var(--text) !important;
  border-color:var(--border) !important;
}

.alert{
  border-radius:12px;
  border:1px solid var(--border);
}

.btn{
  min-height:var(--control-h);
  border-radius:12px;
  font-weight:700;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:.45rem;
}

.btn-sm{
  min-height:var(--control-h-sm);
  border-radius:11px;
}

.btn-primary,
.btn-success{
  background:linear-gradient(180deg, var(--accent), var(--accent-strong)) !important;
  border-color:transparent !important;
  color:#fff !important;
}

.btn-primary:hover,
.btn-success:hover{opacity:.96;}

.btn-danger{
  background:linear-gradient(180deg, #ef4444, #dc2626) !important;
  border-color:transparent !important;
}

.btn-warning{
  background:linear-gradient(180deg, #fbbf24, #f59e0b) !important;
  border-color:transparent !important;
  color:#111827 !important;
}

.btn-secondary,
.btn-outline-secondary{
  background:linear-gradient(180deg, rgba(55,65,81,.94), rgba(43,52,65,.94)) !important;
  color:#f9fafb !important;
  border:1px solid rgba(255,255,255,.08) !important;
}

html[data-theme="light"] .btn-secondary,
html[data-theme="light"] .btn-outline-secondary{
  background:linear-gradient(180deg, #eef2f7, #dde5ee) !important;
  color:#1f2937 !important;
  border:1px solid rgba(15,23,42,.10) !important;
}

.btn-outline-light,
.btn-outline-dark{
  border-radius:12px;
}

.badge{
  border-radius:999px;
  padding:.5em .78em;
  font-weight:700;
}

.bg-success-subtle{background:rgba(22,163,74,.14) !important;}
.text-success{color:#15803d !important;}
html[data-theme="dark"] .text-success{color:#86efac !important;}
.bg-danger-subtle{background:rgba(239,68,68,.16) !important;}
.text-danger{color:#dc2626 !important;}
html[data-theme="dark"] .text-danger{color:#fca5a5 !important;}
.bg-warning-subtle{background:rgba(245,158,11,.18) !important;}
.text-warning{color:#b45309 !important;}
html[data-theme="dark"] .text-warning{color:#fde68a !important;}

.pm-page-title{font-size:clamp(1.45rem,2vw,1.72rem);font-weight:800;line-height:1.15;margin-bottom:.75rem;}
.pm-section-title{font-size:1rem;font-weight:700;margin-bottom:.65rem;}
.pm-feature-card{text-align:center;height:100%;}
.pm-feature-card .card-body{padding:1.05rem 1rem;}
.pm-feature-card p{color:var(--muted);margin-bottom:.75rem;line-height:1.45;}

.pm-topbar{
  background:var(--topbar-bg);
  border:1px solid var(--border);
  border-radius:18px;
  box-shadow:var(--shadow-soft);
  padding:.8rem .95rem;
  backdrop-filter:blur(12px);
}

.pm-brand-logo-wrap{
  display:flex;
  align-items:center;
  height:56px;
  overflow:visible;
}

.pm-brand-logo{
  display:block;
  height:120px;
  width:auto;
  object-fit:contain;
}

.pillbar{
  background:transparent;
  padding:0;
}

.pillbar .nav-link{
  min-height:40px;
  display:inline-flex;
  align-items:center;
  border-radius:14px;
  border:1px solid transparent;
  color:var(--text);
  background:rgba(255,255,255,.02);
  font-weight:600;
}

.pillbar .nav-link:hover{
  background:var(--surface-soft);
  border-color:var(--border);
  text-decoration:none;
}

.pillbar .nav-link.active{
  background:linear-gradient(180deg, rgba(22,163,74,.20), rgba(22,163,74,.10)) !important;
  border-color:rgba(22,163,74,.28) !important;
  color:var(--text) !important;
  box-shadow:inset 0 0 0 1px rgba(22,163,74,.08);
}

.theme-pill,
.topbar-user,
.topbar-club{
  display:flex;
  align-items:center;
  gap:.55rem;
  min-height:36px;
  padding:.34rem .66rem;
  background:var(--surface-soft);
  border:1px solid var(--border);
  border-radius:999px;
  color:var(--text);
  box-shadow:0 8px 20px rgba(0,0,0,.10);
  white-space:nowrap;
}

.topbar-user i,
.topbar-club i{color:var(--accent);}

.theme-label{
  color:var(--text);
  font-size:.9rem;
  display:flex;
  align-items:center;
  gap:.42rem;
}

.theme-switch{
  width:44px;
  height:24px;
  border-radius:999px;
  border:1px solid var(--border);
  background:rgba(255,255,255,.06);
  position:relative;
  cursor:pointer;
  outline:none;
}

html[data-theme="light"] .theme-switch{background:rgba(15,23,42,.06);}
.theme-switch:focus{box-shadow:var(--focus);}
.theme-knob{
  width:20px;
  height:20px;
  border-radius:999px;
  background:linear-gradient(180deg, #ffffff, #d1d5db);
  position:absolute;
  top:1px;
  left:1px;
  transition:transform .18s ease;
  box-shadow:0 4px 10px rgba(0,0,0,.18);
}
html[data-theme="dark"] .theme-knob{transform:translateX(20px);background:linear-gradient(180deg, #bbf7d0, #16a34a);}

.footer-crest{display:flex;justify-content:center;margin-top:12px;opacity:.92;}
.footer-crest img{max-width:220px;width:100%;height:auto;filter:drop-shadow(0 10px 18px rgba(0,0,0,.35));}

select.form-select,
select.form-control{background-color:var(--input) !important;color:var(--inputText) !important;}
select.form-select option,
select.form-control option{background-color:var(--bg1);color:var(--text);}
select.form-select option:checked,
select.form-control option:checked{background-color:rgba(22,163,74,.28);color:var(--text);}

.ts-wrapper.form-select{padding:0 !important;border:none !important;background:transparent !important;}
.ts-wrapper.single .ts-control{padding:.36rem .72rem !important;min-height:var(--control-h) !important;}
.ts-dropdown{
  background:var(--surface) !important;
  color:var(--text) !important;
  border:1px solid var(--border) !important;
  border-radius:14px !important;
  box-shadow:var(--shadow) !important;
  overflow:hidden;
}
.ts-dropdown .option{color:var(--text) !important;}
.ts-dropdown .active,
.ts-dropdown .option.active{background:rgba(22,163,74,.18) !important;color:var(--text) !important;}
.ts-dropdown .option:hover{background:rgba(22,163,74,.12) !important;}
.ts-wrapper.single .ts-control:after{border-color:var(--muted) transparent transparent transparent !important;}
select[data-pro-select="1"]{visibility:hidden;position:absolute;pointer-events:none;}

.icon-svg{width:40px;height:40px;margin-bottom:12px;}
[data-theme="light"] .icon-svg{filter:brightness(0) saturate(100%) invert(24%) sepia(90%) saturate(946%) hue-rotate(92deg) brightness(94%) contrast(88%);}
[data-theme="dark"] .icon-svg{filter:brightness(0) saturate(100%) invert(82%) sepia(45%) saturate(431%) hue-rotate(75deg) brightness(96%) contrast(90%);}

.topbar-actions{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;justify-content:flex-end;}

@media (max-width: 991.98px){
  .pm-topbar{padding:.95rem;}
  .pm-brand-logo{max-height:50px;}
  .topbar-actions{justify-content:flex-start;}
}

.row{--bs-gutter-y:1rem;}
.g-4,.gx-4{--bs-gutter-x:1.1rem;}
.g-4,.gy-4{--bs-gutter-y:1.1rem;}
.g-3,.gx-3{--bs-gutter-x:1rem;}
.g-3,.gy-3{--bs-gutter-y:1rem;}
.mb-4{margin-bottom:1.1rem !important;}
.mt-4{margin-top:1.1rem !important;}
.py-4{padding-top:1.1rem !important;padding-bottom:1.1rem !important;}
.py-3{padding-top:.9rem !important;padding-bottom:.9rem !important;}
@media (max-width: 991.98px){
  .app-shell{padding-left:12px;padding-right:12px;}
  .pm-topbar{padding:.72rem .8rem;}
  .pm-brand-logo{max-height:42px;}
  .theme-pill,.topbar-user,.topbar-club{min-height:34px;padding:.3rem .58rem;}
  .table td,.table th{padding:.44rem .7rem;}
}
@media (max-width: 575.98px){
  .card-header,.pm-card-header,.card-body{padding:.78rem .82rem;}
  .pm-page-title{margin-bottom:.6rem;}
  .row{--bs-gutter-y:.85rem;}
}
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

        echo '<div class="pm-topbar d-flex align-items-center justify-content-between mb-3 flex-wrap gap-3">';
        echo '<div class="pm-brand-logo-wrap">';
        echo '<img src="/assets/palmeiras_manager_full.png" alt="Palmeiras Manager" class="pm-brand-logo">';
        echo '</div>';

        echo '<div class="topbar-actions">';

        if ($username !== '') {
            echo '<div class="topbar-user">';
            echo '<i class="bi bi-person-circle"></i>';
            echo '<span>' . htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
            echo '</div>';
        }

        echo '<div class="topbar-club">';
        echo '<i class="bi bi-shield-fill-check"></i>';
        echo '<span>Clube: <strong>' .
            htmlspecialchars(function_exists('app_club') ? app_club() : 'PALMEIRAS', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
            '</strong></span>';
        echo '</div>';

        echo '<div class="theme-pill">';
        echo '<span class="theme-label"><i id="themeIcon" class="bi bi-moon-stars"></i> <span id="themeText">Escuro</span></span>';
        echo '<div id="themeSwitch" class="theme-switch" role="button" tabindex="0" aria-label="Alternar tema" onclick="toggleTheme()"><div class="theme-knob"></div></div>';
        echo '</div>';

        echo '<a class="btn btn-danger btn-sm" href="/logout.php"><i class="bi bi-box-arrow-right"></i><span>Sair</span></a>';
        echo '</div>';
        echo '</div>';

        echo '<ul class="nav nav-pills pillbar flex-wrap gap-2 mb-4">';
        foreach ($items as $k => [$lbl, $icon]) {
            $active = ($page === $k) ? 'active' : '';
            $href = '/?page=' . urlencode($k);
            echo '<li class="nav-item">';
            echo '<a class="nav-link ' . $active . '" href="' . $href . '">';
            echo '<i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . ' me-2"></i>' .
                htmlspecialchars($lbl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '</a>';
            echo '</li>';
        }
        echo '</ul>';
    }
}

if (!function_exists('render_footer')) {
    function render_footer(): void
    {
        $crest = '/assets/palmeiras_manager.png';

        echo '<div class="text-center text-muted small mt-4">Palmeiras Manager • ' . date('Y') . '</div>';
        echo '<div class="footer-crest"><img src="' . htmlspecialchars($crest, ENT_QUOTES, 'UTF-8') . '" alt="Palmeiras"></div>';

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
  document.addEventListener("DOMContentLoaded", function(){ initProSelects(document); });
})();
</script>';
        echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>';
        echo '</body></html>';
    }
}