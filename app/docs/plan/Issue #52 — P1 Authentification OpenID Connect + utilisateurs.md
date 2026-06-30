# Issue #52 — P1 Authentification OpenID Connect + utilisateurs

## Contexte
Deuxième phase de l'épopée #47. Introduit l'authentification **OpenID Connect** (générique, configurable), le modèle
**utilisateurs** multi-tenant, et la **fondation i18n**. Conçu **non-cassant** : OIDC est désactivé par défaut
(`oidc.enabled=false`) → comportement historique (Basic Auth) inchangé. Lien :
https://github.com/Rayman223/Manage-energy-costs/issues/52

## Fichiers impactés
- `composer.json` / `composer.lock` — dépendance runtime `jumbojett/openid-connect-php` (firebase/php-jwt bloqué par advisory).
- [app/sql/migrations/2026-06-30_users.sql](app/sql/migrations/2026-06-30_users.sql) + [app/sql/schema.sql](app/sql/schema.sql) — tables `users`, `user_profiles`.
- [app/config/config.example.php](app/config/config.example.php) — sections `oidc` et `i18n`.
- [app/src/Domain/User.php](app/src/Domain/User.php), [app/src/Repository/UserRepository.php](app/src/Repository/UserRepository.php) (+ interface, fake) — couche utilisateurs.
- [app/src/Security/Oidc/OidcClientFactory.php](app/src/Security/Oidc/OidcClientFactory.php) — client OIDC (Authorization Code + PKCE S256).
- [app/src/Security/AuthSession.php](app/src/Security/AuthSession.php), [app/src/Security/AccountProvisioner.php](app/src/Security/AccountProvisioner.php), [app/src/Security/AuthGuard.php](app/src/Security/AuthGuard.php).
- [app/src/Security/WebAccessGuard.php](app/src/Security/WebAccessGuard.php) — extraction `enforceIp()` + `basePath()`.
- [app/public/auth/login.php](app/public/auth/login.php) (login + callback), [app/public/auth/logout.php](app/public/auth/logout.php).
- [app/public/index.php](app/public/index.php), [app/public/api.php](app/public/api.php), [app/public/tariffs.php](app/public/tariffs.php) — `AuthGuard::protect()`.
- [app/src/I18n/Translator.php](app/src/I18n/Translator.php), [app/src/I18n/LocaleResolver.php](app/src/I18n/LocaleResolver.php) + `app/translations/{fr,en,nl,de}.php`.
- Tests : LocaleResolver, Translator, AccountProvisioner (unit) ; UserRepository (intégration BDD).

## Étapes
- [x] Tables `users` / `user_profiles` (migration idempotente + schema.sql) ; identité = `sub` + `iss`, sans e-mail/mdp.
- [x] Client OIDC générique (discovery, PKCE S256, state/nonce, validation id_token via JWKS — gérés par la lib).
- [x] Auto-inscription ouverte (`AccountProvisioner`) à la 1re connexion ; nom d'affichage rafraîchi ; `last_login_at`.
- [x] Session porteuse du `user_id` (`AuthSession`), cookies durcis (réutilise `Session` de P0).
- [x] `AuthGuard` : OIDC activé → exige une session ; désactivé → délègue au Basic Auth (non-cassant). Allowlist IP conservée.
- [x] Endpoints `auth/login.php` (login+callback), `auth/logout.php`.
- [x] Fondation i18n : `Translator` + `LocaleResolver` + catalogues fr/en/nl/de ; `locale` stockée par utilisateur.
- [x] Tests + PHPStan 6 + php -l.

## Vérification
- `php app/scripts/migrate.php --dry-run` liste la migration `users` (sur base existante).
- OIDC désactivé : dashboard/API/tarifs fonctionnent comme avant (Basic Auth).
- OIDC activé contre un issuer de test : redirection → IdP → callback → compte auto-créé → session → accès.
- `/auth/logout.php` vide la session.
- PHPUnit (unit + intégration BDD) verts.

## Notes
- **Dépendance** : `jumbojett/openid-connect-php` choisie car `firebase/php-jwt` est bloqué par l'advisory
  PKSA-y2cr-5h3j-g3ys (aucune version corrigée). Elle gère discovery + PKCE + échange de code + JWKS/validation id_token.
- **Anti-abus** : minimal en P1 (compte `status=blocked` refusé). Rate-limiting renforcé en P7.
- **i18n** : fondation seulement ; l'externalisation complète des chaînes de l'UI existante est en P6.
- **Déconnexion** : locale (session). RP-initiated logout (IdP) possible ultérieurement via `signOut()`.
