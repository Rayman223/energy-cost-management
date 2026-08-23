# Installation & onboarding (Unraid)

End-to-end setup of the community platform on **Unraid** (app served by the
**SWAG** container = Nginx + PHP-FPM, with a **MariaDB** container): deploy the
code, create the schema, bring up the **owner** account, migrate data from the old
single-home project, and seed the first Belgian tariff templates.

> All CLI commands run inside the SWAG container, from the app directory
> (`/config/www/energyv3` by default), e.g.
> `docker exec -w /config/www/energyv3 swag php app/scripts/migrate.php`.

---

## Prerequisites

- Unraid with the **SWAG** container (PHP 8.4+, `pdo_mysql`, `curl`; `intl`
  recommended) and a **MariaDB** (10.11+) container.
- A database and user for the app (e.g. `energy` / `energy_user`).
- For OIDC (recommended): an OpenID Connect client at your provider (Google, or a
  self-hosted IdP) — client id/secret and the redirect URI
  `https://<your-host>/auth/login`.

---

## 1. Deploy the code

Use [`app/scripts/deploy_unraid.sh`](../scripts/deploy_unraid.sh). Every variable
at the top is env-overridable. The simplest is `APP_NAME` (default `energyv3`),
which derives both `APP_DIR` and `CONTAINER_APP_DIR`; override `CONTAINER` /
`DB_CONTAINER` too if your install differs. Then:

```bash
./app/scripts/deploy_unraid.sh                     # deploy latest main
./app/scripts/deploy_unraid.sh v1.0.0              # deploy a git tag
APP_NAME=energyv3 ./app/scripts/deploy_unraid.sh   # pin the deploy directory
```

The script is idempotent and: installs the SSH deploy key when the repo is
private (see below) → `git fetch` + `reset --hard` to the target →
`composer install --no-dev` → applies `app/sql/schema.sql` (via the `mariadb`
client, falling back to `mysql`) → runs `app/scripts/migrate.php` → checks the
OIDC config. `git clean` runs **without `-x`**, so `app/config/config.php` and
`/vendor/` are preserved.

### Private repository access (SSH deploy key)

> This repository is **public**: `REPO_URL` is an `https://…` URL and the deploy
> needs no credentials at all. This section only applies if you host your own
> private fork — the bootstrap above then needs the `GIT_SSH_COMMAND` lines back.

If the repo is **private**, the deploy needs SSH auth. The script uses a
per-repo **deploy key** and sets it up itself (step 0) whenever `REPO_URL` is an
SSH URL (`git@github.com:…`); with an `https://…` URL the block is skipped
(public repo). Two Unraid quirks make persistence tricky, both handled by the
script:

- the root filesystem lives **in RAM** → `/root/.ssh` is wiped on reboot;
- `/boot` (USB **or** SSD) stays **FAT32** → it does **not** keep Unix
  permissions, and SSH refuses a key that isn't `chmod 600`.

So the key is stored on `/boot` (persistent) and **copied into RAM with the
right permissions on every run**. One-time setup:

```bash
# 1. Generate a dedicated key (no passphrase → non-interactive).
ssh-keygen -t ed25519 -f /tmp/github_deploy_ed25519 -N ""

# 2. Persist it on the boot device (survives reboots).
mkdir -p /boot/config/ssh
cp /tmp/github_deploy_ed25519 /boot/config/ssh/github_deploy_ed25519

# 3. Pin GitHub's host key (avoids MITM + the interactive prompt).
echo 'github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl' \
    > /boot/config/ssh/github_known_hosts

# 4. Add the PUBLIC key as a Deploy key on GitHub:
#    repo → Settings → Deploy keys → Add deploy key (leave write access OFF).
cat /tmp/github_deploy_ed25519.pub
```

Override `SSH_KEY_SRC` / `SSH_KNOWN_HOSTS_SRC` if you store them elsewhere.

### Running via Unraid User Scripts (bootstrap)

The **User Scripts** plugin executes its **own pasted copy** of a script, not the
git checkout — so pasting the full `deploy_unraid.sh` means re-pasting it on every
change. Instead, paste this tiny **bootstrap** once; it pulls the latest code and
delegates to the versioned script (always current):

