# Energy Cost PRD autonome sur Unraid (#126)

Ce guide remplace le runtime PHP Energy Cost dans SWAG. Le modèle est celui de
Battle-conquest, avec un seul environnement Energy Cost : **PRD**, sans QUA.
La préparation ne change pas le vhost ; la bascule et la recette sont manuelles.
Exécuter les commandes hôte comme root, après montage de l’array.

## Architecture et commandes

| Élément | Valeur |
| --- | --- |
| Conteneur / réseau / HTTP | `energy-cost-prd` / `swagproxy` / `8080` interne, aucun port hôte |
| Sources et opérateur | `/mnt/user/www/energy-cost/src`, checkout initial explicitement revu |
| Configuration privée | `config/` → `/app/app/config` en lecture seule |
| État applicatif | `data/var/` → `/app/var` |
| Journaux | `data/logs/` → `/app/logs` |
| Dumps PHP manuels | `backups/manual/` → `/app/backups` |
| Sauvegardes opérateur | `backups/<date-pid>/`, non montées dans l’application |
| Reçus de déploiement | `state/`, non monté dans l’application |
| Base et sauvegarde récurrente | MariaDB existante, job `energyv3` de `database-backup` |

L’image contient le code et Composer sans dépendances de développement.
FrankenPHP et le scheduler tournent sous `33:33`. Aucun socket Docker ni montage
de SWAG n’est transmis à l’application. Les marqueurs de volumes évitent de
démarrer sur des répertoires vides ; ne jamais les créer pour masquer une perte
de données. Les archives privées restent en mode `700/600`.

```bash
ROOT=/mnt/user/www/energy-cost
OPS="$ROOT/src/app/scripts/deploy_unraid.sh"
bash "$OPS" prepare main             # préparer sans ouvrir le site ni les crons
bash "$OPS" activate                 # première migration, ancienne instance déjà gelée
bash "$OPS" resume-announcements     # après bascule SWAG : HTTP, Discord puis crons
bash "$OPS" update                   # livrer main ; un tag est accepté en second argument
bash "$OPS" update v1.3.0
bash "$OPS" status
bash "$OPS" backup
bash "$OPS" rollback --schema-compatible
```

Toutes les commandes se verrouillent. Avant d’agir sur un conteneur existant,
l’opérateur vérifie son label et les sources/destinations de ses quatre montages,
ainsi que la lecture seule de la configuration. Une racine ou un montage
différent est refusé avant toute interruption. La mise à jour construit avant interruption,
attend les tâches actives avant l’arrêt, sauvegarde avant migration, puis contrôle\nPHP, DB, santé et HTTP depuis SWAG.
La réponse publique doit porter le SHA attendu dans `X-Energy-Cost-Revision`.
Si une liste d’IP autorisées est configurée, son refus 403 du visiteur de contrôle
est accepté uniquement avec ce SHA et une santé interne valide ; le contrôle
n’usurpe jamais une IP autorisée.
Les reçus conservent image, commit, phase et sauvegarde. Ne pas nettoyer les images
courante/précédente ni `state/`. Les checkouts de construction sont conservés pour
diagnostic et exclus des archives ; supprimer uniquement ceux dont la livraison
est terminée et qui ne servent plus au diagnostic.

L’opérateur vient du checkout initial. Pour le mettre à jour, récupérer et
examiner la révision voulue puis faire explicitement `git -C "$ROOT/src" checkout
--detach SHA_COMPLET` sur un checkout propre. Il n’est jamais mis à jour au démarrage.
Le dépôt est public et cloné par HTTPS, sans clé SSH ni jeton dans une URL.

Variables hôte facultatives : `EC_ROOT`, `CONTAINER`, `EC_NETWORK`, `EC_PROXY`,
`EC_REPOSITORY`, `EC_BASE_URL`, `EC_HEALTH_TIMEOUT` (180 secondes par défaut),
`EC_BACKUP_CONTAINER`, `EC_BACKUP_JOB` et `EC_BACKUP_ROOT`. Pour une instance
personnalisée, adapter aussi le vhost fourni et les entrées de démarrage.

## 1. Inventorier et préparer, sans bascule

Prérequis hôte : Docker, Git, Bash, jq, flock, timeout, tar, gzip, sha256sum,
find et User Scripts. Vérifier l’espace disponible, les autres usages de SWAG,
le nom réel du vhost, les planifications et le fuseau Unraid.

