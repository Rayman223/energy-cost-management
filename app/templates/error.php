<?php

/**
 * Page d'erreur générique (403/404/…). Minimale, sans dépendance JS.
 *
 * @var int    $code
 * @var string $message
 */
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $this->e((string) $code) ?> — <?= $this->te('app.title') ?></title>
<script nonce="<?= $this->e(\App\Http\SecurityHeaders::nonce()) ?>">(function(){try{var t=localStorage.getItem('theme');if(!t)t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
<link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/tokens.css') ?>">
<style>
  body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0;
    display: flex; min-height: 100vh; align-items: center; justify-content: center; text-align: center; }
  .box { padding: 32px; }
  .code { font-size: 3rem; font-weight: 800; color: var(--muted); }
  a { color: var(--blue); }
</style>
</head>
<body>
<div class="box">
  <div class="code"><?= $this->e((string) $code) ?></div>
  <p><?= $this->e($message) ?></p>
  <p><a href="index.php"><?= $this->te('nav.back_dashboard') ?></a></p>
</div>
</body>
</html>
