# Prix dynamiques ENTSO-E — configuration et cron quart-horaire

Guide pas-à-pas pour mettre en service la récupération automatique des prix
day-ahead du marché spot via **ENTSO-E Transparency Platform**, y compris les
valeurs **quart-horaires (15 min)**, et leur stockage en base.

À la fin de ce guide, un cron alimente chaque jour la table `dynamic_prices` avec
96 points par jour et par zone de marché, et le dashboard peut facturer au
quart-horaire.

---

## 1. Comment fonctionne la chaîne

```
cron_dynamic_prices.php     → orchestration : zones, fenêtre, boucle
  EntsoePriceClient         → appel HTTP de l'API ENTSO-E (documentType A44)
  EntsoePriceParser         → parsing XML, €/MWh → €/kWh, détection résolution
  DynamicPriceRepository    → upsert idempotent dans `dynamic_prices`
```

| Rôle | Fichier |
| --- | --- |
| Script CLI | [`app/scripts/cron_dynamic_prices.php`](../scripts/cron_dynamic_prices.php) |
| Client HTTP | [`app/src/Service/EntsoePriceClient.php`](../src/Service/EntsoePriceClient.php) |
| Parseur XML | [`app/src/Service/EntsoePriceParser.php`](../src/Service/EntsoePriceParser.php) |
| Persistance | [`app/src/Repository/DynamicPriceRepository.php`](../src/Repository/DynamicPriceRepository.php) |

> **Point essentiel — il n'y a rien à configurer pour obtenir du 15 min.**
> Le client demande le document `A44` **sans paramètre de résolution**. C'est
> ENTSO-E qui publie en `PT15M` sur les zones passées au MTU 15 minutes (la
> Belgique, notamment), et le parseur qui détecte la résolution reçue :
> `PT15M → 15`, `PT30M → 30`, `PT60M`/`PT1H → 60`. La valeur atterrit dans la
> colonne `resolution_min`. Aucune option de config, aucun argument CLI ne
> pilote ce choix.

Le seul réglage « 15 min » côté application est le **mode de tarification de la
grille tarifaire** (`tariff_grids.pricing_mode = dynamic_quarter`), qui décide si
le calcul de coût *utilise* ces points quart-horaires — voir l'étape 6.

---

## 2. Obtenir un token ENTSO-E

Le token est **gratuit** mais n'est pas délivré automatiquement.

1. Créer un compte sur <https://transparency.entsoe.eu/> (*Login → Register*).
2. Envoyer un e-mail à **transparency@entsoe.eu** :
   - objet : `Restful API access`
   - corps : indiquer l'adresse e-mail **exacte** du compte créé à l'étape 1.
3. Attendre la confirmation (généralement 1 à 3 jours ouvrés).
4. Une fois l'accès activé : se connecter, puis
   *My Account Settings* → **Web API Security Token** → *Generate a new token*.
5. Copier le token (chaîne de type UUID).

> Sans cette activation manuelle, l'API répond `HTTP 401` même avec un compte
> valide. C'est le cas de figure le plus fréquent lors d'une première mise en
> service.

---

## 3. Configurer `app/config/config.php`

Le fichier `app/config/config.php` est **hors dépôt** (`.gitignore`) et préservé
par le script de déploiement. S'il n'existe pas encore, le créer à partir de
[`app/config/config.example.php`](../config/config.example.php).

Renseigner la section `dynamic_prices` :

```php
'dynamic_prices' => [
    'enabled'        => true,                            // ← premier interrupteur (false par défaut)
    'provider'       => 'entsoe',
    'api_url'        => 'https://web-api.tp.entsoe.eu/api',
    'security_token' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', // token de l'étape 2
    'bidding_zone'   => '10YBE----------2',              // zone par défaut du site
    'timeout'        => 30,                              // secondes
],
```

Zones de marché (codes EIC) les plus courantes :