```bash
mountpoint -q /mnt/user || exit 1
docker network inspect swagproxy
docker exec swag nginx -t
docker exec database-backup dbbackup list
docker inspect database-backup --format '{{range .Config.Env}}{{println .}}{{end}}' | grep '^TZ='
docker inspect swag --format '{{(index .NetworkSettings.Networks "swagproxy").IPAddress}}'
docker exec swag nginx -T 2>/dev/null | grep -n -B 8 -A 8 'energy-cost.eu'
date +%Z
readlink /etc/localtime
```

Vérifier que `energyv3` désigne bien la base existante, son horaire actuel
(documenté `30 3 * * *` dans le fuseau du générateur), la rétention 30 jours et
les dumps `.sql.gz` sous
`/mnt/user/appdata/database-backup/backups/mariadb/energyv3`.
L’opérateur refuse les dumps absents, ambigus, vides ou illisibles. Un dump
chiffré exige une vérification/déchiffrement explicite avant d’adapter l’opérateur ;
ne pas changer silencieusement le chiffrement existant. Préserver les sauvegardes
hors site et les autres jobs. Aucun nouveau job SQL récurrent n’est ajouté.

Inspecter l’ancienne racine, `var/`, `backups/` et les journaux SWAG pour repérer
tous les fichiers privés ou écrits. Expliquer les sources absentes avant de
poursuivre. Choisir le SHA complet contenant cette livraison :

```bash
EC_COMMIT=SHA_COMPLET_DE_LA_LIVRAISON
ROOT=/mnt/user/www/energy-cost
OLD=/mnt/user/appdata/swag/www/energyv3
umask 077
mkdir -p "$ROOT/config" "$ROOT/data/var" "$ROOT/data/logs" "$ROOT/backups/legacy"
git clone --no-checkout https://github.com/Rayman223/energy-cost-management.git "$ROOT/src"
git -C "$ROOT/src" fetch origin "$EC_COMMIT"
git -C "$ROOT/src" checkout --detach "$EC_COMMIT"
cp -a "$OLD/app/config/config.php" "$ROOT/config/config.php"
if [ -f "$OLD/app/config/config.local.php" ]; then cp -a "$OLD/app/config/config.local.php" "$ROOT/config/"; fi
if [ -d "$OLD/var" ]; then cp -a "$OLD/var/." "$ROOT/data/var/"; fi
if [ -d "$OLD/backups" ]; then cp -a "$OLD/backups/." "$ROOT/backups/legacy/"; fi
if [ -f "$OLD/app/public/ads.txt" ]; then cp -a "$OLD/app/public/ads.txt" "$ROOT/data/var/ads.txt"; fi
for name in energy-dynamic.log energy-dynamic.log.1 energy-load-profile.log energy-load-profile.log.1; do
    if [ -f "/mnt/user/appdata/swag/log/$name" ]; then cp -a "/mnt/user/appdata/swag/log/$name" "$ROOT/data/logs/"; fi
done
if [ -f /mnt/user/appdata/swag/log/energy-load-profile.notified ]; then
    cp -a /mnt/user/appdata/swag/log/energy-load-profile.notified "$ROOT/data/var/"
fi
printf 'EC_SCHEDULER_TZ=Europe/Brussels\n' > "$ROOT/config/runtime.env"
```

Si `src` existe, conserver ses modifications : utiliser une racine propre,
sans reset forcé. La construction emploie une archive Git du SHA résolu,
jamais les fichiers privés ou non suivis du checkout.

Remplacer `Europe/Brussels` par le fuseau IANA relevé sur Unraid pour conserver
les horaires. Le scheduler exige ce réglage explicite ; la configuration PHP
applicative reste UTC. Dans les fichiers PHP privés, conserver les réglages et
secrets existants, puis renseigner :

```php
'seo' => ['base_url' => 'https://energy-cost.eu'],
'web_security' => [
    // Conserver ici les autres réglages existants.
    'trusted_hosts' => ['energy-cost.eu'],
    'trusted_proxies' => ['IP_EXACTE_SWAG_SUR_SWAGPROXY'],
],
```

Ne pas remplacer toute une section existante par cet extrait. Vérifier que
`database.host` reste accessible depuis le nouveau conteneur ; une adresse
loopback SWAG doit être remplacée par le nom MariaDB sur le réseau, sans changer
base, compte ou données. Réserver l’IP SWAG ou actualiser la liste après sa
recréation. Ne jamais faire confiance à tout le réseau Docker. Les en-têtes
transmis par un autre pair sont ignorés. Conserver la normalisation `real_ip`
existante dans SWAG, notamment si Cloudflare le précède.

