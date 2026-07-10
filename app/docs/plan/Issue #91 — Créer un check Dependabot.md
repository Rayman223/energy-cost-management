# Issue #91 — Créer un check Dependabot

## Contexte
Ajouter [Dependabot](https://docs.github.com/code-security/dependabot) pour
surveiller **chaque semaine** les mises à jour des dépendances du dépôt (même
principe que le projet Battle-Conquest). Le dépôt possède `composer.json` /
`composer.lock` mais aucune configuration Dependabot.

Lien GH : https://github.com/Rayman223/Manage-energy-costs/issues/91

Périmètre retenu : **Composer + GitHub Actions**. En plus des dépendances
Composer visées par l'issue, on surveille aussi les actions consommées par les
workflows (`actions/checkout`, `shivammathur/setup-php`, `actions/setup-node`) —
bonne pratique, coût nul.

## Fichiers impactés
- [.github/dependabot.yml](.github/dependabot.yml) — nouveau fichier de configuration Dependabot v2

## Étapes
- [x] Créer `.github/dependabot.yml` avec 2 écosystèmes (`composer`, `github-actions`)
- [x] Planification hebdomadaire (`interval: weekly`)
- [x] Préfixe de commit `chore` aligné sur la convention du dépôt

## Vérification
- Validité YAML : `python3 -c "import yaml; yaml.safe_load(open('.github/dependabot.yml'))"`.
- Après merge sur `main` : onglet **Insights → Dependency graph → Dependabot**
  doit lister les 2 écosystèmes ; un premier lot de PR de mise à jour peut
  apparaître sous quelques minutes.