| Pays / zone | Code EIC |
| --- | --- |
| Belgique | `10YBE----------2` |
| France | `10YFR-RTE------C` |
| Pays-Bas | `10YNL----------L` |
| Allemagne – Luxembourg | `10Y1001A1001A82H` |

> **La zone n'est pas globale au site — elle dépend de l'utilisateur.**
> `dynamic_prices.bidding_zone` n'est que le **défaut**, appliqué aux profils qui
> n'en déclarent pas. Chaque utilisateur peut choisir la sienne sur son profil
> (étape 6) : un utilisateur français saisit `10YFR-RTE------C` et le cron
> récupérera les prix français **en plus** des belges, dans la même exécution.
> Il n'y a donc rien à changer ici pour desservir plusieurs pays ; il suffit que
> les profils soient renseignés. Seuls le **token** et l'`api_url` sont réellement
> globaux au site.
>
> À l'inverse, une zone déclarée sur un profil mais que le cron ne récupère pas
> (parce qu'il n'a pas tourné depuis) laisse cet utilisateur sans prix : ajouter
> un profil dans un nouveau pays justifie une exécution manuelle du cron
> (étape 7) pour ne pas attendre la passe du lendemain.

Vérifier la config :

```bash
php app/scripts/config_check.php
```

Codes de sortie : `0` = OK, `1` = au moins une erreur, `2` = au moins un
avertissement (avec `--strict`). Un `security_token` resté à `change_me` est
signalé comme sentinelle non remplacée.

---

## 4. Préparer la base de données

```bash
# Tables (CREATE TABLE IF NOT EXISTS — sans effet si déjà présentes)
mariadb -u <user> -p <base> < app/sql/schema.sql

# Migrations versionnées (voir d'abord ce qui serait appliqué)
php app/scripts/migrate.php --dry-run
php app/scripts/migrate.php
```

Table cible, [`app/sql/schema.sql`](../sql/schema.sql) :

