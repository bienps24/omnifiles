-- The last menu screen sent to the user; it is deleted when the next one opens
ALTER TABLE users ADD COLUMN IF NOT EXISTS ui_msg_id BIGINT;
