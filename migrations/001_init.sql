-- OmniFiles schema v1 (PostgreSQL)

CREATE TABLE IF NOT EXISTS bots (
    id              BIGINT PRIMARY KEY,               -- Telegram user id of the bot
    username        TEXT NOT NULL,
    name            TEXT NOT NULL,
    token_enc       TEXT NOT NULL,                    -- encrypted bot token
    owner_id        BIGINT,                           -- NULL for the main bot
    is_main         BOOLEAN NOT NULL DEFAULT FALSE,
    webhook_secret  TEXT NOT NULL,
    status          TEXT NOT NULL DEFAULT 'active',   -- active | paused | banned
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS bots_owner_idx ON bots (owner_id);

-- One row per Telegram user per bot
CREATE TABLE IF NOT EXISTS users (
    bot_id      BIGINT NOT NULL,
    user_id     BIGINT NOT NULL,
    first_name  TEXT,
    username    TEXT,
    lang        TEXT,
    tz_offset   INT NOT NULL DEFAULT 0,               -- minutes from UTC
    tz_auto     BOOLEAN NOT NULL DEFAULT TRUE,
    state       TEXT,
    state_data  JSONB NOT NULL DEFAULT '{}'::jsonb,
    defaults    JSONB NOT NULL DEFAULT '{}'::jsonb,   -- default link settings
    blocked     BOOLEAN NOT NULL DEFAULT FALSE,
    banned      BOOLEAN NOT NULL DEFAULT FALSE,
    joined_via  BIGINT,                               -- link id that brought the user in
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen   TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (bot_id, user_id)
);
CREATE INDEX IF NOT EXISTS users_joined_idx ON users (joined_via);

-- Global per Telegram user (purchases live here, shared by all bots)
CREATE TABLE IF NOT EXISTS accounts (
    user_id          BIGINT PRIMARY KEY,
    is_pro           BOOLEAN NOT NULL DEFAULT FALSE,
    pro_since        TIMESTAMPTZ,
    extra_bot_slots  INT NOT NULL DEFAULT 0,
    rules_accepted   BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE IF NOT EXISTS links (
    id              BIGSERIAL PRIMARY KEY,
    bot_id          BIGINT NOT NULL,
    owner_id        BIGINT NOT NULL,
    code            TEXT NOT NULL,                    -- lower case, used in t.me/bot?start=<code>
    title           TEXT NOT NULL,
    intro           TEXT,                             -- "Look": message shown before the files
    expires_at      TIMESTAMPTZ,
    max_people      INT,
    password_hash   TEXT,
    autodelete_sec  INT NOT NULL DEFAULT 300,
    resend          BOOLEAN NOT NULL DEFAULT FALSE,
    protect         BOOLEAN NOT NULL DEFAULT FALSE,
    paused          BOOLEAN NOT NULL DEFAULT FALSE,
    banned          BOOLEAN NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (bot_id, code)
);
CREATE INDEX IF NOT EXISTS links_owner_idx ON links (bot_id, owner_id, id DESC);

CREATE TABLE IF NOT EXISTS files (
    id              BIGSERIAL PRIMARY KEY,
    link_id         BIGINT NOT NULL REFERENCES links (id) ON DELETE CASCADE,
    position        INT NOT NULL,
    kind            TEXT NOT NULL,                    -- photo | video | document | audio | voice | animation | video_note | sticker
    file_id         TEXT NOT NULL,
    file_unique_id  TEXT,
    caption         TEXT,
    size            BIGINT NOT NULL DEFAULT 0,
    backup_msg_id   BIGINT
);
CREATE INDEX IF NOT EXISTS files_link_idx ON files (link_id, position);

-- People who opened a link (one row per person)
CREATE TABLE IF NOT EXISTS opens (
    link_id   BIGINT NOT NULL REFERENCES links (id) ON DELETE CASCADE,
    user_id   BIGINT NOT NULL,
    first_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    times     INT NOT NULL DEFAULT 1,
    was_new   BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (link_id, user_id)
);

-- Every single open (for today / 7 days counters)
CREATE TABLE IF NOT EXISTS open_events (
    id       BIGSERIAL PRIMARY KEY,
    link_id  BIGINT NOT NULL REFERENCES links (id) ON DELETE CASCADE,
    user_id  BIGINT NOT NULL,
    at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS open_events_link_idx ON open_events (link_id, at);

-- Incoming files waiting to become (part of) a link
-- bucket: 'mg:<media_group_id>' (album), 'many' (Many Files session), 'add:<link_id>' (Add files)
CREATE TABLE IF NOT EXISTS pending_files (
    id              BIGSERIAL PRIMARY KEY,
    bot_id          BIGINT NOT NULL,
    user_id         BIGINT NOT NULL,
    bucket          TEXT NOT NULL,
    message_id      BIGINT NOT NULL,
    kind            TEXT NOT NULL,
    file_id         TEXT NOT NULL,
    file_unique_id  TEXT,
    caption         TEXT,
    size            BIGINT NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS pending_bucket_idx ON pending_files (bot_id, user_id, bucket);

-- Messages to delete later (auto-delete)
CREATE TABLE IF NOT EXISTS deletions (
    id           BIGSERIAL PRIMARY KEY,
    bot_id       BIGINT NOT NULL,
    chat_id      BIGINT NOT NULL,
    message_ids  BIGINT[] NOT NULL,
    due_at       TIMESTAMPTZ NOT NULL
);
CREATE INDEX IF NOT EXISTS deletions_due_idx ON deletions (due_at);

-- Banner photo file_ids (file_ids are different for every bot)
CREATE TABLE IF NOT EXISTS banners (
    bot_id   BIGINT NOT NULL,
    key      TEXT NOT NULL,
    file_id  TEXT NOT NULL,
    PRIMARY KEY (bot_id, key)
);

CREATE TABLE IF NOT EXISTS ads (
    id           BIGSERIAL PRIMARY KEY,
    text         TEXT NOT NULL,
    button_text  TEXT NOT NULL,
    url          TEXT NOT NULL,
    active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS payments (
    id           BIGSERIAL PRIMARY KEY,
    user_id      BIGINT NOT NULL,
    bot_id       BIGINT NOT NULL,
    product      TEXT NOT NULL,                       -- pro | slot
    stars        INT NOT NULL,
    charge_id    TEXT NOT NULL UNIQUE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS reports (
    id           BIGSERIAL PRIMARY KEY,
    link_id      BIGINT NOT NULL REFERENCES links (id) ON DELETE CASCADE,
    reporter_id  BIGINT NOT NULL,
    reason       TEXT NOT NULL,
    status       TEXT NOT NULL DEFAULT 'open',        -- open | banned | ignored
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS schema_migrations (
    name        TEXT PRIMARY KEY,
    applied_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
