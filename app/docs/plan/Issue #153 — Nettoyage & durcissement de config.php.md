# Issue #153 — Nettoyage & durcissement de config.php

## Contexte
`app/config/config.example.php` mélange config serveur, reliquats mono-tenant v2 (dongles
HomeWizard) et réglages économiques qui devraient être par utilisateur. Aucune validation :
tout est lu en `?? défaut`, donc toute dérive désactive silencieusement des fonctionnalités.
Bug latent : deux unités de TVA incompatibles (`dynamic_prices.vat_rate = 0.21` fraction vs
`tariff_grids.vat_rate = 21.00` pourcentage). Lien : https://github.com/Rayman223/Manage-energy-costs/issues/153

3 phases = 3 PR empilées (convention épopée #47). `Closes #153` uniquement en P3.

## P1 — Supprimer le volet dongles v2
Fichiers supprimés :
- [app/scripts/cron_hourly.php](../../scripts/cron_hourly.php), [app/scripts/agent_push.php](../../scripts/agent_push.php)
- [app/src/Service/MeterApiService.php](../../src/Service/MeterApiService.php)
- [app/tools/conversion_solaire.php](../../tools/conversion_solaire.php) + entrée `phpstan-baseline.neon`
- `app/docs/suivi.md` (périmé ~80 %)

Config : sections `meters` et `agent` retirées de `config.example.php`.
Doc : README (arbre scripts + crontab), api-contract.md (action `live`), api-ingestion.md (modes d'ingestion).

### Étapes
- [x] Supprimer les 4 fichiers de code + suivi.md
- [x] Retirer l'entrée baseline `conversion_solaire.php`
- [x] Nettoyer `config.example.php` (meters, agent)
- [x] Mettre à jour la doc (README, api-contract, api-ingestion)
- [x] Vérifs : grep 0 résultat, php -l, PHPUnit, PHPStan 6

## P2 — Validateur de config
Namespace `App\Config` (`ConfigSchema`, `ConfigValidator`, `ConfigIssue`), CLI `config_check.php`,
garde CI `--schema-only --strict`, `energyid.enabled`, déblocage nl/de (`Locale.php`).
- [x] `App\Config\ConfigIssue` / `ConfigSchema` / `ConfigValidator` (fonction pure)
- [x] CLI `app/scripts/config_check.php` (--file, --schema-only, --strict ; codes 0/1/2)
- [x] Validation non bloquante au bootstrap (error_log ERROR, jamais throw)
- [x] Garde CI dans le job `lint` (--schema-only --strict sur config.example.php)
- [x] `energyid.enabled` ajouté au template + schéma
- [x] Déblocage nl/de (`Locale::settings` défaut `['fr','en','nl','de']`)
- [x] Tests `ConfigValidatorTest`, `ConfigExampleTest`, `LocaleTest` adapté
- [x] `installation.md` : mention de `config_check.php`
- [x] Vérifs : php -l, PHPUnit (278), PHPStan 6, CLI sur les 3 scénarios clés

## P3 — TVA & marge par utilisateur (après #147, déjà mergée)
Migration `2026-07-17_user_dynamic_pricing.sql`, formule alignée `* (1 + vat/100) + markup`,
UI /account, RGPD, bugs #88.
- [ ] À venir (branche empilée sur P2)

## Vérification
- P1 : `grep -rn "meters\]\|agent_push\|cron_hourly\|MeterApiService" app/ --include=*.php | grep -v worktrees` → 0.
  Dashboard, import CSV, `?action=ingest_electricity` toujours fonctionnels.
- P2 : `php app/scripts/config_check.php` sur le config réel → sections absentes sans ERROR.
- P3 : montant dashboard `dynamic_hourly` identique avec `vat_rate = 21.00` (pas d'écart ×100).
