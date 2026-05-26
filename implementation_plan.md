# ShieldPanel Implementation Plan

ShieldPanel is a portfolio-grade security integration platform that bridges a PHP-based cPanel plugin dashboard with a Go backend service for asynchronous log parsing, threat detection, and event-driven automation.

We will build the entire application inside a Docker Compose environment. The project will feature a simulated cPanel interface (with the ShieldPanel plugin running inside it), a Go log parsing worker, a RabbitMQ message broker for handling scan jobs and event hooks, a PostgreSQL database, and an Nginx reverse proxy.

---

## Changes from Previous Plan

> [!IMPORTANT]
> This is a revised plan. The following major gaps from the Architecture Spec have been addressed:
>
> 1. **Nginx missing** — The spec lists Nginx as a required Docker service. Added as the front-facing reverse proxy.
> 2. **Event Hooks system missing** — The spec dedicates an entire section to `domain_created`, `domain_deleted`, and `account_suspended` hooks. Added as a dedicated Phase 4.
> 3. **Settings page missing** — The spec defines both a Dashboard *and* a Settings page. Added to the UI phase.
> 4. **Incomplete database schema** — The spec defines `domains`, `scans`, `events`, `findings` tables. Previous plan had no schema detail. Full schema now included.
> 5. **Only 1 of 4 detection types addressed** — The spec covers Bot Traffic, Credential Stuffing, XMLRPC Abuse, and Scraping. Previous plan mentioned only generic "regex heuristics." All four are now explicit.
> 6. **`error.log` not parsed** — The spec lists both `access.log` and `error.log` as inputs.
> 7. **RabbitMQ topology undefined** — The spec defines a `shieldpanel.events` exchange, `security.jobs` queue, and named routing keys. Now explicit.
> 8. **Threat scoring model not specified** — The spec gives exact weights (bot=30, cred_stuffing=40, xmlrpc=20). Now codified.
> 9. **No portfolio/demo phase** — The spec has a dedicated "Portfolio Presentation" section. Added as Phase 8.

---

## User Review Required

> [!IMPORTANT]
> **1. Nginx vs Direct PHP Exposure:**
> The spec lists Nginx as a service. The plan adds it as a reverse proxy in front of PHP-FPM. This is more realistic than exposing Apache/PHP directly, and better demonstrates infrastructure awareness. Confirm this is acceptable.
>
> **2. Connection Resilience / Startup Sequence:**
> Docker Compose services start in parallel. We will implement robust retry loops in both PHP (PDO connection helper) and Go (dialer wrappers with exponential backoff) to prevent container crash loops. `depends_on` with `condition: service_healthy` will also be used where possible.
>
> **3. Environment Variables & Shared Logs:**
> We will use a local bind mount `./shared/logs/` → `/shared/logs/` in the containers. This lets the user inspect the mock Apache `access.log` and `error.log` directly on the host.
>
> **4. Composer Integration:**
> The PHP container uses a custom Dockerfile with `composer` to install `php-amqplib/php-amqplib` during the build step.

---

## Open Questions

