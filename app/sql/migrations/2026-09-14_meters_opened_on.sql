-- ============================================================
-- Migration 2026-09-14 — mise en service d'un compteur (Issue #81)
-- NON baselinée : idempotente (IF NOT EXISTS), laissée hors du seed de
-- schema.sql. Elle ne porte AUCUNE donnée — aucune ligne n'est réécrite.
--
-- `meters.closed_on` (#55) disait quand un compteur SORT du parc ; rien ne disait
-- quand il y entre. Les rapports en tiraient la conséquence à l'envers : la
-- fenêtre couverte étant l'intersection des fenêtres de relevés du parc, un
-- compteur déclaré en juin repoussait `data_from` jusqu'à son premier relevé pour
-- TOUTES les périodes antérieures. Un rapport de mars s'annonçait partiel parce
-- qu'un compteur posé en juin n'avait « pas de relevé » en mars.
--
-- Sans cette date, « compteur posé le 15/06 » et « compteur existant mais non
-- relevé avant le 15/06 » sont indiscernables — et le second rend bien le rapport
-- incomplet, il ne faut donc pas les traiter pareil. La colonne tranche, à la
-- saisie, plutôt que de faire deviner le code.
--
-- Borne de DÉBUT, donc INCLUSE : premier jour EN service. La convention #1
-- (app/docs/date-bounds.md) ne porte que sur les dates de FIN ; `opened_on` se
-- lit comme `tariff_grids.valid_from`, son pendant côté début.
--
-- NULL = « depuis toujours » : le parc existant garde exactement le comportement
-- d'avant tant que la date n'est pas renseignée. C'est délibéré — un backfill
-- depuis le premier relevé aurait inventé une mise en service à partir d'une
-- absence de donnée, soit précisément la déduction que cette colonne existe pour
-- éviter.
-- ============================================================

-- Placée AVANT `closed_on` : les deux bornes du cycle de vie se lisent dans
-- l'ordre, et une base migrée reste structurellement identique à une base
-- fraîchement importée depuis schema.sql.
ALTER TABLE meters
    ADD COLUMN IF NOT EXISTS opened_on DATE NULL
        COMMENT 'Mise en service du compteur, borne INCLUSE : premier jour en service'
        AFTER timezone;
