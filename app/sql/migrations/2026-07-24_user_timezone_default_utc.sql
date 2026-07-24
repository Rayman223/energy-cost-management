-- ============================================================
-- Migration 2026-07-24 — user_profiles.timezone : DEFAULT → 'UTC' (Issue #200)
-- NON baselinée : idempotente, laissée hors du seed de schema.sql.
--
-- Le fuseau par défaut d'un NOUVEAU profil passe d'un 'Europe/Brussels' codé en
-- dur (géographiquement arbitraire) à 'UTC', neutre et cohérent avec le stockage
-- (toutes les DATETIME sont en UTC). Rien à voir avec le time_zone du serveur :
-- cette colonne est une chaîne IANA, la PRÉFÉRENCE d'affichage de l'utilisateur.
-- Côté UI, la page compte pré-remplit le fuseau du navigateur (account.js), donc
-- le 1er enregistrement capture le vrai fuseau ; UTC ne reste que par repli.
--
-- ALTER … SET DEFAULT ne touche QUE le défaut des futurs INSERT — les lignes
-- existantes (fuseau déjà choisi) sont inchangées. Idempotent : re-fixer le même
-- défaut est un no-op (le runner ne rollback pas le DDL). Sur base fraîche
-- (schema.sql déjà en 'UTC'), no-op ; sur base existante ('Europe/Brussels'),
-- bascule réelle.
-- ============================================================

ALTER TABLE user_profiles ALTER COLUMN timezone SET DEFAULT 'UTC';
