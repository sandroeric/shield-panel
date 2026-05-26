<?php
// ShieldPanel API: Get Domain Security Status

header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/DomainService.php';

use ShieldPanel\Services\DomainService;

$domainId = isset($_GET['domain_id']) ? (int)$_GET['domain_id'] : 0;

if ($domainId <= 0) {
    echo json_encode(['error' => 'Invalid or missing domain_id parameter.']);
    exit;
}

try {
    $domain = DomainService::getById($domainId);
    if (!$domain) {
        echo json_encode(['error' => 'Domain not found.']);
        exit;
    }

    $stats = DomainService::getStats($domainId);
    $findings = DomainService::getRecentFindings($domainId, 25);

    echo json_encode([
        'success' => true,
        'domain_id' => $domainId,
        'domain' => $domain['domain'],
        'status' => $domain['status'],
        'protection' => (bool)$domain['protection'],
        'scan_status' => $stats['scan_status'],
        'threat_score' => $stats['threat_score'],
        'risk_level' => $stats['risk_level'],
        'last_scan_at' => $stats['last_scan_at'],
        'findings_counts' => $stats['findings_counts'],
        'findings' => $findings
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve domain security status: ' . $e->getMessage()
    ]);
}
