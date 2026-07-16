<?php

/**
 * Guide d'envoi API (#132) : mode d'emploi utilisateur pour pousser ses relevés
 * (électricité, gaz, eau) sur l'API d'ingestion avec un jeton Bearer.
 *
 * Les extraits techniques (endpoints, en-têtes, JSON, curl) restent littéraux —
 * seules les phrases explicatives sont traduites. `$apiUrl` est l'URL absolue de
 * l'API, dérivée de l'hôte courant, pour un exemple copiable tel quel.
 *
 * @var string       $apiUrl    URL absolue de l'API (ex. https://exemple/api)
 * @var int          $rateLimit Plafond de requêtes/heure par jeton (config)
 * @var list<string> $available Locales disponibles
 */
$title = $this->t('apiguide.title');
$url   = $this->e($apiUrl);
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $title . ' — ' . $this->t('app.title'),
    'css'   => ['assets/css/page-standalone.css', 'assets/css/api-guide.css'],
]) ?>
</head>
<body>
<div class="wrap">
  <p><a href="<?= $this->url('account') ?>"><?= $this->te('legal.my_account') ?></a>
     &nbsp;·&nbsp;
     <?php foreach ($available as $loc): ?><a href="?lang=<?= $this->e($loc) ?>"<?= $loc === $this->locale() ? ' class="lang-active"' : '' ?>><?= $this->e(strtoupper($loc)) ?></a> <?php endforeach; ?>
  </p>
  <h1><?= $this->e($title) ?></h1>
  <p><?= $this->te('apiguide.intro') ?></p>

  <!-- ── Authentification ─────────────────────────────────────────────────── -->
  <h2><?= $this->te('apiguide.auth_title') ?></h2>
  <p><?= $this->te('apiguide.auth_desc') ?></p>
<pre><code>Authorization: Bearer mec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</code></pre>
  <p class="muted"><?= $this->te('apiguide.auth_note') ?></p>

  <!-- ── Électricité ──────────────────────────────────────────────────────── -->
  <h2><?= $this->te('apiguide.elec_title') ?></h2>
  <p><?= $this->te('apiguide.elec_desc') ?></p>
<pre><code>curl -X POST "<?= $url ?>?action=ingest_electricity" \
  -H "Authorization: Bearer mec_votre_jeton" \
  -H "Content-Type: application/json" \
  -d '{"readings":[{"timestamp":"2026-07-16 10:00:00","import_t1":1234.5,"import_t2":678.9,"export_t1":12.3,"export_t2":4.5,"production":89.0}]}'</code></pre>
  <p class="muted"><?= $this->te('apiguide.elec_registers') ?></p>

  <!-- ── Gaz ──────────────────────────────────────────────────────────────── -->
  <h2><?= $this->te('apiguide.gas_title') ?></h2>
  <p><?= $this->te('apiguide.gas_desc') ?></p>
<pre><code>curl -X POST "<?= $url ?>?action=ingest_gas" \
  -H "Authorization: Bearer mec_votre_jeton" \
  -H "Content-Type: application/json" \
  -d '{"readings":[{"reading_at":"2026-07-16 10:00:00","counter_m3":45.678}]}'</code></pre>

  <!-- ── Eau ──────────────────────────────────────────────────────────────── -->
  <h2><?= $this->te('apiguide.water_title') ?></h2>
  <p><?= $this->te('apiguide.water_desc') ?></p>
<pre><code>curl -X POST "<?= $url ?>?action=ingest_water" \
  -H "Authorization: Bearer mec_votre_jeton" \
  -H "Content-Type: application/json" \
  -d '{"readings":[{"reading_at":"2026-07-16 10:00:00","counter_m3":123.456}]}'</code></pre>

  <!-- ── Réponse & envoi en lot ───────────────────────────────────────────── -->
  <h2><?= $this->te('apiguide.response_title') ?></h2>
  <p><?= $this->te('apiguide.response_desc') ?></p>
<pre><code>{"ok":true,"received":1,"inserted":1}</code></pre>
  <p><?= $this->te('apiguide.batch_desc') ?></p>
<pre><code>{"readings":[
  {"timestamp":"2026-07-16 10:00:00","import_t1":1234.5},
  {"timestamp":"2026-07-16 11:00:00","import_t1":1240.1}
]}</code></pre>

  <!-- ── Bon à savoir ─────────────────────────────────────────────────────── -->
  <h2><?= $this->te('apiguide.notes_title') ?></h2>
  <ul>
    <li><?= $this->te('apiguide.note_idempotent') ?></li>
    <li><?= $this->te('apiguide.note_batch_limit') ?></li>
    <li><?= $this->te('apiguide.note_rate_limit', ['limit' => $rateLimit]) ?></li>
    <li><?= $this->te('apiguide.note_errors') ?></li>
  </ul>

  <p class="mt-12"><a href="<?= $this->url('account') ?>"><?= $this->te('legal.my_account') ?></a></p>
</div>
</body>
</html>
