<?php
// src/bootstrap.php
declare(strict_types=1);

/**
 * Bootstrap do projeto
 * - Carrega db.php e garante $pdo disponível
 */

require_once __DIR__ . '/db.php';

// redundância segura: se db.php já setou, ok.
// se alguma outra coisa mexer, ainda garantimos.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = db();
}
