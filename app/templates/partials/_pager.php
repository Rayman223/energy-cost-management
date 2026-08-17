<?php

/**
 * Contrôle de pagination d'un tableau d'historique (#257).
 *
 * Les libellés fixes sont rendus ici (traduits côté serveur) ; meter-readings.js
 * ne remplit que le statut (« Page 2 / 14 ») et l'état activé/désactivé des
 * boutons, et masque le bloc tant qu'une seule page existe.
 *
 * @var string $id Identifiant du conteneur (ex. 'gas-pager'), corrélé au préfixe
 *                 d'énergie côté script.
 */
?>
<div class="pager" id="<?= $this->e($id) ?>" hidden>
  <button type="button" class="btn btn-ghost btn-xs" data-page-prev disabled>‹ <?= $this->te('meter.page_prev') ?></button>
  <span class="pager-status" data-page-status aria-live="polite"></span>
  <button type="button" class="btn btn-ghost btn-xs" data-page-next disabled><?= $this->te('meter.page_next') ?> ›</button>
</div>