```bash
#!/bin/bash
# User Script — pulls the latest repo, then delegates to the versioned deploy.
set -euo pipefail
APP_DIR="/mnt/user/appdata/swag/www/energyv3"
REPO_URL="https://github.com/Rayman223/energy-cost-management.git"

mkdir -p "$APP_DIR"; cd "$APP_DIR"
[ -d .git ] || git init -q
git remote get-url origin >/dev/null 2>&1 || git remote add origin "$REPO_URL"
git remote set-url origin "$REPO_URL"
git fetch -q --depth 1 origin "${1:-main}"
git reset -q --hard FETCH_HEAD

exec bash "$APP_DIR/app/scripts/deploy_unraid.sh" "$@"   # "$@" forwards an optional tag
```

Schedule it *At First Array Start Only* (or run it manually). The bootstrap's
`git fetch` and the versioned script's step 1 overlap harmlessly (shallow,
idempotent).

### Web server & URL rewriting

Since #106 the app runs behind a **single front controller** ([`app/public/index.php`](../public/index.php))
serving clean, extensionless URLs (`/account`, `/tariffs`, `/api?action=…`). The
document root must be **`app/public/`**, and any non-file request must fall back to
`index.php`. With SWAG/Nginx, point the `location /` block at the front controller:

```nginx
root /config/www/energyv3/app/public;   # document root = app/public
index index.php;

location / {
    try_files $uri $uri/ /index.php$is_args$args;
}

# Only index.php exists in the web root; every other …/xxx.php request must
# reach the front controller so it can 308-redirect to the clean URL. Without
# the `try_files … /index.php` fallback below, a request to a legacy path such
# as /api.php would hit PHP-FPM with a missing SCRIPT_FILENAME → 404, and the
# backward-compat redirect (incl. the ingestion API) would never fire.
location ~ \.php$ {
    try_files $uri /index.php$is_args$args;
    include fastcgi_params;
    fastcgi_pass 127.0.0.1:9000;               # SWAG's PHP-FPM (TCP, cf. default.conf)
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

Old `…/xxx.php` URLs are **308-redirected** (method + body preserved, so machine
clients still posting to `/api.php` keep working) to their clean form by the
front controller.

#### Sous-domaine SWAG (`energy.domain.com`)

Pour exposer le site sur un sous-domaine dédié, le repo fournit un **site-conf**
d'exemple : [`nginx-swag-site.conf.example`](nginx-swag-site.conf.example). Il reprend le
bloc Nginx ci-dessus dans un `server { server_name energy.*; … }` servi
directement par le PHP-FPM de SWAG (pas de reverse-proxy — l'app vit dans le
container SWAG). Remplacer `domain.com` par le domaine réel.

1. **DNS** — pointer `energy.domain.com` (A/AAAA, ou CNAME vers l'hôte) sur
   l'IP de l'Unraid, comme les autres sous-domaines de `domain.com`.
2. **Certificat** — le wildcard `*.domain.com` géré par SWAG couvre déjà le
   sous-domaine ; sinon, ajouter `energy` à la variable d'env `SUBDOMAINS` du
   container et laisser SWAG renouveler le certificat.
3. **Activer la conf** — copier le fichier dans les site-confs de SWAG puis
   recharger Nginx :

   ```bash
   # depuis l'hôte Unraid ; APP_NAME=energyv3 par défaut (cf. deploy_unraid.sh)
   cp /mnt/user/appdata/swag/www/energyv3/app/docs/nginx-swag-site.conf.example \
      /mnt/user/appdata/swag/nginx/site-confs/energy.domain.com.conf
   docker exec swag nginx -t          # vérifie la syntaxe
   docker exec swag nginx -s reload   # applique sans redémarrer le container
   ```

   Adapter `energyv3` (chemin `root`) dans le fichier si `APP_NAME` diffère.

With **Apache**, the same fallback ships in the repo as
[`app/public/.htaccess`](../public/.htaccess) (mirror of the Nginx `try_files`).
Set the `DocumentRoot` to **`app/public/`**, enable `mod_rewrite`, and allow the
bundled `.htaccess` to take effect with `AllowOverride All` (or at least
`FileInfo Options`) on that directory — otherwise clean URLs like `/login` 404:

```apache
<VirtualHost *:80>
    DocumentRoot /var/www/energyv3/app/public   # document root = app/public
    <Directory /var/www/energyv3/app/public>
        AllowOverride All        # honour the bundled .htaccess (front controller)
        Require all granted
    </Directory>
