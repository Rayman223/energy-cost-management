# Sign in with GitHub (OAuth 2.0)

How to let users sign in with their GitHub account. The plumbing is already built
in — this guide covers registering the OAuth App on GitHub, filling in
`config.php`, and the one structural difference with every other provider: **GitHub
is not an OpenID Connect provider**. See [oidc-google.md](oidc-google.md) for the
shared background and [installation.md](installation.md) for the full deployment.

> **No e-mail is requested or stored.** The app identifies users by an
> `issuer` + `subject` pair only. **No scope at all** is requested by default: a
> scopeless token is enough to read the public `id`, `login` and `name` of the
> signed-in account, which is all the app needs.

---

## What makes GitHub different

GitHub does **not** implement OpenID Connect for user sign-in:
`https://github.com/.well-known/openid-configuration` returns **404**, and its token
endpoint returns no ID token. (The OIDC issuer `token.actions.githubusercontent.com`
you may find in GitHub's documentation is for GitHub Actions workloads, not for
signing in users.) The OIDC library the app uses for Google, Microsoft, Discord and
self-hosted IdPs cannot talk to it.

GitHub is therefore handled by a dedicated OAuth 2.0 connector,
[GithubOAuthClient](../src/Security/OAuth/GithubOAuthClient.php), plugged into the
same `/auth/login` route. Everything downstream — account provisioning, identity
linking, error logging — is shared with the OIDC providers.

| Detail | GitHub | How the app handles it |
| --- | --- | --- |
| Discovery | None (404). | Endpoints are hard-coded in the connector. The `issuer` you configure is **not** fetched: it is the provider identifier stored in `users.oidc_iss`. |
| ID token | Never issued. | Identity is read from `https://api.github.com/user` with the access token. |
| PKCE | Not supported by OAuth Apps. | The flow is protected by a single-use random `state` kept in the session and compared in constant time. |
| Subject | The account `id` (immutable integer) and the `login` (renameable). | `sub` is the **`id`**. Reusing a `login` would let a third party take over an abandoned account name. |
| Display name | `name` is often empty on GitHub profiles. | Falls back to `login`, refreshed at each sign-in. |

> **GitHub Enterprise Server is not supported** — its endpoints live on a
> per-organisation host. Only `github.com` is recognised.

---

## 1. Create the OAuth App

<https://github.com/settings/developers> → **OAuth Apps** → **New OAuth App**.
(For an organisation: *Organisation settings → Developer settings → OAuth Apps*.)

1. **Application name** — anything (e.g. *Energy cost management*); it is shown on
   the authorisation screen.
2. **Homepage URL** — your site.
3. **Authorization callback URL** — the `/auth/login` route of your site, exact
   match, no query string, no trailing slash:

   ```
   https://<your-host><base>/auth/login
   ```

   `<base>` is empty for a root install, or the sub-path when the app is served from
   a sub-directory. A local development install can register a second OAuth App with
   `http://localhost:8080/auth/login` — GitHub allows plain HTTP on `localhost`.
4. Leave **Enable Device Flow** unchecked: the app uses the authorization-code flow.

> Prefer a **OAuth App** over a **GitHub App** here. A GitHub App is built around
> repository permissions and installations; for plain sign-in it adds ceremony
> without adding anything.

## 2. Collect the credentials

On the app page:

- **Client ID** — visible directly.
- **Client secret** — **Generate a new client secret** reveals it once; copy it
  immediately and store it like a password.

---

## 3. Fill in `config.php`

Add a `github` entry under the `oidc.providers` list of `app/config/config.php`
(the commented template is already in [config.example.php](../config/config.example.php)):

```php
'oidc' => [
    'enabled'   => true,
    'providers' => [
        // … existing google entry …
        'github' => [
            'issuer'        => 'https://github.com', // identifier only — never fetched
            'client_id'     => '<client-id>',
            'client_secret' => '<client-secret>',
            'redirect_uri'  => '',                   // empty = auto-derived (…/auth/login)
            'label'         => 'GitHub',             // button label (default: capitalised key)
        ],
    ],
],
```

