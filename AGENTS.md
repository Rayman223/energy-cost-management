# Consignes du dépôt

## Collaboration

- Répondre en français, sauf demande contraire.
- Si une demande est ambiguë, sous-spécifiée ou implique plusieurs choix aux conséquences différentes, poser les questions utiles en un seul passage, avec des options concrètes et une recommandation. Ne pas interrompre pour un détail déductible du code ou un choix réversible; avancer avec une hypothèse raisonnable et la signaler.
- Ne jamais attendre ni surveiller la CI après un push ou une mise à jour de PR. Effectuer les vérifications locales demandées, fournir le lien de la PR et laisser l'utilisateur consulter la CI.
- Tout changement de schéma ou de données de base doit inclure une migration versionnée et idempotente dans la même PR. Maintenir aussi `ensureSchema()` et `database.sql` à jour lorsqu'ils font partie du mécanisme du projet.
- Les notes de release Discord doivent couvrir toutes les modifications livrées, y compris refactorings, tests, dépendances, CI, documentation et configuration publique. Ce projet open-source ne filtre pas les annonces selon leur impact visible pour l'utilisateur. Préparer les notes en anglais dans `releases/<version>.json` et mettre à jour `VERSION`; ne jamais inclure de secrets ni modifier un manifeste déjà publié.
- Préfixer les commandes shell avec `rtk` lorsque cet outil est disponible et requis par l'environnement.

## Projet

- Application PHP sans framework, avec Composer et PHPUnit.
- L'autoloader maison de `app/bootstrap.php` associe `App\\` à `app/src/`; le namespace d'une classe doit suivre son chemin sous `app/src/`. Le bootstrap charge `vendor/autoload.php` si présent.
- Le flux applicatif est `app/public/index.php` → `app/src/Http/Router.php` → `app/routes/*.php` → Services → Repositories → Infrastructure. Les routes font le câblage et le rendu; la logique métier reste dans les Services et Repositories. Les objets-valeur sont dans `app/src/Domain` et le contrôle d'accès web dans `app/src/Security/WebAccessGuard.php`.
- Dépendances principales : `jumbojett/openid-connect-php` à l'exécution et `phpunit/phpunit` pour le développement.
- Tout fichier PHP commence par `declare(strict_types=1);`.
- Les tests unitaires sont dans `tests/Unit`, les tests d'intégration dans `tests/Integration`, configurés par `phpunit.xml.dist`. L'intégration BDD nécessite MariaDB.
- La CI utilise PHPStan niveau 6 (`phpstan.dist.neon` et baseline). Le code nouveau doit passer sans être ajouté à la baseline.
- Réutiliser les services et repositories existants avant d'introduire une logique parallèle.

Les instructions de configuration Claude du dépôt ont été portées vers les fichiers Codex. Voir `.codex/README.md` pour les correspondances et les réglages Claude sans équivalent direct.
