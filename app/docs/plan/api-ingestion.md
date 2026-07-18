# API d'ingestion — contrat (P4, #55)

Base : `POST /api.php?action=<action>` · Corps JSON · Réponses `{"ok": true|false, ...}`.

## Authentification

Deux modes, exclusifs par requête :
- **Session** (navigateur) : cookie de session (OIDC ou Basic Auth legacy).
- **Jeton Bearer** (machines/agents) : `Authorization: Bearer mec_<40 hex>`.
  - Créé via `POST ?action=api_token_create` (**session uniquement**) — le secret n'est affiché qu'une fois.
  - Haché (SHA-256) en base ; révocable (`api_token_revoke`) avec effet immédiat.
  - **Rate-limit** par jeton : 600 requêtes/heure par défaut (`api.rate_limit_per_hour`) → `429` au-delà.
  - **Scope `ingest` (P4) : un jeton n'autorise QUE les actions `ingest_*`.** Toute autre action (lecture des
    données/coûts, saisie manuelle, tarifs, gestion des jetons) renvoie `400 Unknown action` en Bearer et
    n'est accessible qu'en session. Un jeton d'agent compromis ne peut donc que pousser des index.

Erreurs d'auth : `401` (jeton invalide/révoqué, session absente), `403` (allowlist IP), `429` (rate-limit).

## Gestion des jetons (session uniquement)

| Action | Corps | Réponse |
|---|---|---|
| `GET ?action=api_tokens` | — | `{tokens: [{id, name, prefix, scopes, last_used_at, created_at, revoked_at}]}` |
| `POST ?action=api_token_create` | `{"name": "Import automatisé"}` | `{ok, id, prefix, token}` — **token affiché une seule fois** |
| `POST ?action=api_token_revoke` | `{"id": 3}` | `{ok}` |

## Ingestion (session ou Bearer) — idempotente

Renvoyer un relevé déjà transmis ne crée **pas** de doublon (`received` vs `inserted` dans la réponse).
Batch : max **1000** lectures par requête.

### `POST ?action=ingest_electricity`
Registres : `import_t1`, `import_t2`, `export_t1`, `export_t2`, `production` — index **cumulés** en kWh, ≥ 0.
Au moins un registre par lecture ; seuls les registres présents sont enregistrés.

```json
{ "readings": [
    { "timestamp": "2026-07-02 14:00:00",
      "import_t1": 12345.678, "import_t2": 6789.012,
      "export_t1": 345.6, "export_t2": 12.3,
      "production": 9876.543 }
] }
```
Unitaire : mêmes champs à la racine (sans `readings`).
Réponse : `{"ok": true, "received": 5, "inserted": 5}` (`inserted` < `received` = doublons ignorés).

### `POST ?action=ingest_gas` / `POST ?action=ingest_water`
Index compteur **cumulé** en m³, ≥ 0.

```json
{ "readings": [ { "reading_at": "2026-07-01 08:00:00", "counter_m3": 1234.567 } ] }
```
Unitaire : `{"reading_at": "...", "counter_m3": ...}`.
Réponse : `{"ok": true, "received": 1, "inserted": 1}`.

> Différence avec `gas_entry`/`water_entry` (saisie manuelle UI) : la saisie manuelle impose la stricte
> croissance (date et index) par rapport au dernier relevé ; l'ingestion accepte l'historique dans le
> désordre (backfill d'agent) et s'appuie sur l'unicité `(user, type, horodatage)`.

## Erreurs de validation
`422` avec `{"ok": false, "error": "<message>"}` — ex. registre manquant, `counter_m3` négatif,
horodatage illisible, batch > 1000.

## Modes d'ingestion des relevés
Trois voies alimentent les relevés, toutes scopées par utilisateur :
- **API Bearer** — `POST ?action=ingest_electricity` / `ingest_gas` / `ingest_water` avec un jeton `mec_…`
  (cf. ci-dessus). Voie recommandée pour tout client automatisé.
- **Import CSV** — via l'UI d'import (cf. [app/docs/import.md](../import.md)).
- **Saisie manuelle** — formulaires `gas_entry` / `water_entry` de l'interface web.
