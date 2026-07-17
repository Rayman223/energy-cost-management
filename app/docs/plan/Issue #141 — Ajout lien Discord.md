# Issue #141 — Ajout lien Discord

## Contexte

[#141](https://github.com/Rayman223/Manage-energy-costs/issues/141) : afficher un lien vers le
serveur Discord du projet, avec son logo, dans l'en-tête de toutes les pages (page d'accueil
comprise), l'URL d'invitation étant configurable dans `config.php`.

Deux contraintes du code cadrent l'implémentation :

- **Pas de layout partagé** : le `<header>` est dupliqué dans 6 templates, seul le `<head>` est
  mutualisé (partial `_head`). Le lien est donc un partial inséré dans chacun des 6 en-têtes.
- **`config.php` n'est pas global** : chaque route fait `require bootstrap.php` et passe `$config`
  en argument. L'URL transite par les données de vue, comme `oidcEnabled`.

Choix retenus : lien sur les 6 pages qui ont un en-tête ; URL seule en config (vide ou absente =
lien masqué), sans bascule `enabled` séparée.

## Fichiers impactés

- [app/config/config.example.php:113](../../config/config.example.php#L113) — section `discord.invite_url` (vide par défaut)
- [app/src/Support/DiscordLink.php](../../src/Support/DiscordLink.php) — `inviteUrl(array $config): ?string`, source unique du prédicat (calquée sur `AuthGuard::isOidcEnabled()`) ; n'accepte que `http`/`https`
- [app/templates/partials/discord-link.php](../../templates/partials/discord-link.php) — logo Discord en SVG inline (modèle : `oidc-provider-icon.php`), classe `.theme-toggle`, ne rend rien si `$url` est `null`
- Routes — `'discordUrl' => DiscordLink::inviteUrl($config)` dans les données de vue : [dashboard.php](../../routes/dashboard.php) (dashboard **et** landing `welcome`), [tariffs.php](../../routes/tariffs.php), [meter-readings.php](../../routes/meter-readings.php), [account.php](../../routes/account.php), [admin.php](../../routes/admin.php)
- Templates — `$this->partial('discord-link', ['url' => $discordUrl ?? null])` dans l'en-tête : `welcome.php`, `dashboard.php`, `tariffs.php`, `meter_readings.php`, `account.php`, `admin.php`
- Traductions — clé `common.discord` dans les 4 catalogues (`fr`, `en`, `de`, `nl`)
- [tests/Unit/Support/DiscordLinkTest.php](../../../tests/Unit/Support/DiscordLinkTest.php) — URL valide, espaces, config absente, URL vide, schéma non-http, URL malformée, formes inattendues
- Doc — [README.md](../../../README.md) « Configuration » et [installation.md](../installation.md)

## Étapes

- [x] Section `discord.invite_url` dans `config.example.php`
- [x] Helper `App\Support\DiscordLink`
- [x] Partial `discord-link.php` (SVG inline)
- [x] Clé `common.discord` dans les 4 catalogues
- [x] Câblage des 5 fichiers de routes (6 pages) et des 6 templates
- [x] Test unitaire `DiscordLinkTest`
- [x] Documentation (README, installation.md)

## Vérification

- `php -l` sur les 19 fichiers touchés : OK
- `vendor/bin/phpunit tests/Unit` : 260 tests / 2431 assertions OK (dont `DiscordLinkTest` et `TranslationParityTest`)
- PHPStan **niveau 6** (`phpstan.dist.neon`, hors baseline) : aucune erreur
- Rendu end-to-end du template `welcome` : lien présent et traduit (fr/en) avec le logo ; absent
  quand la config est vide ou que la clé `discordUrl` manque ; URL contenant des métacaractères
  HTML correctement échappée (pas d'injection).

Test manuel restant : renseigner `discord.invite_url` dans `app/config/config.php`, puis vérifier
l'affichage sur les 6 pages en thèmes clair et sombre (le SVG suit `currentColor`).
