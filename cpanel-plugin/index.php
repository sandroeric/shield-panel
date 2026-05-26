<?php
// ShieldPanel cPanel Plugin Simulation Router & Chrome

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/services/DomainService.php';

use ShieldPanel\Services\DomainService;

// Simple Routing
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
if (!in_array($page, ['dashboard', 'settings'])) {
    $page = 'dashboard';
}

// Fetch active domains
$domains = [];
$errorMsg = null;
try {
    $domains = DomainService::listAll();
} catch (\Exception $e) {
    $errorMsg = $e->getMessage();
}

// Get selected domain
$selectedDomainId = isset($_GET['domain_id']) ? (int)$_GET['domain_id'] : 0;
if ($selectedDomainId === 0 && !empty($domains)) {
    $selectedDomainId = (int)$domains[0]['id'];
}

$selectedDomain = null;
if ($selectedDomainId > 0) {
    foreach ($domains as $d) {
        if ((int)$d['id'] === $selectedDomainId) {
            $selectedDomain = $d;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>cPanel - ShieldPanel Security Integration</title>
    <meta name="description" content="Simulated cPanel Security Dashboard featuring ShieldPanel for WordPress and hosting security overview.">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <!-- Style Sheet -->
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="cpanel-container">
        
        <!-- TOP NAVIGATION BAR -->
        <header class="cpanel-header">
            <div class="header-logo">
                <span class="logo-cp">cPanel</span>
                <span class="logo-divider">|</span>
                <span class="logo-plugin">ShieldPanel <small class="version">v0.1</small></span>
            </div>
            
            <div class="header-search">
                <input type="text" id="cp-search" placeholder="Search features..." disabled>
            </div>
            
            <div class="header-profile">
                <div class="profile-avatar">S</div>
                <span class="profile-username">sandro_dev</span>
                <span class="status-indicator online"></span>
            </div>
        </header>

        <!-- MAIN LAYOUT -->
        <div class="cpanel-workspace">
            
            <!-- LEFT NAVIGATION SIDEBAR -->
            <aside class="cpanel-sidebar">
                <nav class="sidebar-nav">
                    <ul>
                        <li class="nav-section-title">Common Features</li>
                        <li><a href="#" class="nav-link disabled"><span class="nav-icon">📁</span> Files</a></li>
                        <li><a href="#" class="nav-link disabled"><span class="nav-icon">🗄️</span> Databases</a></li>
                        <li><a href="#" class="nav-link disabled"><span class="nav-icon">✉️</span> Email</a></li>
                        <li><a href="#" class="nav-link disabled"><span class="nav-icon">🌐</span> Metrics</a></li>
                        
                        <li class="nav-section-title">Security Services</li>
                        <li><a href="#" class="nav-link disabled"><span class="nav-icon">🔑</span> SSH Access</a></li>
                        <li><a href="#" class="nav-link disabled"><span class="nav-icon">🔒</span> SSL/TLS</a></li>
                        <li class="active">
                            <a href="/index.php?page=dashboard<?= $selectedDomainId ? '&domain_id='.$selectedDomainId : '' ?>" class="nav-link">
                                <span class="nav-icon">🛡️</span> ShieldPanel
                            </a>
                        </li>
                        
                        <li class="nav-section-title">Domain Setup</li>
                        <li><a href="#" class="nav-link disabled"><span class="nav-icon">⚙️</span> Zone Editor</a></li>
                    </ul>
                </nav>
                <div class="sidebar-footer">
                    <p>IP: 192.168.1.15</p>
                    <p>Theme: Obsidian Glass</p>
                </div>
            </aside>

            <!-- PLUGIN VIEWPORT -->
            <main class="plugin-viewport">
                
                <?php if ($errorMsg): ?>
                    <div class="error-alert">
                        <h3>⚠️ Database Configuration Error</h3>
                        <p><?= htmlspecialchars($errorMsg) ?></p>
                        <p class="retry-hint">PostgreSQL may still be starting up. Please reload the page in a few seconds.</p>
                    </div>
                <?php else: ?>
                    
                    <!-- ShieldPanel Header Navigation -->
                    <div class="plugin-navbar">
                        <div class="navbar-tabs">
                            <a href="/index.php?page=dashboard<?= $selectedDomainId ? '&domain_id='.$selectedDomainId : '' ?>" class="tab-link <?= $page === 'dashboard' ? 'active' : '' ?>">
                                📊 Security Dashboard
                            </a>
                            <a href="/index.php?page=settings<?= $selectedDomainId ? '&domain_id='.$selectedDomainId : '' ?>" class="tab-link <?= $page === 'settings' ? 'active' : '' ?>">
                                ⚙️ Policy Settings
                            </a>
                        </div>
                        
                        <div class="navbar-actions">
                            <label for="domain-selector">Domain:</label>
                            <select id="domain-selector" onchange="window.location.href='/index.php?page=<?= $page ?>&domain_id=' + this.value">
                                <?php if (empty($domains)): ?>
                                    <option value="0">-- No Domains --</option>
                                <?php else: ?>
                                    <?php foreach ($domains as $d): ?>
                                        <option value="<?= $d['id'] ?>" <?= (int)$d['id'] === $selectedDomainId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['domain']) ?> (<?= htmlspecialchars($d['status']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Inner Page Injector -->
                    <div class="plugin-content">
                        <?php 
                        if ($page === 'dashboard') {
                            require_once __DIR__ . '/views/dashboard.php';
                        } else {
                            require_once __DIR__ . '/views/settings.php';
                        }
                        ?>
                    </div>

                <?php endif; ?>
                
            </main>
        </div>
    </div>
    
    <!-- Script References -->
    <script src="/assets/app.js"></script>
</body>
</html>
