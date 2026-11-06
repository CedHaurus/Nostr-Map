<?php
/**
 * GET /api/status.php
 * Endpoint de statut minimal pour la découverte d'API.
 */

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    jsonError('Méthode non autorisée', 405);
}

try {
    $db = getDB();
    $db->query('SELECT 1');

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'ok',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
