# Issue #84 — Compteur d'utilisation par template (nombre d'utilisateurs distincts)

## Contexte
Afficher, à côté de chaque template (builtin + perso **public**), le nombre
d'**utilisateurs distincts** l'ayant utilisé. Une utilisation = un utilisateur
(idempotent). Prérequis : rendre les templates perso partageables via une
visibilité **public/privé**. RGPD : à la clôture d'un compte, les templates
publics sont conservés, les privés supprimés.

Lien : https://github.com/Rayman223/Manage-energy-costs/issues/84 (relié à #80).

Décisions : garder `user_id` tel quel sur les templates publics conservés (pas
d'anonymisation dans ce lot) ; une seule PR pour les 3 phases.

## Fichiers impactés
- [app/sql/schema.sql](app/sql/schema.sql) — colonnes/table
- app/sql/migrations/<date>_tariff_template_visibility.sql — visibility
- app/sql/migrations/<date>_tariff_template_usages.sql — table usages
- [app/src/Repository/TariffTemplateRepository.php](app/src/Repository/TariffTemplateRepository.php) — visibilité + purge usages au delete
- app/src/Repository/TariffTemplateUsageRepository.php — nouveau (record + countsByRef)
- [app/src/Service/AccountEraser.php](app/src/Service/AccountEraser.php) — suppr. templates privés
- [app/public/tariffs.php](app/public/tariffs.php) — visibilité au save, upsert usage, compteurs à la vue
- [app/templates/tariffs.php](app/templates/tariffs.php) — option public/privé, champ source_template, chips + badge
- [app/templates/account.php](app/templates/account.php) — avertissement RGPD
- app/translations/{fr,en,nl,de}.php — libellés visibilité + avertissement
- Tests DB associés

## Étapes
- [ ] Phase 1 — Migration + schéma : colonne `visibility` sur `tariff_templates`
- [ ] Phase 1 — Repository : `save($visibility)`, `findForEnergy`/`findById` élargis (owner OU public), flag `is_owner`/`visibility` exposés
- [ ] Phase 1 — UI création (option public/privé) + import public + traductions
- [ ] Phase 2 — Migration + schéma : table `tariff_template_usages`
- [ ] Phase 2 — `TariffTemplateUsageRepository` (record idempotent + countsByRef)
- [ ] Phase 2 — Câblage save (champ `source_template`) + affichage chips + badge
- [ ] Phase 3 — `AccountEraser` : suppr. templates privés (publics conservés)
- [ ] Phase 3 — Avertissement à côté du bouton de suppression (4 langues)
- [ ] Phase 3 — Purge usages au delete d'un template perso
- [ ] Phase 4 — Tests DB + PHPStan 6 + `php -l`

## Vérification
- Compte A crée un template public ; compte B le voit, l'importe, sauvegarde une
  grille → compteur = 1 ; B re-sauvegarde → toujours 1 ; A sauvegarde aussi → 2.
- Template privé de A invisible pour B.
- Clôture de A : template public conservé, template privé supprimé.
- `php -l` sur fichiers modifiés + PHPStan niveau 6 vert.
