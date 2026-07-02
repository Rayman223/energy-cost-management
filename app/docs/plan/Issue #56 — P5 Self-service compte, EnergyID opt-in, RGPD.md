# Issue #56 — P5 Self-service compte, intégrations (EnergyID) & RGPD

## Contexte
Septième phase de l'épopée #47. Donne à chaque membre une **page compte** (profil, jetons API), rend l'intégration
**EnergyID opt-in par utilisateur** (BE/NL), et apporte la **conformité RGPD** (export + suppression) + pages légales.
Lien : https://github.com/Rayman223/Manage-energy-costs/issues/56

## Fichiers
- [app/sql/migrations/2026-07-05_account_energyid.sql](app/sql/migrations/2026-07-05_account_energyid.sql) + schema.sql
  — `users.terms_accepted_at` ; table `energyid_integrations` (opt-in par utilisateur, **aucun secret** : enabled +
  deviceId dérivé + claimed_at).
- [app/public/account.php](app/public/account.php) + [app/templates/account.php](app/templates/account.php) — page
  compte (profil, jetons create/revoke, EnergyID on/off, export/suppression), self-POST + CSRF, session uniquement.
- [app/public/privacy.php](app/public/privacy.php) / [app/public/terms.php](app/public/terms.php) +
  [app/templates/legal.php](app/templates/legal.php) — pages légales publiques.
- [app/src/Repository/EnergyIdIntegrationRepository.php](app/src/Repository/EnergyIdIntegrationRepository.php),
  [app/src/Service/AccountDataExporter.php](app/src/Service/AccountDataExporter.php),
  [app/src/Service/AccountEraser.php](app/src/Service/AccountEraser.php).
- [app/src/Repository/UserRepository.php](app/src/Repository/UserRepository.php) — `updateProfile()`,
  `acceptTermsIfNeeded()` (+ interface + fake).
- [app/src/Security/AccountProvisioner.php](app/src/Security/AccountProvisioner.php) — acceptation CGU à l'inscription.
- [app/scripts/cron_daily_webhook.php](app/scripts/cron_daily_webhook.php) — **itère sur les utilisateurs opt-in**,
  device par utilisateur, marque le claim au 1er envoi réussi.
- Nav : liens Tarifs/Compte ajoutés à l'en-tête du dashboard.

## Décisions
- **EnergyID sans secret stocké** : le flux `hello()` re-provisionne à chaque run via les credentials partenaire
  **globaux** ; per-user = un `deviceId` dérivé (`<base>-u<id>`) que le membre réclame dans son propre compte EnergyID.
  → pas de chiffrement de secrets à gérer.
- **Consentement** : l'inscription (1re connexion OIDC) horodate `terms_accepted_at` ; les pages légales sont liées
  partout. (Gate interactif dur possible plus tard.)
- **Suppression RGPD** : transaction ; cascade FK pour la plupart des tables, suppression explicite des
  `tariff_grids` perso et `webhook_sync_state` (sans FK vers users).

## Étapes
- [x] BDD (terms + energyid_integrations) + baseline
- [x] Repos/services (EnergyId opt-in, updateProfile, export, eraser, provisioner terms)
- [x] Cron webhook per-user (opt-in)
- [x] Page compte + pages légales + nav
- [x] Tests : export/erase RGPD, EnergyId repo (intégration) ; consentement (unit)

## Vérification
- CI (unit + intégration MariaDB).
- Page compte : éditer le profil ; créer/révoquer un jeton (secret montré une fois) ; activer EnergyID ;
  exporter (JSON téléchargé) ; supprimer le compte (→ déconnexion, toutes les données effacées).
- `cron_daily_webhook` ne synchronise que les membres ayant activé EnergyID.

## Notes / reports
- UI de sélection de grille du catalogue → intégrée au profil plus tard si besoin (P3 gère déjà perso > partagé).
- Espace admin (gestion des utilisateurs, modération) → **P7 (#58)**.
- i18n complet de la page compte / pages légales → **P6 (#57)** (chaînes actuellement en dur, fondation i18n prête).