```bash
bash "$ROOT/src/app/scripts/deploy_unraid.sh" prepare main
docker inspect energy-cost-prd --format '{{.State.Health.Status}} {{json .HostConfig.PortBindings}}'
docker exec swag curl -fsS http://energy-cost-prd:8080/healthz
docker exec swag curl -i http://energy-cost-prd:8080/login -H 'Host: energy-cost.eu'
```

Attendus : santé `healthy`, aucun port hôte publié, `/healthz` répond `ok`,
les pages restent en `503` pendant la préparation. Aucune migration ou annonce
n’est lancée, et aucun cron métier ne tourne. Pour un tag initial, utiliser
`prepare v1.3.0` lorsque ce tag existe et contient cette livraison.

## 2. Geler l’ancienne instance et basculer

1. Désactiver les User Scripts prix/profils et tout ancien backup PHP récurrent
   seulement après vérification de sa couverture par `database-backup`.
   Attendre la fin des traitements déjà lancés.
2. Sauvegarder le vhost réel. Vérifier qu’il ne contient que les domaines Energy
   Cost. Le chemin ci-dessous est à remplacer par celui relevé à l’inventaire.

```bash
ROOT=/mnt/user/www/energy-cost
OLD=/mnt/user/appdata/swag/www/energyv3
VHOST=/mnt/user/appdata/swag/nginx/site-confs/energy-cost.conf
mkdir -p "$ROOT/backups/cutover"
cp -a "$VHOST" "$ROOT/backups/cutover/old-vhost.conf"
cat > "$VHOST" <<'NGINX'
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name energy-cost.eu www.energy-cost.eu;
    include /config/nginx/ssl.conf;
    return 503;
}
NGINX
docker exec swag nginx -t && docker exec swag nginx -s reload
```

Si `nginx -t` échoue, restaurer immédiatement le vhost sauvegardé, puis
revalider. Attendre la fin des requêtes PHP de l’ancienne instance. Conserver
le service fermé jusqu’à la bascule.

3. Produire le dump final et archiver l’ancienne installation :

```bash
docker exec database-backup dbbackup backup --job energyv3
tar -czf "$ROOT/backups/cutover/old-site.tar.gz" -C "$(dirname "$OLD")" "$(basename "$OLD")"
gzip -t "$ROOT/backups/cutover/old-site.tar.gz"
if [ -d "$OLD/var" ]; then cp -a "$OLD/var/." "$ROOT/data/var/"; fi
if [ -f /mnt/user/appdata/swag/log/energy-load-profile.notified ]; then
    cp -a /mnt/user/appdata/swag/log/energy-load-profile.notified "$ROOT/data/var/"
fi
chown -R 33:33 "$ROOT/data/var" "$ROOT/data/logs"
bash "$ROOT/src/app/scripts/deploy_unraid.sh" activate
```

Le reçu `migrated` atteste une sauvegarde vérifiée et les migrations. La nouvelle
instance démarre en maintenance ; ses crons restent suspendus.

4. Installer le nouveau vhost, vérifier puis ouvrir le site :

```bash
cp "$ROOT/src/deploy/swag-prd.conf" "$VHOST"
docker exec swag nginx -t && docker exec swag nginx -s reload
bash "$ROOT/src/app/scripts/deploy_unraid.sh" resume-announcements
curl -I https://energy-cost.eu/login
curl -I https://www.energy-cost.eu/
```

Après retrait de la maintenance, l’opérateur contrôle la santé et l’accès HTTP
depuis SWAG, dont le SHA reçu sur l’URL publique. Il publie ensuite les annonces
Discord en attente puis autorise les crons. Les reçus BDD évitent de republier
les versions déjà annoncées. Après correction d’un contrôle HTTP ou de santé en échec sur la candidate déjà\nmigrée, la même commande peut revalider cette image sans refaire les migrations.\nUn échec Discord laisse le site accessible et les
crons suspendus ; relancer `resume-announcements`, sans refaire les migrations.

Ne pas retirer les montages généraux de SWAG avant inventaire de leurs autres
usages. Conserver l’ancien code, les configurations et archives jusqu’à recette
complète. Les anciennes sessions SWAG ne sont pas reprises : une reconnexion
peut être nécessaire.

## 3. Crons, notifications et démarrage de l’array

