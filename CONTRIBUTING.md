# Contributing

Thanks for taking the time to contribute. Bug reports, translations, tariff
templates for a new country and code are all welcome.

By participating you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).
Security vulnerabilities go through [`SECURITY.md`](SECURITY.md), **not** through
a public issue.

---

## Local setup

```bash
git clone https://github.com/Rayman223/energy-cost-management.git
cd energy-cost-management

composer install                                          # dev tooling included
cp app/config/config.example.php app/config/config.php    # then fill in credentials

mysql -u <user> -p <database> < app/sql/schema.sql        # create tables
php app/scripts/migrate.php                               # apply versioned migrations
```

Requirements: PHP **8.4+** with `pdo_mysql` and `curl` (`intl` optional),
MySQL 8.0 / MariaDB 10.11+, Composer.

`app/config/config.php` is gitignored and is the single source of truth for
secrets — never commit it, and never put a real credential in
`config.example.php`.

Serve `app/public/` as the document root; see
[`app/docs/installation.md`](app/docs/installation.md) for a production setup.

---

## Project conventions

The project is **vanilla PHP** — no framework. Composer is used for PHPUnit and
the OIDC client only.

- **Autoloading**: an in-house autoloader maps the `App\` namespace onto
  `app/src/`. A class namespace **must** mirror its path under `app/src/`, or the
  autoloader will not find it.
- **Strict types**: every PHP file starts with `declare(strict_types=1);`.
- **Layering**: `app/public/*.php` (thin entry point) → `app/routes/*.php`
  (wiring + rendering only) → `Service` (domain logic) → `Repository` (data
  access, type-hinted on the `Repository/Contract/` interfaces) →
  `Infrastructure` (PDO, HTTP). Value objects live in `app/src/Domain`, access
  control in `app/src/Security`.
- **Never put domain logic in a route script** — it belongs in a Service.
- **Reuse before adding**: check `app/src/Service` and `app/src/Support` first
  (e.g. `TariffCalculatorService`, the existing repositories) rather than
  duplicating logic.
- **Views**: templates live in `app/templates/`, rendered through the in-house
  `View` engine with centralized escaping (`$this->e()`, `$this->te()`). Never
  interpolate raw user input into HTML.
- **Dates are stored in UTC** and rendered in the user's timezone.
- Comments and commit messages: French or English, both are fine. Match the
  surrounding file.

Design notes: [`app/docs/architecture.md`](app/docs/architecture.md).

---

## Checks before opening a PR

The three checks the CI runs, in the order it is worth running them locally:

```bash
find app -name '*.php' -print0 | xargs -0 -n1 php -l   # syntax lint
vendor/bin/phpunit                                     # integration tests auto-skip without a test DB
phpstan analyse --configuration=phpstan.dist.neon      # static analysis, level 6
```

- **PHPStan runs at level 6.** `phpstan-baseline.neon` freezes pre-existing
  findings only — **do not add new code to the baseline**. Type your new
  parameters and return values instead.
- Add or extend PHPUnit tests whenever you touch domain code. Unit tests live in
  `tests/Unit`, DB-backed ones in `tests/Integration`. The latter extend
  `Tests\Integration\DatabaseTestCase`, which connects to a **derived** database
  — `database.name` plus `_test` (`energy` → `energy_test`) — so a destructive
  seed can never reach your working one, and running them needs no config change.
  They skip themselves, naming the database they expected, when it is missing;
  see [`app/docs/installation.md`](app/docs/installation.md#the-test-database-databasename_test)
  to create it.
- The CI additionally runs a JS syntax check (`node --check`) on the assets under
  `app/public/assets/js/`.

---

## Workflow

1. **Open an issue first** for anything beyond a typo — it is where the scope
   gets agreed.
2. Branch off `main`: `fix/<issue>-<slug>` for a bugfix, `feat/<issue>-<slug>`
   for a feature, `chore/<issue>-<slug>` for cleanup.
3. Commit with the project format, referencing the issue:
   ```
   fix(#123): reject blank dates in the tariff form
   feat(#124): add the Dutch tariff line catalog
   chore(#125): drop the unused legacy webhook merger
   ```
   Several commits are fine when they cover distinct phases. Commit **only** the
   files related to the change — no local configuration, no editor dotfiles.
4. Open a PR against `main` whose body contains `Closes #<issue>`, a summary of
   the change, and the result of the local checks above.
5. The CI runs five jobs (PHP Lint matrix, JS Syntax, PHPStan level 6, PHPUnit
   matrix, PHPUnit integration against MariaDB). A PR is reviewed once they pass.

---

## Adding a language

The UI, validation messages and legal pages are fully translated. Adding a
language means adding one catalog:

1. Copy `app/translations/en.php` to `app/translations/<locale>.php` and
   translate the values (keep the keys **identical** — the parity test enforces
   it).
2. Register the locale in `app/src/I18n/Locale.php` and in the `i18n.available`
   list of `app/config/config.example.php`.
3. Run `vendor/bin/phpunit tests/Unit/I18n` — `TranslationParityTest` compares
   the catalogs against each other, and `TemplateCatalogTest` checks that every
   key referenced from a template exists.

Partial translations are still useful: `Translator::t()` falls back to the raw
key, so a missing entry is visible rather than fatal — but the parity test will
ask for the full set before merge.

---

## Adding tariffs for a new country

Tariff grids are flexible `line_key => amount` rows, so most national structures
fit without a schema change. The line keys are declared in
`app/src/Domain/TariffLineCatalog.php`; the shared community catalog is managed
from `/tariffs.php` by admins. Open an issue describing the structure of your
country's bill before adding keys — the goal is a catalog that stays readable
across Europe.
