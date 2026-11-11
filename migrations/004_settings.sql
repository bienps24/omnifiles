-- Small key/value store (custom emoji ids set with /setemoji, ...)
CREATE TABLE IF NOT EXISTS settings (
    key    TEXT PRIMARY KEY,
    value  TEXT NOT NULL
);
