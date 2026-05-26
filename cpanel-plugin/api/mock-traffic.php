<?php
// ShieldPanel API: Generate Attack Traffic logs

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(455);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

$domain = isset($_POST['domain']) ? trim($_POST['domain']) : '';

if (empty($domain)) {
    echo json_encode(['error' => 'Domain is required.']);
    exit;
}

$accessLog = getenv('ACCESS_LOG_PATH') ?: '/shared/logs/access.log';
$errorLog = getenv('ERROR_LOG_PATH') ?: '/shared/logs/error.log';

// Verify directory exists
$logDir = dirname($accessLog);
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

try {
    $timeStr = date('d/M/Y:H:i:s O'); // Standard Apache time format, e.g. 25/May/2026:14:32:10 +0000
    $errTimeStr = date('D M d H:i:s.u Y'); // Standard Apache error time format, e.g. Mon May 25 14:32:12.124567 2026

    $accessEntries = [];
    $errorEntries = [];

    // --- 1. BOT TRAFFIC (SemrushBot, AhrefsBot, etc.) ---
    // Source: 192.168.80.11
    $accessEntries[] = "192.168.80.11 - - [$timeStr] \"GET http://$domain/robots.txt HTTP/1.1\" 200 150 \"-\" \"Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)\"\n";
    $accessEntries[] = "192.168.80.11 - - [$timeStr] \"GET http://$domain/blog/security-primer HTTP/1.1\" 200 4520 \"-\" \"Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)\"\n";
    $accessEntries[] = "192.168.80.11 - - [$timeStr] \"GET http://$domain/products/category HTTP/1.1\" 200 8120 \"-\" \"Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)\"\n";

    // --- 2. CREDENTIAL STUFFING (Rapid POSTs to wp-login.php with failures) ---
    // Source: 192.168.80.22
    for ($i = 0; $i < 5; $i++) {
        $secOffset = $i * 2;
        $tStr = date('d/M/Y:H:i:s O', time() + $secOffset);
        $etStr = date('D M d H:i:s.u Y', time() + $secOffset);
        
        $accessEntries[] = "192.168.80.22 - - [$tStr] \"POST http://$domain/wp-login.php HTTP/1.1\" 200 4120 \"http://$domain/wp-login.php\" \"Mozilla/5.0 (Windows NT 10.0; Win64; x64)\"\n";
        $errorEntries[] = "[$etStr] [auth_digest:error] [pid 8234:tid 1234] [client 192.168.80.22:38421] AH01790: user admin: password mismatch for $domain\n";
    }

    // --- 3. XMLRPC ABUSE (Pingback/System Multicall attacks) ---
    // Source: 192.168.80.33
    $accessEntries[] = "192.168.80.33 - - [$timeStr] \"POST http://$domain/xmlrpc.php HTTP/1.1\" 200 980 \"-\" \"Mozilla/5.0 (compatible; XMLRPC-Abuse-Tester)\"\n";
    $accessEntries[] = "192.168.80.33 - - [$timeStr] \"POST http://$domain/xmlrpc.php HTTP/1.1\" 200 980 \"-\" \"Mozilla/5.0 (compatible; XMLRPC-Abuse-Tester)\"\n";
    $accessEntries[] = "192.168.80.33 - - [$timeStr] \"POST http://$domain/xmlrpc.php HTTP/1.1\" 200 980 \"-\" \"Mozilla/5.0 (compatible; XMLRPC-Abuse-Tester)\"\n";

    // --- 4. SCRAPING (Rapid GETs to api/products from Python script) ---
    // Source: 192.168.80.44
    for ($i = 0; $i < 8; $i++) {
        $secOffset = $i;
        $tStr = date('d/M/Y:H:i:s O', time() + $secOffset);
        $accessEntries[] = "192.168.80.44 - - [$tStr] \"GET http://$domain/api/v1/products?page=$i HTTP/1.1\" 200 12050 \"-\" \"Python-urllib/3.10\"\n";
    }

    // Write to files
    file_put_contents($accessLog, implode("", $accessEntries), FILE_APPEND | LOCK_EX);
    file_put_contents($errorLog, implode("", $errorEntries), FILE_APPEND | LOCK_EX);

    echo json_encode([
        'success' => true,
        'message' => 'Mock attack traffic successfully appended to log files.'
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to generate mock traffic: ' . $e->getMessage()
    ]);
}