> [!NOTE]
> 1. **Seed data scope** — Should we pre-populate domains on startup? (Proposed: Yes. Seed 3 domains via `init.sql` — `example.com`, `myshop.com`, `blog.dev` — and also support adding new ones via the UI.)
> 2. **Log format** — Are there specific log formats besides Apache Combined Log Format we should support? (Proposed: Stick to Apache Combined for MVP. The spec mentions Nginx/LiteSpeed as "possible future support.")
> 3. **Authentication** — The spec mentions "Authentication and authorization" in the cPanel plugin responsibilities. Should we implement even a basic auth layer (e.g., hardcoded admin user), or skip it for MVP? (Proposed: Skip for MVP; add a note in README that this would use cPanel's native auth in production.)

---

## Database Schema

```sql
-- Domains being monitored
CREATE TABLE domains (
    id          SERIAL PRIMARY KEY,
    domain      VARCHAR(255) NOT NULL UNIQUE,
    status      VARCHAR(20) NOT NULL DEFAULT 'active',  -- active, suspended, deleted
    protection  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Scan execution records
CREATE TABLE scans (
    id          SERIAL PRIMARY KEY,
    domain_id   INTEGER NOT NULL REFERENCES domains(id),
    status      VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending, running, completed, failed
    started_at  TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    threat_score INTEGER DEFAULT 0,
    risk_level  VARCHAR(10),  -- low, medium, high
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Event hook log (domain_created, domain_deleted, account_suspended)
CREATE TABLE events (
    id          SERIAL PRIMARY KEY,
    domain_id   INTEGER REFERENCES domains(id),
    event_type  VARCHAR(50) NOT NULL,
    payload     JSONB,
    processed   BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Individual security findings from scans
CREATE TABLE findings (
    id          SERIAL PRIMARY KEY,
    domain_id   INTEGER NOT NULL REFERENCES domains(id),
    scan_id     INTEGER REFERENCES scans(id),
    type        VARCHAR(50) NOT NULL,  -- bot_traffic, credential_stuffing, xmlrpc_abuse, scraping
    severity    VARCHAR(10) NOT NULL,  -- low, medium, high, critical
    details     JSONB NOT NULL,
    source_ip   VARCHAR(45),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

---

## RabbitMQ Topology

```text
Exchange:  shieldpanel.events  (topic)

Queue:     security.jobs

Routing Keys:
  scan.requested       → Worker picks up, parses logs, writes findings
  protection.enabled   → Worker enables baseline rules for domain
  protection.disabled  → Worker disables rules for domain
  domain.created       → Worker registers protection profile + queues initial scan
  domain.deleted       → Worker cleans up config + archives findings
  account.suspended    → Worker disables protection + archives findings
```

---

## Implementation Phases

### Phase 1: Environment & Infrastructure Setup
- [x] Create `.env` configuration file (DB credentials, RabbitMQ credentials, ports).
- [x] Create `db/init.sql` with full schema (domains, scans, events, findings) and seed data (3 domains).
- [x] Create `shared/logs/access.log` and `shared/logs/error.log` with sample log entries.
- [x] Create `nginx/nginx.conf` to reverse proxy to PHP-FPM and serve static assets.
- [x] Create `nginx/Dockerfile` (or use stock `nginx:alpine` with config mount).
- [x] Create `cpanel-plugin/Dockerfile` (PHP-FPM + Composer + pdo_pgsql + sockets extension).
- [x] Create `cpanel-plugin/composer.json` requiring `php-amqplib/php-amqplib`.
- [x] Create `go-worker/Dockerfile` (multi-stage build: build + alpine runtime).
- [x] Create `go-worker/go.mod` and initial `go.sum`.
- [x] Create `docker-compose.yml` orchestrating: **nginx**, **php** (cpanel-plugin), **go-worker**, **rabbitmq**, **postgres**.
- [x] Configure healthchecks for postgres and rabbitmq in compose file.
- [x] Configure `depends_on` with `condition: service_healthy` for dependent services.

### Phase 2: Database & Queue Services (PHP)
- [x] Create `cpanel-plugin/config/database.php` — PDO connection with retry loop + exponential backoff.
- [x] Create `cpanel-plugin/services/queue.php` — RabbitMQ publisher via `php-amqplib`:
  - Declare `shieldpanel.events` exchange (topic type).
  - Publish messages with routing keys (`scan.requested`, `protection.enabled`, `domain.created`, etc.).
- [x] Create `cpanel-plugin/services/domain.php` — Domain CRUD logic (create, list, delete, suspend).

### Phase 3: Simulated cPanel Dashboard (PHP / UI)
- [x] Create `cpanel-plugin/index.php` — Front controller / router dispatching to views.
- [x] Create `cpanel-plugin/views/dashboard.php` — Main dashboard showing:
  - Protection status toggle (ON/OFF)
  - Threat score gauge (with conic gradient meter)
  - Blocked bot count
  - Failed login attempts
  - XMLRPC abuse detection count
  - Last scan timestamp
  - Recent findings list
- [x] Create `cpanel-plugin/views/settings.php` — Settings page with:
  - Enable/disable protection toggle
  - Trigger scan button
  - Refresh results button
  - Domain management (add/remove)
- [x] Create `cpanel-plugin/assets/style.css` — High-fidelity glassmorphic dark theme:
  - Glassmorphism card backgrounds with `backdrop-filter`
  - Conic gradients for threat score meter
  - Smooth hover transitions and micro-animations
  - Responsive layout
- [x] Create `cpanel-plugin/assets/app.js` — Client-side JavaScript:
  - AJAX polling for scan status updates
  - Event-driven UI updates (status badges, findings table)
  - Domain add/remove interactions
  - Traffic generation trigger

### Phase 4: Event Hooks System (PHP + RabbitMQ)
- [x] Create `cpanel-plugin/hooks/domain_created.php` — On new domain:
  - Insert into `domains` table.
  - Publish `domain.created` event to RabbitMQ.
  - (Worker will register protection profile + queue initial scan.)
- [x] Create `cpanel-plugin/hooks/domain_deleted.php` — On domain removal:
  - Mark domain as `deleted` in DB.
  - Publish `domain.deleted` event to RabbitMQ.
  - (Worker will cleanup config + archive findings.)
- [x] Create `cpanel-plugin/hooks/account_suspended.php` — On account suspension:
  - Mark domain as `suspended` in DB.
  - Publish `account.suspended` event to RabbitMQ.
  - (Worker will disable protection + archive findings.)
- [x] Wire hooks into the domain management API endpoints.

### Phase 5: API Endpoints (PHP Control Layer)
- [x] Create `cpanel-plugin/api/status.php` — Returns JSON:
  - Current threat score and risk level for selected domain.
  - Counts: blocked bots, failed logins, xmlrpc hits, scraping attempts.
  - Active findings list.
  - Last scan timestamp.
- [x] Create `cpanel-plugin/api/scan.php` — Publishes `scan.requested` message to RabbitMQ with domain payload.
- [x] Create `cpanel-plugin/api/toggle.php` — Toggles protection state; publishes `protection.enabled` or `protection.disabled`.
- [x] Create `cpanel-plugin/api/add-domain.php` — Registers new domain; triggers `domain.created` hook.
- [x] Create `cpanel-plugin/api/delete-domain.php` — Removes domain; triggers `domain.deleted` hook.
- [x] Create `cpanel-plugin/api/mock-traffic.php` — Appends realistic attack patterns to `/shared/logs/access.log` and `/shared/logs/error.log`:
  - Bot traffic entries (suspicious user agents: curl, python, scrapy)
  - Credential stuffing entries (401/403 on `/wp-login.php` from same IP)
  - XMLRPC abuse entries (POST `/xmlrpc.php` bursts)
  - Scraping entries (rapid sequential path enumeration)

### Phase 6: Go Security Worker Service
- [x] Create `go-worker/cmd/worker/main.go`:
  - Startup retry loops for DB and RabbitMQ connections (exponential backoff).
  - Declare exchange and queue; bind routing keys.
  - Consume messages and dispatch to handlers.
  - Graceful shutdown on SIGINT/SIGTERM (drain in-flight messages).
- [x] Create `go-worker/internal/db/db.go`:
  - Connection pool management.
  - Write scan records (start, complete, update score).
  - Write findings (insert with type, severity, details, source_ip).
  - Process event hooks (mark events as processed).
- [x] Create `go-worker/internal/queue/consumer.go`:
  - RabbitMQ consumer with manual ACK.
  - Route messages by routing key to appropriate handler.
- [x] Create `go-worker/internal/parser/logparser.go`:
  - Parse Apache Combined Log Format from `access.log`.
  - Parse `error.log` for additional signals.
  - Return structured log entries.
- [x] Create `go-worker/internal/detector/detector.go` — Four detection modules:
  - **Bot Traffic**: Match suspicious user agents (`curl`, `python`, `scrapy`, `wget`); detect high request rate from single IP; detect repeated identical request patterns.
  - **Credential Stuffing**: Detect clusters of 401/403 responses; same IP targeting multiple usernames; rapid login endpoint hits.
  - **XMLRPC Abuse**: Detect `xmlrpc.php` request bursts; identify multicall patterns.
  - **Scraping**: Detect path enumeration patterns; aggressive fetch frequency; repetitive crawling from single source.
- [x] Create `go-worker/internal/scorer/scorer.go`:
  - Weighted threat scoring per the spec model:
    - Bot traffic = +30
    - Credential stuffing = +45
    - XMLRPC abuse = +20
    - Scraping = +10
  - Risk level mapping: 0–30 → Low, 31–60 → Medium, 61+ → High.
- [x] Create `go-worker/internal/handler/scan.go` — `scan.requested` handler:
  - Mark scan as `running`.
  - Parse logs → detect threats → score → write findings → update scan record.
- [x] Create `go-worker/internal/handler/hooks.go` — Event hook handlers:
  - `domain.created`: Register protection profile, queue initial scan.
  - `domain.deleted`: Archive findings, cleanup.
  - `account.suspended`: Disable protection, archive.
  - `protection.enabled`/`protection.disabled`: Update domain protection status.

### Phase 7: System Orchestration & Verification
- [x] Boot containers with `docker compose up --build`.
- [x] Verify startup logs — all retry loops resolve, services connect successfully.
- [x] Smoke test the full flow via `http://localhost:8089`:
  1. View pre-seeded domains on Dashboard.
  2. Click "Generate Traffic" → verify log entries appear in `./shared/logs/` (Permission issue fixed by world-writable directory).
  3. Click "Trigger Scan" → verify scan status transitions (pending → running → completed).
  4. View findings and threat score update on Dashboard.
  5. Add a new domain → verify `domain.created` event fires and initial scan runs.
  6. Delete a domain → verify cleanup.
  7. Toggle protection ON/OFF → verify state changes.
- [x] Test graceful shutdown: `docker compose stop go-worker` — verify in-flight messages are completed, no message loss.
- [x] Verify Settings page functionality.

### Phase 8: Portfolio Polish & Demo
- [x] Create `README.md` with:
  - Project description and goals.
  - Architecture diagram (can be ASCII or Mermaid).
  - Tech stack overview.
  - Setup guide (`docker compose up --build`).
  - Screenshots of the dashboard.
  - Note about authentication (would use cPanel native auth in production).
- [x] Capture screenshots of: Dashboard, Settings, Scan results, Findings detail (performed via browser subagent).
- [x] Create `docs/architecture.md` with detailed system design.
- [x] Record a short demo walkthrough (performed and saved via browser recording).

---

## Verification Plan

### Automated Tests
- `docker compose up --build` — all 5 services start and pass healthchecks.
- `curl http://localhost:8080/api/status.php` — returns valid JSON with domain stats.
- `curl -X POST http://localhost:8080/api/mock-traffic.php` — returns success, logs grow.
- `curl -X POST http://localhost:8080/api/scan.php -d '{"domain":"example.com"}'` — returns scan ID.
- Poll `api/status.php` until scan completes — threat score > 0, findings populated.
- `curl -X POST http://localhost:8080/api/add-domain.php -d '{"domain":"newsite.com"}'` — domain appears in status.
- `docker compose logs go-worker` — no panics, clean message processing.

### Manual Verification
- Browse to `http://localhost:8080` and visually verify the glassmorphic dark theme.
- Confirm threat score gauge animates on update.
- Confirm findings list populates with typed entries (bot, cred_stuffing, xmlrpc, scraping).
- Confirm Settings page toggles work and persist.
- Confirm graceful shutdown: stop worker mid-scan, restart, verify no lost messages.