</VirtualHost>
```

No rewrite rules to copy by hand: the `.htaccess` routes every non-file request to
`index.php`, including legacy `…/xxx.php` paths (which the front controller then
308-redirects to their clean form).

### Local development

The front controller doubles as a `php -S` router (it serves existing files as-is):

```bash
php -S localhost:8000 -t app/public app/public/index.php
```

Then open <http://localhost:8000/>.

---

## 2. Configure `config.php`

Copy the example and fill it in (this file is gitignored and preserved across
deployments):

```bash
cp app/config/config.example.php app/config/config.php
```

After editing, validate the file against the schema (no database connection, lists
missing sections and leftover `change_me` sentinels):

```bash
php app/scripts/config_check.php
```

Set at least `database`, then choose the authentication mode:

- **Community mode (recommended)** — set `oidc.enabled = true` and fill
  `issuer` / `client_id` / `client_secret`; leave `redirect_uri` empty to derive it
  from the `/auth/login` route. Multi-user accounts, no password/e-mail stored.
  Step-by-step guides: [oidc-google.md](oidc-google.md) (Google),
  [oidc-microsoft.md](oidc-microsoft.md) (Microsoft / Entra ID),
  [oidc-discord.md](oidc-discord.md) (Discord),
  [oidc-authentik.md](oidc-authentik.md) (authentik, *in French*) and
  [oidc-generic.md](oidc-generic.md) (Keycloak, Zitadel, any other OIDC IdP).
- **Legacy single-tenant mode** — keep `oidc.enabled = false`; the historic HTTP
  Basic Auth (`web_security.basic_auth`) protects everything and a single implicit
  owner account is used.

Optionally set `dynamic_prices` (ENTSO-E token), `energyid`, `i18n`, and `api`.
`dynamic_prices` is off by default; the full walkthrough — obtaining the token,
15-minute (MTU15) prices, the daily cron and a ready-made Unraid User Script —
is in [entsoe-dynamic-prices.md](entsoe-dynamic-prices.md) (*in French*).
`energyid` is opt-in: keep `enabled => false` (or omit the flag) to hide the
connector card from the account page and skip the nightly push.
Set `discord.invite_url` to show a Discord link in the page header (empty = hidden).

Fill in `legal` (publisher, address, contact e-mail, hosting provider,
jurisdiction): those values feed `/legal-notice` and the "data controller"
section of `/privacy`. Any field left empty is rendered as *not configured* on
the public pages — visible on purpose, since both are legally required.

### Advertising (Google AdSense, optional)

Disabled by default. While `adsense.enabled` is `false`, no third-party script is
loaded and the Content-Security-Policy stays strict — leave it that way unless
you actually run ads.

To enable it:

1. **Activate the consent CMP first.** In the AdSense console, open *Privacy &
   messaging* and publish a GDPR message. Google's CMP is IAB TCF certified and
   handles consent for you: the application ships no cookie banner of its own,
   and serving ads to EEA/UK visitors without it is not compliant.
2. Set `adsense.enabled = true` and `adsense.client_id` to your publisher ID
   (`ca-pub-…`, digits only after the prefix; a malformed value is ignored and
   ads stay off).
3. Publish `ads.txt` at the site root:

   ```bash
   cp app/public/ads.txt.example app/public/ads.txt   # then replace pub-XXXX… with your ID
   ```

The format is *Auto ads*: a single asynchronous script in `<head>`, Google picks
the placements. Ads are served on the public and application pages, never on the
error or sign-in pages (program policy: no ads on pages without content).

Enabling advertising **widens the Content-Security-Policy**: Google's ad and
consent origins are allowed, and `style-src` gains `'unsafe-inline'` because Auto
ads and the CMP inject their own inline styles. `script-src` never allows inline
code. Turning `adsense.enabled` back to `false` restores the strict policy — see
`App\Http\SecurityHeaders`.

---

## 3. Create tables & apply migrations

The deploy script already does this; to run it manually:

```bash
mariadb -u energy_user -p energy < app/sql/schema.sql # CREATE TABLE IF NOT EXISTS … (or `mysql`)
php app/scripts/migrate.php                            # versioned migrations (tracked in schema_migrations)
php app/scripts/migrate.php --dry-run                  # preview pending migrations
```

### The test database (`<database.name>_test`)

The integration suite (`tests/Integration`) seeds destructively — `DELETE FROM
users`, `DELETE FROM tariff_grids`… before every test. It therefore **never**
connects to `database.name`: it derives a throwaway database by appending
`_test` to it, and connects there instead.

```
database.name = "energy"   →   integration suite uses "energy_test"
```

Nothing to add to `config.php`: the derivation *is* the safeguard — the name the
suite opens always ends in `_test`, so a destructive seed cannot reach your
working database. The test database must live on the same server, with the same
credentials.

Create it once (same server, same user), then import the schema into it:

```bash
mariadb -u root -p -e "CREATE DATABASE energy_test CHARACTER SET utf8mb4;
                       GRANT ALL PRIVILEGES ON energy_test.* TO 'energy_user'@'%';"
