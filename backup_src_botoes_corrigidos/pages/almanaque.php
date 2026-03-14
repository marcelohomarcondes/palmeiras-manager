<?php
require_once __DIR__ . '/../layout.php';
render_header('Almanaque');
?>

<style>

/* tamanho dos ícones */
.icon-svg{
width:42px;
height:42px;
margin-bottom:12px;
}

/* tema claro */
[data-theme="light"] .icon-svg{
filter:brightness(0);
}

/* tema escuro */
[data-theme="dark"] .icon-svg{
filter:brightness(0) invert(1);
}

</style>

<div class="container-fluid">

<h2 class="mb-4">Almanaque</h2>

<div class="row g-4">

<!-- Jogadores -->
<div class="col-lg-4">
<div class="card shadow-sm h-100">
<div class="card-body text-center">

<img src="/assets/player.svg" class="icon-svg">

<h5 class="mt-3">Jogadores</h5>

<p>
Histórico completo de todos os atletas que passaram pelo elenco profissional.
</p>

<a href="index.php?page=almanaque_players" class="btn btn-primary">
Acessar
</a>

</div>
</div>
</div>

<!-- Adversários -->
<div class="col-lg-4">
<div class="card shadow-sm h-100">
<div class="card-body text-center">

<img src="/assets/shield.svg" class="icon-svg">

<h5 class="mt-3">Adversários</h5>

<p>
Estatísticas históricas contra todos os adversários enfrentados.
</p>

<a href="index.php?page=almanaque_opponents" class="btn btn-primary">
Acessar
</a>

</div>
</div>
</div>

<!-- Linha do Tempo -->
<div class="col-lg-4">
<div class="card shadow-sm h-100">
<div class="card-body text-center">

<img src="/assets/timeline.svg" class="icon-svg">

<h5 class="mt-3">Linha do Tempo</h5>

<p>
Navegação histórica por décadas e anos com resumo completo das temporadas.
</p>

<a href="index.php?page=almanaque_timeline" class="btn btn-primary">
Acessar
</a>

</div>
</div>
</div>

<!-- Campeonatos -->
<div class="col-lg-4">
<div class="card shadow-sm h-100">
<div class="card-body text-center">

<img src="/assets/trophy.svg" class="icon-svg">

<h5 class="mt-3">Campeonatos</h5>

<p>
Desempenho do clube em cada competição disputada ao longo da história.
</p>

<a href="index.php?page=almanaque_competitions" class="btn btn-primary">
Acessar
</a>

</div>
</div>
</div>

<!-- Estádios -->
<div class="col-lg-4">
<div class="card shadow-sm h-100">
<div class="card-body text-center">

<img src="/assets/stadium.svg" class="icon-svg">

<h5 class="mt-3">Estádios</h5>

<p>
Histórico de jogos por estádio e desempenho do clube em cada um deles.
</p>

<a href="index.php?page=almanaque_stadiums" class="btn btn-primary">
Acessar
</a>

</div>
</div>
</div>

<!-- Árbitros -->
<div class="col-lg-4">
<div class="card shadow-sm h-100">
<div class="card-body text-center">

<img src="/assets/referee.svg" class="icon-svg">

<h5 class="mt-3">Árbitros</h5>

<p>
Histórico de partidas apitadas por cada árbitro.
</p>

<a href="index.php?page=almanaque_referees" class="btn btn-primary">
Acessar
</a>

</div>
</div>
</div>

</div>

</div>

<?php render_footer(); ?>