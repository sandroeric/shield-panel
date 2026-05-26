package handler

import (
	"encoding/json"
	"log"
	"os"
	
	"shieldpanel/worker/internal/db"
	"shieldpanel/worker/internal/detector"
	"shieldpanel/worker/internal/parser"
	"shieldpanel/worker/internal/scorer"
)

type ScanPayload struct {
	ScanID    int    `json:"scan_id"`
	DomainID  int    `json:"domain_id"`
	Domain    string `json:"domain"`
	Timestamp int64  `json:"timestamp"`
}

// HandleScanRequested executes the log parsing, threat detection, and scoring workflow.
func HandleScanRequested(database *db.Database, messageBody []byte) error {
	var payload ScanPayload
	if err := json.Unmarshal(messageBody, &payload); err != nil {
		return err
	}

	log.Printf("Executing scan %d for domain %s (ID %d)", payload.ScanID, payload.Domain, payload.DomainID)

	// 1. Update status to 'running'
	if err := database.UpdateScanStatus(payload.ScanID, "running", 0, "low"); err != nil {
		log.Printf("Failed to update scan status to running: %v", err)
		return err
	}

	accessLogPath := os.Getenv("ACCESS_LOG_PATH")
	if accessLogPath == "" {
		accessLogPath = "/shared/logs/access.log"
	}
	errorLogPath := os.Getenv("ERROR_LOG_PATH")
	if errorLogPath == "" {
		errorLogPath = "/shared/logs/error.log"
	}

	// 2. Parse access and error log entries
	accessEntries, err := parser.ParseAccessLog(accessLogPath, payload.Domain)
	if err != nil {
		log.Printf("Access log parse warning: %v", err)
	}

	errorEntries, err := parser.ParseErrorLog(errorLogPath, payload.Domain)
	if err != nil {
		log.Printf("Error log parse warning: %v", err)
	}

	// 3. Detect security threats
	findings := detector.DetectThreats(accessEntries, errorEntries)

	// 4. Save individual findings to database
	for _, f := range findings {
		detailsMap := map[string]interface{}{
			"message": f.Message,
		}
		detailsBytes, _ := json.Marshal(detailsMap)
		
		if err := database.InsertFinding(payload.DomainID, payload.ScanID, f.SourceIP, f.Type, f.Severity, string(detailsBytes)); err != nil {
			log.Printf("Failed to insert finding for domain %d: %v", payload.DomainID, err)
		}
	}

	// 5. Score findings
	score, risk := scorer.CalculateThreatScore(findings)

	// 6. Complete scan
	if err := database.UpdateScanStatus(payload.ScanID, "completed", score, risk); err != nil {
		log.Printf("Failed to mark scan %d complete: %v", payload.ScanID, err)
		return err
	}

	log.Printf("Scan %d completed successfully. Score: %d, Risk: %s, Findings Count: %d", 
		payload.ScanID, score, risk, len(findings))
		
	return nil
}