mariadb -u energy_user -p energy_test < app/sql/schema.sql
vendor/bin/phpunit --testsuite integration            # runs for real, config.php untouched
```

Without it, every integration test skips itself with a message naming the
database it expected — `Base « energy_test » introuvable (ou droits manquants
pour l'utilisateur « energy_user »)`. The same message covers a database that
exists but that your user has no `GRANT` on: `information_schema.SCHEMATA` hides
both cases identically.

---

## 4. Create the owner account

The **owner** is simply the **first** user account. It is created with the `admin`
role automatically (subsequent accounts get `user`), so no manual promotion is needed.

- **OIDC mode**: open the site and **sign in once**. On first login your account is
  provisioned automatically (issuer + subject + display name).
- **Legacy mode**: the first authenticated web request auto-creates a technical
  `local/owner` account.

Verify:

```sql
SELECT id, provider, display_name, role, status FROM users ORDER BY id;
```

---

## 5. Admin access (owner is promoted automatically)

The admin space (`admin.php`) requires an existing admin. Since the **first** account
is created as `admin` (step 4), the owner can manage roles/status and the shared tariff
catalog from the UI right away — there is no self-service escalation for other members.

Fallback — if you upgraded from an older install where the owner is still `user`, or you
need to bootstrap admin manually, promote the first account directly in the database:

```sql
UPDATE users SET role = 'admin' WHERE id = 1;   -- the owner's id from step 4
```

---

## 6. Migrate data from the old project

The historic tables from the old mono-tenant project are `Data_Dries`
(electricity T1/T2 + injection), `Data_Solaire` (production), `Data_gaz`,
`Data_eau`. `Data_Brusol` is **not** migrated (dropped).

Export the old readings to CSV and use the bulk importer (idempotent,
re-runnable), targeting the owner:

```bash
php app/scripts/import_readings.php --type=electricity --file=elec.csv --user=1 --execute
php app/scripts/import_readings.php --type=gas         --file=gas.csv  --user=1 --execute
php app/scripts/import_readings.php --type=water       --file=water.csv --user=1 --execute
```

Both paths are safe to re-run: `INSERT IGNORE` on the composite unique keys means no
duplicates. See [`import.md`](import.md) for CSV/JSON formats and column mapping.

---

## 7. Seed the first Belgian tariff templates

The community catalog is made of **shared** tariff grids (`user_id NULL`) that every
member can select. Seed the Belgian ones as an **admin**:

1. Sign in as the admin (step 5) and open **`/tariffs`**.
2. Create a grid, tick **“shared catalog”**, and set **country = `BE`**, currency
   `EUR`.
3. Fill the line values for the Belgian structure (keys from
   [`app/src/Domain/TariffLineCatalog.php`](../src/Domain/TariffLineCatalog.php)):
   - **Electricity (bi-hourly, Sibelga)** — `energy_t1` / `energy_t2`,
     `distribution_t1` / `distribution_t2`, `transport`, `subscription`,
     `management_annual`, `excise_duty`, `energy_contribution`, `green_contribution`,
     `prosumer_annual`, `public_service_annual`, and `injection_t1` / `injection_t2`
     for solar credit. (Use `energy_simple` instead of T1/T2 for a single-rate meter.)
   - **Gas** — `energy`, `subscription`, `distribution`, `distribution_fixed`,
     `transport`, `federal_excise`, `energy_contribution`, `meter_reading_annual`.
4. Set the **valid-from** date. To supersede a grid later, create a new one starting
   the next day (personal overrides always win over the shared catalog).

Members can then pick a shared BE grid or create a personal override. To add another
country, repeat with its country code and the relevant line keys.

---

## Verification

- `SELECT role FROM users WHERE id = 1;` → `admin`.
- The dashboard shows migrated/imported history; a re-run of the import/backfill
  reports **duplicates**, not new rows (idempotent).
- `/tariffs` lists the shared BE grids; cost estimates appear on the dashboard.
- `/admin` is reachable by the admin and returns 403 to non-admins.

---

## Related docs

- [`../../README.md`](../../README.md) — overview & configuration.
- [`architecture.md`](architecture.md) — architecture & design decisions.
- [`import.md`](import.md) — bulk import.
- [`security-review.md`](security-review.md) — security checklist.
