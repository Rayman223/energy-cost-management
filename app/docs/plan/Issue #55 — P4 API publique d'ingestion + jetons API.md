# Issue #55 — P4 API publique d'ingestion + jetons API (+ retrait du live)

## Contexte
Cinquième phase de l'épopée #47 : les membres peuvent **envoyer automatiquement** leurs index (agents) en plus de la
saisie manuelle, authentifiés par **jetons API** révocables. Le **« live » est retiré** (le serveur communautaire ne
peut pas atteindre les compteurs LAN des membres) — l'API est **index-only**.
Lien : https://github.com/Rayman223/Manage-energy-costs/issues/55

## Fichiers impactés
- [app/sql/migrations/2026-07-04_api_tokens.sql](app/sql/migrations/2026-07-04_api_tokens.sql) + schema.sql — table
  `api_tokens` (hash SHA-256, préfixe UI, scopes, rate-limit fenêtre fixe, révocation).
- [app/src/Security/ApiToken.php](app/src/Security/ApiToken.php) — génération/validation pure (mec_ + 160 bits).
- [app/src/Repository/ApiTokenRepository.php](app/src/Repository/ApiTokenRepository.php) — create (secret montré une
  fois), authenticate (refus si révoqué ou compte bloqué), revoke, rate-limit.
- [app/src/Http/Controller/IngestController.php](app/src/Http/Controller/IngestController.php) — ingestion élec
  (5 registres) / gaz / eau, unitaire + batch (max 1000), idempotente.
- [app/src/Http/Controller/ApiTokenController.php](app/src/Http/Controller/ApiTokenController.php) — gestion des
  jetons (session uniquement).
- [app/public/api.php](app/public/api.php) — restructuré : BDD → auth **Bearer OU session** → repos scopés ; routes
  `ingest_*` + `api_token_*` ; **route `live` retirée**.
- Interfaces [ElectricityIngestionInterface](app/src/Repository/Contract/ElectricityIngestionInterface.php) /
  [UtilityIngestionInterface](app/src/Repository/Contract/UtilityIngestionInterface.php) ; `insertIndexes()` retourne
  le nombre inséré ; `UtilityReadingRepository::saveIgnore()`.
- [app/scripts/agent_push.php](app/scripts/agent_push.php) — **agent de référence** (poll compteurs locaux → POST API).
- Retrait live : [dashboard.php](app/templates/dashboard.php), [dashboard.js](app/public/assets/js/dashboard.js),
  `MeterController` supprimé. (`MeterApiService` reste : utilisé par cron_hourly et l'agent.)
- Config : sections `api` (rate limit) + `agent` (url/jeton). Doc contrat :
  [app/docs/plan/api-ingestion.md](app/docs/plan/api-ingestion.md).

## Sécurité
- Jeton `mec_` + 40 hex (160 bits CSPRNG), stocké **haché** (SHA-256), préfixe non-secret pour l'UI.
- Bearer refusé si jeton révoqué **ou compte bloqué** ; rate-limit 600 req/h par jeton (configurable) → 429.
- La gestion des jetons exige la **session** (un jeton ne peut pas créer/révoquer de jetons).
- L'allowlist IP s'applique aussi aux agents.

## Étapes
- [x] Table + repo jetons (hash, révocation, rate-limit, garde compte bloqué)
- [x] Auth Bearer dans api.php (avant session), 401/429 distincts
- [x] Endpoints ingestion idempotents (batch max 1000) + contrat documenté
- [x] Endpoints gestion jetons (session seulement)
- [x] Agent de référence agent_push.php + config
- [x] Retrait live (route, contrôleur, widget, JS)
- [x] Tests unit (ApiToken, IngestController×11) + intégration (jetons×5)

## Vérification
- CI (unit + intégration MariaDB).
- Créer un jeton (`api_token_create` en session), pousser via `agent_push.php` → index sous le bon compte ;
  renvoyer le même batch → `inserted: 0` ; révoquer → 401 immédiat ; spammer → 429.
- Dashboard sans widget live, tout le reste inchangé.

## Notes
- La saisie manuelle UI (`gas_entry`/`water_entry`) garde ses gardes strictes (croissance) ; l'ingestion accepte
  l'historique désordonné (backfill agent) — documenté dans le contrat.
- UI de gestion des jetons (page compte) → **P5 (#56)** ; scopes fins et durcissement → **P7 (#58)**.
