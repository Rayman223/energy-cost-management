-- Issue #112: English Discord release receipts. Safe to replay.
-- Receipts are written only after Discord confirms delivery, never by migration.
CREATE TABLE IF NOT EXISTS release_announcements (
    version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    lang VARCHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sent_at DATETIME NOT NULL COMMENT 'Discord delivery time (UTC)',
    PRIMARY KEY (version, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
