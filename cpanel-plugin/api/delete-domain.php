<?php
// ShieldPanel API: Delete Domain Profile (Soft Delete)

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(455);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/DomainService.php';
require_once __DIR__ . '/../hooks/domain_deleted.php';

use ShieldPanel\Services\DomainService;
use function ShieldPanel\Hooks\triggerDomainDeletedHook;

$domainId = isset($_POST['domain_id']) ? (int)$_POST['domain_id'] : 0;

if ($domainId <= 0) {
    echo json_encode(['error' => 'Invalid or missing domain_id.']);
    exit;
}

try {
    $domain = DomainService::getById($domainId);
    if (!$domain) {
        echo json_encode(['error' => 'Domain profile not found.']);
        exit;
    }

    // Soft delete domain in database
    DomainService::delete($domainId);
    
    // Trigger lifecycle event hook (publishes domain.deleted event to RMQ)
    triggerDomainDeletedHook($domainId, $domain['domain']);

    echo json_encode([
        'success' => true,
        'message' => 'Domain profile deleted successfully.'
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to delete domain profile: ' . $e->getMessage()
    ]);
}
