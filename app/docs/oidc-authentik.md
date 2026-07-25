# Se connecter avec authentik (OpenID Connect)

*Guide en français — les autres documents de `app/docs/` sont en anglais.*

Comment utiliser [authentik](https://goauthentik.io/) comme fournisseur d'identité
OpenID Connect. La plomberie OIDC est déjà intégrée à l'application (Authorization
Code + PKCE S256, découverte via `.../.well-known/openid-configuration`) : ce guide
ne couvre que la création du provider côté authentik et le remplissage de
`config.php`. Voir [installation.md](installation.md) pour le déploiement complet et
[oidc-generic.md](oidc-generic.md) pour les autres IdP auto-hébergés.

> **Aucune adresse e-mail n'est demandée ni stockée.** L'application identifie les
> comptes par le couple `issuer` + `subject` uniquement. Les scopes demandés sont
> `openid` + `profile`.

---

## 1. authentik s'auto-héberge

**Il n'existe pas d'offre cloud officielle.** authentik est un logiciel que vous
faites tourner vous-même ; l'édition *Enterprise* ajoute du support et des
fonctionnalités payantes, mais reste auto-hébergée. Des prestataires tiers proposent
de l'authentik infogéré — ce n'est pas un produit de l'éditeur.

Déploiements courants :

- **Docker Compose** — méthode recommandée par l'éditeur (serveur + worker +
  PostgreSQL + Redis).
- **Kubernetes** — chart Helm officiel.
- **Unraid** — disponible en *Community App*, comme cette application.

L'installation d'authentik elle-même sort du périmètre de ce dépôt : suivez
<https://docs.goauthentik.io/install-config/>. Une fois l'instance en place, il faut :

| Prérequis | Pourquoi |
| --- | --- |
| Un nom de domaine et **HTTPS** valide (ex. `https://sso.example.com`) | Le navigateur y est redirigé, et l'application refuse un callback en `http://`. |
| authentik joignable **depuis le navigateur** de vos utilisateurs | Redirections d'autorisation et écran de connexion. |
| authentik joignable **depuis le serveur PHP** de cette application | La découverte OIDC et l'échange du code sont des appels *serveur à serveur*. En réseau privé ou avec du split-DNS, c'est le point qui manque le plus souvent : le nom `sso.example.com` doit résoudre **aussi** depuis le conteneur/serveur web. |
| Un compte administrateur authentik (`akadmin` par défaut) | Créer l'application et le provider. |

Les deux services n'ont pas besoin d'être sur la même machine.

---

## 2. Le vocabulaire authentik

C'est le point qui bloque le plus souvent : « créer une app » se fait en **deux
objets liés**, et ce sont les *deux* qui produisent les informations à recopier dans
`config.php`.

| Objet authentik | Rôle | Ce qu'on en tire |
| --- | --- | --- |
| **Application** | L'entrée visible dans le portail utilisateur. Porte le **slug**. | Le slug compose l'URL de l'issuer : `https://<hôte>/application/o/<slug>/`. |
| **Provider** (*OAuth2/OpenID*) | La configuration du protocole rattachée à l'application. | **Client ID**, **Client Secret**, **Redirect URIs**. |
| **Flows** | Les parcours réutilisables (autorisation, invalidation). | On garde ceux fournis par défaut. |
| **Bindings / Policies** | Qui a le droit d'utiliser l'application. | Sert à restreindre l'accès (§ 6). |

L'assistant *New Application* crée l'application **et** son provider en une passe :
c'est le chemin le plus simple.

---

## 3. Créer l'application et le provider

Dans l'**interface d'administration** d'authentik (`https://<hôte>/if/admin/`) :
**Applications → Applications → New Application** (le bouton s'appelle *Create with
Provider* sur certaines versions) : un assistant en quatre étapes s'ouvre.

### Étape *Application*

| Champ | Valeur |
| --- | --- |
| **Name** | `Manage Energy Costs` (libre) |
| **Slug** | `manage-energy` — **il apparaîtra dans l'issuer**, choisissez-le maintenant : le changer plus tard change l'issuer et invalide les comptes déjà provisionnés. |
| **Launch URL** | L'URL publique de l'application (facultatif, pour le portail) |

### Étape *Choose a Provider*

Sélectionnez **OAuth2/OpenID Provider**.