```sql
CREATE TABLE IF NOT EXISTS dynamic_prices (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    energy_type    ENUM('electricity') NOT NULL DEFAULT 'electricity',
    bidding_zone   VARCHAR(32) NOT NULL DEFAULT '10YBE----------2',
    period_start   DATETIME NOT NULL COMMENT 'Début intervalle (UTC)',
    period_end     DATETIME NOT NULL,
    resolution_min SMALLINT UNSIGNED NOT NULL COMMENT '15 ou 60',
    price_eur_kwh  DECIMAL(12,7) NOT NULL COMMENT 'Prix spot day-ahead €/kWh (HTVA, hors marge)',
    source         VARCHAR(50) NOT NULL DEFAULT 'entsoe',
    fetched_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dynamic_prices (energy_type, bidding_zone, resolution_min, period_start),
    INDEX idx_dynamic_prices_period (period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Trois points à retenir :

- La clé unique inclut **`bidding_zone`** : les prix belges, français, néerlandais…
  cohabitent dans la même table et sont lus par zone. Rien à cloisonner
  manuellement pour un site multi-pays.
- La clé unique inclut **`resolution_min`** (migration
  `2026-07-11_pricing_mode_and_native_hourly.sql`). Les séries horaires et
  quart-horaires coexistent donc sans s'écraser, et l'insertion est **idempotente**
  (`INSERT … ON DUPLICATE KEY UPDATE`) : relancer le cron ne crée pas de doublon.
- `period_start` / `period_end` sont stockés en **UTC**. L'affichage est reconverti
  vers le fuseau du profil (`user_profiles.timezone`). Ne pas comparer ces colonnes
  à une heure locale dans vos requêtes de contrôle.

---

## 5. Fuseaux horaires : ce qui est converti, et où

Tout est stocké en **UTC**, et les conversions nécessaires sont déjà faites par le
code. **Il n'y a aucun réglage de fuseau à ajouter pour ENTSO-E.** Cette section
explique pourquoi, parce que le sujet est une source d'erreurs classique.

| Étape | Fuseau | Qui s'en charge |
| --- | --- | --- |
| Fenêtre demandée par le cron | calculée dans `config.timezone`, **convertie en UTC** pour la requête | `EntsoePriceClient` : `$from->setTimezone(new DateTimeZone('UTC'))->format('YmdHi')` |
| Horodatages du XML reçu | **déjà en UTC**, suffixés `Z` (ex. `2026-07-27T22:00Z`) | ENTSO-E |
| Instants écrits en base | **UTC** | `EntsoePriceParser` : `->setTimezone(new DateTimeZone('UTC'))` |
| Heures affichées à l'écran | fuseau de l'utilisateur | `user_profiles.timezone` |

### Le réglage CET/CEST de votre compte ENTSO-E n'a aucun effet ici

Le fuseau choisi dans *My Account Settings* sur transparency.entsoe.eu ne
s'applique qu'à l'**interface web** et aux exports CSV téléchargés depuis le site.
L'**API REST**, elle, renvoie toujours des horodatages UTC explicitement suffixés
`Z` dans le XML — quel que soit le réglage du compte. Rien n'est donc à
convertir manuellement, et laisser son compte en CET/CEST est sans conséquence
sur les données enregistrées.

Deux conséquences pratiques :

- `new DateTimeImmutable('2026-07-27T22:00Z')` construit l'instant à partir du
  suffixe `Z`, **pas** du fuseau PHP courant : le résultat est identique que PHP
  tourne en `UTC`, `Europe/Brussels` ou `America/New_York`.
- En été (CEST, UTC+2), une journée belge du 28/07 00:00 → 29/07 00:00 correspond
  en base à `2026-07-27 22:00` → `2026-07-28 22:00`. Ce décalage apparent de deux
  heures dans les requêtes SQL est donc **normal** : c'est bien la même journée.

### Fuseau du serveur

- **`config.timezone`** doit valoir `'UTC'` sur tous les environnements (voir
  [`config.example.php`](../config/config.example.php)) : c'est le fuseau appliqué
  par `bootstrap.php` via `date_default_timezone_set()`. La fenêtre du cron
  (`today 00:00` → `+2 days`) est calculée dans ce fuseau — elle couvre le
  day-ahead J+1 dans les deux cas, mais UTC garde les bornes lisibles.
- Le **cron système** (crontab ou Unraid), lui, s'exécute dans le fuseau du
  serveur. C'est le seul endroit où l'heure locale compte vraiment : voir la note
  de l'étape 9 sur l'horaire à retenir selon que le serveur est en UTC ou en CET.

---

## 6. Régler la grille tarifaire et le profil

Deux réglages, à deux endroits **différents depuis #245** :

**Page /tariffs — sur la grille électricité concernée :**

- **Tarif de cette grille** → *Tarif dynamique — quart-horaire (15 min)*
  (`tariff_grids.pricing_mode = dynamic_quarter`). Les autres valeurs sont `fixed`
  et `dynamic_hourly`.

Le mode appartient à la grille, donc au contrat, et vaut pour **sa seule période
de validité**. Pour dater une bascule fixe → dynamique, on ne modifie pas la
grille en cours : on en crée une nouvelle démarrant à la date de bascule. Les mois
antérieurs restent alors calculés au tarif qui était réellement le vôtre, et un
mois à cheval est facturé en deux moitiés — le tarif fournisseur avant, le prix de
marché après.

**Page /account :**

- **Zone de marché ENTSO-E** → le code EIC **du pays de l'utilisateur** (tableau de
  l'étape 3). Un utilisateur en France saisit `10YFR-RTE------C`, un utilisateur
  en Belgique `10YBE----------2`. Laisser vide revient à utiliser la zone par
  défaut du site.

La zone reste au profil : elle est géographique, pas contractuelle. C'est ce champ,
et non la config, qui détermine les prix appliqués à un utilisateur donné. Cascade
de repli : profil → `config.dynamic_prices.bidding_zone` →
`DynamicPriceRepository::DEFAULT_ZONE` (`10YBE----------2`).

Côté récupération, le cron lit `SELECT DISTINCT bidding_zone FROM user_profiles`
et fusionne le résultat avec la zone de la config : **une seule exécution couvre
tous les utilisateurs**, quel que soit leur pays. Un site avec des profils belges
et français interroge donc ENTSO-E deux fois par passe et remplit les deux séries
de prix — sans configuration supplémentaire.

> Le prix ENTSO-E est un **index brut** : aucun fournisseur ne le facture tel
> quel. La formule contractuelle (`spot × coefficient × (1 + TVA) + offset`) se
> saisit dans la grille tarifaire sous **/tariffs**, via les lignes
> `spot_coefficient` et `spot_offset`. Voir
> [`architecture.md`](architecture.md).

---

## 7. Première exécution manuelle

Sur l'hôte (installation classique) :

```bash
php /var/www/Manage-energy-costs/app/scripts/cron_dynamic_prices.php
```

Dans un conteneur (Unraid / SWAG) :

```bash
docker exec -w /config/www/energyv3 swag php app/scripts/cron_dynamic_prices.php
```

Sortie attendue — **une ligne par zone rencontrée**, ici un site avec des profils
belges et français :

```
[OK] 10YBE----------2 : 192 prix enregistrés (2026-07-27 → 2026-07-29).
[OK] 10YFR-RTE------C : 192 prix enregistrés (2026-07-27 → 2026-07-29).
```

Le script **n'accepte aucun argument** : ni `--zone`, ni `--from` / `--to`, ni
`--dry-run`. Il traite systématiquement toutes les zones (config + profils) et sa
fenêtre est fixe : **aujourd'hui 00:00 → après-demain 00:00** dans le fuseau de
`config.timezone` (UTC par convention, étape 5), ce qui couvre le jour courant et
le day-ahead J+1.

Préfixes de sortie et codes de retour :

| Sortie | Signification | Code |
| --- | --- | --- |
| `[OK] <zone> : N prix enregistrés (…)` | succès | `0` |
| `[SKIP] Tarif dynamique désactivé…` | `enabled` est à `false` | `0` |
| `[WARN] <zone> : aucun prix retourné…` | requête acceptée, aucune donnée | `0` |
| `[ERROR] Token ENTSO-E manquant…` | token vide ou `change_me` | `1` |
| `[ERROR] Provider non supporté: …` | `provider` ≠ `entsoe` | `1` |
| `[ERROR] <zone> : <message>` | erreur HTTP ou XML sur une zone | `1` |

Le code `1` est renvoyé dès qu'**au moins une** zone est en erreur ; les autres
zones sont malgré tout traitées.

---

## 8. Vérifier en base

**Inventaire de la journée UTC en cours** — le filtre de date est indispensable :
sans lui, `COUNT(*)` totalise tout l'historique et grossit à chaque jour écoulé,
ce qui n'a rien d'anormal.

```sql
SELECT bidding_zone,
       resolution_min,
       COUNT(*)          AS points,
       MIN(period_start) AS debut_utc,
       MAX(period_end)   AS fin_utc
