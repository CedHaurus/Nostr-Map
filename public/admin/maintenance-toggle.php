<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';

// Admin uniquement
requireAdmin('admin');
verifyCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

$flag = '/var/www/html/storage/.maintenance';

if (file_exists($flag)) {
    if (!@unlink($flag)) {
        http_response_code(500);
        echo json_encode([
            'error'  => 'Impossible de supprimer le fichier de maintenance. Vérifier les permissions.',
            'active' => true,
        ]);
        exit;
    }
    $active = false;
    logActivity('maintenance_off', 'system', null, 'Mode maintenance désactivé');
} else {
    if (@file_put_contents($flag, date('Y-m-d H:i:s')) === false) {
        http_response_code(500);
        echo json_encode([
            'error'  => 'Impossible d\'écrire le fichier de maintenance. Vérifier les permissions.',
            'active' => false,
        ]);
        exit;
    }
    @chmod($flag, 0664);
    $active = true;
    logActivity('maintenance_on', 'system', null, 'Mode maintenance activé');
}

echo json_encode(['active' => $active]);
