PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;
PRAGMA secure_delete = ON;

CREATE TABLE IF NOT EXISTS echoes (
    id TEXT PRIMARY KEY,
    ciphertext TEXT NOT NULL,
    iv TEXT NOT NULL,
    access_token_hash TEXT NOT NULL,
    burn_after_reading INTEGER NOT NULL CHECK (burn_after_reading IN (0, 1)),
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    read_count INTEGER NOT NULL DEFAULT 0 CHECK (read_count >= 0),
    last_read_at INTEGER NULL,
    CHECK (length(id) BETWEEN 20 AND 32),
    CHECK (length(iv) = 16),
    CHECK (length(access_token_hash) = 43),
    CHECK (expires_at > created_at)
);

CREATE INDEX IF NOT EXISTS idx_echoes_expires_at
    ON echoes (expires_at);

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket TEXT NOT NULL,
    identity_hash TEXT NOT NULL,
    window_start INTEGER NOT NULL,
    request_count INTEGER NOT NULL CHECK (request_count >= 0),
    PRIMARY KEY (bucket, identity_hash)
);

CREATE INDEX IF NOT EXISTS idx_rate_limits_window_start
    ON rate_limits (window_start);
