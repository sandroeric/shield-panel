package db

import (
	"database/sql"
	"fmt"
	"log"
	"time"

	_ "github.com/lib/pq"
)

type Database struct {
	*sql.DB
}

// Connect establishes a connection to Postgres with an exponential backoff retry loop.
func Connect(host string, port int, user, password, dbname string) (*Database, error) {
	connStr := fmt.Sprintf("host=%s port=%d user=%s password=%s dbname=%s sslmode=disable",
		host, port, user, password, dbname)

	var db *sql.DB
	var err error
	maxRetries := 10
	delay := 1 * time.Second

	for i := 1; i <= maxRetries; i++ {
		db, err = sql.Open("postgres", connStr)
		if err == nil {
			err = db.Ping()
			if err == nil {
				log.Printf("Successfully connected to database after %d attempts", i)
				return &Database{db}, nil
			}
		}

		log.Printf("Database connection attempt %d failed: %v. Retrying in %v...", i, err, delay)
		time.Sleep(delay)
		delay *= 2
	}

	return nil, fmt.Errorf("could not connect to database after %d attempts: %w", maxRetries, err)
}

// UpdateScanStatus updates the progress or outcome of a scan.
func (db *Database) UpdateScanStatus(scanID int, status string, score int, risk string) error {
	var err error
	if status == "completed" {
		_, err = db.Exec(`
			UPDATE scans 
			SET status = $1, threat_score = $2, risk_level = $3, completed_at = CURRENT_TIMESTAMP 
			WHERE id = $4`,
			status, score, risk, scanID)
	} else {
		_, err = db.Exec(`
			UPDATE scans 
			SET status = $1 
			WHERE id = $2`,
			status, scanID)
	}
	return err
}

// InsertFinding registers a new security threat occurrence in the database.
func (db *Database) InsertFinding(domainID int, scanID int, sourceIP, fType, severity, details string) error {
	_, err := db.Exec(`
		INSERT INTO findings (domain_id, scan_id, source_ip, type, severity, details, created_at) 
		VALUES ($1, $2, $3, $4, $5, $6, CURRENT_TIMESTAMP)`,
		domainID, scanID, sourceIP, fType, severity, details)
	return err
}

// DeleteFindingsForDomain performs database cleanup when a domain is deleted.
func (db *Database) DeleteFindingsForDomain(domainID int) error {
	_, err := db.Exec("DELETE FROM findings WHERE domain_id = $1", domainID)
	return err
}

// ArchiveFindingsForDomain flags all existing findings for a domain as archived (e.g. on account suspension).
func (db *Database) ArchiveFindingsForDomain(domainID int) error {
	_, err := db.Exec(`
		UPDATE findings 
		SET details = details || ' [ARCHIVED]' 
		WHERE domain_id = $1 AND details NOT LIKE '%[ARCHIVED]%'`, 
		domainID)
	return err
}

// UpdateDomainProtection toggles protection mode.
func (db *Database) UpdateDomainProtection(domainID int, protection bool) error {
	_, err := db.Exec("UPDATE domains SET protection = $1 WHERE id = $2", protection, domainID)
	return err
}

// UpdateDomainStatus updates domain lifecycle status.
func (db *Database) UpdateDomainStatus(domainID int, status string) error {
	_, err := db.Exec("UPDATE domains SET status = $1 WHERE id = $2", status, domainID)
	return err
}
