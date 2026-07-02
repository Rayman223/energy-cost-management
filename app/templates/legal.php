<?php

/**
 * Pages légales (CGU / confidentialité). Contenu à faire relire juridiquement
 * avant ouverture publique réelle (placeholder de bonne foi).
 *
 * @var string $title
 * @var string $page  'privacy' | 'terms'
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $this->e($title) ?> — Manage Energy</title>
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
  <p><a href="index.php">← Retour</a></p>
  <h1><?= $this->e($title) ?></h1>

  <?php if ($page === 'privacy'): ?>
    <p class="muted">Dernière mise à jour : <?= date('Y-m-d') ?>. Modèle à faire valider juridiquement.</p>
    <h2>Données collectées</h2>
    <p>Identité : identifiant OpenID Connect (issuer + subject) et nom d'affichage. <strong>Aucun mot de passe
       ni e-mail n'est stocké.</strong> Données d'usage : relevés de compteurs (électricité, gaz, eau), profil
       (pays, devise, fuseau, zone de marché, langue), grilles tarifaires, jetons API (hachés), et l'état de
       synchronisation EnergyID si vous l'activez.</p>
    <h2>Finalité</h2>
    <p>Calcul et suivi de vos coûts énergétiques, et — si vous l'activez — synchronisation vers EnergyID.</p>
    <h2>Vos droits</h2>
    <p>Accès et portabilité : export complet de vos données depuis <a href="account.php">votre compte</a>
       (format JSON). Effacement : suppression définitive de votre compte et de toutes vos données depuis la
       même page.</p>
    <h2>Sous-traitants</h2>
    <p>Votre fournisseur OpenID Connect (authentification) et, en option, EnergyID (BE/NL). Les prix de marché
       proviennent d'ENTSO-E.</p>
  <?php else: ?>
    <p class="muted">Dernière mise à jour : <?= date('Y-m-d') ?>. Modèle à faire valider juridiquement.</p>
    <h2>Objet</h2>
    <p>Le service permet le suivi communautaire des coûts énergétiques. Il est fourni « en l'état », sans
       garantie d'exactitude des estimations de coûts.</p>
    <h2>Compte</h2>
    <p>La connexion se fait via un fournisseur OpenID Connect. Vous êtes responsable de la confidentialité de
       vos jetons API. Un usage abusif peut entraîner la suspension du compte.</p>
    <h2>Données</h2>
    <p>Vous restez propriétaire de vos données et pouvez les exporter ou les supprimer à tout moment depuis
       <a href="account.php">votre compte</a>.</p>
  <?php endif; ?>

  <p style="margin-top:32px"><a href="account.php">Mon compte</a> · <a href="<?= $page === 'privacy' ? 'terms.php' : 'privacy.php' ?>"><?= $page === 'privacy' ? 'CGU' : 'Confidentialité' ?></a></p>
</div>
</body>
</html>
