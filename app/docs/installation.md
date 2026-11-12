# Installation & onboarding

## Production Unraid : conteneur PRD autonome

Suivre le [guide Unraid PRD](unraid-containers.md) pour préparer puis migrer
l’installation SWAG existante, les volumes privés, le proxy, les sauvegardes et
les tâches internes. Le modèle courant est celui de Battle-conquest :
`energy-cost-prd` sur `swagproxy`, sans QUA. SWAG termine uniquement HTTPS.

Les commandes PHP ci-dessous s’exécutent depuis `/app` dans le nouveau conteneur,
par exemple `docker exec -w /app energy-cost-prd php app/scripts/migrate.php`.
Pour une installation neuve, créer la base puis importer le schéma initial une
seule fois avant les migrations ; pour la base existante, appliquer seulement
les migrations. Configuration privée : `/mnt/user/www/energy-cost/config/`.

Prérequis applicatifs : PHP 8.4+, pdo_mysql, curl, mbstring et MariaDB 10.11+.
L’image fournit aussi intl et OPcache. Pour les autres hébergements, conserver
`app/public/` comme racine web et diriger les routes absentes vers `index.php` ;
Apache peut utiliser le `.htaccess` fourni.

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

For private deployment-only values, copy
`app/config/config.local.example.php` to `app/config/config.local.php` and fill
in the deployment-specific values. The local file returns a PHP array that
deep-merges over `config.php`; nested associative settings can be overridden
individually, while list values replace the original list. Both private files
are ignored by Git. The configuration checker loads this local override when
checking the default `config.php`.

When support is enabled, the diagnostic warns about an unavailable public OIDC
page, an invalid Stripe mode, or missing/incompatible Stripe URL, Payment Link
ID and signing secrets. Account-reference and webhook secrets must be distinct.
Private values are never included in these warnings. Disabled support and
GitHub Sponsors-only configurations do not require Stripe credentials. Checking
the main configuration through a symlink target uses the application's own
`config.local.php`, just like the default command.

Set at least `database`, then choose the authentication mode:

- **Community mode (recommended)** — set `oidc.enabled = true` and fill
  `issuer` / `client_id` / `client_secret`; leave `redirect_uri` empty to derive it
  from the `/auth/login` route. Multi-user accounts, no password/e-mail stored.
  Step-by-step guides: [oidc-google.md](oidc-google.md) (Google),
  [oidc-microsoft.md](oidc-microsoft.md) (Microsoft / Entra ID),
  [oidc-discord.md](oidc-discord.md) (Discord),
  [oidc-github.md](oidc-github.md) (GitHub — OAuth 2.0, not OIDC),
  [oidc-authentik.md](oidc-authentik.md) (authentik, *in French*) and
  [oidc-generic.md](oidc-generic.md) (Keycloak, Zitadel, any other OIDC IdP).
- **Legacy single-tenant mode** — keep `oidc.enabled = false`; the historic HTTP
  Basic Auth (`web_security.basic_auth`) protects everything and a single implicit
  owner account is used.

Optionally set `dynamic_prices` (ENTSO-E token), `i18n`, and `api`.
`dynamic_prices` is off by default; the full walkthrough — obtaining the token,
15-minute (MTU15) prices, the internal scheduler and the Unraid notification relay —
is in [entsoe-dynamic-prices.md](entsoe-dynamic-prices.md) (*in French*).
Set `discord.invite_url` to show a Discord link in the page header (empty = hidden).
Configure the optional `support` section to publish the support page. It is
disabled by default on self-hosted instances; the legacy `donate.url` setting is
ignored and reported by the configuration diagnostic.

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
   cp /mnt/user/www/energy-cost/src/app/public/ads.txt.example /mnt/user/www/energy-cost/data/var/ads.txt
chown 33:33 /mnt/user/www/energy-cost/data/var/ads.txt   # then replace pub-XXXX… with your ID
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

### Voluntary project support (official public instance)

Support remains voluntary: the application stays free, with no paid features or
donor perks. Enable this only on a public OIDC deployment. Install the release,
then apply the database migrations (initial storage and isolation of test/live data):

```bash
php app/scripts/migrate.php --dry-run
php app/scripts/migrate.php
php app/scripts/config_check.php --strict
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Create a Stripe Payment Link that lets the customer choose an amount and set
its currency to EUR. The application automatically appends a signed
`client_reference_id` for signed-in donors. Its alphanumeric/underscore format
follows [Stripe's character and length restrictions](https://docs.stripe.com/payment-links/url-parameters)
(up to 200 characters); Stripe silently drops invalid values.
Configure `support` with the Payment Link URL and its `plink_…` identifier.
Use the command above to generate an account-reference signing key; it must not
reuse the webhook secret. Keep both secrets outside version control.

Create a Stripe webhook at `https://energy-cost.eu/stripe/webhook` using the
**Snapshot** payload format and select these event types:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `charge.refunded`
- `charge.dispute.created`
- `charge.dispute.closed`