The key `github` selects the GitHub button icon and is the value stored in
`users.provider`. Keep `issuer` exactly as shown: it is what ties existing accounts
to this provider, so changing it later would orphan them.

**About `scopes`.** Omit the line. The app then requests **no scope**, which is
enough to identify the account. Adding `'scopes' => ['read:user']` only widens
access to the *private* profile — request it only if you have a reason to.

---

## 4. Sign in

Open the site → the sign-in page now shows a **"Sign in with GitHub"** button
alongside the others. GitHub asks the user to authorise the application, then
returns to `/auth/login`.

The first account to sign in (any provider) is provisioned as admin; later accounts
get the `user` role. Check the result with:

```sql
SELECT provider, display_name, created_at FROM users ORDER BY id;
```

> **Open self-registration.** Anyone with a GitHub account can sign up — an OAuth
> App cannot be restricted to the members of an organisation. Keep this in mind for
> a public instance; the IP allow-list of the `web_security` section
> ([installation.md](installation.md)) is the way to fence off the site.

---

## Troubleshooting

Every failed sign-in logs its actual cause to the PHP error log and shows the user a
short **incident reference**; the matching log line looks like:

```
OIDC auth failed [3f9c1a02] provider=github stage=callback RuntimeException: GitHub token endpoint: bad_verification_code
```

Read it with `docker logs <container>` (or wherever your PHP error log goes) and grep
for the reference the user gives you. `stage=initiation` means the failure happened
before the redirect to GitHub; `stage=callback`, on the way back.

| Symptom | Cause / fix |
| --- | --- |
| *"The redirect_uri MUST match the registered callback URL for this application."* | The callback registered on GitHub ≠ the one the app sends. Match `https://<host><base>/auth/login` exactly: scheme, host, base path, no trailing slash, no query string. |
| Callback arrives as `http://` and is rejected | Reverse proxy not forwarding `X-Forwarded-Proto`; set `redirect_uri` explicitly in the config, or fix the proxy header. |
| Log says `GitHub callback: state invalide.` | The session was lost between the redirect and the return (cookie blocked, session GC, a different browser or host), or the callback was replayed — the `state` is single-use. Simply sign in again. |
| Log says `GitHub token endpoint: bad_verification_code` | The authorisation code expired (10 minutes) or was already exchanged. Sign in again. |
| Log says `GitHub token endpoint: incorrect_client_credentials` | Wrong client secret (it is shown only once — generate a new one and update `config.php`), or a client ID copied from another OAuth App. |
| Log says `GitHub /user: HTTP 401` | The access token was rejected — usually the app's authorisation was revoked mid-flow. Sign in again. |
| The GitHub button does not appear on the sign-in page | `oidc.enabled` is `false`, or the `github` block has an empty `issuer`/`client_id` — incomplete provider blocks are ignored. Run `php app/scripts/config_check.php`. |
| Account created with the GitHub username instead of the full name | The GitHub profile has no public **Name**; the app falls back to `login`. Setting the name on GitHub updates it at the next sign-in. |

> **One identity = one `issuer` + `subject` pair.** A first sign-in through GitHub
> creates a **separate account**; to use GitHub *and* another provider on the same
> account, link the extra identity from **My account → Sign-in providers** while
> signed in.

---

## Other providers

- **Google** — [oidc-google.md](oidc-google.md).
- **Microsoft / Entra ID** — [oidc-microsoft.md](oidc-microsoft.md).
- **Discord** — [oidc-discord.md](oidc-discord.md).
- **authentik** — [oidc-authentik.md](oidc-authentik.md) *(written in French)*.
- **Keycloak, Zitadel and other self-hosted IdPs** — [oidc-generic.md](oidc-generic.md).
