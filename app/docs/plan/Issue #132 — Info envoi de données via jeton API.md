# Issue #132 — Info utilisateur : envoyer ses données via le jeton API

## Contexte

La carte « Jetons API » de [Mon compte](app/routes/account.php) permet de créer/révoquer un
jeton mais n'explique pas **comment l'utiliser** pour pousser des relevés (URL, en-tête
`Authorization: Bearer`, format JSON). La doc technique existe ([app/docs/api-contract.md](app/docs/api-contract.md),
[app/scripts/agent_push.php](app/scripts/agent_push.php)) mais n'est **pas servie aux
utilisateurs**. Lien GH : https://github.com/Rayman223/Manage-energy-costs/issues/132

Décision : page web dédiée détaillée (les 3 flux + exemple curl) + résumé court avec lien
depuis la carte Jetons API.

## Fichiers impactés

- [app/public/index.php:54](app/public/index.php#L54) — route `'/api-guide' => 'api-guide.php'`.
- [app/routes/api-guide.php](app/routes/api-guide.php) — nouveau front controller (calqué sur `privacy.php`), dérive l'URL absolue de l'API.
- [app/templates/api-guide.php](app/templates/api-guide.php) — nouveau template (calqué sur `legal.php`), sections élec/gaz/eau + curl.
- [app/public/assets/css/api-guide.css](app/public/assets/css/api-guide.css) — style des blocs `<pre><code>`.
- [app/templates/account.php:171](app/templates/account.php#L171) — résumé + lien vers `/api-guide`.
- [app/translations/fr.php](app/translations/fr.php) + `en.php` / `de.php` / `nl.php` — clés `account.tokens_usage*`, `apiguide.*`.

## Étapes

- [ ] Route `/api-guide` dans le front controller
- [ ] `app/routes/api-guide.php` (URL absolue de l'API depuis l'hôte)
- [ ] `app/templates/api-guide.php` (3 flux + curl copiable)
- [ ] `api-guide.css`
- [ ] Résumé + lien dans la carte Jetons API
- [ ] Clés i18n (fr/en/de/nl)

## Vérification

`php -l` sur les fichiers touchés + PHPStan niveau 5. Dev : `php -S localhost:8000 app/public/index.php`,
`GET /api-guide` → 200 avec URL absolue correcte dans le curl ; `GET /account` → lien présent.
Bout en bout : créer un jeton, exécuter le curl du guide → `{"ok":true,…}`.
