# Sign in with a self-hosted OIDC provider (Keycloak, Authentik, Zitadel)

How to plug a self-hosted OpenID Connect identity provider into the app. The OIDC
plumbing is already built in and standards-compliant (Authorization Code + PKCE
S256, discovery via `.../.well-known/openid-configuration`), so any conformant IdP
works. See [oidc-google.md](oidc-google.md) for the shared background, and
[oidc-discord.md](oidc-discord.md) for the hosted Discord IdP.

> **No e-mail is requested or stored.** The app identifies users by the OIDC
> `issuer` + `subject` pair only. Requested scopes are `openid` + `profile`
> (Discord being the exception: `openid` + `identify`).

---

## What the app needs from any IdP

- An **issuer URL** exposing `<issuer>/.well-known/openid-configuration`.
- A **confidential client** (client ID + secret) using the **Authorization Code**
  flow with **PKCE (S256)**.
- One **redirect URI**, the `/auth/login` route of your site, exact match, no query
  string:

  ```
  https://<your-host><base>/auth/login
  ```

The provider key you choose in `config.php` (`keycloak`, `authentik`, `zitadel`, …)
is stored in `users.provider` and picks the button icon (a neutral key icon for
providers without a dedicated logo). Set `label` for the button text.

---

## Keycloak

1. In the target **realm**: **Clients → Create client**.
   - **Client type**: OpenID Connect.
   - **Client ID**: e.g. `manage-energy`.
2. **Capability config**: enable **Client authentication** (confidential) and
   **Standard flow** (Authorization Code). Keycloak enforces PKCE S256 when the
   client advertises it, which the app does.
3. **Valid redirect URIs**: `https://<host><base>/auth/login`.
4. **Credentials** tab → copy the **Client secret**.
5. Issuer = `https://<keycloak-host>/realms/<realm>` (Keycloak ≥ 17;
   older builds use `/auth/realms/<realm>`).

```php
'oidc' => [
    'enabled'   => true,
    'providers' => [
        // … other entries …
        'keycloak' => [
            'issuer'        => 'https://auth.example.com/realms/my-realm',
            'client_id'     => 'manage-energy',
            'client_secret' => '<client-secret>',
            'redirect_uri'  => '',                  // empty = auto-derived (…/auth/login)
            'scopes'        => ['openid', 'profile'],
            'label'         => 'Keycloak',          // button label
        ],
    ],
],
```

---

## authentik

authentik has its own **step-by-step guide**, including the self-hosting
prerequisites and the exact provider screens:
**[oidc-authentik.md](oidc-authentik.md)** *(written in French)*.

In short: create an **Application** with an **OAuth2/OpenID Provider** (client type
**Confidential**, redirect URI `https://<host><base>/auth/login` in **Strict** mode,
a **Signing Key** selected), then use the provider's *OpenID Configuration Issuer* —
typically `https://<authentik-host>/application/o/<app-slug>/`, trailing slash
included — as `issuer`. Config: same shape as Keycloak, with
`'label' => 'Authentik'`.

## Zitadel

- Create a project → **Application** of type **Web**, auth method
  **Code** with **PKCE**, and add the redirect URI `https://<host><base>/auth/login`.
- Issuer = your Zitadel instance URL, e.g. `https://<instance>.zitadel.cloud`.
- Config: same shape, with `'label' => 'Zitadel'`.

---

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| Discovery / connection error at sign-in | `<issuer>/.well-known/openid-configuration` unreachable or wrong. Verify the exact issuer URL (realm path, trailing slash where the IdP requires it). |
| Redirect URI mismatch | The IdP's allowed redirect URI ≠ `https://<host><base>/auth/login`. Match scheme exactly, no trailing slash, no query string. |
| Callback arrives as `http://` and is rejected | Reverse proxy not forwarding `X-Forwarded-Proto`; set `redirect_uri` explicitly, or fix the proxy header. |
| `invalid_client` | Wrong client secret, or the client is public instead of confidential. |

> **One identity = one `issuer` + `subject` pair.** A first sign-in through a new IdP
> creates a **separate account**; to use several IdPs with the *same* account, link
> the extra identity from **My account → Sign-in providers** while signed in.
