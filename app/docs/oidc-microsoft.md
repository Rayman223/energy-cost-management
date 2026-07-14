# Sign in with Microsoft / Entra ID (OpenID Connect)

How to configure Microsoft (Entra ID, formerly Azure AD) as an OpenID Connect
(OIDC) identity provider. The OIDC plumbing is already built in — this guide only
covers registering the application in Entra and filling in `config.php`. See
[oidc-google.md](oidc-google.md) for the shared background and
[installation.md](installation.md) for the full deployment.

> **No e-mail is requested or stored.** The app identifies users by the OIDC
> `issuer` + `subject` pair only. Requested scopes are `openid` + `profile`.

---

## 1. Register the application in Entra

Portal: <https://entra.microsoft.com/> → **Identity → Applications → App
registrations → New registration**.

1. **Name**: anything (e.g. *Manage Energy Costs*).
2. **Supported account types** — this choice drives the `issuer` (see step 3):
   - *Accounts in this organizational directory only* → single tenant.
   - *Accounts in any organizational directory* → `organizations`.
   - *…and personal Microsoft accounts* → `common`.
   - *Personal Microsoft accounts only* → `consumers`.
3. **Redirect URI** — platform **Web**, value = the `/auth/login` route of your
   site (no query string, exact match):

   ```
   https://<your-host><base>/auth/login
   ```

   `<base>` is empty for a root install, or the sub-path under a sub-directory.
4. Register, then note the **Application (client) ID** and, under **Overview**, the
   **Directory (tenant) ID**.

## 2. Create a client secret

**Certificates & secrets → Client secrets → New client secret**. Copy the secret
**value** (not the ID) immediately — it is shown only once.

---

## 3. Choose the tenant (issuer)

The issuer URL is `https://login.microsoftonline.com/<tenant>/v2.0`, where
`<tenant>` is one of:

| `<tenant>` | Who can sign in | Auto-registration |
| --- | --- | --- |
| **`<tenant-id>`** (the GUID) | Only accounts of that Entra tenant | Restricted to your organization — **recommended** |
| `organizations` | Any Entra (work/school) tenant | Open to any organization |
| `common` | Any Entra tenant **and** personal Microsoft accounts | Open to anyone with a Microsoft account |
| `consumers` | Personal Microsoft accounts only | Open to any personal account |

> **Recommended: use the explicit tenant ID.** Because the app has **open
> self-registration** (the first sign-in provisions an account), a broad tenant
> (`common`/`organizations`/`consumers`) lets **any** Microsoft user create an
> account. Restrict access with the tenant GUID unless you deliberately want a
> public sign-up.

**Multi-tenant validation.** For the shared endpoints (`common`, `organizations`,
`consumers`), the ID token's real issuer contains the signer tenant's GUID and
never equals the configured URL. The app detects these values and automatically
relaxes issuer validation to accept any valid
`https://login.microsoftonline.com/<tenant-guid>/v2.0` issuer (see
[OidcClientFactory](../src/Security/Oidc/OidcClientFactory.php)). With an explicit
tenant GUID, validation stays strict (exact issuer match).

---

## 4. Fill in `config.php`

Add a `microsoft` entry under the `oidc.providers` list of `app/config/config.php`:

```php
'oidc' => [
    'enabled'   => true,
    'providers' => [
        // … existing google entry …
        'microsoft' => [
            'issuer'        => 'https://login.microsoftonline.com/<tenant-id>/v2.0',
            'client_id'     => '<application-client-id>',
            'client_secret' => '<client-secret-value>',
            'redirect_uri'  => '',                  // empty = auto-derived (…/auth/login)
            'scopes'        => ['openid', 'profile'],
            // 'label'      => 'Microsoft',         // button label (default: capitalised key)
        ],
    ],
],
```

The key `microsoft` selects the four-square Microsoft button icon and is the value
stored in `users.provider`.

---

## 5. Sign in

Open the site → the sign-in page now shows a **"Sign in with Microsoft"** button
alongside the others. The first account to sign in (any provider) is provisioned as
admin; later accounts get the `user` role.

---

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `AADSTS50011: redirect URI … does not match` | The redirect URI in Entra ≠ the one the app sends. Ensure it is `https://<host><base>/auth/login`, platform **Web**, exact scheme, no trailing slash, no query string. |
| Issuer validation error on a `common`/`organizations` config | Expected only if the issuer is not a `login.microsoftonline.com/<guid>/v2.0` URL. Check the configured issuer uses `/v2.0` and one of `common`/`organizations`/`consumers`/`<tenant-id>`. |
| Callback arrives as `http://` and is rejected | Reverse proxy not forwarding `X-Forwarded-Proto`; set `redirect_uri` explicitly, or fix the proxy header. |
| Unwanted users can register | A broad tenant (`common`/`organizations`) allows open self-registration; switch to the explicit tenant GUID. |
