<?php
// ShieldPanel Settings View Content

use ShieldPanel\Services\DomainService;

// Reload all domains (including status values)
$allDomains = [];
try {
    $allDomains = DomainService::listAll();
} catch (\Exception $e) {
    // Already handled in main container
}
?>

<div class="settings-grid">
    
    <!-- LEFT SIDE: ACTIVE DOMAIN POLICY CONFIG -->
    <div class="settings-column-main">
        <?php if ($selectedDomain): ?>
            <div class="settings-card active-policy">
                <div class="card-header">
                    <h3>🛡️ Security Policy: <?= htmlspecialchars($selectedDomain['domain']) ?></h3>
                    <p>Configure protection status and access rules for the selected profile.</p>
                </div>
                
                <div class="policy-setting-row">
                    <div class="policy-setting-info">
                        <span class="setting-title">WAF Intrusion Protection</span>
                        <p class="setting-desc">Analyze HTTP traffic in real time and automatically parse logs for bot crawling, scraping, and brute-force events.</p>
                    </div>
                    <div class="policy-setting-control">
                        <label class="switch">
                            <input type="checkbox" id="policy-protection-toggle" data-domain-id="<?= $selectedDomainId ?>" <?= $selectedDomain['protection'] ? 'checked' : '' ?>>
                            <span class="slider round"></span>
                        </label>
                    </div>
                </div>

                <hr class="card-divider">

                <div class="policy-setting-row">
                    <div class="policy-setting-info">
                        <span class="setting-title">Domain Lifecycle Control</span>
                        <p class="setting-desc">Simulate hosting workflows that trigger asynchronous hooks (RabbitMQ topic notifications).</p>
                    </div>
                    <div class="policy-setting-control horizontal-actions">
                        <?php if ($selectedDomain['status'] === 'active'): ?>
                            <button class="btn btn-warning btn-hook-action" data-action="suspend" data-domain-id="<?= $selectedDomainId ?>">
                                ⏸️ Suspend Profile
                            </button>
                        <?php else: ?>
                            <button class="btn btn-success btn-hook-action" data-action="activate" data-domain-id="<?= $selectedDomainId ?>">
                                ▶️ Reactivate Profile
                            </button>
                        <?php endif; ?>
                        
                        <button class="btn btn-danger btn-hook-action" data-action="delete" data-domain-id="<?= $selectedDomainId ?>">
                            🗑️ Delete Domain
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- NEW DOMAIN REGISTRATION FORM -->
        <div class="settings-card add-domain">
            <div class="card-header">
                <h3>🌐 Register New Domain Profile</h3>
                <p>Add a new domain to the cPanel hosting configuration to trigger the <code>domain_created</code> hook.</p>
            </div>
            
            <form id="form-add-domain" class="form-horizontal">
                <div class="form-group">
                    <label for="new-domain-name">Domain Name:</label>
                    <div class="input-action-group">
                        <input type="text" id="new-domain-name" placeholder="e.g. mynewsite.org" required pattern="^[a-zA-Z0-9][a-zA-Z0-9-]{1,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$">
                        <button type="submit" class="btn btn-primary">➕ Register Domain</button>
                    </div>
                </div>
            </form>
            <div id="add-domain-msg" class="action-feedback"></div>
        </div>
    </div>

    <!-- RIGHT SIDE: ACCOUNT PROFILE LIST (HOOK WORKFLOW VIEW) -->
    <div class="settings-column-sidebar">
        <div class="settings-card domain-list-card">
            <div class="card-header">
                <h3>📁 Registered Panel Domains</h3>
                <p>Active and suspended domains on this simulated account.</p>
            </div>
            
            <div class="domain-sidebar-list">
                <?php if (empty($allDomains)): ?>
                    <p class="empty-list-text">No registered profiles.</p>
                <?php else: ?>
                    <?php foreach ($allDomains as $d): ?>
                        <div class="domain-item <?= (int)$d['id'] === $selectedDomainId ? 'active-item' : '' ?>">
                            <div class="domain-item-info">
                                <a href="/index.php?page=settings&domain_id=<?= $d['id'] ?>" class="domain-item-link">
                                    <strong class="domain-name-text"><?= htmlspecialchars($d['domain']) ?></strong>
                                </a>
                                <span class="badge badge-mini <?= $d['status'] === 'active' ? 'badge-mini-active' : 'badge-mini-suspended' ?>">
                                    <?= htmlspecialchars($d['status']) ?>
                                </span>
                            </div>
                            <div class="domain-item-actions">
                                <span class="indicator-led <?= $d['protection'] ? 'led-on' : 'led-off' ?>" title="Protection status"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
