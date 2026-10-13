# English release announcements

This adapts Battle-Conquest's versioned manifests, deployment publication and
delivery receipts to Energy Cost Management. Announcements are English only.
There is no forum: notes are posted directly to Discord, with a link to the
versioned `releases/` directory on GitHub.
Energy Cost Management is open source: every delivered change matters to readers,
including contributors and self-hosting operators. Announcements cover all changes,
regardless of their visible impact on application users.

## Prepare a release

1. Increment the root `VERSION` file (`X.Y.Z`, initially `1.0.0`). This is the
   installed application version, independently of the git tag used to deploy.
2. Commit `releases/<version>.json` with the same version. Describe every delivered
   change in natural English and Discord Markdown: features, fixes, refactoring,
   tests, dependencies, CI, documentation, migrations and public configuration.
   Explain what changed and why it matters, grouping related changes where useful.
   Include required deployment or configuration steps, but never secrets or
   private instance values. Compare against the previous release to avoid omissions.
3. Validate locally without configuration, database or network access:
   `php app/scripts/release-notes.php --validate --version=1.0.1`.
4. Deploy `VERSION` and the whole `releases/` directory with the code. Keep all
   previously shipped manifests and do not rewrite a delivered announcement.

```json
{
  "version": "1.0.1",
  "announcements": {
    "en": {
      "title": "Version 1.0.1",
      "content": "**Fixes**\n- Describe the corrected behavior.\n\n**Maintenance**\n- Describe refactoring, tests, dependencies, CI and documentation changes."
    }
  }
}
```

Titles must contain the exact version, occupy one line and fit 256 UTF-16 code
units. Content must be nonempty, at most 20,000 characters and 65,535 UTF-8 bytes.
Long announcements are split into numbered Discord messages, each with a
description of at most 4,096 UTF-16 units and a link to the versioned notes. No
content is truncated, including notes from older pending versions. Code fences
are closed and reopened across message boundaries. Mentions are disabled.
These limits follow Discord's [embed documentation](https://docs.discord.com/developers/resources/message#embed-object).

Every release must include an English announcement, even if it contains only
technical or documentation changes. The obsolete `user_impact` field is no longer
required and is ignored in existing manifests; setting it to `false` cannot
suppress an announcement. An empty announcement is rejected for all versions
except the historical `1.0.0` baseline, which remains silent and does not announce
old changes or send a test message. Keep already delivered manifests unchanged.

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
- `--publish`: uses the installed `VERSION`; English messages group every release
  not yet delivered and no newer than the installed version. The newest notes
  appear first. Long batches use multiple messages. Future manifests are not
  announced.

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

Validation alone returns `validated` or `ignored` (historical empty baseline or no file).
Neither migration, application page load nor CI sends announcements.

## Retry safely

Run the same `--publish` command after correcting the failure. Confirmed versions
are stored in `release_announcements`, keyed by version and language, with UTC
timestamps. The idempotent migration is
`app/sql/migrations/2026-10-13_release_announcements.sql`; `schema.sql` also includes
the table. No receipt is created until Discord confirms delivery of every message
in the batch (`wait=true`).

A database-scoped advisory lock covers receipt lookup, all messages and atomic receipt
storage. Concurrent publishers cannot both send the same pending batch; a
competing call fails promptly and can be retried. A network failure leaves the
batch pending. A 429 waits for `retry_after` (at most 10 seconds) and retries once,
as specified in Discord's [rate limit documentation](https://docs.discord.com/developers/topics/rate-limits).

As in Battle-Conquest, a duplicate remains possible if Discord accepts the
message but its confirmation is lost, or if receipt storage fails afterwards.
If a later message in a multipart batch fails, the whole batch remains pending;
retrying repeats the earlier messages so the complete notes can be delivered.
Changing or deleting a Discord message does not erase its database receipt.
Tests inject a fake transport and never read the configured webhook.
