# Security Policy

## Supported versions

The project is not released under version tags: **only the `main` branch is
supported**. Fixes land on `main`, and self-hosted deployments are expected to
track it. A report against an older commit is welcome, but the fix will only be
published on `main`.

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report it privately through GitHub:
[**Security → Report a vulnerability**](https://github.com/Rayman223/energy-cost-management/security/advisories/new).
This opens a private advisory visible only to you and the maintainers.

Please include, as far as you can:

- the affected component (page, API endpoint, CLI script, class);
- the commit or date of the version you tested;
- the steps to reproduce, and what an attacker gains (read another member's
  readings, escalate to `admin`, forge an API token, …);
- your configuration if it matters (OIDC on/off, IP allowlist, PHP version).

Expect an acknowledgement within **7 days** and a status update within **30
days**. This is a spare-time project — a fix may take longer than a commercial
product, and that will be said plainly rather than left silent. You will be
credited in the advisory unless you prefer otherwise.

## Scope

The application is **multi-tenant**: the findings that matter most are anything
crossing the boundary between two members, or between a member and an admin.

In scope:

- data isolation between users (readings, meters, tariff grids, API tokens);
- authentication and session handling (OIDC flow, HTTP Basic fallback, session
  fixation/revocation, blocked accounts);
- the ingestion API — Bearer token forgery, scope escape, rate-limit bypass;
- injection of any kind (SQL, XSS in templates, CSV/JSON import), CSRF;
- privilege escalation to `role=admin`;
- exposure of secrets from `app/config/config.php` or of the SQL backups.

Out of scope:

- anything requiring an already-compromised server or database;
- a deployment misconfiguration outside the repository (permissions on
  `config.php`, an unprotected reverse proxy, an outdated PHP);
- missing hardening headers with no demonstrated impact — the current posture is
  documented in [`app/docs/security-review.md`](app/docs/security-review.md);
- automated scanner output with no reproducible scenario;
- denial of service through raw traffic volume.

## For self-hosters

- `app/config/config.php` holds every secret and is gitignored — keep it out of
  the repository and readable only by the web user.
- Rotate the API tokens (`/account.php`) and the OIDC client secret if you
  suspect any exposure; blocking a member from the admin page revokes their
  session and their tokens on the next request.
- The SQL dumps written to `backups/` are unencrypted — they contain every
  member's data.

The current posture and the open follow-ups are documented in
[`app/docs/security-review.md`](app/docs/security-review.md).
