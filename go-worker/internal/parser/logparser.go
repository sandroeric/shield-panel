package parser

import (
	"bufio"
	"log"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"
)

type LogEntry struct {
	SourceIP   string
	Timestamp  time.Time
	Method     string
	URL        string
	StatusCode int
	Referer    string
	UserAgent  string
	IsError    bool
	Message    string
}

// Combined log regex: IP ident auth [time] "method URL proto" status bytes "referer" "user-agent"
var accessLogRegex = regexp.MustCompile(`^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+)\s+(\S+)\s+[^"]*" (\d+) (\d+|-)(?:\s+"([^"]*)"\s+"([^"]*)")?`)

// Error log regex: [time] [module:level] [pid:tid] [client IP:port] message
var errorLogRegex = regexp.MustCompile(`^\[([^\]]+)\] \[([^\]]+)\] \[([^\]]+)\] \[client ([^\]:]+)(:\d+)?\] (.*)`)

// ParseAccessLog reads the access log file and returns entries filtering by target domain.
func ParseAccessLog(filePath, domain string) ([]LogEntry, error) {
	file, err := os.Open(filePath)
	if err != nil {
		return nil, err
	}
	defer file.Close()

	var entries []LogEntry
	scanner := bufio.NewScanner(file)
	
	for scanner.Scan() {
		line := scanner.Text()
		if !strings.Contains(strings.ToLower(line), strings.ToLower(domain)) {
			continue
		}

		matches := accessLogRegex.FindStringSubmatch(line)
		if len(matches) < 6 {
			continue
		}

		ip := matches[1]
		timeStr := matches[2]
		method := matches[3]
		url := matches[4]
		status, _ := strconv.Atoi(matches[5])
		
		var referer, ua string
		if len(matches) > 7 {
			referer = matches[7]
		}
		if len(matches) > 8 {
			ua = matches[8]
		}

		// Parse Apache time: e.g. 25/May/2026:14:32:10 +0000
		t, err := time.Parse("02/Jan/2006:15:04:05 -0700", timeStr)
		if err != nil {
			t = time.Now()
		}

		entries = append(entries, LogEntry{
			SourceIP:   ip,
			Timestamp:  t,
			Method:     method,
			URL:        url,
			StatusCode: status,
			Referer:    referer,
			UserAgent:  ua,
			IsError:    false,
		})
	}

	if err := scanner.Err(); err != nil {
		log.Printf("Error scanning access log: %v", err)
	}

	return entries, nil
}

// ParseErrorLog reads the error log file and returns entries filtering by target domain.
func ParseErrorLog(filePath, domain string) ([]LogEntry, error) {
	file, err := os.Open(filePath)
	if err != nil {
		return nil, err
	}
	defer file.Close()

	var entries []LogEntry
	scanner := bufio.NewScanner(file)

	for scanner.Scan() {
		line := scanner.Text()
		if !strings.Contains(strings.ToLower(line), strings.ToLower(domain)) {
			continue
		}

		matches := errorLogRegex.FindStringSubmatch(line)
		if len(matches) < 7 {
			continue
		}

		timeStr := matches[1]
		clientIP := matches[4]
		message := matches[6]

		// Parse error log time: e.g. Mon May 25 14:32:12.124567 2026
		// Apache time format layout with microseconds is complex. We will try a few standard ones.
		var t time.Time
		var parseErr error
		layouts := []string{
			"Mon Jan 02 15:04:05.000000 2006",
			"Mon Jan 02 15:04:05 2006",
			time.ANSIC,
		}
		for _, layout := range layouts {
			t, parseErr = time.Parse(layout, timeStr)
			if parseErr == nil {
				break
			}
		}
		if parseErr != nil {
			t = time.Now()
		}

		entries = append(entries, LogEntry{
			SourceIP:  clientIP,
			Timestamp: t,
			IsError:   true,
			Message:   message,
		})
	}

	if err := scanner.Err(); err != nil {
		log.Printf("Error scanning error log: %v", err)
	}

	return entries, nil
}
