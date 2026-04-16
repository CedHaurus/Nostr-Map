<?php
/**
 * Connexion PDO MySQL — Nostr Map
 * Les variables d'environnement sont injectées par Docker Compose.
 */

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('MYSQL_HOST') ?: 'mysql';
    $db   = getenv('MYSQL_DATABASE') ?: 'nostrmap';
    $user = getenv('MYSQL_USER') ?: 'nostrmap';
    $pass = getenv('MYSQL_PASSWORD') ?: '';

    $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);

    return $pdo;
}
