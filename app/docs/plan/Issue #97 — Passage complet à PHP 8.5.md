# Issue #97 — Passage complet à PHP 8.5

## Contexte
Le CI testait la matrice `php: ['8.1', '8.2', '8.3']` (jobs `lint` et `tests`),
jugée redondante et ne couvrant pas la dernière version de PHP. Décision retenue :
**abandonner le support de PHP 8.1–8.4 et standardiser tout le projet sur PHP 8.5**
(dernière version). On remonte le minimum déclaré, on réduit la matrice CI à une
seule version, et on aligne toutes les mentions de version dans la doc.

Lien GH : https://github.com/Rayman223/Manage-energy-costs/issues/97

Compromis assumé : le projet ne tourne plus sous PHP 8.1–8.4 (cible de
déploiement = 8.5).

## Fichiers impactés
- [composer.json:7](composer.json#L7) — `"php": ">=8.1"` → `">=8.5"`
- [.github/workflows/ci.yml](.github/workflows/ci.yml) — matrices `lint`/`tests` → `['8.5']`, jobs `static-analysis`/`tests-db` → `8.5`
- [README.md:91](README.md#L91) — tableau prérequis → `8.5`
- [README.md:257](README.md#L257) — description CI → `PHP lint (8.5)`
- [app/scripts/deploy_unraid.sh:91](app/scripts/deploy_unraid.sh#L91) — commentaire `php >=8.5`
- [app/docs/security-review.md:70](app/docs/security-review.md#L70) — `Lint PHP 8.5`
- [app/docs/installation.md:16](app/docs/installation.md#L16) — `PHP 8.5+`
- [app/docs/architecture.md:52](app/docs/architecture.md#L52) — `PHP 8.5`

## Étapes
- [x] Bumper `composer.json` `>=8.1` → `>=8.5`
- [x] Réduire les matrices CI `lint` / `tests` à `['8.5']`
- [x] Aligner `static-analysis` et `tests-db` sur PHP `8.5`
- [x] Mettre à jour les mentions de version dans README + docs + script de déploiement

## Vérification
- `composer validate` doit rester OK après le bump.
- Validité YAML du workflow : `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))"`.
- Grep de contrôle — plus aucune mention de version PHP obsolète (hors faux
  positifs non liés, ex. TVA `8.1` dans `EuropeanCountries.php`) :
  `grep -rniE "8\.(1|2|3|4)" README.md app/docs app/scripts .github composer.json`.
- **Point d'attention** : PHP 8.5 peut faire remonter de nouvelles dépréciations
  au lint / PHPStan / PHPUnit. Si le CI échoue sur 8.5, traiter le signal (dans
  ce PR ou en issue de suivi) — surveiller via `gh pr checks`.
