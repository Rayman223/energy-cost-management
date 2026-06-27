# Issue #25 — Phase 5 (front) : chargement non-bloquant des scripts

## Contexte

Épopée [#25](https://github.com/Rayman223/Manage-energy-costs/issues/25),
Phase 5 (optimisation), axe **front**. `chart.js` (~200 Ko) était chargé en
`<script>` synchrone dans le `<head>` du dashboard → il **bloquait le parsing du
HTML** (et donc le premier rendu) le temps de son téléchargement/exécution.

## Constat préalable

Les `fetch` du dashboard (`loadChart`, `fetchLive`, `loadGasHistory`,
`loadWaterHistory`) sont déjà **lancés en parallèle** (appels non-awaités au
chargement) — rien à paralléliser de ce côté. Le seul point bloquant était
`chart.js`.

## Changement

[app/templates/dashboard.php](app/templates/dashboard.php) :
- `chart.js` passe en `<script defer>` → téléchargé en parallèle du parsing, ne
  bloque plus le rendu.
- `dashboard.js` passe aussi en `defer` pour **préserver l'ordre d'exécution**
  (les scripts `defer` s'exécutent dans l'ordre du document : chart.js, présent
  dans le `<head>`, s'exécute donc avant `dashboard.js`).
- `<link rel="preconnect" href="https://cdn.jsdelivr.net">` ajouté (handshake CDN
  anticipé).

Le **pont d'init inline** (`window.__INIT_*`) reste un script inline : il
s'exécute pendant le parsing, **avant** les scripts `defer` → `dashboard.js` lit
bien les valeurs au moment de son exécution.

## Vérification

- `php -l` OK ; rendu du template (CLI) : `defer` présent sur les deux scripts ;
  ordre `chart.js` → pont d'init → `dashboard.js` confirmé.
- Sémantique standard `defer` (exécution post-parsing, dans l'ordre du document)
  → `Chart` et `window.__INIT_*` sont définis quand `dashboard.js` s'exécute.
