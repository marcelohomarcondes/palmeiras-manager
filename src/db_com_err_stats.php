<?php
// src/db.php
declare(strict_types=1);

/**
 * DB bootstrap (SQLite)
 * - Exponibiliza PDO de forma padronizada:
 *   - $pdo (global)
 *   - função db(): PDO (singleton)
 * - Evita "PDO não disponível" nas páginas
 */

if (!function_exists('db')) {

    function db(): PDO
    {
        static $instance = null;
        if ($instance instanceof PDO) {
            return $instance;
        }

        $dbPath = realpath(__DIR__ . '/../data/app.sqlite');
        if ($dbPath === false) {
            // tenta path direto (caso o arquivo exista mas realpath falhe por permissão/ambiente)
            $dbPath = __DIR__ . '/../data/app.sqlite';
        }

        // DSN SQLite
        $dsn = 'sqlite:' . $dbPath;

        try {
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Recomendado para SQLite
            $pdo->exec("PRAGMA foreign_keys = ON;");
            $pdo->exec("PRAGMA journal_mode = WAL;");
            $pdo->exec("PRAGMA synchronous = NORMAL;");

            $instance = $pdo;

            // Exporta também em $GLOBALS para qualquer página “pegar”
            $GLOBALS['pdo'] = $pdo;

            return $pdo;

        } catch (Throwable $e) {
            // Não deixa tela branca sem pista
            die("Erro ao conectar no SQLite. Verifique data/app.sqlite e permissões. Detalhe: " . $e->getMessage());
        }
    }
}

// Define $pdo global SEMPRE (padrão do projeto)
$pdo = db();
