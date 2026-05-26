<?php
namespace ShieldPanel\Hooks;

require_once __DIR__ . '/../services/QueueService.php';
use ShieldPanel\Services\QueueService;

function triggerAccountSuspendedHook($domainId, $domainName) {
    QueueService::publish('account.suspended', [
        'event' => 'account.suspended',
        'domain_id' => (int)$domainId,
        'domain' => $domainName,
        'timestamp' => time()
    ]);
}