Les prix tournent à `13:30` et `18:30`, les profils à `09:00`, dans
`EC_SCHEDULER_TZ`. Le passage hiver/été suit le fuseau IANA. Les créneaux
manqués ne sont pas rejoués ; chaque créneau est réclamé une fois sur disque.
Une tâche occupée ne bloque pas l’autre. Ne pas réactiver les anciens horaires
User Scripts. Les wrappers `cron_*_unraid.sh` servent uniquement au lancement
manuel et utilisent les mêmes verrous.

Les contrôles conservent cinq jours de grâce et douze mois de recherche.
Les journaux de tâches sont limités à 1 Mo avec une rotation. Un journal
indisponible produit un avertissement sans empêcher la tâche ni ses notifications.
PHP et Supervisor
ont leurs propres rotations. Les échecs produisent une alerte ; les mois manquants
sont dédupliqués et l’ancien fichier `energy-load-profile.notified` est repris.
Un mois redevenu complet puis à nouveau manquant est signalé de nouveau.

Créer un seul User Script **relais de notifications**, horaire `* * * * *` :

```bash
#!/bin/bash
exec bash /mnt/user/www/energy-cost/src/deploy/notify-unraid.sh
```

Ce relais n’exécute aucune tâche métier. Il lit et accuse réception via PHP dans
le conteneur, valide les données et appelle le programme Unraid de notification
avec des arguments fixes. Un échec garde l’alerte en attente. Comme pour tout envoi
externe, un arrêt entre l’envoi et l’accusé peut occasionner un doublon.

