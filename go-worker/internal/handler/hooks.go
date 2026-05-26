package handler

import (
	"encoding/json"
	"log"
	
	"shieldpanel/worker/internal/db"
)

type HookPayload struct {
	Event     string `json:"event"`
	DomainID  int    `json:"domain_id"`
	Domain    string `json:"domain"`
	Timestamp int64  `json:"timestamp"`
}

// HandleLifecycleHook processes cPanel lifecycle and protection toggle events.
func HandleLifecycleHook(
	database *db.Database, 
	routingKey string, 
	messageBody []byte,
	publishFunc func(routingKey string, body []byte) error,
) error {
	var payload HookPayload
	if err := json.Unmarshal(messageBody, &payload); err != nil {
		return err
	}

	log.Printf("Received event hook [%s] for domain %s (ID %d)", routingKey, payload.Domain, payload.DomainID)

	switch routingKey {
	case "domain.created":
		// Register protection profile (enabled by default)
		if err := database.UpdateDomainProtection(payload.DomainID, true); err != nil {
			log.Printf("Failed to enable protection for created domain: %v", err)
		}
		
		// Queue initial scan (insert pending scan and publish scan.requested event)
		var scanID int
		err := database.QueryRow(`
			INSERT INTO scans (domain_id, status, threat_score, risk_level, created_at) 
			VALUES ($1, 'pending', 0, 'low', CURRENT_TIMESTAMP) RETURNING id`,
			payload.DomainID).Scan(&scanID)
		
		if err != nil {
			log.Printf("Failed to insert initial scan: %v", err)
			return err
		}

		scanPayload := ScanPayload{
			ScanID:    scanID,
			DomainID:  payload.DomainID,
			Domain:    payload.Domain,
			Timestamp: payload.Timestamp,
		}
		scanBytes, _ := json.Marshal(scanPayload)
		if err := publishFunc("scan.requested", scanBytes); err != nil {
			log.Printf("Failed to publish initial scan request: %v", err)
			return err
		}
		log.Printf("Queued initial security scan %d for new domain %s", scanID, payload.Domain)

	case "domain.deleted":
		// Database soft-deletes domain record (status is updated in PHP).
		// Here, we purge findings for this domain as cleanup.
		if err := database.DeleteFindingsForDomain(payload.DomainID); err != nil {
			log.Printf("Failed to delete findings for deleted domain: %v", err)
			return err
		}
		
		// Mark all active scans for this domain as failed/deleted
		_, err := database.Exec("UPDATE scans SET status = 'failed' WHERE domain_id = $1 AND status IN ('pending', 'running')", payload.DomainID)
		if err != nil {
			log.Printf("Failed to clear scans for deleted domain: %v", err)
		}
		log.Printf("Purged security records for deleted domain %s", payload.Domain)

	case "account.suspended":
		// Disable protection on suspension
		if err := database.UpdateDomainProtection(payload.DomainID, false); err != nil {
			log.Printf("Failed to disable protection on suspension: %v", err)
		}
		// Archive findings
		if err := database.ArchiveFindingsForDomain(payload.DomainID); err != nil {
			log.Printf("Failed to archive findings on suspension: %v", err)
			return err
		}
		log.Printf("Archived findings and disabled protection for suspended domain %s", payload.Domain)

	case "protection.enabled":
		if err := database.UpdateDomainProtection(payload.DomainID, true); err != nil {
			log.Printf("Failed to enable domain protection: %v", err)
			return err
		}
		log.Printf("Protection policy enabled for domain %s", payload.Domain)

	case "protection.disabled":
		if err := database.UpdateDomainProtection(payload.DomainID, false); err != nil {
			log.Printf("Failed to disable domain protection: %v", err)
			return err
		}
		log.Printf("Protection policy disabled for domain %s", payload.Domain)
	}

	return nil
}
