# Manage Energy Costs

A PHP application to track and estimate energy costs (electricity, gas, water).
Originally a **single-home Belgian** tracker (Sibelga grid), it has grown into a
**multi-tenant, public, European community platform**:
members sign in with OpenID Connect, push their own meter readings (manually or
via an API), and get full tariff-based cost estimates across Europe.

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
├── docs/                          ← installation.md · architecture.md · import.md · security-review.md · plan/ · …
├── public/                        ← index · tariffs · account · admin · login · privacy · terms · api · auth/
│   └── assets/                    ← versioned CSS/JS (cache-busting)
├── scripts/                       ← migrate · backfill_multitenant · import_* · cron_* · agent_push
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
| PHP       | 8.5 |
| MySQL / MariaDB | 8.0 / 10.11 |
| Extensions | `pdo_mysql`, `curl` (required); `intl` (optional — localized formatting, falls back gracefully) |
| Composer  | runtime dep: the OIDC client (`jumbojett/openid-connect-php`); dev: PHPUnit |

---

## Installation

Quick local setup:

```bash
git clone https://github.com/Rayman223/Manage-energy-costs.git
cd Manage-energy-costs

cp app/config/config.example.php app/config/config.php    # then edit credentials
composer install --no-dev                                 # OIDC runtime lib

mysql -u <user> -p <database> < app/sql/schema.sql        # create tables
php app/scripts/migrate.php                               # apply versioned migrations
```

For a full production install on **Unraid (SWAG)** — including creating the owner
account, promoting it to admin, migrating data from the old project, and seeding
the first Belgian tariff templates — see the step-by-step guide:
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
    'oidc'         => [ 'enabled' => false, 'issuer' => 'https://accounts.google.com',
                        'client_id' => 'change_me', 'client_secret' => 'change_me',
                        'redirect_uri' => '', 'scopes' => ['openid', 'profile'] ],

    'web_security' => [ 'enabled' => true, 'allowed_ips' => [],   // [] = no IP restriction
                        'basic_auth' => [ 'enabled' => true, 'username' => 'admin', 'password' => 'change_me_now' ] ],

    'i18n'         => [ 'default_locale' => 'fr', 'available' => ['fr', 'en', 'nl', 'de'] ],
    'api'          => [ 'rate_limit_per_hour' => 600 ],          // per Bearer token

    // Optional: day-ahead spot prices (ENTSO-E), EnergyID sync, local meter agent.
    'dynamic_prices' => [ 'enabled' => true, 'provider' => 'entsoe', 'security_token' => 'change_me',
                          'bidding_zone' => '10YBE----------2', 'vat_rate' => 0.21 ],
    'energyid'     => [ 'provisioning_key' => 'change_me', 'provisioning_secret' => 'change_me' ],

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
| `energyid_integrations` / `webhook_sync_state` | per-user EnergyID opt-in and sync state |
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

## Cron jobs

```cron
# Hourly meter ingestion (owner's local meters, via the agent or local polling)
0 * * * *  /usr/bin/php /path/app/scripts/cron_hourly.php          >> /var/log/energy-hourly.log 2>&1
# Daily EnergyID push (01:15) — iterates over opted-in users
15 1 * * * /usr/bin/php /path/app/scripts/cron_daily_webhook.php   >> /var/log/energy-daily.log 2>&1
# Day-ahead dynamic prices, after market publication (~13:30)
30 13 * * * /usr/bin/php /path/app/scripts/cron_dynamic_prices.php >> /var/log/energy-dynamic.log 2>&1
```

Dynamic prices use the `dynamic_prices` config (ENTSO-E by default; free token at
[transparency.entsoe.eu](https://transparency.entsoe.eu/)). The dashboard then
shows a regulated-vs-dynamic comparison.

---

## Pages & API

| URL | Description |
|-----|-------------|
| `/` (`index.php`) | Dashboard: live, monthly deltas, cost estimates, history |
| `/tariffs.php` | Tariff grids (personal + shared catalog for admins) |
| `/account.php` | Profile, API tokens, EnergyID opt-in, GDPR export/delete, self-service import |
| `/admin.php` | Admin: members (role/status) + import on behalf of a user |
| `/login.php`, `/auth/login.php`, `/auth/logout.php` | Authentication (Basic / OIDC) |
| `/privacy.php`, `/terms.php` | Legal pages (localized) |
| `/api.php` | JSON API — ingestion (`ingest_*`) and read/cost endpoints. See [`app/docs/plan/api-ingestion.md`](app/docs/plan/api-ingestion.md) |

---

## Internationalization

UI, validation messages and legal pages are fully translatable via catalogs in
`app/translations/{fr,en,nl,de}.php`. Locale resolution order is
`?lang` > profile > cookie > `Accept-Language` > default; the choice is persisted
per user. Adding a language = adding a catalog. See
[`app/docs/plan/i18n.md`](app/docs/plan/i18n.md).

---

## Tests & quality

```bash
composer install                                    # dev tooling (PHPUnit)
vendor/bin/phpunit                                  # integration tests auto-skip without a DB
phpstan analyse --configuration=phpstan.dist.neon   # static analysis (level 6)
find app -name '*.php' -print0 | xargs -0 -n1 php -l # syntax lint
```

CI ([.github/workflows/ci.yml](.github/workflows/ci.yml)) runs on every push/PR:
PHP lint (8.5), **PHPStan level 6**, **PHPUnit** (unit + integration
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

---

## EnergyID

Optional per-user integration (BE/NL). When enabled, readings are pushed nightly to
[EnergyID](https://app.energyid.eu/) via the V2 provisioning protocol
(`POST /hello` device provisioning, then the first daily value of each stream).
Credentials live under `energyid` in `config.php`; users opt in from their account
page.

---

## Documentation

- [`app/docs/installation.md`](app/docs/installation.md) — production install on Unraid, owner account, data migration, tariff templates.
- [`app/docs/architecture.md`](app/docs/architecture.md) — layered architecture and the #47 community-platform design.
- [`app/docs/import.md`](app/docs/import.md) — bulk import formats, mapping, idempotence.
- [`app/docs/security-review.md`](app/docs/security-review.md) — security checklist.
- [`app/docs/plan/`](app/docs/plan/) — per-phase design notes (P0–P8) and the API contract.
