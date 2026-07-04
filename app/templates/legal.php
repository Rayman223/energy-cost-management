<?php

/**
 * Pages légales (CGU / confidentialité). Contenu à faire relire juridiquement
 * avant ouverture publique réelle (placeholder de bonne foi).
 *
 * @var string       $page       'privacy' | 'terms'
 * @var list<string> $available
 */
$title = $page === 'privacy' ? $this->t('legal.privacy') : $this->t('legal.terms');
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $this->e($title) ?> — <?= $this->te('app.title') ?></title>
<script>(function(){try{var t=localStorage.getItem('theme');if(!t)t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/tokens.css') ?>">
<style>
  body { font-family: 'Syne', system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; }
  .wrap { max-width: 760px; margin: 0 auto; padding: 32px 20px 64px; line-height: 1.6; }
  h1 { font-size: 1.5rem; } h2 { font-size: 1.1rem; margin-top: 28px; }
  a { color: var(--blue); } .muted { color: var(--muted); font-size: .85rem; }
</style>
</head>
<body>
<div class="wrap">
  <p><a href="index.php"><?= $this->te('nav.back') ?></a>
     &nbsp;·&nbsp;
     <?php foreach ($available as $loc): ?><a href="?lang=<?= $this->e($loc) ?>"<?= $loc === $this->locale() ? ' style="font-weight:700"' : '' ?>><?= $this->e(strtoupper($loc)) ?></a> <?php endforeach; ?>
  </p>
  <h1><?= $this->e($title) ?></h1>
  <p class="muted"><?= $this->te('legal.updated', ['date' => date('Y-m-d')]) ?></p>

  <?php if ($page === 'privacy'): ?>
    <h2>Données / Data</h2>
    <p>Identité : identifiant OpenID Connect (issuer + subject) et nom d'affichage. <strong>Aucun mot de passe
       ni e-mail n'est stocké.</strong> Données d'usage : relevés de compteurs (électricité, gaz, eau), profil,
       grilles tarifaires, jetons API (hachés), et l'état de synchronisation EnergyID si vous l'activez.</p>
    <p>Accès, portabilité et effacement depuis <a href="account.php"><?= $this->te('legal.my_account') ?></a>
       (export JSON / suppression définitive). Sous-traitants : votre fournisseur OpenID Connect, EnergyID (option,
       BE/NL), prix de marché ENTSO-E.</p>
  <?php else: ?>
    <p>Le service permet le suivi communautaire des coûts énergétiques, fourni « en l'état », sans garantie
       d'exactitude des estimations. La connexion se fait via un fournisseur OpenID Connect ; vous êtes responsable
       de la confidentialité de vos jetons API. Vous restez propriétaire de vos données et pouvez les exporter ou
       les supprimer à tout moment depuis <a href="account.php"><?= $this->te('legal.my_account') ?></a>.</p>
  <?php endif; ?>

  <p style="margin-top:32px">
    <a href="account.php"><?= $this->te('legal.my_account') ?></a> ·
    <a href="<?= $page === 'privacy' ? 'terms.php' : 'privacy.php' ?>"><?= $page === 'privacy' ? $this->te('legal.terms') : $this->te('legal.privacy') ?></a>
  </p>
</div>
</body>
</html>
