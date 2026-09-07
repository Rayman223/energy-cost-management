<?php

use App\Domain\Meter;

/**
 * Parc de compteurs (#55) : les compteurs déclarés par l'utilisateur, une ligne
 * par compteur, groupés par énergie.
 *
 * Deux choses sont volontairement visibles à l'écran plutôt que découvertes à
 * l'usage : le QUOTA de chaque énergie (« 2 / 5 »), parce qu'un refus de créer
 * sans compteur affiché serait incompréhensible, et le NOMBRE DE RELEVÉS de
 * chaque compteur, parce que c'est ce que la suppression emporte.
 *
 * @var list<Meter>       $meters         Parc, électricité puis gaz puis eau
 * @var array<int, int>   $readingCounts  Relevés portés par compteur (id => nombre)
 * @var array<string,int> $countsByEnergy Compteurs déjà déclarés par énergie
 * @var int               $maxPerEnergy   Plafond configuré (limits.meters_per_energy)
 * @var Meter|null        $editing        Compteur rouvert pour renommage
 * @var DateTimeImmutable $today          Jour civil de l'utilisateur (badge « fermé »)
 * @var string|null       $error
 * @var string|null       $success
 * @var bool              $isAdmin
 * @var list<string>      $available
 * @var ?string           $timezone
 * @var ?string           $discordUrl
 * @var ?string           $donateUrl
 * @var ?string           $adsenseClient
 */

// Énergies encore ouvertes à la création. Une énergie saturée sort du sélecteur :
// la laisser proposable pour ne refuser qu'après validation ferait chercher une
// faute de saisie là où il n'y a qu'un quota atteint.
$openEnergies = array_values(array_filter(
    Meter::ENERGIES,
    static fn (string $energy): bool => ($countsByEnergy[$energy] ?? 0) < $maxPerEnergy,
));
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>" data-confirm-title="<?= $this->e($this->t('common.confirm_title')) ?>" data-confirm-ok="<?= $this->e($this->t('common.confirm')) ?>" data-confirm-cancel="<?= $this->e($this->t('common.cancel')) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $this->t('meters.title') . ' — ' . $this->t('app.title'),
    // tariffs.css d'abord : la page reprend la grammaire de formulaire des tarifs
    // (.form-card, .section-header, .btn, .alert) plutôt que de la dupliquer.
    'css'   => ['assets/css/app-header.css', 'assets/css/lang-switcher.css', 'assets/css/confirm.css', 'assets/css/tariffs.css', 'assets/css/meters.css'],
    'adsenseClient' => $adsenseClient ?? null,
]) ?>
</head>
<body>
<div class="wrap">

<?= $this->partial('_header', [
    'subtitle'   => $this->t('meters.subtitle'),
    'current'    => 'meters',
    'isAdmin'    => $isAdmin ?? false,
    'discordUrl' => $discordUrl ?? null,
    'donateUrl'  => $donateUrl ?? null,
    'available'  => $available,
    'timezone'   => $timezone ?? null,
]) ?>

<?php if ($success): ?>
<div class="alert alert-ok alert--push">✓ <?= $this->e($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-err alert--push">✗ <?= $this->e($error) ?></div>
<?php endif; ?>

<p class="mtr-intro"><?= $this->te('meters.intro') ?></p>

<!-- ── Quotas ───────────────────────────────────────────────────────────── -->
<div class="mtr-quotas">
  <?php foreach (Meter::ENERGIES as $energy): ?>
  <?php $used = $countsByEnergy[$energy] ?? 0; ?>
  <div class="mtr-quota<?= $used >= $maxPerEnergy ? ' mtr-quota--full' : '' ?>">
    <span class="mtr-quota-label"><?= $this->te('meters.energy.' . $energy) ?></span>
    <span class="mtr-quota-value"><?= $this->e((string) $used) ?> / <?= $this->e((string) $maxPerEnergy) ?></span>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Parc ─────────────────────────────────────────────────────────────── -->
<div class="section-header">
  <span class="section-title"><?= $this->te('meters.fleet_title') ?></span>
  <span class="section-line"></span>
</div>

<?php if ($meters === []): ?>
<p class="mtr-empty-hint"><?= $this->te('meters.no_meter') ?></p>
<?php else: ?>
<div class="mtr-table-wrap">
  <table class="mtr-table">
    <thead>
      <tr>
        <th><?= $this->te('meters.col_meter') ?></th>
        <th><?= $this->te('meters.col_energy') ?></th>
        <th class="num"><?= $this->te('meters.col_readings') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($meters as $meter): ?>
      <?php
        $closed = $meter->isClosedOn($today);
        $count  = $readingCounts[$meter->id] ?? 0;
        $rowClass = array_filter([
            $editing !== null && $editing->id === $meter->id ? 'is-editing' : '',
            $closed ? 'is-closed' : '',
        ]);
      ?>
      <tr class="<?= $this->e(implode(' ', $rowClass)) ?>">
        <td>
          <?php // Libellé vide ⇒ dérivé ici, dans la langue du lecteur — jamais figé en base. ?>
          <?= $meter->isNamed() ? $this->e($meter->label) : $this->te($meter->defaultLabelKey()) ?>
          <?php if (!$meter->isNamed()): ?>
          <span class="mtr-note"><?= $this->te('meters.unnamed_hint') ?></span>
          <?php endif; ?>
          <?php if ($closed && $meter->closedOn !== null): ?>
          <span class="mtr-badge mtr-badge--closed"><?= $this->te('meters.closed_on', ['date' => $meter->closedOn->format('Y-m-d')]) ?></span>
          <?php endif; ?>
        </td>
        <td><?= $this->te($meter->energyLabelKey()) ?></td>
        <td class="num"><?= $this->e($this->num((float) $count, 0)) ?></td>
        <td class="actions">
          <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('meters')) ?>?edit=<?= $this->e((string) $meter->id) ?>#mtr-label"><?= $this->te('meters.edit') ?></a>
          <?php
            // L'intention vit dans un champ caché, pas sur le bouton :
            // confirm.js relance l'envoi par requestSubmit(), qui ne transmet ni
            // le nom ni la valeur du bouton pressé.
          ?>
          <form method="post" data-confirm="<?= $this->e($this->t('meters.delete_confirm', ['count' => $count])) ?>" data-confirm-ok="<?= $this->e($this->t('meters.delete')) ?>" data-confirm-danger>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="meter_id" value="<?= $this->e((string) $meter->id) ?>">
            <?= \App\Security\Csrf::field() ?>
            <button type="submit" class="btn btn-red btn-sm"><?= $this->te('meters.delete') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── Formulaire ───────────────────────────────────────────────────────── -->
