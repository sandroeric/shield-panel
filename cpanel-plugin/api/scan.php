<?php
// ShieldPanel API: Trigger Security Scan (Async)

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(455);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/DomainService.php';
require_once __DIR__ . '/../services/QueueService.php';

use ShieldPanel\Services\DomainService;
use ShieldPanel\Services\QueueService;

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

    if ($domain['status'] !== 'active') {
        echo json_encode(['error' => 'Cannot scan a domain that is ' . $domain['status']]);
        exit;
    }

    $db = getDBConnection();
    
    // Insert scan entry as pending
    $stmt = $db->prepare("
        INSERT INTO scans (domain_id, status, threat_score, risk_level, created_at) 
        VALUES (?, 'pending', 0, 'low', CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$domainId]);
    $scanId = $db->lastInsertId();

    // Publish to RabbitMQ exchange shieldpanel.events with routing key scan.requested
    QueueService::publish('scan.requested', [
        'scan_id' => (int)$scanId,
        'domain_id' => $domainId,
        'domain' => $domain['domain'],
        'timestamp' => time()
    ]);

    echo json_encode([
        'success' => true,
        'scan_id' => (int)$scanId,
        'message' => 'Security scan successfully queued.'
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to queue security scan: ' . $e->getMessage()
    ]);
}
