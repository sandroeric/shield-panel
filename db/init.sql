-- ShieldPanel PostgreSQL Schema Initialization

-- Drop tables if they exist (for easy resetting/development)
DROP TABLE IF EXISTS findings CASCADE;
DROP TABLE IF EXISTS events CASCADE;
DROP TABLE IF EXISTS scans CASCADE;
DROP TABLE IF EXISTS domains CASCADE;

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
    domain_id   INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
    status      VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending, running, completed, failed
    started_at  TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    threat_score INTEGER DEFAULT 0,
    risk_level  VARCHAR(10) DEFAULT 'low',  -- low, medium, high
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Event hook log (domain_created, domain_deleted, account_suspended)
CREATE TABLE events (
    id          SERIAL PRIMARY KEY,
    domain_id   INTEGER REFERENCES domains(id) ON DELETE SET NULL,
    event_type  VARCHAR(50) NOT NULL,
    payload     JSONB,
    processed   BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Individual security findings from scans
CREATE TABLE findings (
    id          SERIAL PRIMARY KEY,
    domain_id   INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
    scan_id     INTEGER REFERENCES scans(id) ON DELETE CASCADE,
    type        VARCHAR(50) NOT NULL,  -- bot_traffic, credential_stuffing, xmlrpc_abuse, scraping
    severity    VARCHAR(10) NOT NULL,  -- low, medium, high, critical
    details     JSONB NOT NULL,
    source_ip   VARCHAR(45),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Seed Initial Domains
INSERT INTO domains (domain, status, protection) VALUES
('example.com', 'active', TRUE),
('myshop.com', 'active', TRUE),
('blog.dev', 'active', FALSE);
