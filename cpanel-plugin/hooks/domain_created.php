<?php
namespace ShieldPanel\Hooks;

require_once __DIR__ . '/../services/QueueService.php';
use ShieldPanel\Services\QueueService;

function triggerDomainCreatedHook($domainId, $domainName) {
    QueueService::publish('domain.created', [
        'event' => 'domain.created',
        'domain_id' => (int)$domainId,
        'domain' => $domainName,
        'timestamp' => time()
    ]);
}
