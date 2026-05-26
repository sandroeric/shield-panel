package detector

import (
	"fmt"
	"strings"
	
	"shieldpanel/worker/internal/parser"
)

type Finding struct {
	SourceIP string
	Type     string // bot_traffic, credential_stuffing, xmlrpc_abuse, scraping
	Severity string // low, medium, high, critical
	Message  string
}

// DetectThreats analyzes the access and error logs and returns a slice of security findings.
func DetectThreats(accessEntries []parser.LogEntry, errorEntries []parser.LogEntry) []Finding {
	var findings []Finding

	// Maps for grouping by IP to avoid duplicates
	botRequestsByIP := make(map[string][]parser.LogEntry)
	wpLoginRequestsByIP := make(map[string][]parser.LogEntry)
	xmlrpcRequestsByIP := make(map[string][]parser.LogEntry)
	scrapingRequestsByIP := make(map[string][]parser.LogEntry)
	
	// Track User-Agent bots we identify
	botUserAgents := []string{"semrushbot", "ahrefsbot", "mj12bot", "dotbot", "rogerbot", "exabot", "screaming frog"}
	scrapingUserAgents := []string{"python", "scrapy", "curl", "wget", "urllib", "http-client"}

	// 1. Process Access Logs
	for _, entry := range accessEntries {
		ua := strings.ToLower(entry.UserAgent)
		
		// Check Bot Traffic
		isBot := false
		for _, bUA := range botUserAgents {
			if strings.Contains(ua, bUA) {
				botRequestsByIP[entry.SourceIP] = append(botRequestsByIP[entry.SourceIP], entry)
				isBot = true
				break
			}
		}

		if isBot {
			continue
		}

		// Check Credential Stuffing (POST /wp-login.php)
		if entry.Method == "POST" && strings.Contains(entry.URL, "/wp-login.php") {
			wpLoginRequestsByIP[entry.SourceIP] = append(wpLoginRequestsByIP[entry.SourceIP], entry)
			continue
		}

		// Check XMLRPC Abuse (POST /xmlrpc.php)
		if entry.Method == "POST" && strings.Contains(entry.URL, "/xmlrpc.php") {
			xmlrpcRequestsByIP[entry.SourceIP] = append(xmlrpcRequestsByIP[entry.SourceIP], entry)
			continue
		}

		// Check Scraping (Using scrape engines, or rapid GET requests to APIs)
		isScraperUA := false
		for _, sUA := range scrapingUserAgents {
			if strings.Contains(ua, sUA) {
				isScraperUA = true
				break
			}
		}
		
		if isScraperUA || strings.Contains(entry.URL, "/api/v1/") {
			scrapingRequestsByIP[entry.SourceIP] = append(scrapingRequestsByIP[entry.SourceIP], entry)
		}
	}

	// 2. Process Error Logs (For Credential Stuffing verification)
	mismatchByIP := make(map[string]int)
	for _, entry := range errorEntries {
		if strings.Contains(entry.Message, "password mismatch") {
			mismatchByIP[entry.SourceIP]++
		}
	}

	// --- 3. Evaluate Grouped Logs & Formulate Findings ---

	// Bot Findings (Low)
	for ip, entries := range botRequestsByIP {
		lastUA := entries[len(entries)-1].UserAgent
		// Extract cleaner bot name
		botName := "Generic Search Bot"
		for _, bUA := range botUserAgents {
			if strings.Contains(strings.ToLower(lastUA), bUA) {
				botName = strings.Title(bUA)
				break
			}
		}
		findings = append(findings, Finding{
			SourceIP: ip,
			Type:     "bot_traffic",
			Severity: "low",
			Message:  fmt.Sprintf("%s crawler detected making %d requests", botName, len(entries)),
		})
	}

	// Credential Stuffing Findings (High/Critical)
	// Trigger if we see multiple POST requests OR password mismatch logs
	for ip, entries := range wpLoginRequestsByIP {
		if len(entries) >= 3 {
			findings = append(findings, Finding{
				SourceIP: ip,
				Type:     "credential_stuffing",
				Severity: "high",
				Message:  fmt.Sprintf("Multiple sequential auth attempts targeting wp-login.php (%d requests)", len(entries)),
			})
		}
	}

	for ip, count := range mismatchByIP {
		// If we also saw wp-login requests or count of mismatches is high, upgrade to Critical
		severity := "high"
		if count >= 4 {
			severity = "critical"
		}
		findings = append(findings, Finding{
			SourceIP: ip,
			Type:     "credential_stuffing",
			Severity: severity,
			Message:  fmt.Sprintf("Login authentication failure: %d password mismatches recorded in error log", count),
		})
	}

	// XMLRPC Abuse Findings (Medium)
	for ip, entries := range xmlrpcRequestsByIP {
		findings = append(findings, Finding{
			SourceIP: ip,
			Type:     "xmlrpc_abuse",
			Severity: "medium",
			Message:  fmt.Sprintf("WordPress XML-RPC request burst detected (%d POST requests)", len(entries)),
		})
	}

	// Scraping Findings (Medium)
	for ip, entries := range scrapingRequestsByIP {
		if len(entries) >= 4 {
			findings = append(findings, Finding{
				SourceIP: ip,
				Type:     "scraping",
				Severity: "medium",
				Message:  fmt.Sprintf("Automated scrapers detected accessing API endpoints (%d requests)", len(entries)),
			})
		}
	}

	return findings
}
