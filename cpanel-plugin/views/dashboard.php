<?php
// ShieldPanel Dashboard View Content

use ShieldPanel\Services\DomainService;

if (!$selectedDomain) {
    echo '<div class="empty-state">
        <div class="empty-icon">🛡️</div>
        <h2>No Domains Registered</h2>
        <p>Go to the Policy Settings page to add your first domain and configure protection policies.</p>
        <a href="/index.php?page=settings" class="btn btn-primary">Go to Settings</a>
    </div>';
    return;
}

$stats = DomainService::getStats($selectedDomainId);
$findings = DomainService::getRecentFindings($selectedDomainId);
$protectionStatus = $selectedDomain['protection'] ? 'ON' : 'OFF';
$scanStatus = $stats['scan_status']; // 'pending', 'running', 'completed'
?>

<!-- DASHBOARD TOP STATS OVERVIEW -->
<div class="dashboard-header-row">
    <div class="domain-overview-card">
        <div class="overview-title">Domain Security Profile</div>
        <h2 class="overview-domain-name"><?= htmlspecialchars($selectedDomain['domain']) ?></h2>
        <div class="overview-meta">
            <span class="badge badge-status <?= $selectedDomain['status'] === 'active' ? 'status-active' : 'status-suspended' ?>">
                Status: <?= strtoupper($selectedDomain['status']) ?>
            </span>
            <span class="badge badge-protection <?= $selectedDomain['protection'] ? 'prot-on' : 'prot-off' ?>" id="protection-badge">
                Protection: <?= $protectionStatus ?>
            </span>
        </div>
    </div>
    
    <!-- Threat Score Circular Progress -->
    <div class="threat-gauge-card">
        <div class="gauge-title">Calculated Threat Level</div>
        <div class="gauge-container">
            <div class="threat-gauge" id="threat-gauge-visual" data-score="<?= $stats['threat_score'] ?>" style="background: conic-gradient(var(--threat-color) <?= $stats['threat_score'] * 3.6 ?>deg, rgba(255, 255, 255, 0.05) 0deg);">
                <div class="gauge-inner">
                    <span class="gauge-number" id="threat-score-num"><?= $stats['threat_score'] ?></span>
                    <span class="gauge-label" id="threat-risk-label"><?= strtoupper($stats['risk_level']) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- THREAT HEURISTICS MATRIX -->
<div class="metrics-grid">
    <div class="metric-card bot-traffic">
        <div class="metric-icon">🤖</div>
        <div class="metric-info">
            <span class="metric-label">Blocked Bot Traffic</span>
            <h3 class="metric-value" id="count-bot"><?= $stats['findings_counts']['bot_traffic'] ?></h3>
        </div>
    </div>
    <div class="metric-card credential-stuffing">
        <div class="metric-icon">🔑</div>
        <div class="metric-info">
            <span class="metric-label">Auth Abuse Warnings</span>
            <h3 class="metric-value" id="count-cred"><?= $stats['findings_counts']['credential_stuffing'] ?></h3>
        </div>
    </div>
    <div class="metric-card xmlrpc-abuse">
        <div class="metric-icon">🔌</div>
        <div class="metric-info">
            <span class="metric-label">XMLRPC Attacks Blocked</span>
            <h3 class="metric-value" id="count-xmlrpc"><?= $stats['findings_counts']['xmlrpc_abuse'] ?></h3>
        </div>
    </div>
    <div class="metric-card scraping">
        <div class="metric-icon">🕵️</div>
        <div class="metric-info">
            <span class="metric-label">Scraping Attempts</span>
            <h3 class="metric-value" id="count-scraping"><?= $stats['findings_counts']['scraping'] ?></h3>
        </div>
    </div>
</div>

<!-- ACTIONS PANEL -->
<div class="actions-panel-card">
    <div class="card-header">
        <h3>🛡️ Async Security Operations</h3>
        <p>Trigger background analysis or inject mock attacks into the log stream to test the heuristic detection engine.</p>
    </div>
    <div class="actions-button-group">
        <button id="btn-trigger-scan" class="btn btn-scan <?= in_array($scanStatus, ['pending', 'running']) ? 'loading' : '' ?>" data-domain-id="<?= $selectedDomainId ?>" <?= in_array($scanStatus, ['pending', 'running']) ? 'disabled' : '' ?>>
            <span class="btn-spinner"></span>
            <span class="btn-text" id="scan-btn-text">
                <?= in_array($scanStatus, ['pending', 'running']) ? 'Analysis In Progress...' : '⚡ Trigger Security Scan' ?>
            </span>
        </button>
        
        <button id="btn-generate-traffic" class="btn btn-traffic" data-domain="<?= htmlspecialchars($selectedDomain['domain']) ?>">
            <span class="btn-text">🔥 Generate Attack Traffic</span>
        </button>
    </div>
    <div class="scan-status-indicator" id="scan-status-indicator" style="display: <?= in_array($scanStatus, ['pending', 'running']) ? 'block' : 'none' ?>;">
        <div class="progress-bar-container">
            <div class="progress-bar-fill animate-pulse"></div>
        </div>
        <p class="status-msg">Go worker is consuming rabbitmq logs queue, running threat metrics...</p>
    </div>
</div>

<!-- SECURITY FINDINGS DETAILED HISTORY -->
<div class="findings-card">
    <div class="card-header">
        <h3>📊 Incident & Event Logs</h3>
        <span class="last-scan-stamp">Last Scanned: <strong id="last-scan-time"><?= $stats['last_scan_at'] ? date('Y-m-d H:i:s', strtotime($stats['last_scan_at'])) : 'Never' ?></strong></span>
    </div>
    
    <div class="findings-table-wrapper">
        <table class="findings-table" id="findings-table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Source IP</th>
                    <th>Threat Category</th>
                    <th>Severity</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($findings)): ?>
                    <tr class="no-findings-row">
                        <td colspan="5">No incidents recorded. Trigger a scan above to analyze recent access log patterns.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($findings as $f): 
                        $details = json_decode($f['details'], true) ?: [];
                        $detailStr = isset($details['message']) ? $details['message'] : '';
                        if (isset($details['path'])) {
                            $detailStr .= ' targeting ' . $details['path'];
                        }
                    ?>
                        <tr class="finding-row">
                            <td class="finding-time"><?= date('Y-m-d H:i:s', strtotime($f['created_at'])) ?></td>
                            <td class="finding-ip"><code><?= htmlspecialchars($f['source_ip']) ?></code></td>
                            <td class="finding-type">
                                <span class="type-indicator font-mono"><?= strtoupper(str_replace('_', ' ', $f['type'])) ?></span>
                            </td>
                            <td>
                                <span class="badge badge-sev badge-sev-<?= htmlspecialchars($f['severity']) ?>">
                                    <?= strtoupper($f['severity']) ?>
                                </span>
                            </td>
                            <td class="finding-details"><?= htmlspecialchars($detailStr) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
