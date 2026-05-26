// ShieldPanel cPanel Plugin Interactivity & AJAX Manager

document.addEventListener('DOMContentLoaded', () => {
    
    // Elements
    const btnTriggerScan = document.getElementById('btn-trigger-scan');
    const btnGenerateTraffic = document.getElementById('btn-generate-traffic');
    const policyProtectionToggle = document.getElementById('policy-protection-toggle');
    const formAddDomain = document.getElementById('form-add-domain');
    const domainSelector = document.getElementById('domain-selector');
    const scanStatusIndicator = document.getElementById('scan-status-indicator');
    
    let pollInterval = null;
    const selectedDomainId = domainSelector ? parseInt(domainSelector.value) : 0;

    // --- UTILITIES ---
    function showFeedback(elementId, message, isSuccess = true) {
        const el = document.getElementById(elementId);
        if (!el) return;
        el.textContent = message;
        el.className = 'action-feedback ' + (isSuccess ? 'text-success' : 'text-danger');
        setTimeout(() => { el.textContent = ''; }, 4000);
    }

    // Dynamic color picker for threat levels
    function getThreatColor(score) {
        if (score <= 30) return '#10b981'; // Green
        if (score <= 60) return '#f59e0b'; // Orange
        return '#ef4444'; // Red
    }

    // Animate and update the conic-gradient threat score dial
    function updateThreatGauge(score, risk) {
        const gauge = document.getElementById('threat-gauge-visual');
        const scoreNum = document.getElementById('threat-score-num');
        const riskLabel = document.getElementById('threat-risk-label');
        if (!gauge || !scoreNum || !riskLabel) return;

        const color = getThreatColor(score);
        gauge.style.background = `conic-gradient(${color} ${score * 3.6}deg, rgba(255, 255, 255, 0.05) 0deg)`;
        gauge.style.setProperty('--threat-color', color);
        scoreNum.textContent = score;
        riskLabel.textContent = risk.toUpperCase();
        riskLabel.style.color = color;
    }

    // --- SCAN POLLING LOOP ---
    function startPollingStatus() {
        if (pollInterval) clearInterval(pollInterval);
        
        pollInterval = setInterval(() => {
            fetch(`/api/status.php?domain_id=${selectedDomainId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        console.error("Status check failed:", data.error);
                        return;
                    }

                    // Update metrics numbers
                    document.getElementById('count-bot').textContent = data.findings_counts.bot_traffic;
                    document.getElementById('count-cred').textContent = data.findings_counts.credential_stuffing;
                    document.getElementById('count-xmlrpc').textContent = data.findings_counts.xmlrpc_abuse;
                    document.getElementById('count-scraping').textContent = data.findings_counts.scraping;

                    // Update threat score dial
                    updateThreatGauge(data.threat_score, data.risk_level);

                    // Update last scan timestamp
                    if (data.last_scan_at) {
                        const date = new Date(data.last_scan_at);
                        // Format YYYY-MM-DD HH:MM:SS
                        const formatted = date.getFullYear() + '-' + 
                            String(date.getMonth() + 1).padStart(2, '0') + '-' + 
                            String(date.getDate()).padStart(2, '0') + ' ' + 
                            String(date.getHours()).padStart(2, '0') + ':' + 
                            String(date.getMinutes()).padStart(2, '0') + ':' + 
                            String(date.getSeconds()).padStart(2, '0');
                        document.getElementById('last-scan-time').textContent = formatted;
                    }

                    // Render findings table rows
                    const tbody = document.querySelector('#findings-table tbody');
                    if (tbody) {
                        if (data.findings.length === 0) {
                            tbody.innerHTML = `<tr class="no-findings-row"><td colspan="5">No incidents recorded. Trigger a scan above to analyze recent access log patterns.</td></tr>`;
                        } else {
                            tbody.innerHTML = data.findings.map(f => {
                                const details = JSON.parse(f.details) || {};
                                let detailStr = details.message || '';
                                if (details.path) {
                                    detailStr += ' targeting ' + details.path;
                                }
                                const dateStr = new Date(f.created_at).toISOString().replace('T', ' ').substring(0, 19);
                                return `
                                    <tr class="finding-row">
                                        <td class="finding-time">${dateStr}</td>
                                        <td class="finding-ip"><code>${f.source_ip || '-'}</code></td>
                                        <td class="finding-type">
                                            <span class="type-indicator font-mono">${f.type.replace('_', ' ').toUpperCase()}</span>
                                        </td>
                                        <td>
                                            <span class="badge badge-sev badge-sev-${f.severity}">
                                                ${f.severity.toUpperCase()}
                                            </span>
                                        </td>
                                        <td class="finding-details">${detailStr}</td>
                                    </tr>
                                `;
                            }).join('');
                        }
                    }

                    // Check if scan completed
                    if (data.scan_status === 'completed' || data.scan_status === 'failed') {
                        clearInterval(pollInterval);
                        pollInterval = null;
                        
                        // Restore buttons
                        if (btnTriggerScan) {
                            btnTriggerScan.disabled = false;
                            btnTriggerScan.classList.remove('loading');
                            document.getElementById('scan-btn-text').textContent = '⚡ Trigger Security Scan';
                        }
                        if (scanStatusIndicator) {
                            scanStatusIndicator.style.display = 'none';
                        }
                    }
                })
                .catch(err => console.error("Error polling scan status:", err));
        }, 2000);
    }

    // --- TRIGGER SCAN ACTION ---
    if (btnTriggerScan) {
        btnTriggerScan.addEventListener('click', () => {
            btnTriggerScan.disabled = true;
            btnTriggerScan.classList.add('loading');
            document.getElementById('scan-btn-text').textContent = 'Queueing Scan...';
            if (scanStatusIndicator) {
                scanStatusIndicator.style.display = 'block';
            }

            fetch('/api/scan.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `domain_id=${selectedDomainId}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('scan-btn-text').textContent = 'Analysis In Progress...';
                    startPollingStatus();
                } else {
                    alert("Failed to queue scan: " + (data.error || "Unknown error"));
                    btnTriggerScan.disabled = false;
                    btnTriggerScan.classList.remove('loading');
                    document.getElementById('scan-btn-text').textContent = '⚡ Trigger Security Scan';
                    if (scanStatusIndicator) {
                        scanStatusIndicator.style.display = 'none';
                    }
                }
            })
            .catch(err => {
                console.error(err);
                btnTriggerScan.disabled = false;
                btnTriggerScan.classList.remove('loading');
                document.getElementById('scan-btn-text').textContent = '⚡ Trigger Security Scan';
                if (scanStatusIndicator) {
                    scanStatusIndicator.style.display = 'none';
                }
            });
        });
    }

    // --- GENERATE TRAFFIC ACTION ---
    if (btnGenerateTraffic) {
        btnGenerateTraffic.addEventListener('click', () => {
            const domain = btnGenerateTraffic.getAttribute('data-domain');
            btnGenerateTraffic.disabled = true;
            btnGenerateTraffic.querySelector('.btn-text').textContent = 'Generating...';

            fetch('/api/mock-traffic.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `domain=${encodeURIComponent(domain)}`
            })
            .then(res => res.json())
            .then(data => {
                btnGenerateTraffic.disabled = false;
                btnGenerateTraffic.querySelector('.btn-text').textContent = '🔥 Generate Attack Traffic';
                if (data.success) {
                    alert("Simulated attack vectors successfully written to log streams! Click 'Trigger Security Scan' to analyze.");
                } else {
                    alert("Failed to write mock traffic: " + (data.error || "Unknown error"));
                }
            })
            .catch(err => {
                console.error(err);
                btnGenerateTraffic.disabled = false;
                btnGenerateTraffic.querySelector('.btn-text').textContent = '🔥 Generate Attack Traffic';
            });
        });
    }

    // --- POLICY TOGGLE PROTECTION ACTION ---
    if (policyProtectionToggle) {
        policyProtectionToggle.addEventListener('change', (e) => {
            const enabled = e.target.checked;
            const domainId = e.target.getAttribute('data-domain-id');

            fetch('/api/toggle.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `domain_id=${domainId}&protection=${enabled ? 1 : 0}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const badge = document.getElementById('protection-badge');
                    if (badge) {
                        badge.textContent = 'Protection: ' + (enabled ? 'ON' : 'OFF');
                        badge.className = 'badge badge-protection ' + (enabled ? 'prot-on' : 'prot-off');
                    }
                } else {
                    alert("Failed to update protection status: " + (data.error || "Unknown error"));
                    e.target.checked = !enabled; // revert
                }
            })
            .catch(err => {
                console.error(err);
                e.target.checked = !enabled; // revert
            });
        });
    }

    // --- FORM ADD DOMAIN ACTION ---
    if (formAddDomain) {
        formAddDomain.addEventListener('submit', (e) => {
            e.preventDefault();
            const newDomainName = document.getElementById('new-domain-name').value.trim();
            if (!newDomainName) return;

            fetch('/api/add-domain.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `domain=${encodeURIComponent(newDomainName)}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showFeedback('add-domain-msg', 'Domain profile successfully created. Reloading accounts list...', true);
                    setTimeout(() => {
                        window.location.href = `/index.php?page=settings&domain_id=${data.domain_id}`;
                    }, 1000);
                } else {
                    showFeedback('add-domain-msg', data.error || 'Failed to add domain', false);
                }
            })
            .catch(err => {
                console.error(err);
                showFeedback('add-domain-msg', 'Server connection failure', false);
            });
        });
    }

    // --- LIFE CYCLE HOOK ACTIONS ---
    document.querySelectorAll('.btn-hook-action').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const action = btn.getAttribute('data-action');
            const domainId = btn.getAttribute('data-domain-id');

            let endpoint = '/api/delete-domain.php';
            let requestBody = `domain_id=${domainId}`;

            if (action === 'suspend' || action === 'activate') {
                endpoint = '/api/toggle.php';
                requestBody = `domain_id=${domainId}&status_action=${action}`;
            }

            const confirmMsg = action === 'delete' 
                ? "Are you sure you want to delete this domain profile? This will trigger the 'domain_deleted' worker hook."
                : `Simulate domain ${action === 'suspend' ? 'suspension' : 'reactivation'}?`;

            if (!confirm(confirmMsg)) return;

            btn.disabled = true;

            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: requestBody
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(`Action completed. Context: ${action.toUpperCase()} event published to queue.`);
                    window.location.href = action === 'delete' ? '/index.php?page=settings' : `/index.php?page=settings&domain_id=${domainId}`;
                } else {
                    alert("Action failed: " + (data.error || "Unknown error"));
                    btn.disabled = false;
                }
            })
            .catch(err => {
                console.error(err);
                alert("Server error executing lifecycle trigger");
                btn.disabled = false;
            });
        });
    });

    // --- STARTUP CHECKS ---
    // If the gauge score visual is present, initialize its visual
    const visualGauge = document.getElementById('threat-gauge-visual');
    if (visualGauge) {
        const score = parseInt(visualGauge.getAttribute('data-score') || '0');
        const risk = document.getElementById('threat-risk-label').textContent.trim().toLowerCase();
        updateThreatGauge(score, risk);
    }

    // If page loaded with scan active, continue polling automatically
    const isScanning = btnTriggerScan && btnTriggerScan.classList.contains('loading');
    if (isScanning && selectedDomainId > 0) {
        startPollingStatus();
    }
});
