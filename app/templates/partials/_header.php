<?php

declare(strict_types=1);

/**
 * En-tête applicatif commun : bandeau logo + horloge + navigation textuelle,
 * mutualisé entre toutes les pages de la coquille app (#193).
 *
 * Calqué sur le design du dashboard : logo « ⚡ Energy cost management » cliquable vers le
 * tableau de bord, sous-titre propre à la page, horloge live (alimentée par
 * header.js) et navigation par catégories. La page et sa catégorie sont marquées actives.
 * Le lien Admin n'apparaît que pour les administrateurs.
 *
 * @var string            $subtitle    Sous-titre de page, déjà traduit (échappé ici)
 * @var string            $current     Clé de page active : dashboard|tariffs|reconciliation|advances|batteries|meters|admin|account|meter-readings|api-guide|stats
 * @var bool|null         $isAdmin     Affiche le lien Admin (défaut false)
 * @var bool|null         $authenticated Défaut true. À false (visiteur anonyme sur une
 *                                      page publique, cf. /stats #8), la navigation vers
 *                                      les pages privées et le bouton de déconnexion
 *                                      disparaissent au profit d'un lien « Se connecter » :
 *                                      des liens menant tous à /login seraient au mieux
 *                                      inutiles, au pire trompeurs.
 * @var string|null       $discordUrl  URL d'invitation Discord (partial discord-link)
 * @var string|null       $donateUrl   URL de soutien au projet (partial donate-link)
 * @var list<string>|null $available   Locales disponibles pour le sélecteur (défaut [])
 * @var string|null       $timezone    Fuseau IANA du profil pour l'horloge ; null/vide ⇒
 *                                      l'horloge suit le fuseau du navigateur (heure locale
 *                                      du visiteur), plutôt qu'un défaut géographique arbitraire
 */

$current   = $current ?? '';
$isAdmin   = !empty($isAdmin);
$available = $available ?? [];
$timezone  = $timezone ?? '';
// Défaut true : toutes les pages appelantes historiques omettent le paramètre et
// doivent rendre exactement la même sortie qu'avant (#8).
$authenticated = !isset($authenticated) || $authenticated;

?>
<header class="site-header" data-header>
  <a class="logo" href="<?= $this->e($this->url()) ?>">
    <div class="logo-icon" aria-hidden="true">⚡</div>
    <div>
      <div class="logo-text"><?= $this->appName() ?></div>
      <div class="logo-sub"><?= $this->e($subtitle) ?></div>
    </div>
  </a>
  <div class="header-top-tools">
    <div class="clock" data-timezone="<?= $this->e($timezone) ?>">
      <span class="time" id="clock-time">--:--:--</span>
      <span id="clock-date">--- -- ----</span>
    </div>
    <?= $this->partial('_header-controls', [
        'available' => $available, 'authenticated' => $authenticated,
        'isAdmin' => $isAdmin, 'current' => $current,
    ]) ?>
  </div>
  <?= $this->partial('_header-navigation', [
      'authenticated' => $authenticated, 'current' => $current,
      'discordUrl' => $discordUrl ?? null, 'donateUrl' => $donateUrl ?? null,
  ]) ?>
</header>
<script defer src="<?= \App\Support\Assets::url('assets/js/header-nav.js') ?>"></script>
