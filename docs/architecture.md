# ShieldPanel Architecture Design

This document details the system design, communication patterns, database schema, and component breakdowns for ShieldPanel.

---

## Component Layout

The platform consists of five primary containers working in unison:

```
                  +-----------------------------------+
                  |        Nginx Reverse Proxy        |
                  |             (Port 8089)           |
                  +-----------------+-----------------+
                                    |
                         /index.php | /assets/*
                                    v
                  +-----------------+-----------------+
                  |      PHP-FPM Control Service      |
                  |         (cPanel Plugin)           |
                  +--------+-----------------+--------+
                           |                 |
                SQL (PDO)  |                 | AMQP (php-amqplib)
                           v                 v
            +--------------+---+     +-------+---------+
            |    PostgreSQL    |     |    RabbitMQ     |
            |     Database     |     |  Message Broker |
            +--------------+---+     +-------+---------+
                           ^                 |
                 SQL (SQL) |                 | AMQP (amqp091-go)
                           |                 v
                  +--------+-----------------+--------+
                  |         Go Security Worker        |
                  |          (Log Processing)         |
                  +-----------------+-----------------+
                                    |
                                    | Reads access.log / error.log
                                    v
                  +-----------------+-----------------+
                  |          Shared Directory         |
                  |            /shared/logs           |
                  +-----------------------------------+
```

---

## 1. Nginx Reverse Proxy (`nginx/`)
Serves as the entrypoint for HTTP clients. 
- Listens on host port `8089`.
- Routes static file requests directly from `/var/www/html/assets/` to ensure low-latency delivery.
- Proxies all dynamic PHP requests (`.php` files) to the `php-app` service via FastCGI on port `9000`.

---

## 2. cPanel Simulation & PHP Control Layer (`cpanel-plugin/`)
Written in PHP 8.2 and running in a dedicated PHP-FPM container, this service simulates the hosting panel control interface.

### Responsibilities
- **Routing & Rendering**: Serves the user dashboard and settings pages through `index.php`, pulling data from PostgreSQL.
- **Queue Dispatches**: Publishes JSON payloads to RabbitMQ using the `php-amqplib/php-amqplib` library when scans are requested or domain states toggle.
- **Log Generator (Mock Traffic)**: Write logic in `api/mock-traffic.php` simulates real-world web traffic (both standard entries and security threats) to files within the shared log mount.

### Database Interaction
Uses the PDO driver with an custom connection recovery wrapper. If PostgreSQL is not ready during Compose startup, the wrapper initiates an exponential backoff retry loop (up to 10 attempts) rather than failing immediately.

---

## 3. Go Security Worker (`go-worker/`)
A high-performance daemon written in Go 1.21.

### Initialization & Resilience
- Connects to PostgreSQL and RabbitMQ using dialer retry loops with exponential backoff.
- Listens for `os.Signal` (`SIGINT`, `SIGTERM`) to trigger graceful shutdowns.
- Graceful shutdown stops the queue consumers first, processes all in-flight jobs in a `sync.WaitGroup`, and exits cleanly.

### Log Ingestion & Parsing
The log parser reads from the shared volume `/shared/logs/`:
- **`access.log`**: Parsed line-by-line using a regex that breaks down standard Apache Combined Log format (Source IP, Request Method, URL Path, HTTP Status, User-Agent).
- **`error.log`**: Parsed for Apache-formatted error logs, specifically extracting PHP password mismatch messages.

### Threat Heuristic Modules
1. **Bot Traffic**: Identifies common scrapers and search spiders (e.g. `AhrefsBot`, `SemrushBot`).
2. **Credential Stuffing**: Looks for repetitive login failure logs (`password mismatch`) from the same source IP, or high POST counts to `/wp-login.php`.
3. **XMLRPC Abuse**: Flags bursts of POST requests hitting `/xmlrpc.php` which are common in pingback reflection DDoS attacks.
4. **Scraping**: Evaluates aggressive URL fetch frequencies and the presence of scripting library user-agents (`Python-urllib`, `curl`).

### Threat Scorer
Applies weighted severity values to identified heuristics:
- **Bot Traffic**: +30
- **Credential Stuffing**: +45
- **XMLRPC Abuse**: +20
- **Scraping**: +10

Scores are capped at 100.
Risk categories:
- `0 - 30`: **LOW**
- `31 - 60`: **MEDIUM**
- `61+`: **HIGH**

---

## 4. Message Broker Topography (`rabbitmq`)
Uses a single Topic Exchange: `shieldpanel.events`.

### Routing Keys & Binds
- **`scan.requested`**: Dispatched when a user clicks "Trigger Scan". Routes to the `security.jobs` queue.
- **`domain.created`**: Fired when a new domain is registered. Tells the Go worker to initialize a profile and request an initial scan.
- **`domain.deleted`**: Cleans up configuration data and deletes records.
- **`account.suspended`**: Archives findings for the domain.
- **`protection.enabled` / `protection.disabled`**: Updates WAF mode in the database.

---

## 5. PostgreSQL Database Schema
Optimized schema tracking relationships, indexes, and cascades.

- **`domains`**: Main domain directory, containing status states (`active`, `suspended`, `deleted`).
- **`scans`**: Historical audit trace of scans.
- **`findings`**: Specific log lines parsed and flagged. Contains structured `details` stored as a `JSONB` column.
- **`events`**: Tracks backend synchronization and event processing status.
