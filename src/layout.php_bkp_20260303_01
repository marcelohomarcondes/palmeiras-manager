<?php
declare(strict_types=1);

/**
 * Layout central (header/footer) - PRO
 * - Tema claro/escuro persistente (localStorage)
 * - Aplica o tema ANTES de renderizar (evita flash)
 * - Variáveis CSS por tema (contraste correto)
 * - Selects legíveis no modo escuro
 * - Footer com imagem estável (public/assets/...)
 *
 * Requisito de execução:
 *   php -S localhost:8000 -t public
 *
 * Salve a imagem do rodapé em:
 *   public/assets/escudos-inst_3.png
 */

function render_header(string $title): void {
  $page = (string)($_GET['page'] ?? 'dashboard');

  $items = [
    'dashboard'  => ['Dashboard', 'bi-speedometer2'],
    'matches'    => ['Partidas', 'bi-calendar3'],
    'players'    => ['Elenco', 'bi-people'],
    'crias'      => ['Crias Da Academia', 'bi-mortarboard'],
    'templates'  => ['Templates', 'bi-layout-text-window'],
    'transfers'  => ['Transferências', 'bi-arrow-left-right'],
    'injuries'   => ['Lesões', 'bi-bandaid'],
    'trophies'   => ['Troféus', 'bi-trophy'],
    'opponents'  => ['Vs Adversários', 'bi-bar-chart'],
    'stats'      => ['Relatórios', 'bi-graph-up'],
  ];

  // ========= HEAD =========
  echo '<!doctype html><html lang="pt-br"><head>';
  echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>';

  // Pré-script: define tema ANTES do CSS/paint (evita flash e quebra de cores)
  echo '<script>
(function(){
  var theme = "dark";
  try { theme = localStorage.getItem("pm_theme") || "dark"; } catch(e) {}
  if (theme !== "dark" && theme !== "light") theme = "dark";
  var html = document.documentElement;
  html.setAttribute("data-theme", theme);
  html.setAttribute("data-bs-theme", theme);
  // Ajuda widgets nativos
  html.style.colorScheme = theme;
})();
</script>';

  echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
  echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">';
  echo '<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">';

  // ========= CSS (PRO) =========
  echo '<style>
:root{
  --bg0:#f6f8fc;
  --bg1:#ffffff;
  --card:#ffffff;
  --card2:#f1f5ff;
  --border:rgba(0,0,0,.10);
  --text:#0b1220;
  --muted:rgba(11,18,32,.68);
  --shadow:0 10px 30px rgba(0,0,0,.08);
  --accent:#2f6fff;
  --accent2:#16a34a;
  --danger:#ef4444;

  --input:#ffffff;
  --inputText:#0b1220;
  --placeholder:rgba(11,18,32,.45);
  --tableHead:rgba(0,0,0,.04);
  --focus: 0 0 0 .25rem rgba(47,111,255,.18);
}
html[data-theme="dark"]{
  --bg0:#071226;
  --bg1:#081a33;
  --card:rgba(255,255,255,.06);
  --card2:rgba(255,255,255,.04);
  --border:rgba(255,255,255,.12);
  --text:rgba(255,255,255,.92);
  --muted:rgba(255,255,255,.62);
  --shadow:0 14px 42px rgba(0,0,0,.45);

  --accent:#3b82f6;
  --accent2:#22c55e;
  --danger:#fb7185;

  --input:rgba(255,255,255,.06);
  --inputText:rgba(255,255,255,.92);
  --placeholder:rgba(255,255,255,.45);
  --tableHead:rgba(255,255,255,.06);
  --focus: 0 0 0 .25rem rgba(59,130,246,.22);
}

* { box-sizing: border-box; }
body{
  background: radial-gradient(1200px 700px at 20% 10%, rgba(59,130,246,.18), transparent 60%),
              radial-gradient(900px 600px at 80% 30%, rgba(34,197,94,.12), transparent 60%),
              linear-gradient(180deg, var(--bg0), var(--bg1));
  color: var(--text);
  min-height:100vh;
}

a{ color: var(--accent); text-decoration:none; }
a:hover{ text-decoration:underline; }

/* ✅ FIX GLOBAL: ocupa a largura útil do monitor (sem “cantos vazios”) */
.app-shell{
  width: 100%;
  max-width: none;
  margin: 0 auto;
  padding-left: clamp(12px, 2vw, 28px);
  padding-right: clamp(12px, 2vw, 28px);
}

/* Opcional: em telas MUITO largas, aumenta um pouco o respiro */
@media (min-width: 2200px){
  .app-shell{
    padding-left: clamp(16px, 3vw, 44px);
    padding-right: clamp(16px, 3vw, 44px);
  }
}

.card-soft{
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 18px;
  box-shadow: var(--shadow);
}

.pillbar .nav-link{
  border-radius: 14px;
  border: 1px solid transparent;
  color: var(--text);
}
.pillbar .nav-link:hover{
  background: var(--card2);
  border-color: var(--border);
}
.pillbar .nav-link.active{
  background: rgba(59,130,246,.22) !important;
  border-color: rgba(59,130,246,.28) !important;
  color: var(--text) !important;
}

.text-muted{ color: var(--muted) !important; }

.form-control, .form-select{
  background: var(--input) !important;
  color: var(--inputText) !important;
  border-color: var(--border) !important;
  border-radius: 14px;
}
.form-control:focus, .form-select:focus{
  box-shadow: var(--focus) !important;
  border-color: rgba(59,130,246,.35) !important;
}
.form-control::placeholder{
  color: var(--placeholder) !important;
}
.form-control:disabled, .form-select:disabled{
  opacity:.75;
}

.table{
  color: var(--text) !important;
}
.table thead th{
  background: var(--tableHead) !important;
  color: var(--text) !important;
  border-bottom: 1px solid var(--border) !important;
}
.table td, .table th{
  border-color: var(--border) !important;
}

.alert{
  border-radius: 16px;
  border-color: var(--border);
}

.btn{
  border-radius: 14px;
}
.btn-success{
  background: var(--accent2) !important;
  border-color: rgba(0,0,0,0) !important;
}
.btn-danger{
  background: var(--danger) !important;
  border-color: rgba(0,0,0,0) !important;
}
.btn-outline-light{
  border-radius: 14px;
}

/* Toggle PRO */
.theme-pill{
  display:flex;
  align-items:center;
  gap:.55rem;
  padding:.38rem .6rem;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 999px;
  box-shadow: 0 10px 26px rgba(0,0,0,.12);
}
.theme-label{
  color: var(--text);
  font-size: .9rem;
  padding: .12rem .42rem;
  border-radius: 999px;
  background: var(--card2);
  border: 1px solid var(--border);
  display:flex;
  align-items:center;
  gap:.4rem;
}
.theme-switch{
  width: 46px;
  height: 26px;
  border-radius: 999px;
  border: 1px solid var(--border);
  background: var(--card2);
  position: relative;
  cursor:pointer;
  outline: none;
}
.theme-switch:focus{
  box-shadow: var(--focus);
}
.theme-knob{
  width: 22px;
  height: 22px;
  border-radius: 999px;
  background: var(--text);
  position:absolute;
  top: 1px;
  left: 1px;
  transition: transform .18s ease;
  opacity:.9;
}
html[data-theme="dark"] .theme-knob{ transform: translateX(20px); }

/* Rodapé */
.footer-crest{
  display:flex;
  justify-content:center;
  margin-top: 16px;
  opacity: .92;
}
.footer-crest img{
  max-width: 220px;
  width: 100%;
  height:auto;
  filter: drop-shadow(0 10px 18px rgba(0,0,0,.35));
}

/* Selects: melhora MUITO no modo escuro */
select.form-select, select.form-control {
  background-color: var(--input) !important;
  color: var(--inputText) !important;
}
/* Alguns browsers respeitam */
select.form-select option,
select.form-control option {
  background-color: var(--bg1);
  color: var(--text);
}
select.form-select option:checked,
select.form-control option:checked {
  background-color: rgba(59,130,246,.35);
  color: var(--text);
}
  /* ===== Tom Select (PRO) ===== */
.ts-wrapper.form-select, .ts-wrapper.single .ts-control, .ts-control {
  background: var(--input) !important;
  color: var(--inputText) !important;
  border-color: var(--border) !important;
  border-radius: 14px !important;
}

.ts-wrapper.single .ts-control {
  padding: .375rem .75rem !important; /* combina com bootstrap */
  min-height: calc(1.5em + .75rem + 2px) !important;
  box-shadow: none !important;
}

.ts-control input {
  color: var(--inputText) !important;
}

.ts-control::placeholder,
.ts-control input::placeholder {
  color: var(--placeholder) !important;
}

/* dropdown */
.ts-dropdown {
  background: var(--bg1) !important;
  color: var(--text) !important;
  border: 1px solid var(--border) !important;
  border-radius: 14px !important;
  box-shadow: var(--shadow) !important;
  overflow: hidden;
}

.ts-dropdown .option {
  color: var(--text) !important;
}

.ts-dropdown .active,
.ts-dropdown .option.active {
  background: rgba(59,130,246,.22) !important;
  color: var(--text) !important;
}

.ts-dropdown .option:hover {
  background: rgba(59,130,246,.16) !important;
}

/* foco */
.ts-wrapper.single.focus .ts-control,
.ts-wrapper.multi.focus .ts-control {
  box-shadow: var(--focus) !important;
  border-color: rgba(59,130,246,.35) !important;
}

/* seta */
.ts-wrapper.single .ts-control:after {
  border-color: var(--muted) transparent transparent transparent !important;
}

/* remove o select nativo “por trás” visualmente (tom-select já faz, mas reforça) */
select[data-pro-select="1"] {
  visibility: hidden;
  position: absolute;
  pointer-events: none;
}
</style>';

  // ========= JS (toggle robusto) =========
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

  // Sincroniza label/ícone com o tema já aplicado no pré-script
  var current = document.documentElement.getAttribute("data-theme") || "dark";
  applyTheme(current);

  // Teclado no switch
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

  // ✅ FIX: container-fluid para NÃO limitar largura (Bootstrap container limita)
  echo '<div class="container-fluid app-shell py-4">';

  // ========= Topbar =========
  echo '<div class="d-flex align-items-center justify-content-between mb-3">';
  echo '<div>';
  echo '<div class="fw-bold fs-3">Palmeiras Manager</div>';
  // echo '<div class="text-muted small">PRO • PHP + SQLite</div>';
  echo '</div>';

  echo '<div class="d-flex align-items-center gap-3">';
  echo '<div class="text-muted small">Clube: <span class="fw-semibold">' .
       htmlspecialchars(function_exists("app_club") ? app_club() : "PALMEIRAS", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8")
       . '</span></div>';

  echo '<div class="theme-pill">';
  echo '<span class="theme-label"><i id="themeIcon" class="bi bi-moon-stars"></i> <span id="themeText">Escuro</span></span>';
  echo '<div id="themeSwitch" class="theme-switch" role="button" tabindex="0" aria-label="Alternar tema" onclick="toggleTheme()"><div class="theme-knob"></div></div>';
  echo '</div>';

  echo '</div>';
  echo '</div>';

  // ========= Navbar =========
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

function render_footer(): void {
  // Imagem no rodapé: caminho absoluto a partir do root do site (/public)
  // Salvar em: public/assets/escudos-inst_3.png
  $crest = '/assets/escudos-inst_3.png';

  echo '<div class="text-center text-muted small mt-4">Palmeiras Manager • ' . date('Y') . '</div>';
  echo '<div class="footer-crest"><img src="' . htmlspecialchars($crest, ENT_QUOTES, 'UTF-8') . '" alt="Palmeiras"></div>';

  echo '</div>'; // container-fluid (app-shell)
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
          controlInput: null, // evita input “editável” em select simples
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
