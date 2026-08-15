-- =============================================================================
-- 030_terminal_anomaly_detection.sql — is this token on more than one device?
-- =============================================================================
-- ADR-0041. A terminal authenticates with a bearer token and nothing else, so
-- two clients holding the same token are indistinguishable: both resolve to one
-- `terminals` row, both write sales attributed to it, and both keep
-- `last_sync_at` warm so the dashboard shows one healthy till. ADR-0036 gave
-- tokens a lifetime and #395 gave them overlap rotation; neither answers the
-- question in between.
--
-- Three tables, recording two signals and the conclusions drawn from them.
-- Everything here is observation: nothing on the request path reads these to
-- decide whether to serve a request (ADR-0041 decision — alert, never enforce).
--
-- Rollback: db/rollback/030_terminal_anomaly_detection.down.sql
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Where a terminal's authenticated requests come from
-- -----------------------------------------------------------------------------
-- Bucketed at five minutes rather than one row per request. A terminal issues
-- roughly 180–300 authenticated requests an hour (a 60-second cycle of 3–5
-- calls), so per-request rows would be ~7,000/terminal/day to answer a question
-- that five-minute resolution answers exactly as well. Bucketing bounds it to
-- at most 12 rows per hour per (terminal, IP).
--
-- `first_seen_at`/`last_seen_at` are kept per bucket, not just a count, because
-- the detector's question is about *intervals*: two IPs whose activity overlaps
-- for a sustained period is the signal, and two IPs in adjacent buckets with no
-- overlap is an ISP reconnect handing over cleanly.
--
-- VARCHAR(45) matches the existing IP columns (`login_attempts`,
-- `terminal_auth_attempts`, `audit_log`) and holds an IPv6 literal.
CREATE TABLE terminal_ip_sightings (
    terminal_id   CHAR(36)     NOT NULL,
    ip_address    VARCHAR(45)  NOT NULL COMMENT 'REMOTE_ADDR; X-Forwarded-For is never trusted (ADR-0041 §6)',
    bucket_start  DATETIME     NOT NULL COMMENT 'Request time floored to a 5-minute boundary',
    first_seen_at DATETIME     NOT NULL,
    last_seen_at  DATETIME     NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (terminal_id, ip_address, bucket_start),
    -- The detector scans one terminal's recent window; the pruner scans by age
    -- across every terminal. Neither is served well by the primary key alone,
    -- whose third column is the one both of them range over.
    INDEX idx_terminal_ip_sightings_window (terminal_id, bucket_start),
    INDEX idx_terminal_ip_sightings_bucket (bucket_start),
    CONSTRAINT fk_terminal_ip_sightings_terminal
        FOREIGN KEY (terminal_id) REFERENCES terminals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. The delta cursor each terminal presents
-- -----------------------------------------------------------------------------
-- `SyncCursor` issues a millisecond watermark, the terminal stores it and echoes
-- it back as `?since=`. For an honest client the value is monotonic
-- non-decreasing — the terminal persists whatever the server returned, and
-- `SyncCursor::next()` never returns less than it was given. A second client on
-- the same token holds its own copy of that state, and the divergence shows up
-- here, where until now the server kept nothing at all.
--
-- One row per stream, not per terminal: the three delta endpoints advance
-- independently, and a regression on one of them is the event worth recording.
--
-- The counters are cumulative and the `last_regression_*` triple describes only
-- the most recent one. That is enough: the detector opens an anomaly on a single
-- regression (ADR-0041 §3 — a lagging client catches up on its next poll, so a
-- fork produces one regression per data-change event, never a stream of them),
-- and the anomaly row is what carries the history from there.
CREATE TABLE terminal_sync_cursors (
    terminal_id           CHAR(36)        NOT NULL,
    stream                ENUM('members', 'products', 'categories') NOT NULL,
    high_water_cursor     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Highest cursor this terminal has ever presented, in ms',
    last_presented_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    regression_count      INT UNSIGNED    NOT NULL DEFAULT 0,
    last_regression_at    DATETIME        NULL,
    last_regression_from  BIGINT UNSIGNED NULL COMMENT 'The high-water mark at the moment of the last regression',
    last_regression_to    BIGINT UNSIGNED NULL COMMENT 'What was presented instead',
    updated_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (terminal_id, stream),
    -- The cron tick asks "which streams regressed since I last looked".
    INDEX idx_terminal_sync_cursors_regression (last_regression_at),
    CONSTRAINT fk_terminal_sync_cursors_terminal
        FOREIGN KEY (terminal_id) REFERENCES terminals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. What the detector concluded, and whether anybody has looked at it
-- -----------------------------------------------------------------------------
-- One open row per (terminal, kind), updated in place while the condition
-- persists so an ongoing anomaly is one alert rather than one per cron tick.
--
-- That "one open row" rule is enforced by the writer, not by a unique index:
-- the natural key would be (terminal_id, kind, acknowledged_at IS NULL), and
-- MySQL cannot express it — NULL never equals NULL, so a unique index over a
-- nullable column permits unlimited open rows. The detector is the only writer
-- and runs under the cron tick's FileLock, single-threaded, so a
-- SELECT-then-INSERT is safe there in a way it would not be on a request path.
--
-- `details` carries the evidence: the overlapping IP pair and the overlap
-- duration, or the stream and the two cursor values. It therefore holds IP
-- addresses that outlive the sightings retention window (ADR-0041 §5) — a
-- deliberate exception, because the record of why an admin was told something
-- has to outlive the raw data it was derived from.
--
-- No foreign key on `acknowledged_by_admin_id`, following `audit_log`: this is
-- an accountability record, and deleting an admin should not cascade away the
-- fact that somebody looked at an alert.
CREATE TABLE terminal_anomalies (
    id                       CHAR(36)     NOT NULL PRIMARY KEY,
    terminal_id              CHAR(36)     NOT NULL,
    kind                     ENUM('concurrent_ip', 'cursor_regression', 'cursor_reset') NOT NULL,
    first_detected_at        DATETIME     NOT NULL,
    last_detected_at         DATETIME     NOT NULL,
    occurrence_count         INT UNSIGNED NOT NULL DEFAULT 1,
    details                  JSON         NULL,
    acknowledged_at          DATETIME     NULL,
    acknowledged_by_admin_id CHAR(36)     NULL,
    created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- The detector's lookup ("is there an open row for this terminal and
    -- kind?") and every read surface's ("what is open right now?").
    INDEX idx_terminal_anomalies_open (terminal_id, kind, acknowledged_at),
    INDEX idx_terminal_anomalies_ack (acknowledged_at),
    CONSTRAINT fk_terminal_anomalies_terminal
        FOREIGN KEY (terminal_id) REFERENCES terminals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. The mail kind the detector queues
-- -----------------------------------------------------------------------------
-- Restating the whole list, as 026 did — MariaDB has no ADD VALUE, and dropping
-- a value here would silently un-add it. `terminal_anomaly_warning` addresses an
-- admin, not a member, so its subject is the terminal (MailKind::subjectType()).
ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'payment_request',
    'key_expiry_warning',
    'terminal_token_expiry_warning',
    'terminal_anomaly_warning'
) NOT NULL;
