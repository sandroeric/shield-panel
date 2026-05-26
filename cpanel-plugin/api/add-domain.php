<?php
// ShieldPanel API: Register New Domain

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(455);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/DomainService.php';
require_once __DIR__ . '/../hooks/domain_created.php';

use ShieldPanel\Services\DomainService;
use function ShieldPanel\Hooks\triggerDomainCreatedHook;

$domainName = isset($_POST['domain']) ? trim($_POST['domain']) : '';

if (empty($domainName)) {
    echo json_encode(['error' => 'Domain name is required.']);
    exit;
}

// Basic regex validator for domain format
if (!preg_match("/^[a-zA-Z0-9][a-zA-Z0-9-]{1,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$/", $domainName)) {
    echo json_encode(['error' => 'Invalid domain format. Use e.g. mysite.com']);
    exit;
}

try {
    // Create the domain profile in PostgreSQL
    $domainId = DomainService::create($domainName);
    
    // Trigger lifecycle event hook (publishes domain.created event to RMQ)
    triggerDomainCreatedHook($domainId, $domainName);

    echo json_encode([
        'success' => true,
        'domain_id' => (int)$domainId,
        'domain' => $domainName,
        'message' => 'Domain profile registered successfully.'
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to register domain profile: ' . $e->getMessage()
    ]);
}