FROM dynamic_prices
WHERE period_start >= UTC_DATE()
  AND period_start <  UTC_DATE() + INTERVAL 1 DAY
GROUP BY bidding_zone, resolution_min;
```

Chaque zone en MTU 15 min doit afficher une ligne `resolution_min = 15` avec
exactement **96 points** (24 h × 4). Une ligne `resolution_min = 60` peut
subsister : ce sont des données antérieures à la bascule MTU15, elles ne gênent
pas.

**Fraîcheur, zone par zone** — le `GROUP BY` est tout aussi important : avec une
seule valeur globale, une zone parfaitement à jour masquerait une zone qui ne
reçoit plus rien.

```sql
SELECT bidding_zone,
       resolution_min,
       MAX(period_end) AS dernier_point_utc
FROM dynamic_prices
GROUP BY bidding_zone, resolution_min;
```

Après la passe de 13:30, **chaque zone** utilisée doit couvrir la fin de la
journée J+1 (soit un `dernier_point_utc` au-delà de demain 00:00 UTC). Une zone
en retard sur les autres signale une zone récemment ajoutée par un profil, ou un
code EIC invalide.

---

## 9. Planifier le cron

Les prix day-ahead sont publiés autour de **13h heure de marché** (CET l'hiver,
CEST l'été). La planification retenue est **13:30 + une passe de rattrapage à
18:30** : l'insertion étant idempotente, la seconde exécution ne coûte qu'un appel
API et rattrape les publications en retard.

Ces heures s'entendent dans le **fuseau du serveur**, qui est le seul fuseau que
le cron connaisse (l'application, elle, travaille en UTC — étape 5) :

| Fuseau du serveur | Expression cron | Correspond à |
| --- | --- | --- |
| `Europe/Brussels` (ou autre fuseau CET/CEST) | `30 13,18 * * *` | 13:30 et 18:30 heure de marché, toute l'année |
| `UTC` | `30 12,17 * * *` | 13:30/18:30 en hiver, 14:30/19:30 en été — après publication dans les deux cas |

### Crontab classique

```cron
# Prix dynamiques day-ahead ENTSO-E — passe principale + rattrapage
# (serveur en CET/CEST ; en UTC, préférer « 30 12,17 * * * »)
30 13,18 * * * /usr/bin/php /path/app/scripts/cron_dynamic_prices.php >> /var/log/energy-dynamic.log 2>&1
```

### Unraid (plugin *User Scripts*)

Le dépôt fournit un script prêt à l'emploi :
[`app/scripts/cron_dynamic_prices_unraid.sh`](../scripts/cron_dynamic_prices_unraid.sh).
Il vérifie que le conteneur tourne, exécute le cron PHP dedans, horodate la
sortie dans un log, applique une rotation simple et **propage le code de sortie**.

Variables surchargeables (mêmes conventions que
[`deploy_unraid.sh`](../scripts/deploy_unraid.sh)) :

| Variable | Défaut |
| --- | --- |
| `APP_NAME` | `energyv3` |
| `CONTAINER` | `swag` |
| `CONTAINER_APP_DIR` | `/config/www/$APP_NAME` |
| `LOG_FILE` | `/mnt/user/appdata/swag/log/energy-dynamic.log` |
| `LOG_MAX_BYTES` | `1048576` (1 Mo) |

**Mise en place :**

1. Installer le plugin **User Scripts** (*Apps → User Scripts*) si ce n'est pas
   déjà fait.
2. *Settings → User Scripts → **Add New Script***, nommer par exemple
   `energy-dynamic-prices`.
3. *Edit Script* et coller ce **wrapper** — le plugin exécute sa propre copie du
   script, donc on délègue à la version du dépôt pour ne pas avoir à recoller le
   contenu à chaque mise à jour :

   ```bash
   #!/bin/bash
   # Délègue au script versionné du dépôt (toujours à jour après déploiement).
   exec bash /mnt/user/appdata/swag/www/energyv3/app/scripts/cron_dynamic_prices_unraid.sh
   ```

4. *Set Schedule* → **Custom**, puis saisir :

   ```cron
   30 13,18 * * *
   ```

5. Cliquer **Run Script** une première fois et vérifier la fenêtre de log : elle
   doit se terminer par une ligne `[OK] … prix enregistrés`.

> Le cron Unraid s'exécute dans le **fuseau du serveur** (*Settings → Date and
> Time*), pas en UTC. Vérifier ce réglage avant de saisir l'horaire et se reporter
> au tableau ci-dessus : un serveur en UTC demande `30 12,17 * * *`. Le fuseau du
> serveur n'a en revanche **aucune influence sur les données** — celles-ci sont
> horodatées en UTC par ENTSO-E puis stockées telles quelles (étape 5).

Le log est consultable à tout moment :

```bash
tail -f /mnt/user/appdata/swag/log/energy-dynamic.log
```

---

## 10. Dépannage

| Symptôme | Cause probable | Action |
| --- | --- | --- |
| `[SKIP] Tarif dynamique désactivé` | `dynamic_prices.enabled` encore à `false` | Étape 3 |
| `[ERROR] Token ENTSO-E manquant` | token vide ou resté à `change_me` | Étape 3 |
| `Erreur API ENTSO-E (HTTP 401)` | token généré mais accès API non activé | Étape 2 (e-mail à transparency@entsoe.eu) |
| `Erreur API ENTSO-E (HTTP 400)` | fenêtre ou paramètres refusés | Vérifier l'horloge du serveur et le code EIC |
| Message issu d'un `Acknowledgement_MarketDocument` | zone EIC inexistante, ou aucune publication sur la fenêtre | Corriger `bidding_zone` ; retenter après 13h |
| `[WARN] aucun prix retourné` | zone sans marché day-ahead, ou publication non encore faite | Laisser la passe de 18:30 rattraper |
| Erreur cURL / timeout | `curl` absent du conteneur, ou sortie réseau bloquée | Installer l'extension PHP `curl` ; augmenter `timeout` |
| Le dashboard affiche du horaire alors que la base contient bien du `resolution_min = 15` | seuils de couverture non atteints | Voir ci-dessous |
| « Aucun prix dynamique pour cette période (lancez cron_dynamic_prices). » | aucun prix en base sur la période affichée | Exécuter le cron ; vérifier l'étape 8 |
| Les `period_start` en base semblent décalés de 1 à 2 h par rapport à l'heure belge | comportement normal : stockage en UTC | Étape 5 — rien à corriger |
| Une zone de profil n'a aucun prix alors que les autres en ont | zone ajoutée après la dernière passe du cron, ou code EIC invalide | Relancer le cron (étape 7) ; vérifier le code EIC |

### Repli automatique vers l'horaire

[`CostCalculationService`](../src/Service/CostCalculationService.php) n'applique le
calcul quart-horaire natif que si **deux seuils de 80 %** sont satisfaits sur la
période :

- `QUARTER_READINGS_MIN_PCT` — au moins 80 % de la consommation est portée par des
  créneaux **mesurés au pas 15 min** ;
- `PRICE_COVERAGE_MIN_PCT` — au moins 80 % de la consommation est portée par des
  créneaux **disposant d'un prix** 15 min.

> Ces pourcentages sont **pondérés par les kWh consommés**, pas par le nombre de
> créneaux : ce sont les kilowattheures qui sont facturés, pas les cases du
> calendrier. Un trou de prix sur une nuit creuse pèse donc beaucoup moins qu'un
> trou en pointe du soir. (Sur une période sans consommation, le ratio en kWh
> n'ayant pas de sens, le calcul retombe sur la proportion de créneaux.)

Sinon le calcul se rabat, dans l'ordre : `readings_not_quarter` →
`no_quarter_prices` → `native_hourly` → `avg_hourly`. Un mois affiché en horaire
alors que la base contient du 15 min signale donc le plus souvent un **trou de
prix aux heures fortement consommées** (cron non exécuté certains jours) ou des
**relevés horaires**, pas un problème de configuration ENTSO-E.

---

## Voir aussi

- [`architecture.md`](architecture.md) — tarif dynamique, formule spot, replis de calcul
- [`installation.md`](installation.md) — installation de production sur Unraid
- [`README.md`](../../README.md) — section *Cron jobs*
