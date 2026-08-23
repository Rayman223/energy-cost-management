# Sign in with Discord (OpenID Connect)

How to configure Discord as an OpenID Connect (OIDC) identity provider. The OIDC
plumbing is already built in — this guide covers registering the application on the
Discord Developer Portal, filling in `config.php`, and the two Discord-specific
quirks (scopes and display name). See [oidc-google.md](oidc-google.md) for the
shared background and [installation.md](installation.md) for the full deployment.

> **No e-mail is requested or stored.** The app identifies users by the OIDC
> `issuer` + `subject` pair only. Requested scopes are `openid` + `identify` —
> the `email` scope is deliberately **not** requested.

---

## What makes Discord different

Discord is a *conformant enough* OIDC provider — it publishes
<https://discord.com/.well-known/openid-configuration>, issues RS256 ID tokens,
supports Authorization Code + PKCE S256, and exposes a standard
`/api/oauth2/userinfo` endpoint. Two details differ from Google/Microsoft, and both
are handled by the app:

| Detail | Discord | How the app handles it |
| --- | --- | --- |
| Profile scope | No `profile` scope; the equivalent is **`identify`**. Asking for `profile` fails with `invalid_scope`. | When `scopes` is omitted for the `https://discord.com` issuer, the app falls back to `['openid', 'identify']` instead of `['openid', 'profile']` ([OidcClientFactory](../src/Security/Oidc/OidcClientFactory.php)). |
| Display name | No `name` claim. The ID token only carries `sub`/`aud`/`iss`; `preferred_username` and `nickname` come from the userinfo endpoint (needs `identify`). | The app reads `name`, then `preferred_username`, then `nickname`, in the ID token first and the userinfo endpoint second ([OidcDisplayName](../src/Security/Oidc/OidcDisplayName.php)). |

---

## 1. Create the application

Portal: <https://discord.com/developers/applications> → **New Application**.

1. **Name**: anything (e.g. *Energy cost management*) — it is shown on the consent
   screen, along with the icon you upload under **General Information**.
2. Accept the developer terms and create the application.
3. Under **General Information**, note nothing in particular: the credentials you
   need live in the **OAuth2** tab.

## 2. Collect the credentials

**OAuth2** tab (left sidebar):

- **Client ID** — visible directly.
- **Client Secret** — **Reset Secret** reveals it once; copy it immediately and
  store it like a password. Resetting it again invalidates the previous one.

## 3. Register the redirect URI

Still in the **OAuth2** tab → **Redirects → Add Redirect**, set the `/auth/login`
route of your site — exact match, no query string, no trailing slash:

```
https://<your-host><base>/auth/login
```

`<base>` is empty for a root install, or the sub-path when the app is served from a
sub-directory. **Save Changes** at the bottom of the page.

> Discord requires an exact, character-for-character match. `http://` vs
> `https://`, a trailing `/`, or a `www.` mismatch all end in
> *"Invalid OAuth2 redirect_uri"*.
>
> A local development install can register a second redirect (e.g.
> `http://localhost:8080/auth/login`) — Discord allows plain HTTP on `localhost`
> only.

## 4. Nothing else to enable

Do **not** use the *OAuth2 URL Generator* / *In-app Authorization* section: the app
builds the authorization URL itself, with PKCE. No bot, no scopes and no permissions
need to be configured there.

---

## 5. Fill in `config.php`

Add a `discord` entry under the `oidc.providers` list of `app/config/config.php`
(the commented template is already in [config.example.php](../config/config.example.php)):

```php
'oidc' => [
    'enabled'   => true,
    'providers' => [
        // … existing google entry …
        'discord' => [
            'issuer'        => 'https://discord.com',
            'client_id'     => '<client-id>',
            'client_secret' => '<client-secret>',
            'redirect_uri'  => '',                     // empty = auto-derived (…/auth/login)
            'scopes'        => ['openid', 'identify'], // NOT 'profile' — see below
            // 'label'      => 'Discord',              // button label (default: capitalised key)
        ],
    ],
],
```

The key `discord` selects the Discord button icon (brand *Blurple*) and is the value
stored in `users.provider`.

**About `scopes`.** `['openid', 'identify']` is both the recommended value and the
automatic fallback if you omit the line — Discord rejects the usual `profile` scope.
Keep `identify`: without it the userinfo endpoint returns no username and accounts
are created with an empty display name.

---

## 6. Sign in

Open the site → the sign-in page now shows a **"Sign in with Discord"** button
alongside the others. Discord asks the user to authorise access to their username
and avatar, then returns to `/auth/login`.

The first account to sign in (any provider) is provisioned as admin; later accounts
get the `user` role. Check the result with:

```sql
SELECT provider, display_name, created_at FROM users ORDER BY id;
```

> **Open self-registration.** Anyone with a Discord account can sign up — Discord
> has no "restrict to my server/organization" equivalent of an Entra tenant. Keep
> this in mind for a public instance; the IP allow-list of the `web_security`
> section ([installation.md](installation.md)) is the way to fence off the site.

---

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `invalid_scope` on the consent screen | `profile` was requested. Use `['openid', 'identify']`, or drop the `scopes` line entirely and let the fallback apply. |
| *"Invalid OAuth2 redirect_uri"* | The redirect registered in the OAuth2 tab ≠ the one the app sends. Match `https://<host><base>/auth/login` exactly: scheme, host, base path, no trailing slash, no query string. |
| Callback arrives as `http://` and is rejected | Reverse proxy not forwarding `X-Forwarded-Proto`; set `redirect_uri` explicitly in the config, or fix the proxy header. |
| `invalid_client` | Wrong client secret (it is shown only once — reset it and update `config.php`), or client ID copied from another application. |
| Account created with an empty name | The `identify` scope is missing, so the userinfo endpoint returns no `preferred_username`. Fix the scopes and sign in again — the name is refreshed on each sign-in. |
| Sign-in worked yesterday, fails today with a discovery error | Transient Discord outage or an endpoint move; the app invalidates its cached discovery document after a failure, so simply retry. Check <https://discordstatus.com/>. |

> **One identity = one `issuer` + `subject` pair.** A first sign-in through Discord
> creates a **separate account**; to use Discord *and* another provider on the same
> account, link the extra identity from **My account → Sign-in providers** while
> signed in.

---

## Other providers

- **Google** — [oidc-google.md](oidc-google.md).
- **Microsoft / Entra ID** — [oidc-microsoft.md](oidc-microsoft.md).
- **authentik** — [oidc-authentik.md](oidc-authentik.md) *(written in French)*.
- **Keycloak, Zitadel and other self-hosted IdPs** — [oidc-generic.md](oidc-generic.md).