Copy the endpoint signing secret into `support.stripe_webhook_secret`, set
`support.stripe_mode` to `test`, and use a test-mode Payment Link and identifier.
Keep `enabled` false while entering the settings, then set it to `true` on the
test instance to accept webhook deliveries and exercise the page. A disabled
support module returns 404 for its webhook. Verify paid and delayed payments,
refunds, disputes and the yearly total before configuring the production
instance with its live-mode link, identifier and secret, `stripe_mode = 'live'`
and `enabled = true`. Test and live donations, adjustments, badges and wall
consent are stored separately, so switching modes cannot count test donations
as real funds or publish test supporters. Keep the account-reference signing
key stable. Enter the current year's
GitHub Sponsors total and year manually in
`github_sponsors_annual_cents` and `github_sponsors_year`. Set a positive
`annual_goal_cents` to show the progress gauge; zero hides it. Stripe amounts
are shown before processing fees.

The calendar year is determined in UTC when Stripe confirms the payment,
including delayed payments started in the previous year. Refunds and disputed
amounts reduce only their own donation, down to zero; they cannot consume
another donation's contribution. Personal exports include both modes, their
adjustments and the wall consent timestamp. The deployment script reads the
same merged configuration as the application, including database and OIDC
overrides in `config.local.php`.

A dispute won or an inquiry closed without escalating to a chargeback
(`warning_closed`) releases the disputed adjustment; any refund remains
deducted.

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

## 8. Search engines & AdSense (public deployments only)

Skip this whole section for a self-hosted, single-tenant instance: with OIDC
disabled the site is behind an IP allowlist and Basic Auth, `/robots.txt`
answers `Disallow: /` and `/sitemap.xml` returns 404 — by design.

### 8.1 Declare the canonical URL

```php
'seo' => [
    'base_url'                 => 'https://example.tld', // no trailing slash
    'google_site_verification' => '',                    // Search Console meta tag token
],
```

Without `base_url`, canonical URLs, `hreflang` links and the sitemap fall back
to the `Host` of the incoming request — so a crawler arriving on
`www.example.tld` would see **www** declared as canonical, which is exactly the
indexing problem to avoid (#84).

### 8.2 Redirect `www` to the apex (or the other way round)

The application cannot do this: the request must be redirected **before** it
reaches PHP — but check what already happens first: a CDN in front of the site
(Cloudflare and friends) often does it for you. If the `www` host already
answers a 301 to the apex, there is nothing to add. Otherwise, see the
reverse-proxy example in
[`nginx-swag-site.conf.example`](nginx-swag-site.conf.example).

Whatever host wins, it must be the one in `seo.base_url`: the redirect, the
config key and the emitted canonical must all name the same host, or the
signals contradict each other.

Check it:

```bash
curl -I https://www.example.tld/     # expect: 301 → https://example.tld/
curl -s https://example.tld/robots.txt
curl -s https://example.tld/sitemap.xml | head
```

### 8.3 Google Search Console

1. Add a **domain property** (not a URL prefix): it covers the apex, `www` and
   both schemes at once, so a redirect between them is no longer reported as a
   problem.
2. Verify it by DNS, or paste the meta tag token into
   `seo.google_site_verification` — the tag is then emitted on every page.
3. Submit `https://example.tld/sitemap.xml`.

`/robots.txt` and `/sitemap.xml` are generated, not static files: they follow
the configured canonical URL, the locales in `i18n.available`, and — for the
`/stats?country=…` entries — the countries whose aggregates actually clear the
anonymity threshold.

### 8.4 `ads.txt` (AdSense only)

```bash
cp /mnt/user/www/energy-cost/src/app/public/ads.txt.example /mnt/user/www/energy-cost/data/var/ads.txt
chown 33:33 /mnt/user/www/energy-cost/data/var/ads.txt
# replace pub-XXXXXXXXXXXXXXXX with the publisher id of `adsense.client_id`
```

The file stays out of the repository (git-ignored): the id belongs to the
deployment, not to the code. Without it AdSense reports “Missing ads.txt” and
ad value drops; it is pointless while `adsense.enabled` is `false`.

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
