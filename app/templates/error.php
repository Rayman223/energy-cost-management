<?php

/**
 * Page d'erreur générique (403/404/…). Minimale (pas de polices Google) ;
 * charge seulement l'anti-FOUC et theme.js via le partial _head.
 *
 * @var int    $code
 * @var string $message
 */
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<?= $this->partial('_head', [
    'title' => (string) $code . ' — ' . $this->t('app.title'),
    'css'   => ['assets/css/error.css'],
    'fonts' => false,
]) ?>
</head>
<body>
<div class="box">
  <div class="code"><?= $this->e((string) $code) ?></div>
  <p><?= $this->e($message) ?></p>
  <p><a href="<?= $this->url() ?>"><?= $this->te('nav.back_dashboard') ?></a></p>
</div>
</body>
</html>
