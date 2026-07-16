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
<?= $this->partial('_head', [
    'title' => $title . ' — ' . $this->t('app.title'),
    'css'   => ['assets/css/page-standalone.css', 'assets/css/legal.css'],
]) ?>
</head>
<body>
<div class="wrap">
  <p><a href="<?= $this->url() ?>"><?= $this->te('nav.back') ?></a>
     &nbsp;·&nbsp;
     <?php foreach ($available as $loc): ?><a href="?lang=<?= $this->e($loc) ?>"<?= $loc === $this->locale() ? ' class="lang-active"' : '' ?>><?= $this->e(strtoupper($loc)) ?></a> <?php endforeach; ?>
  </p>
  <h1><?= $this->e($title) ?></h1>
  <p class="muted"><?= $this->te('legal.updated', ['date' => date('Y-m-d')]) ?></p>

  <?php if ($page === 'privacy'): ?>
    <h2>Données / Data</h2>
    <p>Identité : identifiant OpenID Connect (issuer + subject) et nom d'affichage. <strong>Aucun mot de passe
       ni e-mail n'est stocké.</strong> Données d'usage : relevés de compteurs (électricité, gaz, eau), profil,
       grilles tarifaires, jetons API (hachés), et l'état de synchronisation EnergyID si vous l'activez.</p>
    <p>Accès, portabilité et effacement depuis <a href="<?= $this->url('account') ?>"><?= $this->te('legal.my_account') ?></a>
       (export JSON / suppression définitive). Sous-traitants : votre fournisseur OpenID Connect, EnergyID (option,
       BE/NL), prix de marché ENTSO-E.</p>
  <?php else: ?>
    <p>Le service permet le suivi communautaire des coûts énergétiques, fourni « en l'état », sans garantie
       d'exactitude des estimations. La connexion se fait via un fournisseur OpenID Connect ; vous êtes responsable
       de la confidentialité de vos jetons API. Vous restez propriétaire de vos données et pouvez les exporter ou
       les supprimer à tout moment depuis <a href="<?= $this->url('account') ?>"><?= $this->te('legal.my_account') ?></a>.</p>
  <?php endif; ?>

  <p class="legal-nav">
    <a href="<?= $this->url('account') ?>"><?= $this->te('legal.my_account') ?></a> ·
    <a href="<?= $this->url($page === 'privacy' ? 'terms' : 'privacy') ?>"><?= $page === 'privacy' ? $this->te('legal.terms') : $this->te('legal.privacy') ?></a>
  </p>
</div>
</body>
</html>
