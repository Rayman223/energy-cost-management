-- Soutien volontaire, paiements Stripe et préférences de publication (#120).
-- Dons conservés sans identifiant de compte après suppression RGPD.
CREATE TABLE IF NOT EXISTS support_donations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    checkout_session_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payment_intent VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    amount_cents BIGINT NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    donation_year SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_support_session (checkout_session_id),
    UNIQUE KEY uq_support_payment_intent (payment_intent),
    INDEX idx_support_year (donation_year, currency),
    INDEX idx_support_user (user_id),
    CONSTRAINT fk_support_donation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS support_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_created BIGINT UNSIGNED NOT NULL,
    payment_intent VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    adjustment_ref VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    adjustment_cents BIGINT NOT NULL,
    state VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_support_event (event_id),
    UNIQUE KEY uq_support_adjustment (adjustment_ref),
    INDEX idx_support_adjustment_payment (payment_intent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS support_members (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    badge_enabled TINYINT(1) NOT NULL DEFAULT 0,
    public_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    public_opted_in_at DATETIME NULL,
    manual_override TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
