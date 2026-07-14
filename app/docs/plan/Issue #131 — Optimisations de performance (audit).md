# Issue #131 — Optimisations de performance (audit)

## Contexte

Audit global du 2026-07-13 (préparation #122). Quatre chantiers de **performance**
retenus (les bugs → #130, le multi-OIDC → #122, déjà mergés). Une seule branche
`chore/131-perf-audit` depuis `main` (avec #122 mergé), une seule PR (`Closes #131`).
GH : https://github.com/Rayman223/Manage-energy-costs/issues/131

## Fichiers impactés

- [app/src/Repository/ElectricityReadingRepository.php](app/src/Repository/ElectricityReadingRepository.php) — O1 (insertIndexes), O2 (mémo mois donné), O3 (interpolation batch)
- [app/src/Http/Controller/CostController.php](app/src/Http/Controller/CostController.php) — bénéficiaire O2 (aucun changement requis)
- [app/src/Security/Oidc/OidcClientFactory.php](app/src/Security/Oidc/OidcClientFactory.php) — A3 (injection cache découverte)
- [app/src/Security/Oidc/OidcDiscoveryCache.php](app/src/Security/Oidc/OidcDiscoveryCache.php) — **nouveau**, cache fichier well-known par issuer
- [app/src/Security/AuthGuard.php](app/src/Security/AuthGuard.php) — A4 (cache session statut)
- [app/src/Security/AuthSession.php](app/src/Security/AuthSession.php) — A4 (invalidation du cache au login)

## Étapes

### O1 — Topologie résolue une fois par requête
- [ ] Ajouter `private bool $topologyEnsured = false;`.
- [ ] Dans `insertIndexes`, n'exécuter `ensureElectricityMeter` + `ensureRegisters`
      (et remplir `$this->registerMap`) que si `!$topologyEnsured`, puis passer le flag.
      Réutiliser `$this->registerMap` pour la boucle d'insertion.

### O2 — Mémoïsation « mois donné »
- [ ] Propriété `array $monthlyDeltasForMonthCache = [];`.
- [ ] `getMonthlyDeltasForMonth($year,$month)` : clé `"$year-$month"`, calcul unique
      (même motif que `getMonthlyDeltas`). Commentaire : cache requête-scopé.

### O3 — Interpolation en batch
- [ ] Nouvelle privée `interpolatedValuesAt(array $registerIds, string $instant): array<int, array{value:float,timestamp:string}|null>`
      : 2 requêtes (before `<=` / after `>=`) via `ROW_NUMBER() OVER (PARTITION BY register_id ORDER BY reading_at DESC|ASC)`,
      puis **même arithmétique** que `interpolatedValueAt` (clamp / égalité exacte / interpolation linéaire).
- [ ] `interpolatedMonthlyDeltas` : résoudre les rid une fois, batcher à `monthStart` (élec + prod)
      et à `nextMonthStart` (élec) ; garder l'appel unique pour la borne solaire à `$result['to']`.
- [ ] Conserver `interpolatedValueAt` (utilisée par la borne solaire) OU la réimplémenter via le batch. Préserver la sémantique exacte (cf. tests d'intégration).

### A3 — Cache de la découverte OIDC (well-known)
- [ ] `OidcDiscoveryCache` : `get(string $issuer): ?array` — lecture fichier
      `sys_get_temp_dir()/mec_oidc_<sha256(issuer)>.json` (TTL 3600 s) ; sinon
      `HttpClient::get(issuer/.well-known/openid-configuration)`, décodage JSON,
      écriture, retour. Tout échec (réseau, JSON, écriture) → `null` (repli comportement actuel).
- [ ] `OidcClientFactory::fromConfig` : après construction du client, si
      `OidcDiscoveryCache::get($issuer)` non nul, injecter le doc **sans la clé `issuer`**
      (préserver l'issuer configuré + `setIssuerValidator` Microsoft) via `$client->providerConfigParam(...)`.
      La lib ne re-télécharge alors plus le well-known (le JWKS reste tiré de `jwks_uri` — non cachable dans cette version de la lib).

### A4 — enforceActiveAccount : cache session TTL 60 s
- [ ] Const `STATUS_CHECK_TTL = 60`, clé session `account_status_checked_at`.
- [ ] En tête de `enforceActiveAccount` : si `now - checked_at < TTL`, retour immédiat.
- [ ] Après confirmation `status === 'active'`, écrire `checked_at = time()`.
- [ ] `AuthSession::login()` : `unset($_SESSION['account_status_checked_at'])`
      (re-vérification au prochain hit après connexion). Commentaire : blocage admin propagé en ≤ 60 s.

## Vérification

1. `php -l` sur chaque fichier modifié + `vendor/bin/phpunit` + `phpstan analyse --configuration=phpstan.dist.neon --no-progress` (niveau 6, rien en baseline).
2. Non-régression : les tests d'intégration `ElectricityReadingRepositoryDbTest` (interpolation mensuelle, idempotence insertIndexes) doivent rester verts sans modification → garantit l'iso-montant du dashboard et de la vue « mois donné ».
3. Manuel : import CSV (moins de requêtes PDO/ligne) ; 2ᵉ login OIDC sans hit réseau well-known ; pages protégées sans SELECT status dans la fenêtre TTL.
