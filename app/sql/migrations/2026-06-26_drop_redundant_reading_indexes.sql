-- ============================================================
-- Migration 2026-06-26 — suppression d'index redondants
-- ============================================================
-- Data_gaz et Data_eau déclarent à la fois UNIQUE KEY (reading_at)
-- (uq_data_gaz_reading / uq_data_eau_reading) ET un INDEX simple sur la même
-- colonne (idx_data_gaz_date / idx_data_eau_date). La clé UNIQUE indexe déjà
-- reading_at : l'index simple est un doublon qui n'accélère aucune requête et
-- ajoute du coût en écriture (un index de plus à maintenir par INSERT).
--
-- Appliquer une fois :
--   mysql -u <user> -p <database> < app/sql/migrations/2026-06-26_drop_redundant_reading_indexes.sql

ALTER TABLE Data_gaz DROP INDEX idx_data_gaz_date;
ALTER TABLE Data_eau DROP INDEX idx_data_eau_date;
