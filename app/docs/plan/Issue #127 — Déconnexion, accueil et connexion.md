# Issue #127 — Bouton de déconnexion, page d'accueil (landing) et bouton de connexion

## Contexte

En mode OIDC (multi-utilisateurs, inscription ouverte), l'application n'avait **aucune
page publique** : la racine `/` sert directement le dashboard protégé, et un visiteur
anonyme était renvoyé vers `/login` (page de connexion brandée introduite par #124) sans
jamais voir de présentation du produit. De plus, une fois connecté, **aucun bouton de
déconnexion** n'était exposé dans l'UI (la route `/auth/logout` existait pourtant déjà).

Cette issue ajoute :
1. une **page d'accueil publique** (landing) qui présente le concept et invite à se connecter ;
2. le **bouton de déconnexion** manquant dans les en-têtes ;
3. un **bouton de connexion** clair (le CTA de la landing) menant à la page `/login`.

Lien : [#127](https://github.com/Rayman223/Manage-energy-costs/issues/127).

## État préexistant (à réutiliser, ne pas dupliquer)

- Route/logout : [app/routes/auth/logout.php](../../routes/auth/logout.php) → `AuthSession::logout()` puis redirection vers `/`.
- Page de connexion brandée (#124) : [app/routes/login.php](../../routes/login.php) rend
  [app/templates/login-oidc.php](../../templates/login-oidc.php) (bouton « Se connecter avec Google » → `/auth/login`).
  C'est **le** point d'extension multi-fournisseurs de [#122](https://github.com/Rayman223/Manage-energy-costs/issues/122).
- `AuthGuard::requireLogin()` redirige déjà les anonymes vers `/login?next=…`.
- Clé i18n `auth.sign_out` déjà traduite (fr/en/de/nl).

## Décisions

- **Landing sur `/` conditionnelle** : anonyme (mode OIDC) → landing ; connecté → dashboard.
  En Basic Auth / OIDC désactivé, comportement inchangé.
- **CTA de la landing → `/login`** (page brandée existante), pas de bouton fournisseur dupliqué
  sur la landing : un seul point d'extension pour #122.
- **Bouton de déconnexion par template** (pas de header partagé mutualisé), gaté sur `oidcEnabled`.

## Changements

- [app/routes/dashboard.php](../../routes/dashboard.php) — avant `AuthGuard::protect()`, si OIDC activé
  et `AuthSession::userId() === null` : `enforceIp` puis rendu du template `welcome`, `return`.
  Ajout du flag `oidcEnabled` aux données du dashboard.
- [app/templates/welcome.php](../../templates/welcome.php) — **nouveau** : header léger (logo + langues + thème),
  hero (titre + accroche + CTA `landing.cta` → `/login`), 4 cartes de features, footer (CGU / confidentialité).
- [app/public/assets/css/welcome.css](../../public/assets/css/welcome.css) — **nouveau** : mise en page de la
  landing, repose sur `tokens.css` (fond pointillé, accents ambre, bi-thème automatique).
- Bouton de déconnexion (🚪 icône ou lien texte selon l'idiome du header) dans
  [dashboard.php](../../templates/dashboard.php), [account.php](../../templates/account.php),
  [admin.php](../../templates/admin.php), [meter_readings.php](../../templates/meter_readings.php),
  [tariffs.php](../../templates/tariffs.php), gaté sur `oidcEnabled`.
- Routes [account.php](../../routes/account.php), [admin.php](../../routes/admin.php),
  [meter-readings.php](../../routes/meter-readings.php), [tariffs.php](../../routes/tariffs.php) — passent
  `'oidcEnabled' => (($config['oidc']['enabled'] ?? false) === true)` au template.
- Traductions `landing.*` dans [fr](../../translations/fr.php)/[en](../../translations/en.php)/[de](../../translations/de.php)/[nl](../../translations/nl.php).

## Vérification effectuée

- `php -l` OK sur tous les fichiers modifiés/créés ; **PHPStan niveau 6** : `No errors`.
- Rendu du template `welcome` dans les 4 langues (CTA → `/login`, features, i18n résolu).
- Serveur de dev avec `oidc.enabled = true` :
  - `GET /` anonyme → **200**, landing affichée (pas de rebond immédiat vers l'IdP) ;
  - `GET /login` → **200**, bouton « Se connecter avec Google » → `/auth/login`.
- Bouton de déconnexion : présent si `oidcEnabled = true`, absent sinon (non-régression Basic Auth).

## Hors périmètre

- Choix multi-fournisseurs (Microsoft/Entra, OIDC générique) → **#122** (étend `/login`).
- RP-initiated logout côté IdP → différé (cf. commentaire dans `auth/logout.php`).
