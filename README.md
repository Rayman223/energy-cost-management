# Energy cost management

### 👉 [**Use the app: energy.lauray.net**](https://energy.lauray.net) 👈

> **[energy.lauray.net](https://energy.lauray.net) is the official production
> instance — free, hosted and ready to use.**
> Sign in with OpenID Connect and start tracking your electricity, gas and water
> costs in minutes: nothing to install, no server to maintain.
>
> The rest of this README is for people who want to **self-host** or **contribute**.

[![Live app](https://img.shields.io/badge/Live%20app-energy.lauray.net-2ea44f?style=for-the-badge)](https://energy.lauray.net)

[![CI](https://github.com/Rayman223/energy-cost-management/actions/workflows/ci.yml/badge.svg)](https://github.com/Rayman223/energy-cost-management/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777bb4.svg)](https://www.php.net/)

A self-hostable **multi-tenant European platform** to track and estimate energy
costs (electricity, gas, water). Members sign in with OpenID Connect, push their
own meter readings (manually or via an API), and get full tariff-based cost
estimates — including day-ahead dynamic prices.

No framework — vanilla PHP with a small in-house autoloader; Composer is used for
dev tooling and the OIDC library only.

---

## Table of contents

- [Features](#features)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Database](#database)
- [Tariff catalog](#tariff-catalog)
- [Bulk import](#bulk-import)
- [Cron jobs](#cron-jobs)
- [Pages & API](#pages--api)
- [Internationalization](#internationalization)
- [Tests & quality](#tests--quality)
- [Security](#security)
- [EnergyID](#energyid)
- [Documentation](#documentation)
- [Contributing](#contributing)
- [License](#license)

---

## Features

- **OpenID Connect authentication** (generic, PKCE/state/nonce) — no password and
  no e-mail stored; identity is `issuer` + `subject` + display name.
- **Multi-tenant data isolation** — every reading is scoped to a user. Electricity
  uses a **register model** (`meters` / `meter_registers` / `meter_readings`,
  EU-ready); gas and water share `utility_readings`.
- **European tariff catalog** — a shared community catalog (`user_id NULL`, managed
  by admins) plus per-user overrides; multi-currency (ISO 4217) and **ENTSO-E**
  bidding zones for dynamic day-ahead prices.
- **Ingestion API** — per-user **Bearer tokens** (hashed, scoped to `ingest`,
  rate-limited, revocable) for automated push; unit and batch, idempotent.
- **Bulk import** (CSV/JSON) — idempotent, with a per-row report; self-service for
  your own data.
- **Full internationalization** — `fr / en / nl / de` (extensible), language
  switcher, localized dates/numbers/currencies. (Help needed for translations)
- **Self-service & GDPR** — account page, EnergyID **opt-in** (BE/NL), JSON export
  and cascading account deletion.
- **Administration** (`admin.php`, admins only) — manage members (role, status) and the shared tariff catalog.
- **Dashboard** — live consumption, monthly deltas, cost estimates, 30/60/90-day
  history, dynamic vs. regulated price comparison.
- **Community statistics** (`/stats`, public) — average price per kWh and average
  consumption per country, plus a personal comparison when signed in. Aggregates
  are k-anonymised (a country appears only from 5 contributing households) and
  every member can opt out from their account page.
- **EnergyID sync** (optional, BE/NL) — daily push of readings via the V2
  provisioning protocol.

---

## Architecture

Layered vanilla PHP: thin entry points (`app/public/*.php`) → **Service** (domain
logic) → **Repository** (data access, type-hinted on `Repository/Contract/`
interfaces) → **Infrastructure** (PDO, HTTP). Pages render through the in-house
`View` engine (templates in `app/templates/`, centralized escaping); `api.php`
routes JSON through `Router` → controllers → `JsonResponse`.

```
app/
├── autoload.php / bootstrap.php   ← App\ autoloader + config loading
├── config/                        ← config.example.php · config.php (⚠ gitignored)
├── docs/                          ← installation.md · architecture.md · import.md · api-contract.md · security-review.md · …
├── public/                        ← index · tariffs · account · admin · login · privacy · terms · api · auth/
│   └── assets/                    ← versioned CSS/JS (cache-busting)
├── scripts/                       ← migrate · import_* · cron_* · gas_cost_audit
├── sql/                           ← schema.sql · migrations/
├── templates/ · translations/     ← HTML views · i18n catalogs (fr/en/nl/de)
└── src/                           ← Domain · Http · I18n · Infrastructure · Repository · Security · Service · Support · View

tests/  ← Unit · Integration (auto-skips without a DB) · Fake
```

Full design notes: [`app/docs/architecture.md`](app/docs/architecture.md).

---

## Requirements

| Component | Minimum |
|-----------|---------|
| PHP       | 8.4 |
| MySQL / MariaDB | 8.0 / 10.11 |
| Extensions | `pdo_mysql`, `curl` (required); `intl` (optional — localized formatting, falls back gracefully) |
| Composer  | runtime dep: the OIDC client (`jumbojett/openid-connect-php`); dev: PHPUnit |

---

## Installation

Quick local setup:

```bash
git clone https://github.com/Rayman223/energy-cost-management.git
cd energy-cost-management

cp app/config/config.example.php app/config/config.php    # then edit credentials
composer install --no-dev                                 # OIDC runtime lib

mysql -u <user> -p <database> < app/sql/schema.sql        # create tables
php app/scripts/migrate.php                               # apply versioned migrations
```

For a full production install on **Unraid (SWAG)** — including creating the owner
account, promoting it to admin and seeding the first Belgian tariff templates —
see the step-by-step guide:
**[`app/docs/installation.md`](app/docs/installation.md)**.

---

## Configuration

Copy `app/config/config.example.php` to `app/config/config.php` (gitignored) and
fill in the keys. Highlights:

```php
return [
    'database'     => [ 'host' => '127.0.0.1', 'port' => 3306, 'name' => 'energy',
                        'user' => 'energy_user', 'password' => 'change_me', 'charset' => 'utf8mb4' ],

    // Authentication. enabled=false keeps the legacy single-tenant Basic Auth.
    // Multi-provider: one entry per IdP under 'providers' (key = button icon +
    // stored users.provider). The legacy flat form (issuer/client_id under
    // 'oidc' directly) still works as a single implicit provider.
    'oidc'         => [ 'enabled' => false, 'providers' => [
                        'google' => [ 'issuer' => 'https://accounts.google.com',
                            'client_id' => 'change_me', 'client_secret' => 'change_me',
                            'redirect_uri' => '', 'scopes' => ['openid', 'profile'] ],
                        // 'microsoft' => [ 'issuer' => 'https://login.microsoftonline.com/<tenant-id>/v2.0', … ],
                    ] ],

    'web_security' => [ 'enabled' => true, 'allowed_ips' => [],   // [] = no IP restriction
                        'basic_auth' => [ 'enabled' => true, 'username' => 'admin', 'password' => 'change_me_now' ] ],

    'i18n'         => [ 'default_locale' => 'fr', 'available' => ['fr', 'en', 'nl', 'de'] ],
    'api'          => [ 'rate_limit_per_hour' => 600 ],          // per Bearer token

    // Discord link shown in the page header. Empty = link hidden.
    'discord'      => [ 'invite_url' => '' ],                    // e.g. https://discord.gg/xxxxxxx

    // Optional: day-ahead spot prices (ENTSO-E), EnergyID sync, local meter agent.
    // VAT and supplier markup are NOT configured here (see the dynamic-price notes below).
    'dynamic_prices' => [ 'enabled' => true, 'provider' => 'entsoe', 'security_token' => 'change_me',
                          'bidding_zone' => '10YBE----------2' ],
    'energyid'     => [ 'enabled' => true, 'provisioning_key' => 'change_me', 'provisioning_secret' => 'change_me' ],

    'timezone'     => 'Europe/Brussels',
];
```

Never commit `config.php`: it is the single source of truth for secrets and is
preserved across deployments (`git clean` runs without `-x`).

---

## Database

The full schema is in `app/sql/schema.sql`; incremental changes live in
`app/sql/migrations/` and are applied idempotently by `php app/scripts/migrate.php`
(tracked in `schema_migrations`). Main tables:

| Table | Purpose |
|-------|---------|
| `users` / `user_profiles` | accounts (OIDC identity, role, status) + country/timezone/currency/locale/bidding zone |
| `meters` / `meter_registers` / `meter_readings` | electricity register model (import T1/T2, export T1/T2, production) |
| `utility_readings` | gas & water meter indexes (m³) |
| `tariff_grids` / `tariff_grid_lines` | tariff catalog (`user_id NULL` = shared community grid, set = personal override) |
| `dynamic_prices` | day-ahead spot prices per bidding zone |
| `api_tokens` | per-user ingestion tokens (hashed, rate-limited, revocable) |
| `user_integrations` / `webhook_sync_state` | per-user export-connector opt-in (EnergyID, …) and sync state |
| `schema_migrations` | applied migration versions |

---

## Tariff catalog

Tariff grids are made of flexible `line_key => amount` rows, so any national tariff
structure fits. The Belgian line keys (source of truth:
`app/src/Domain/TariffLineCatalog.php`):

**Electricity** — `energy_simple` · `energy_t1` · `energy_t2` · `subscription` ·
`distribution_t1` · `distribution_t2` · `transport` · `management_annual` ·
`prosumer_annual` · `excise_duty` · `energy_contribution` · `green_contribution` ·
`public_service_annual` · `injection_t1` · `injection_t2`.

**Gas** — `energy` · `subscription` · `energy_contribution` · `federal_excise` ·
`distribution` · `distribution_fixed` · `transport` · `meter_reading_annual` ·
`connection_fee_kwh` · `public_service_annual`.

Admins create **shared** grids (visible to the whole community) from `tariffs.php`
by ticking *shared* and setting the country. See
[`app/docs/installation.md`](app/docs/installation.md#7-seed-the-first-belgian-tariff-templates).

---

## Bulk import

Idempotent CSV/JSON import of meter indexes with a per-row report
(imported / duplicates / errors), via three routes: the **account page**
(your own data), the **admin page** (on behalf of another user), and the CLI
(`app/scripts/import_readings.php`, plus the `import_gaz.php` / `import_eau.php`
wrappers). The batch **ingestion API** covers programmatic loads. Details and
column mapping: [`app/docs/import.md`](app/docs/import.md).

---

## Cost audit

The dashboard bills **calendar months** while the advances page bills a **free
date range** whose `to` bound is *exclusive*. When both disagree on the same
window, `app/scripts/gas_cost_audit.php` puts them side by side — active tariff
grids in resolution order, readings with their implied m³/day, both paths line
by line, and the resulting gap with its probable cause. Read-only.

```bash
php app/scripts/gas_cost_audit.php --from=2026-01-01 --to=2026-07-01
php app/scripts/gas_cost_audit.php --from=2026-01-01 --to=2026-07-01 --user=2 --energy=water --json
```

---

## Cron jobs

```cron
# Daily export sync (01:15) — iterates over export modules & opted-in users
# (EnergyID, …). `cron_daily_webhook.php` remains a deprecated alias.
15 1 * * * /usr/bin/php /path/app/scripts/cron_export_sync.php      >> /var/log/energy-daily.log 2>&1
# Day-ahead dynamic prices (13:30 after market publication, 18:30 catch-up).
# Full setup — token, 15-min prices, Unraid script: app/docs/entsoe-dynamic-prices.md
30 13,18 * * * /usr/bin/php /path/app/scripts/cron_dynamic_prices.php >> /var/log/energy-dynamic.log 2>&1
# Daily full SQL backup (03:00) — pure-PHP dump | gzip into backups/, 30-day rotation
0 3 * * *  /usr/bin/php /path/app/scripts/backup_db.php            >> /var/log/energy-backup.log 2>&1
```

The backup script (`app/scripts/backup_db.php`) produces a complete logical dump
**in pure PHP via PDO** — no `mysqldump` binary required — so it runs as-is inside
the web container (e.g. swag/Alpine). It reads DB credentials from
`app/config/config.php`, dumps tables (structure + data), views, triggers,
routines and events under a consistent snapshot, and writes timestamped
`backups/<db>_*.sql.gz` archives (git-ignored) atomically (`.part` → rename) with
a `flock` lock and automatic 30-day rotation. Containerized example:
`docker exec <web-container> php /config/www/energyv3/app/scripts/backup_db.php`.

Dynamic prices use the `dynamic_prices` config (ENTSO-E by default; free token at
[transparency.entsoe.eu](https://transparency.entsoe.eu/)). The dashboard then
shows a regulated-vs-dynamic comparison.

ENTSO-E only provides the raw day-ahead index — no supplier bills it as is. The
contract formula lives in the electricity tariff grid, as two line kinds:
`spot_coefficient` (multiplier, e.g. `1.08`, covering grid losses and
balancing/profile costs) and `spot_offset` (€/kWh incl. VAT: supplier margin and
per-kWh fees). Read both off your tariff sheet and enter them under `/tariffs`;
the applied rate is then `spot × coefficient × (1 + VAT) + offset`. Because they
are grid lines, they follow `valid_from`/`valid_to`, so a contract renewal does
not rewrite past months. Each term falls back independently: with no
`spot_offset` line the legacy `user_profiles.supplier_markup_per_kwh` still
applies (even alongside a grid coefficient), and with no `spot_coefficient` line
the coefficient is simply neutral.

The VAT rate comes from the grid too (`tariff_grids.vat_rate`) — a single source
for both the spot price and the VAT breakdown of the TTC amounts, versioned by
validity period so a rate change (Belgium's 21 % → 6 % on residential
electricity) applies from its date onwards instead of rewriting the past.

---

## Pages & API

| URL | Description |
|-----|-------------|
| `/` | Dashboard: live, monthly deltas, cost estimates, history |
| `/meter-readings` | Manual index entry (electricity registers, gas, water) |
| `/tariffs` | Tariff grids (personal + shared catalog for admins) |
| `/reconciliation` | Bill reconciliation: invoiced amounts vs. computed cost |
| `/advances` | Advance payment schedules and their balance |
| `/stats` | Community statistics — average price and consumption per country, k-anonymised at 5 households. **Public**; adds a personal comparison when signed in |
| `/account` | Profile, API tokens, EnergyID opt-in, statistics opt-out, GDPR export/delete, self-service import |
| `/admin` | Admin: members (role/status) + import on behalf of a user |
| `/api-guide` | Ingestion API guide (tokens, examples) |
| `/login`, `/auth/login`, `/auth/logout` | Authentication (Basic / OIDC) |
| `/privacy`, `/terms`, `/cookies`, `/legal-notice` | Legal pages (localized): GDPR notice, terms, cookie policy, publisher identity |
| `/api` | JSON API — ingestion (`ingest_*`) and read/cost endpoints. See [`app/docs/api-contract.md`](app/docs/api-contract.md) |

Legacy `/xxx.php` URLs redirect (308) to their clean equivalent.

---

## Internationalization

UI, validation messages and legal pages are fully translatable via catalogs in
`app/translations/{fr,en,nl,de}.php`. Locale resolution order is
`?lang` > profile > cookie > `Accept-Language` > default; the choice is persisted
per user. Adding a language = adding a catalog — see
[`CONTRIBUTING.md`](CONTRIBUTING.md#adding-a-language). Translation help is
welcome.

---

## Tests & quality

```bash
composer install                                    # dev tooling (PHPUnit)
vendor/bin/phpunit                                  # integration tests auto-skip without a test DB
phpstan analyse --configuration=phpstan.dist.neon   # static analysis (level 6)
find app -name '*.php' -print0 | xargs -0 -n1 php -l # syntax lint
```

The integration suite runs against a **derived** database — `database.name` plus
`_test` (`energy` → `energy_test`) — never against your working one, so no config
change is needed to run it. Creating it:
[`app/docs/installation.md`](app/docs/installation.md#the-test-database-databasename_test).

CI ([.github/workflows/ci.yml](.github/workflows/ci.yml)) runs on every push/PR:
PHP lint (8.4), **PHPStan level 6**, **PHPUnit** (unit + integration
against MariaDB), and a **JS syntax check** (`node --check`).

---

## Security

Access control is handled by `AuthGuard` / `WebAccessGuard` and configured in
`config.php`:

- **IP allowlist** (CIDR) — leave `allowed_ips` empty to disable.
- **Authentication** — OpenID Connect when `oidc.enabled = true` (hardened
  sessions, multi-user), otherwise the legacy HTTP Basic Auth (single tenant). The
  IP allowlist applies in both modes.
- **Blocked accounts** — a member set to `blocked` (admin page) loses access on the
  next request (session revoked) and their API tokens are rejected.
- CLI scripts (`cron_*`, `import_*`, `migrate`, `backfill_*`) are exempt from web
  protection.

Full posture and follow-ups (CSP, tokens, GDPR): [`app/docs/security-review.md`](app/docs/security-review.md).

Found a vulnerability? Please **do not open a public issue** — report it privately,
see [`SECURITY.md`](SECURITY.md).

---

## EnergyID

Optional per-user integration (BE/NL). When enabled, readings are pushed nightly to
[EnergyID](https://app.energyid.eu/) via the V2 provisioning protocol
(`POST /hello` device provisioning, then the first daily value of each stream).
Credentials live under `energyid` in `config.php`; users opt in from their account
page. The module is **off by default**: without `energyid.enabled => true` the
connector card is hidden from the account page and the nightly push is skipped.

---

## Documentation

- [`app/docs/installation.md`](app/docs/installation.md) — production install on Unraid, owner account, tariff templates.
- [`app/docs/oidc-google.md`](app/docs/oidc-google.md) — sign in with Google, step by step.
- [`app/docs/oidc-microsoft.md`](app/docs/oidc-microsoft.md) — sign in with Microsoft / Entra ID.
- [`app/docs/oidc-discord.md`](app/docs/oidc-discord.md) — sign in with Discord, step by step.
- [`app/docs/oidc-github.md`](app/docs/oidc-github.md) — sign in with GitHub (OAuth 2.0, not OIDC).
- [`app/docs/oidc-authentik.md`](app/docs/oidc-authentik.md) — sign in with authentik *(written in French)*.
- [`app/docs/oidc-generic.md`](app/docs/oidc-generic.md) — any other self-hosted OIDC provider (Keycloak, Zitadel).
- [`app/docs/architecture.md`](app/docs/architecture.md) — layered architecture and the #47 community-platform design.
- [`app/docs/date-bounds.md`](app/docs/date-bounds.md) — date bounds convention: every end date is exclusive *(written in French)*.
- [`app/docs/import.md`](app/docs/import.md) — bulk import formats, mapping, idempotence.
- [`app/docs/entsoe-dynamic-prices.md`](app/docs/entsoe-dynamic-prices.md) — ENTSO-E setup, 15-min prices, cron & Unraid script *(written in French)*.
- [`app/docs/security-review.md`](app/docs/security-review.md) — security checklist.
- [`app/docs/api-contract.md`](app/docs/api-contract.md) — JSON API contract (ingestion, read/cost endpoints).

---

## Contributing

Contributions are welcome — bug reports, translations, tariff templates for a new
country, or code. Start with [`CONTRIBUTING.md`](CONTRIBUTING.md): local setup,
project conventions (vanilla PHP, layered architecture, PHPStan level 6) and the
issue → branch → PR workflow. By participating you agree to the
[Code of Conduct](CODE_OF_CONDUCT.md).

---

## License

Released under the [MIT License](LICENSE).
