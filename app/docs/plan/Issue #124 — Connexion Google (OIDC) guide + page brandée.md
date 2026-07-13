# Issue #124 — Connexion Google (OIDC) : guide de configuration + page de connexion brandée

## Contexte

Le socle OpenID Connect est déjà entièrement codé et générique (`OidcClientFactory`,
route `/auth/login` initiatrice+callback, `AuthGuard`, auto-provisioning, table
`users` clé `(oidc_iss, oidc_sub)`). L'exemple de config pointe déjà vers Google.

Le manque réel : (A) **aucun guide concret** pour créer les identifiants Google
Cloud et activer la config ; (B) `AuthGuard::requireLogin()` redirige **directement**
vers l'IdP — il n'existe **aucune page de connexion brandée** avec un bouton
« Se connecter avec Google ».

Lien GH : https://github.com/Rayman223/Manage-energy-costs/issues/124

## Fichiers impactés

- [app/docs/oidc-google.md](../oidc-google.md) — **nouveau** guide pas-à-pas Google Cloud.
- [app/docs/installation.md:212](../installation.md#L212) — référencer le guide depuis la section 2.
- [app/src/Security/AuthGuard.php:97](../../src/Security/AuthGuard.php#L97) — `requireLogin()` redirige vers `/login` (page brandée) au lieu de `/auth/login`.
- [app/routes/login.php](../../routes/login.php) — branche OIDC : rend la page brandée si `oidc.enabled`, sinon comportement Basic Auth historique.
- [app/templates/login-oidc.php](../../templates/login-oidc.php) — **nouveau** template (bouton fournisseur, icône SVG, sélecteur de langue).
- [app/public/assets/css/login.css](../../public/assets/css/login.css) — style du bouton fournisseur.
- [app/translations/{fr,en,nl,de}.php](../../translations/) — clé `auth.signin_with`.

## Étapes

- [ ] A. Doc `oidc-google.md` (projet, écran de consentement, OAuth client ID, redirect URI, remplir config, migrations, dépannage) + lien depuis `installation.md`.
- [ ] B1. `AuthGuard::requireLogin()` → redirige vers `/login?next=…`.
- [ ] B2. `login.php` : branche OIDC (enforceIp seul, déjà-connecté→redirect, rendu `login-oidc`).
- [ ] B3. Template `login-oidc.php` + style bouton dans `login.css`.
- [ ] B4. i18n `auth.signin_with` dans les 4 langues.

## Vérification

- `php -l` sur les fichiers modifiés ; PHPStan niveau 5 vert ; `composer test`.
- Mode OIDC (`oidc.enabled=true`) : page protégée → page avec bouton Google →
  `/auth/login` → consentement → session ouverte (`provider = google`).
- Mode legacy (`oidc.enabled=false`) : formulaire Basic Auth inchangé (non-cassant).
- Open-redirect sur `next` : déjà géré, conservé.
