<?php
namespace ShieldPanel\Services;

require_once __DIR__ . '/../config/database.php';

class DomainService {
    
    public static function listAll() {
        $db = getDBConnection();
        $stmt = $db->query("SELECT * FROM domains WHERE status != 'deleted' ORDER BY domain ASC");
        return $stmt->fetchAll();
    }

    public static function getById($id) {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT * FROM domains WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public static function getByDomain($domainName) {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT * FROM domains WHERE domain = ?");
        $stmt->execute([$domainName]);
        return $stmt->fetch();
    }

    public static function create($domainName) {
        $db = getDBConnection();
        
        // Check if exists and is deleted, we can reactivate it
        $stmt = $db->prepare("SELECT * FROM domains WHERE domain = ?");
        $stmt->execute([$domainName]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['status'] === 'deleted') {
                $stmt = $db->prepare("UPDATE domains SET status = 'active', protection = TRUE WHERE id = ?");
                $stmt->execute([$existing['id']]);
                return $existing['id'];
            } else {
                throw new \Exception("Domain already exists and is " . $existing['status']);
            }
        }

        $stmt = $db->prepare("INSERT INTO domains (domain, status, protection) VALUES (?, 'active', TRUE)");
        $stmt->execute([$domainName]);
        return $db->lastInsertId();
    }

    public static function delete($id) {
        $db = getDBConnection();
        $stmt = $db->prepare("UPDATE domains SET status = 'deleted' WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public static function suspend($id) {
        $db = getDBConnection();
        $stmt = $db->prepare("UPDATE domains SET status = 'suspended' WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public static function toggleProtection($id, $protectionEnabled) {
        $db = getDBConnection();
        $stmt = $db->prepare("UPDATE domains SET protection = ? WHERE id = ?");
        return $stmt->execute([$protectionEnabled ? 'true' : 'false', $id]);
    }

    public static function getStats($domainId) {
        $db = getDBConnection();
        
        // Get threat score of last completed scan
        $stmt = $db->prepare("
            SELECT threat_score, risk_level, completed_at 
            FROM scans 
            WHERE domain_id = ? AND status = 'completed' 
            ORDER BY completed_at DESC LIMIT 1
        ");
        $stmt->execute([$domainId]);
        $lastScan = $stmt->fetch();
        
        if (!$lastScan) {
            $lastScan = ['threat_score' => 0, 'risk_level' => 'low', 'completed_at' => null];
        }

        // Check if there is an active scan running or pending
        $stmt = $db->prepare("
            SELECT status FROM scans 
            WHERE domain_id = ? AND status IN ('pending', 'running')
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$domainId]);
        $activeScan = $stmt->fetch();
        $scanStatus = $activeScan ? $activeScan['status'] : 'completed';

        // Counts of finding types
        $stmt = $db->prepare("
            SELECT type, COUNT(*) as cnt 
            FROM findings 
            WHERE domain_id = ? 
            GROUP BY type
        ");
        $stmt->execute([$domainId]);
        $findingsCounts = [
            'bot_traffic' => 0,
            'credential_stuffing' => 0,
            'xmlrpc_abuse' => 0,
            'scraping' => 0
        ];
        foreach ($stmt->fetchAll() as $row) {
            $findingsCounts[$row['type']] = (int)$row['cnt'];
        }

        return [
            'threat_score' => (int)$lastScan['threat_score'],
            'risk_level' => $lastScan['risk_level'],
            'last_scan_at' => $lastScan['completed_at'],
            'scan_status' => $scanStatus,
            'findings_counts' => $findingsCounts
        ];
    }

    public static function getRecentFindings($domainId, $limit = 10) {
        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT * FROM findings 
            WHERE domain_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$domainId, $limit]);
        return $stmt->fetchAll();
    }
}