<div class="section-header">
  <span class="section-title"><?= $editing !== null ? $this->te('meters.edit_title') : $this->te('meters.add_title') ?></span>
  <span class="section-line"></span>
</div>

<?php if ($editing === null && $openEnergies === []): ?>
<p class="mtr-empty-hint"><?= $this->te('meters.all_full', ['limit' => $maxPerEnergy]) ?></p>
<?php else: ?>
<div class="form-card">
  <form method="post">
    <input type="hidden" name="action" value="save">
    <?php if ($editing !== null): ?>
    <input type="hidden" name="meter_id" value="<?= $this->e((string) $editing->id) ?>">
    <?php endif; ?>
    <?= \App\Security\Csrf::field() ?>

    <div class="form-grid">
      <div class="form-row">
        <label class="form-label" for="mtr-energy"><?= $this->te('meters.energy_field') ?></label>
        <?php if ($editing !== null): ?>
        <?php // Énergie FIGÉE à l'édition : la changer sur un compteur qui a des
              // relevés rendrait sa série incohérente sans que rien ne le signale.
              // Affichée en lecture seule plutôt que masquée, pour que la ligne
              // rouverte reste identifiable. ?>
        <input type="text" id="mtr-energy" class="form-input" disabled
               value="<?= $this->e($this->t($editing->energyLabelKey())) ?>">
        <p class="dates-hint"><?= $this->te('meters.energy_frozen') ?></p>
        <?php else: ?>
        <select id="mtr-energy" name="energy_type" class="form-input">
          <?php foreach ($openEnergies as $energy): ?>
          <option value="<?= $this->e($energy) ?>"><?= $this->te('meters.energy.' . $energy) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
      <div class="form-row">
        <label class="form-label" for="mtr-label"><?= $this->te('meters.label_field') ?> <span class="unit"><?= $this->te('common.optional') ?></span></label>
        <input type="text" id="mtr-label" name="label" class="form-input" maxlength="<?= $this->e((string) Meter::MAX_LABEL) ?>"
               placeholder="<?= $this->e($this->t('meters.label_placeholder')) ?>"
               value="<?= $this->e($editing->label ?? '') ?>">
        <p class="dates-hint"><?= $this->te('meters.label_hint') ?></p>
      </div>
      <div class="form-row">
        <label class="form-label" for="mtr-closed"><?= $this->te('meters.closed_field') ?> <span class="unit"><?= $this->te('common.end_exclusive') ?></span></label>
        <input type="date" id="mtr-closed" name="closed_on" class="form-input"
               value="<?= $this->e($editing?->closedOn?->format('Y-m-d') ?? '') ?>">
        <p class="dates-hint"><?= $this->te('meters.closed_hint') ?></p>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-amber"><?= $editing !== null ? $this->te('meters.save_edit') : $this->te('meters.save_new') ?></button>
      <?php if ($editing !== null): ?>
      <a class="btn btn-ghost" href="<?= $this->e($this->url('meters')) ?>"><?= $this->te('common.cancel') ?></a>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<p class="mtr-intro"><?= $this->te('meters.next_step') ?></p>

<div class="page-footer">
  <span><?= $this->appName() ?> — <?= $this->te('meters.title') ?></span>
</div>

</div>

<script defer src="<?= \App\Support\Assets::url('assets/js/header.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/confirm.js') ?>"></script>
</body>
</html>
