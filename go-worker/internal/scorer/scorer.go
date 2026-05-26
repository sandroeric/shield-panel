package scorer

import (
	"shieldpanel/worker/internal/detector"
)

// CalculateThreatScore computes the overall threat score and maps it to a risk level.
// Weights: Bot Traffic = 30, Credential Stuffing = 45, XMLRPC Abuse = 20, Scraping = 10.
func CalculateThreatScore(findings []detector.Finding) (int, string) {
	hasBot := false
	hasCred := false
	hasXmlrpc := false
	hasScraping := false

	for _, f := range findings {
		switch f.Type {
		case "bot_traffic":
			hasBot = true
		case "credential_stuffing":
			hasCred = true
		case "xmlrpc_abuse":
			hasXmlrpc = true
		case "scraping":
			hasScraping = true
		}
	}

	score := 0
	if hasBot {
		score += 30
	}
	if hasCred {
		score += 45
	}
	if hasXmlrpc {
		score += 20
	}
	if hasScraping {
		score += 10
	}

	// Clamp to 100
	if score > 100 {
		score = 100
	}

	risk := "low"
	if score > 30 && score <= 60 {
		risk = "medium"
	} else if score > 60 {
		risk = "high"
	}

	return score, risk
}
