-- ============================================================
-- Migration 2026-09-06 — socle multi-compteur (Issue #55)
-- NON baselinée : idempotente (IF [NOT] EXISTS + gardes), laissée hors du seed.
--
-- Jusqu'ici l'application supposait UN compteur par énergie et par utilisateur.
-- Côté électricité, `meters` savait déjà en porter plusieurs (aucune clé unique
-- sur (user_id, energy_type)) : c'est le code qui bridait, en résolvant toujours
-- le plus ancien. Côté gaz/eau en revanche, `utility_readings` n'avait aucune
-- notion de compteur — sa clé unique (user_id, energy_type, reading_at) rendait
-- structurellement impossible deux compteurs d'eau relevés au même instant.
--
-- Cette migration aligne les deux modèles : `meters` devient la table des
-- compteurs des TROIS énergies, et `utility_readings` s'y rattache par
-- `meter_id`, exactement comme `battery_readings` se rattache à `batteries`.
--
-- Dénormalisation assumée : `user_id` et `energy_type` RESTENT sur
-- `utility_readings`. `meter_id` est la source de vérité, mais les conserver
-- garde la frontière multi-tenant sous forme de prédicat de colonne
-- (WHERE user_id = :uid) plutôt que de saut de jointure — la classe d'erreur
-- contre laquelle ce dépôt se garde le plus. Les écritures les dérivent
-- désormais du compteur résolu, et un test d'intégration vérifie l'invariant.
--
-- `closed_on` est ajoutée ici mais reste INERTE : aucun code ne la lit encore.
-- Elle arrive dans cette migration pour n'avoir qu'un seul ALTER sur `meters`.
-- Borne de fin EXCLUE (#1), comme batteries.decommissioned_on.
--
-- L'ORDRE DES INSTRUCTIONS EST UNE CONTRAINTE DE CORRECTION : le DDL MySQL
-- commit implicitement, la transaction de MigrationRunner ne peut donc rien
-- annuler. Chaque instruction est individuellement rejouable, et le backfill
-- (4) précède le rattachement (5), qui précède le passage en NOT NULL (6).
-- ============================================================

-- 1) Les trois énergies dans `meters`. Pas d'IF NOT EXISTS sur un MODIFY, mais
--    l'instruction est idempotente par nature (même définition cible). Les
--    valeurs sont ajoutées EN FIN d'ENUM (ALGORITHM=INSTANT) : ne jamais
--    réordonner 'electricity', qui est le DEFAULT et vit dans les données.
ALTER TABLE meters
    MODIFY COLUMN energy_type ENUM('electricity', 'gas', 'water') NOT NULL DEFAULT 'electricity';

-- 2) Fermeture d'un compteur + index de résolution (user, énergie).
ALTER TABLE meters
    ADD COLUMN IF NOT EXISTS closed_on DATE NULL
        COMMENT 'Fermeture du compteur, borne EXCLUE (#1) : aucune ecriture a partir de cette date'
        AFTER timezone;

ALTER TABLE meters
    ADD INDEX IF NOT EXISTS idx_meters_user_energy (user_id, energy_type);

-- Le libellé change de contrat : vide ne veut plus dire « pas renseigné » mais
-- « dérivé à l'affichage » (cf. 9). Le COMMENT le dit en base, et l'aligne sur
-- schema.sql — une base migrée doit être structurellement identique à une base
-- fraîchement importée, sans quoi la garde de convergence ne prouve plus rien.
ALTER TABLE meters
    MODIFY COLUMN label VARCHAR(120) NOT NULL DEFAULT ''
        COMMENT 'Vide = libelle derive a l''affichage, dans la langue du lecteur';

-- 3) Rattachement des relevés gaz/eau : colonne nullable d'abord (phase expand).
ALTER TABLE utility_readings
    ADD COLUMN IF NOT EXISTS meter_id BIGINT UNSIGNED NULL
        COMMENT 'Compteur porteur du releve (#55) ; user_id/energy_type sont denormalises'
        AFTER user_id;

-- 4) Backfill : un compteur par (utilisateur, énergie) ayant des relevés et
--    n'en possédant pas encore. `label` vide = libellé dérivé à l'affichage,
--    dans la langue du lecteur.
INSERT INTO meters (user_id, energy_type, label)
SELECT DISTINCT ur.user_id, ur.energy_type, ''
FROM utility_readings ur
LEFT JOIN meters m ON m.user_id = ur.user_id AND m.energy_type = ur.energy_type
WHERE ur.meter_id IS NULL AND m.id IS NULL;

-- 5) Rattachement déterministe : le plus ancien compteur de l'énergie. La
--    correspondance (utilisateur, énergie) -> compteur est une bijection à cet
--    instant, ce qui garantit l'unicité posée en (8).
UPDATE utility_readings ur
JOIN (
    SELECT user_id, energy_type, MIN(id) AS meter_id
    FROM meters
    GROUP BY user_id, energy_type
) pick ON pick.user_id = ur.user_id AND pick.energy_type = ur.energy_type
SET ur.meter_id = pick.meter_id
WHERE ur.meter_id IS NULL;

-- 6) Contrat. Échoue BRUYAMMENT si une ligne est restée orpheline : mieux vaut
--    une migration avortée qu'un backfill tronqué en silence.
ALTER TABLE utility_readings
    MODIFY COLUMN meter_id BIGINT UNSIGNED NOT NULL
        COMMENT 'Compteur porteur du releve (#55) ; user_id/energy_type sont denormalises';

-- 7) Clé étrangère. `DROP IF EXISTS` puis `ADD` plutôt que `ADD ... IF NOT
--    EXISTS` : le couple est idempotent sans dépendre d'une extension de
--    grammaire, au prix d'une revalidation négligeable au rejeu.
ALTER TABLE utility_readings
    DROP FOREIGN KEY IF EXISTS fk_utility_readings_meter;

ALTER TABLE utility_readings
    ADD CONSTRAINT fk_utility_readings_meter
        FOREIGN KEY (meter_id) REFERENCES meters (id) ON DELETE CASCADE;

-- 8) Unicité par compteur, sur le modèle de uq_battery_readings. Le nouvel
--    index est créé AVANT que l'ancien ne soit supprimé : il sert aussi d'index
--    de couverture à la clé étrangère posée en (7).
--
--    L'ancien unique (user_id, energy_type, reading_at) portait aussi TOUTES les
--    lectures scopées du repository. Le supprimer sans le remplacer les
--    condamnerait au balayage complet : idx_utility_readings_user reprend
--    exactement le même préfixe, en index simple.
ALTER TABLE utility_readings
    ADD UNIQUE KEY IF NOT EXISTS uq_utility_readings_meter (meter_id, reading_at);

ALTER TABLE utility_readings
    ADD INDEX IF NOT EXISTS idx_utility_readings_user (user_id, energy_type, reading_at);

ALTER TABLE utility_readings
    DROP INDEX IF EXISTS uq_utility_readings;

-- 9) Sortir le français de la base : le libellé par défaut était écrit en dur
--    par MeterTopology, et servi tel quel à un lecteur néerlandophone. Un
--    libellé vide est désormais dérivé à l'affichage. Idempotent (au rejeu,
--    plus aucune ligne ne correspond).
UPDATE meters
SET label = ''
WHERE energy_type = 'electricity' AND label = 'Compteur électrique';
