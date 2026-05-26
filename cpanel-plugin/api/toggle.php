<?php
// ShieldPanel API: Toggle Protection Policy or Profile Lifecycle

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(455);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/DomainService.php';
require_once __DIR__ . '/../services/QueueService.php';
require_once __DIR__ . '/../hooks/account_suspended.php';

use ShieldPanel\Services\DomainService;
use ShieldPanel\Services\QueueService;
use function ShieldPanel\Hooks\triggerAccountSuspendedHook;

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

    // Handle Protection Toggle
    if (isset($_POST['protection'])) {
        $protectionEnabled = (int)$_POST['protection'] === 1;
        DomainService::toggleProtection($domainId, $protectionEnabled);
        
        $routingKey = $protectionEnabled ? 'protection.enabled' : 'protection.disabled';
        QueueService::publish($routingKey, [
            'event' => $routingKey,
            'domain_id' => $domainId,
            'domain' => $domain['domain'],
            'timestamp' => time()
        ]);

        echo json_encode(['success' => true, 'protection' => $protectionEnabled]);
        exit;
    }

    // Handle Status Action Toggle (Suspend / Reactivate)
    if (isset($_POST['status_action'])) {
        $action = $_POST['status_action'];
        
        if ($action === 'suspend') {
            DomainService::suspend($domainId);
            
            // Trigger account_suspended hook (publishes account.suspended event)
            triggerAccountSuspendedHook($domainId, $domain['domain']);
            
            echo json_encode(['success' => true, 'status' => 'suspended']);
            exit;
        } elseif ($action === 'activate') {
            $db = getDBConnection();
            $stmt = $db->prepare("UPDATE domains SET status = 'active' WHERE id = ?");
            $stmt->execute([$domainId]);
            
            // Publish status reactivated
            QueueService::publish('domain.created', [
                'event' => 'domain.created',
                'domain_id' => $domainId,
                'domain' => $domain['domain'],
                'timestamp' => time()
            ]);
            
            echo json_encode(['success' => true, 'status' => 'active']);
            exit;
        } else {
            echo json_encode(['error' => 'Invalid status_action. Use suspend or activate.']);
            exit;
        }
    }

    echo json_encode(['error' => 'Missing parameter: protection or status_action.']);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to execute toggle command: ' . $e->getMessage()
    ]);
}