### Étape *Configure the Provider*

| Champ | Valeur à saisir |
| --- | --- |
| **Name** | `Manage Energy Costs — OIDC` (libre) |
| **Authorization flow** | `default-provider-authorization-explicit-consent` (écran de consentement) ou `…-implicit-consent` (sans écran). Les deux conviennent. |
| **Invalidation flow** | `default-provider-invalidation-flow` |
| **Client type** | **Confidential** — obligatoire : l'application est un client serveur qui s'authentifie avec un secret. |
| **Client ID** | Généré automatiquement — à recopier. |
| **Client Secret** | Généré automatiquement — à recopier (visible plus tard sur la page du provider). |
| **Redirect URIs / Origins** | Une seule entrée, en mode de correspondance **Strict** :<br>`https://<votre-hôte><base>/auth/login`<br>Sans slash final, sans chaîne de requête. `<base>` est vide pour une installation à la racine, sinon le sous-chemin. |
| **Signing Key** | Sélectionnez un certificat (ex. `authentik Self-signed Certificate`). **Ne pas laisser vide** : sans clé de signature, authentik ne publie pas de JWKS exploitable et la validation de l'ID token échoue côté application. |

Dépliez **Advanced protocol settings** :

| Champ | Valeur |
| --- | --- |
| **Scopes** (*scope mappings*) | Laissez les mappings par défaut `openid`, `profile`, `email`. L'application ne demande que `openid` + `profile` et ignore l'e-mail. |
| **Subject mode** | `Based on the User's hashed ID` (défaut) — valeur stable, c'est le `sub` que l'application stocke. |
| **Issuer mode** | **`Each provider has a different issuer, based on the application slug`** (défaut). Le mode *Global* fait émettre un `iss` commun `…/application/o/` qui ne correspondra plus à l'issuer configuré ici. |

Terminez l'assistant (*Review and Submit*).

> **Redirect URIs en mode Strict.** Depuis authentik 2024.10 (correctif de
> CVE-2024-52289), chaque URI porte un mode de correspondance explicite, `Strict` ou
> `Regex`. Gardez **Strict** : `Regex` sur une valeur non échappée ouvre des
> redirections non voulues.

---

## 4. Récupérer l'issuer et les identifiants

Ouvrez **Applications → Providers → votre provider**. La page affiche :

- **Client ID** et **Client Secret** (onglet *Overview*, section des identifiants).
- **OpenID Configuration Issuer** :
  `https://<hôte>/application/o/<slug>/` — **avec le slash final**.
- **OpenID Configuration URL** :
  `https://<hôte>/application/o/<slug>/.well-known/openid-configuration`.

Recopiez l'issuer **exactement** tel qu'affiché, slash final compris : c'est la
valeur que l'IdP place dans le `iss` des jetons, et l'application la compare
strictement. Un issuer tronqué fonctionne encore, mais force la bibliothèque OIDC à
retélécharger le document de découverte à chaque connexion pour retrouver la bonne
valeur.

Vérification rapide depuis le serveur qui héberge l'application (et pas seulement
depuis votre poste) :

```bash
curl -s https://sso.example.com/application/o/manage-energy/.well-known/openid-configuration | head -c 300
```

---

## 5. Remplir `config.php`

Ajoutez une entrée `authentik` sous `oidc.providers` dans `app/config/config.php` :

```php
'oidc' => [
    'enabled'   => true,
    'providers' => [
        // … entrées existantes …
        'authentik' => [
            'issuer'        => 'https://sso.example.com/application/o/manage-energy/',
            'client_id'     => '<client-id>',
            'client_secret' => '<client-secret>',
            'redirect_uri'  => '',                  // vide = dérivé (…/auth/login)
            'scopes'        => ['openid', 'profile'],
            'label'         => 'Authentik',         // libellé du bouton
        ],
    ],
],
```

La clé `authentik` sélectionne l'icône du bouton et devient la valeur stockée dans
`users.provider`. Laissez `redirect_uri` vide sauf si l'application est derrière un
proxy qui ne transmet pas correctement l'hôte ou le protocole.

