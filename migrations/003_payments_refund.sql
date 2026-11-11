-- Payments now come from the payment bot (@omnigatepaybot); refunds take Pro / bot slots back
ALTER TABLE payments ADD COLUMN IF NOT EXISTS refunded BOOLEAN NOT NULL DEFAULT FALSE;
