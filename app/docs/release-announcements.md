# English release announcements

This adapts Battle-Conquest's versioned manifests, deployment publication and
delivery receipts to Energy Cost Management. Announcements are English only.
There is no forum: notes are posted directly to Discord, with a link to the
versioned `releases/` directory on GitHub.

## Prepare a release

1. Increment the root `VERSION` file (`X.Y.Z`, initially `1.0.0`). This is the
   installed application version, independently of the git tag used to deploy.
2. Commit `releases/<version>.json` with the same version. Describe only changes
   available to users, in natural English and Discord Markdown. Exclude internal
   refactoring, tests, CI, secrets and configuration details.
3. Validate locally without configuration, database or network access:
   `php app/scripts/release-notes.php --validate --version=1.0.1`.
4. Deploy `VERSION` and the whole `releases/` directory with the code. Keep all
   previously shipped manifests and do not rewrite a delivered announcement.

```json
{
  "version": "1.0.1",
  "user_impact": true,
  "announcements": {
    "en": {
      "title": "Version 1.0.1",
      "content": "**Fixes**\n- Describe the actual change users will see."
    }
  }
}
```

Titles must contain the exact version, occupy one line and fit 256 UTF-16 code
units. Content must be nonempty, at most 20,000 characters and 65,535 UTF-8 bytes.
Discord descriptions are shortened to 4,096 UTF-16 units, preserving the link to
the complete notes and closing a code fence if necessary. Mentions are disabled.

When there is no user impact, use:

```json
{"version": "1.0.1", "user_impact": false, "announcements": []}
```

The initial `1.0.0` manifest has no user impact: enabling this mechanism does not
announce old changes or send a test message.

## Configure Discord

Create an incoming webhook for an English **text channel**, then set its complete
URL in the untracked `app/config/config.php`:

```php
'discord' => [
    'invite_url' => '',
    'release_webhook_en' => 'https://discord.com/api/webhooks/WEBHOOK_ID/WEBHOOK_TOKEN',
],
```

The URL contains a secret. Never commit it. Empty or absent means disabled;
disabled publication keeps pending versions available for later delivery.
Forum/media channels requiring a thread are not supported by this configuration.
See Discord's [webhook documentation](https://docs.discord.com/developers/resources/webhook).

## Publish after deployment

Apply migrations (`php app/scripts/migrate.php`) and check the application before
publishing. The Unraid script already performs migrations, checks the PHP
bootstrap and DB connection, then runs validation and publication as its final
step. It stops on publication errors. This runtime check does not verify the HTTP
reverse proxy; for a manual deployment, also check the website before publishing.

```bash
php app/scripts/release-notes.php --validate
php app/scripts/release-notes.php --status
php app/scripts/release-notes.php --publish
```

- `--validate`: validates the installed manifest, without loading `config.php`.
  Only this mode accepts `--version=X.Y.Z`.
- `--status`: reads receipts and reports pending versions without writing,
  locking or contacting Discord.
- `--publish`: uses the installed `VERSION`; one English embed groups every
  release with user impact, not yet delivered and no newer than the installed
  version. The newest notes appear first. Future manifests are not announced.

An absent installed manifest returns a warning and does not block earlier pending
versions. A present invalid manifest or missing `releases/` directory blocks
publication. All delivered manifests are validated before contacting Discord.
JSON output contains `ok`, `version`, and `languages.en` with a status and the
versions covered. Exit code 1 indicates invalid input or incomplete publication.

| Status | Meaning |
|---|---|
| `pending` | `--status`: versions waiting for delivery |
| `sent` | Versions confirmed and recorded during this call |
| `up_to_date` | No announcement pending |
| `disabled` | No webhook configured; `versions` lists pending notes |
| `error` | Invalid configuration, competing publisher or delivery/DB failure |

Validation alone returns `validated` or `ignored` (no user impact or no file).
Neither migration, application page load nor CI sends announcements.

## Retry safely

Run the same `--publish` command after correcting the failure. Confirmed versions
are stored in `release_announcements`, keyed by version and language, with UTC
timestamps. The idempotent migration is
`app/sql/migrations/2026-10-13_release_announcements.sql`; `schema.sql` also includes
the table. No receipt is created until Discord confirms delivery (`wait=true`).

A database-scoped advisory lock covers receipt lookup, posting and atomic receipt
storage. Concurrent publishers cannot both send the same pending batch; a
competing call fails promptly and can be retried. A network failure leaves the
batch pending. A 429 waits for `retry_after` (at most 10 seconds) and retries once,
as specified in Discord's [rate limit documentation](https://docs.discord.com/developers/topics/rate-limits).

As in Battle-Conquest, a duplicate remains possible if Discord accepts the
message but its confirmation is lost, or if receipt storage fails afterwards.
Changing or deleting a Discord message does not erase its database receipt.
Tests inject a fake transport and never read the configured webhook.
