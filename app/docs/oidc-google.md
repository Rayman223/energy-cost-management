# Sign in with Google (OpenID Connect)

How to configure Google as the OpenID Connect (OIDC) identity provider for the app.
The OIDC plumbing is already built in — this guide only covers creating the Google
credentials and filling in `config.php`. See [installation.md](installation.md) for
the full deployment; this is the detail behind step 2 ("Community mode").

> **No e-mail is requested or stored.** The app identifies users by the OIDC
> `issuer` + `subject` pair only. Requested scopes are `openid` + `profile`
> (display name), nothing else.

---

## 1. Create (or pick) a Google Cloud project

1. Go to <https://console.cloud.google.com/> and sign in.
2. Top bar → project selector → **New project** (or reuse an existing one).
3. Give it a name (e.g. *Manage Energy Costs*) and create it.

---

## 2. Configure the OAuth consent screen

**APIs & Services → OAuth consent screen**.

1. **User type**: **External** (unless you use Google Workspace and want to
   restrict to your org, in which case pick *Internal*).
2. **App information**: app name, user support e-mail, developer contact e-mail.
3. **Scopes** — request **`openid`** and **`profile`** only; leave **`email`** out.

   A *scope* is a permission the app asks for on the user's Google account; Google
   shows the list on the consent screen and asks the user to approve it. The fewer
   you request, the more reassuring the screen and the lighter Google's review.

   Google's picker shows scopes under **long URL names**, while `config.php` uses
   **short aliases** — same thing:

   | Alias in `config.php` | Name shown by Google | Grants |
   | --- | --- | --- |
   | `openid` | `openid` | The OIDC sign-in itself + the **`sub`** claim (stable unique account id). **Required.** |
   | `profile` | `.../auth/userinfo.profile` | The **display name** (also picture, locale…). |
   | `email` | `.../auth/userinfo.email` | The **e-mail address**. |

   (`.../` is Google's shorthand for `https://www.googleapis.com/auth/…`.)

   The app identifies each user by the **`issuer` + `sub`** pair (`users.oidc_iss` /
   `oidc_sub`) and stores only a **display name** — no e-mail, no password. So:

   - **`openid`** — mandatory: yields `sub` and enables OIDC.
   - **`profile`** — a readable name ("Welcome, Jane") instead of a technical id.
   - **`email`** — **skip it**: the app never reads or stores it; requesting it only
     adds a needless permission on the consent screen.

   This matches the config: `'scopes' => ['openid', 'profile']`. In the console,
   `openid`/`email`/`profile` are "non-sensitive"; the key action here is simply to
   **leave `email` unchecked**.
4. **Test users** (while the app is in *Testing*): add every Google account that
   should be able to sign in. In *Testing* mode, only these accounts work.
5. To open sign-in to anyone, **Publish app**. With `openid`/`profile` scopes
   Google does not require verification, but unverified apps may still show a
   warning screen users must click through.

---

## 3. Create the OAuth client ID

**APIs & Services → Credentials → Create credentials → OAuth client ID**.

1. **Application type**: **Web application**.
2. **Name**: anything (e.g. *Manage Energy web*).
3. **Authorized redirect URIs** — add the callback, which is the `/auth/login`
   route of your site:

   ```
   https://<your-host><base>/auth/login
   ```

   - `<base>` is empty for a root install, or the sub-path if the app is deployed
     under a sub-directory. Example: `https://energy.example.net/auth/login`.
   - The redirect URI **must match exactly**, including `https://` and no trailing
     slash. A mismatch yields the Google error `redirect_uri_mismatch`.
   - For local development over HTTP, add e.g. `http://localhost:8080/auth/login`.
4. Create, then copy the **Client ID** and **Client secret**.

> **Behind a reverse proxy (SWAG):** the app leaves `redirect_uri` empty by default
> and derives it from the incoming request, honouring `X-Forwarded-Proto` so the
> callback is built as `https://…` even though TLS is terminated at the proxy (see
> [app/routes/auth/login.php](../routes/auth/login.php)). If your proxy does not
> forward that header, set `redirect_uri` explicitly in `config.php`.

---

## 4. Fill in `config.php`

Edit the `oidc` block of `app/config/config.php`
(copied from [config.example.php](../config/config.example.php)):

```php
'oidc' => [
    'enabled'   => true,
    'providers' => [
        'google' => [
            'issuer'        => 'https://accounts.google.com',
            'client_id'     => '<your-client-id>.apps.googleusercontent.com',
            'client_secret' => '<your-client-secret>',
            // Leave empty to auto-derive from the /auth/login route (recommended);
            // or set it to the exact Authorized redirect URI from step 3.
            'redirect_uri'  => '',
            'scopes'        => ['openid', 'profile'],
        ],
    ],
],
```

Setting `enabled => true` switches the app to multi-user OIDC mode. Leaving it
`false` keeps the historic single-tenant HTTP Basic Auth (`web_security`).

> **Multiple providers.** Add more entries under `providers` (e.g. `microsoft`,
> `keycloak`) to show one button per IdP on the sign-in page — see
> [oidc-microsoft.md](oidc-microsoft.md) and [oidc-generic.md](oidc-generic.md).
> The provider key (`google`, `microsoft`…) is stored in `users.provider` and
> picks the button icon.
>
> **Backwards compatible.** The older flat form (`issuer`/`client_id`/… directly
> under `oidc`, without a `providers` list) is still accepted and behaves as a
> single implicit provider — an existing Google install keeps working unchanged.

---

## 5. Migrate and sign in

1. Apply the schema/migrations (creates the `users` table if needed):

   ```bash
   php app/scripts/migrate.php
   ```

2. Open the site. A protected page now shows a **"Sign in with Google"** button.
   Click it → Google consent → you are returned to `/auth/login` and signed in.
3. The **first** account to sign in is provisioned as **admin** (owner); later
   accounts get the `user` role. Verify:

   ```sql
   SELECT id, provider, display_name, role, status FROM users ORDER BY id;
   ```

   You should see `provider = google` and `role = admin` for the first user.

---

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `redirect_uri_mismatch` | The redirect URI in Google ≠ the one the app sends. Ensure it is `https://<host><base>/auth/login`, exact scheme, no trailing slash. |
| Callback arrives as `http://` and is rejected | Reverse proxy not forwarding `X-Forwarded-Proto`; set `redirect_uri` explicitly, or fix the proxy header. |
| "Access blocked: app not verified" / consent warning | App still in *Testing* → add the account under **Test users**, or *Publish* the app. |
| Signed in but no admin | The admin role goes to the **first** account created. Check `SELECT role FROM users ORDER BY id LIMIT 1;`. |

## Other identity providers

The app supports several providers side by side — one button per configured IdP:

- **Microsoft / Entra ID** — [oidc-microsoft.md](oidc-microsoft.md).
- **Self-hosted OIDC** (Keycloak, Authentik, Zitadel) — [oidc-generic.md](oidc-generic.md).

> **The same person via two different IdPs = two distinct accounts.** Identity is
> the `issuer` + `subject` pair; there is no cross-provider account linking. A user
> who signs in with Google and later with Microsoft ends up with two separate
> accounts.