Validez la configuration (aucune connexion à la base n'est nécessaire) :

```bash
php app/scripts/config_check.php
```

---

## 6. Restreindre qui peut créer un compte

**L'auto-inscription est ouverte** : toute personne qu'authentik laisse passer sur
cette application obtient un compte à sa première connexion, et le **premier** compte
créé devient administrateur. Le filtrage se fait donc côté authentik :

1. **Applications → Applications → votre application → Policy / Group / User
   Bindings**.
2. Liez un **groupe** (ex. `energie`) ou une *policy* d'expression.
3. Passez le **Policy engine mode** sur `any` ou `all` selon la combinaison voulue.

Sans binding, l'application est ouverte à tous les utilisateurs de votre instance
authentik.

---

## 7. Se connecter

1. Appliquez les migrations si ce n'est pas déjà fait :

   ```bash
   php app/scripts/migrate.php
   ```

2. Ouvrez le site : la page de connexion affiche un bouton **« Authentik »** à côté
   des autres fournisseurs configurés.
3. Le **premier** compte connecté (tous fournisseurs confondus) est provisionné en
   `admin` ; les suivants en `user`. Vérification :

   ```sql
   SELECT id, provider, display_name, role, status FROM users ORDER BY id;
   ```

   Vous devez voir `provider = authentik`.

Un utilisateur déjà inscrit via un autre IdP peut rattacher son identité authentik à
son compte existant depuis la page **Mon compte → Fournisseurs de connexion**.

> **Déconnexion.** L'application ferme sa propre session ; elle ne déclenche pas de
> déconnexion côté authentik (*RP-initiated logout*). La session authentik reste
> ouverte dans le navigateur : une reconnexion immédiate ne redemandera pas de mot de
> passe.

---

## Dépannage

| Symptôme | Cause / correctif |
| --- | --- |
| Erreur de découverte / de connexion au moment de se connecter | `<issuer>/.well-known/openid-configuration` injoignable **depuis le serveur PHP** (et pas seulement depuis votre navigateur) : DNS interne, pare-feu, certificat non reconnu. Testez avec le `curl` du § 4 depuis le serveur. |
| `Redirect URI Error` / redirection refusée par authentik | L'URI enregistrée ≠ celle envoyée. Elle doit être exactement `https://<hôte><base>/auth/login`, en mode **Strict**, sans slash final ni chaîne de requête. |
| Le callback arrive en `http://` et est rejeté | Le reverse proxy ne transmet pas `X-Forwarded-Proto` ; corrigez l'en-tête, ou renseignez `redirect_uri` explicitement dans `config.php`. |
| `invalid_client` | Mauvais *Client Secret*, ou provider créé en **Public** au lieu de **Confidential**. |
| Erreur de vérification de signature / JWKS vide | Le champ **Signing Key** du provider est vide : sélectionnez un certificat, puis réessayez. |
| Erreur d'issuer alors que tout semble correct | **Issuer mode** passé sur *Global* côté authentik, ou issuer recopié sans le slash final. Reprenez la valeur exacte de *OpenID Configuration Issuer*. |
| Retour en boucle sur la page de connexion, ou erreur juste après avoir accepté côté authentik | Les sessions PHP ne sont pas inscriptibles sur le serveur (`session.save_path`). Le `state` et le `nonce` y sont stockés entre la redirection et le callback : sans session persistante, le retour d'authentik échoue systématiquement. |
| `Undefined constant "…"` au chargement de `config.php` | Une ligne d'exemple a été décommentée telle quelle : les `…` des commentaires sont des marqueurs de texte, pas du PHP. Reprenez le bloc complet du § 5. |
| Une modification faite dans authentik n'est pas prise en compte | Le document de découverte est mis en cache **1 h** côté application. Il est invalidé automatiquement à la première erreur de connexion ; sinon, attendez l'expiration ou videz `mec_oidc_cache` dans le dossier temporaire du serveur. |
| Le bouton affiche une icône « clé » générique | La clé du fournisseur dans `config.php` n'est pas `authentik` (l'icône est choisie par la clé, pas par le `label`). |
| N'importe quel utilisateur authentik peut créer un compte | Aucun *binding* de groupe/policy sur l'application (§ 6). |

## Autres fournisseurs

- **Google** — [oidc-google.md](oidc-google.md).
- **Microsoft / Entra ID** — [oidc-microsoft.md](oidc-microsoft.md).
- **Keycloak, Zitadel et autres IdP auto-hébergés** — [oidc-generic.md](oidc-generic.md).
