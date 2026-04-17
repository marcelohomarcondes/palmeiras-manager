<?php
require_once __DIR__ . '/auth.php';

if (!auth_check()) {
    header('Location: /login.php');
    exit;
}