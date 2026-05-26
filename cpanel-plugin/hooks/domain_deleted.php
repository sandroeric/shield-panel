<?php
namespace ShieldPanel\Hooks;

require_once __DIR__ . '/../services/QueueService.php';
use ShieldPanel\Services\QueueService;

function triggerDomainDeletedHook($domainId, $domainName) {
    QueueService::publish('domain.deleted', [
        'event' => 'domain.deleted',
        'domain_id' => (int)$domainId,
        'domain' => $domainName,
        'timestamp' => time()
    ]);
}
