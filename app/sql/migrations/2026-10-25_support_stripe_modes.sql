-- Sépare les collectes, badges et consentements Stripe test/live (#120).
-- Les lignes existantes restent en live ; aucun reçu historique n'est supprimé.
ALTER TABLE support_donations
    ADD COLUMN IF NOT EXISTS stripe_mode VARCHAR(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'live',
    DROP INDEX IF EXISTS uq_support_session,
    ADD UNIQUE KEY uq_support_session (stripe_mode, checkout_session_id),
    DROP INDEX IF EXISTS uq_support_payment_intent,
    ADD UNIQUE KEY uq_support_payment_intent (stripe_mode, payment_intent);

ALTER TABLE support_adjustments
    ADD COLUMN IF NOT EXISTS stripe_mode VARCHAR(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'live',
    DROP INDEX IF EXISTS uq_support_event,
    ADD UNIQUE KEY uq_support_event (stripe_mode, event_id),
    DROP INDEX IF EXISTS uq_support_adjustment,
    ADD UNIQUE KEY uq_support_adjustment (stripe_mode, adjustment_ref);

ALTER TABLE support_members
    ADD COLUMN IF NOT EXISTS stripe_mode VARCHAR(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'live',
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (user_id, stripe_mode);
