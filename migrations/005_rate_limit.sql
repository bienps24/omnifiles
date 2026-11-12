-- Anti-spam: how many files a user sent this minute, and their cooldown
ALTER TABLE users ADD COLUMN IF NOT EXISTS rate_start TIMESTAMPTZ;
ALTER TABLE users ADD COLUMN IF NOT EXISTS rate_count INT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS cooldown_until TIMESTAMPTZ;
CREATE INDEX IF NOT EXISTS users_cooldown_idx ON users (cooldown_until) WHERE cooldown_until IS NOT NULL;