Pour le démarrage, installer d’abord la [PR Battle-conquest #1104](https://github.com/Battle-Conquest/Battle-Conquest/pull/1104),
qui supprime la limite de trois applications. Mettre à jour explicitement son
`start-array.sh`, puis ajouter l’entrée fournie sans remplacer les entrées privées :

```bash
BC_OPS=/mnt/user/www/battle-conquest-ops
cp -a "$BC_OPS/config/startup.json" "$BC_OPS/config/startup.before-energy-cost.json"
jq --slurpfile entry "$ROOT/src/deploy/startup-entry.json" \
  'if any(.[]; .name == $entry[0].name) then error("Energy Cost déjà déclaré") else . + $entry end' \
  "$BC_OPS/config/startup.json" > "$BC_OPS/config/startup.candidate.json"
jq -e 'type=="array" and length>0 and ([.[].name]|length==(unique|length))' "$BC_OPS/config/startup.candidate.json"
mv "$BC_OPS/config/startup.candidate.json" "$BC_OPS/config/startup.json"
bash "$BC_OPS/start-array.sh"
```

Conserver l’unique User Script **At Startup of Array** existant. Le démarrage
contrôle marqueurs, montages, réseau, MariaDB et santé. Il ne fetch, ne construit
et ne migre rien ; un échec ne bloque pas l’examen des autres applications.
La politique `unless-stopped` est conservée. Tester aussi un vrai redémarrage
d’array après la recette. Ne jamais déclencher une mise à jour depuis ce script.

## 4. Recette et contrôles locaux

- Vérifier pages et assets, `robots.txt` et `sitemap.xml`, redirections legacy,
  login/logout, callbacks OIDC/OAuth, compte et administration. Les cookies de
  session doivent être Secure/HttpOnly ; les URL de retour restent HTTPS.
- Tester IP cliente et restrictions d’accès depuis SWAG ; des en-têtes falsifiés
  depuis un autre conteneur ne doivent pas changer l’IP ou le schéma reconnus.
- Envoyer un relevé avec un jeton existant : même base, isolation conservée,
  jeton absent/révoqué refusé et limitation de débit conservée.
- Vérifier Stripe en mode test ou avec événements de test, sans paiement réel
  imposé : signature invalide rejetée, corps inchangé, événements rejoués
  idempotents. L’URL, les cinq événements Snapshot et le secret existants ne
  changent pas : voir le guide d’installation.
- Contrôler qu’aucun fichier privé n’est accessible, que `/healthz` est bloqué
  publiquement, et que les autres sites SWAG sont toujours disponibles.
- Exécuter manuellement les deux tâches ; vérifier journaux, notifications et
  reprise après échec du relais. Vérifier absence d’anciens déclenchements.
- Vérifier mise à jour, recréation, persistance et retour arrière ; simuler les
  erreurs sur une cible isolée et restaurer une sauvegarde sur une base jetable.

```bash
vendor/bin/phpunit tests/Unit
vendor/bin/phpstan analyse --configuration=phpstan.dist.neon --no-progress
python3 -m unittest discover -s tests/Deployment -p 'test_*.py'
php app/scripts/release-notes.php --validate
docker build --target production --build-arg "EC_COMMIT=$(git rev-parse HEAD)" -t energy-cost-check .
docker run --rm --entrypoint frankenphp energy-cost-check validate --config /etc/caddy/Caddyfile --adapter caddyfile
```

Les tests Python simulent Docker et utilisent des fichiers temporaires. Ils
n’accèdent pas à la production. Pour PHPUnit BDD, utiliser exclusivement une
configuration locale dérivant une base `<name>_test` isolée. La construction Docker,
les volumes Unraid, TLS et les fournisseurs externes doivent être validés sur
Unraid ; un binaire FrankenPHP local ne remplace pas cette recette.

## 5. Sauvegarde, retour arrière et restauration

`backup` suspend les écritures et crons, arrête le conteneur, demande un dump
ponctuel au générateur existant et archive `config/`, `data/` et `state/`.
`SHA256SUMS` et `manifest.json` identifient les fichiers et l’image ; seuls les
snapshots complets expirent après 30 jours. Les archives incomplètes sont
conservées pour diagnostic. Copier les snapshots privés hors du serveur avec
le mécanisme de sauvegarde existant.

Un échec de construction laisse l’instance en place. Un échec de sauvegarde avant
migration redémarre l’ancienne instance. Après début des migrations ou échec de
santé, la maintenance reste en place : ne pas supprimer les marqueurs pour
contourner le diagnostic. Le retour à une image précédente **n’annule pas SQL**.
Une reprise conserve le chemin de sauvegarde et la référence précédente.
Après échec du redémarrage suivant une sauvegarde, corriger la cause puis lancer
`resume-announcements` : seule l’image courante vérifiée redémarre, sans migration
ni publication Discord pour cette opération de sauvegarde. Une image de retour
arrière absente est refusée avant l’arrêt du service en cours.
Si Docker n’a pas pu créer le conteneur après une migration réussie,
`resume-announcements` le recrée depuis l’image exacte du reçu puis revérifie
sa santé, sans rejouer les migrations. Le retour arrière explicite reste possible
si le conteneur a disparu. Une reprise de retour arrière ne publie pas d’annonce.
Une erreur de lecture Docker est traitée comme un échec, jamais comme une preuve
d’absence de conteneur.

Si l’ancien code supporte le schéma et les données actuels :

```bash
bash "$OPS" rollback --schema-compatible
```

Cette commande sauvegarde l’état courant, démarre l’image précédente et vérifie
HTTP, sans refaire de migration ni annoncer une ancienne version. Après la
première bascule, l’ancien hébergement SWAG reste le retour possible ; vérifier
sa compatibilité avant de restaurer son vhost puis `nginx -t` et reload. Garder les
anciens crons désactivés tant que le nouveau scheduler peut tourner.

Pour un schéma incompatible, fermer le site, suspendre tous les producteurs
d’écritures et sauvegarder l’état courant. Vérifier puis restaurer d’abord sur
une **base isolée**, avec un compte limité à celle-ci :

```bash
SNAPSHOT=/mnt/user/www/energy-cost/backups/DATE-PID
(cd "$SNAPSHOT" && sha256sum -c SHA256SUMS)
gzip -t "$SNAPSHOT/database.sql.gz"
gzip -t "$SNAPSHOT/files.tar.gz"
RESTORE=/mnt/user/www/energy-cost-restore-check
mkdir -m 700 "$RESTORE"
tar -xzf "$SNAPSHOT/files.tar.gz" -C "$RESTORE"
# client.cnf privé contient l’hôte et le compte de restauration isolé.
# Créer préalablement energy_cost_restore_check avec ce seul compte.
gzip -dc "$SNAPSHOT/database.sql.gz" |
  mariadb --defaults-extra-file=/CHEMIN/PRIVE/client.cnf energy_cost_restore_check
```

Adapter la configuration extraite pour la seule base isolée ; ne pas monter
directement les secrets PRD pour le test, ne démarrer ni scheduler ni publication
Discord, et vérifier les tables/données/restauration avec l’image du manifeste.
Le dump ne doit contenir aucune instruction de sélection/création de base qui
viserait la production ; inspecter ce point avant l’import avec le compte limité.

Une restauration réelle doit traiter les écritures reçues depuis le snapshot,
y compris les relevés API et événements Stripe : conserver leur état, organiser
leur reprise puis rouvrir un seul service et un seul scheduler. Ne jamais
restaurer un ancien dump sur la production en supposant que la bascule a annulé
les écritures récentes.
