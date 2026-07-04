# Revue de sécurité — ouverture publique (P7)

Checklist de posture de sécurité avant exposition publique de la plateforme
communautaire (#47). État au moment de P7. `✅` en place · `🟡` partiel /
suivi · `⬜` non applicable en l'état.

## Authentification & sessions
- ✅ **OIDC générique** (Authorization Code + PKCE, `state`, `nonce`, validation
  `id_token` JWKS/RS256, `iss`/`aud`/`exp`/`nonce`). Aucune crypto maison de JWT.
- ✅ **Aucun secret utilisateur stocké** : ni mot de passe ni e-mail — identité =
  `issuer`+`subject`+nom d'affichage.
- ✅ **Cookies de session durcis** (`App\Security\Session`) : `HttpOnly`,
  `SameSite=Lax`, `Secure` en HTTPS, `use_strict_mode`, `use_only_cookies`.
- ✅ **Fixation de session** : `session_regenerate_id(true)` à la connexion et à
  la déconnexion (`App\Security\AuthSession`).
- ✅ **Blocage de compte immédiat** : `AuthGuard` revérifie `status = active` à
  chaque requête (une session ouverte d'un compte bloqué est révoquée dès la
  requête suivante, pas seulement à la prochaine connexion). Tolérant aux pannes
  DB (pas de verrouillage global si la base est injoignable).
- ✅ **Allowlist IP** conservée (`WebAccessGuard::enforceIp`) en complément.

## API (accès machine)
- ✅ **Jetons aléatoires haute entropie** hachés en base (SHA-256), jamais
  stockés en clair ; affichés une seule fois à la création.
- ✅ **Révocables** + **rate-limit** à fenêtre fixe (`api_tokens.window_*`),
  `429` au dépassement.
- ✅ **Scope restreint** : un Bearer n'ouvre que les routes d'ingestion
  (`ingest_*`) ; tout le reste exige une session navigateur.
- ✅ **Comptes bloqués rejetés** : `authenticate()` joint `users.status = active`.
- 🟡 **Anti-bruteforce sur l'échange** : non pertinent pour des jetons aléatoires
  (non devinables) ni pour OIDC (pas de mot de passe). Surface d'attaque par
  devinette ≈ nulle ; pas de compteur d'échecs nécessaire.

## Web (formulaires & en-têtes)
- ✅ **CSRF** sur tous les formulaires mutants (`App\Security\Csrf`), y compris
  les actions admin (rôle/statut) et la persistance de langue.
- ✅ **En-têtes de sécurité** (`App\Http\SecurityHeaders`) : `X-Content-Type-
  Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`,
  `Cross-Origin-Opener-Policy`, `HSTS` (HTTPS).
- 🟡 **CSP en `Report-Only`** : l'app utilise encore des gestionnaires inline
  (`onclick`…). **Suivi** : retirer les handlers inline puis passer la CSP en
  enforcement (hors périmètre de ce PR, tracé dans #47).
- ✅ **Échappement de sortie centralisé** (`View::e()` / `te()`) sur les données
  dynamiques des templates.

## Autorisation & multi-tenant
- ✅ **Cloisonnement** : repositories scopés par `user_id` (`UserContext`),
  `UNIQUE` composites.
- ✅ **Espace admin** réservé à `role = admin` (403 sinon) ; garde-fou
  **anti-verrouillage** : un admin ne peut ni se rétrograder ni se bloquer.
- ✅ **Idempotence d'ingestion** via `UNIQUE` composites (déduplication).

## RGPD & données
- ✅ **Export** (JSON, paginé/streamé) et **suppression de compte en cascade**
  (`AccountEraser`), depuis la page compte.
- ✅ **Pages légales** (CGU/confidentialité) traduites + consentement à
  l'inscription.
- ✅ **Minimisation** : EnergyID **opt-in** par utilisateur (BE/NL), désactivé
  par défaut.

## Secrets & déploiement
- ✅ **Secrets hors dépôt** : `app/config/config.php` (`.gitignore`), conservé au
  déploiement (`git clean` sans `-x`) ; source de vérité unique.
- ✅ **Migrations versionnées** appliquées automatiquement au déploiement
  (`migrate.php`, tracé dans `schema_migrations`).
- ✅ **Contrôle config OIDC** non-bloquant au déploiement (signale champs
  manquants).

## CI / qualité
- ✅ Lint PHP `8.1/8.2/8.3`, PHPStan **niveau 6**, PHPUnit (unit + intégration
  BDD MariaDB).
- ✅ **Contrôle de syntaxe JS** (`node --check`) — comble l'absence de lint JS
  (un `\!==` parasite avait échappé à la CI en P6).

## Suivi (hors périmètre de ce PR)
- 🟡 Enforcement CSP + suppression des handlers inline.
- 🟡 Éventuel audit de pénétration externe avant ouverture réelle.
