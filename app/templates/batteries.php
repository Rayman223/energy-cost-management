<?php

use App\Domain\Battery;
use App\Domain\BatteryDischargeProfile;

/**
 * Parc de batteries (#26) : le matériel déclaré par l'utilisateur, son
 * investissement, et les deux hypothèses qui rendront son bilan calculable.
 *
 * Les hypothèses sont présentées comme telles — part de charge photovoltaïque et
 * profil de décharge ne se mesurent pas depuis les index, ils se déclarent. Les
 * afficher au même rang que la capacité laisserait croire à une donnée relevée.
 *
 * @var string|null                     $error
 * @var string|null                     $success
 * @var bool                            $isAdmin
 * @var list<Battery>                   $batteries   Parc, mise en service décroissante
 * @var Battery|null                    $editing     Batterie rouverte pour modification
 * @var list<BatteryDischargeProfile>   $profiles    Profils de décharge proposables
 * @var DateTimeImmutable               $today       Jour civil de l'utilisateur (badges de service)
 * @var float                           $maxCapacity Plafond de saisie (capacité de la colonne)
 * @var float                           $maxPrice
 * @var int                             $maxCycles
 * @var string                          $currency    Devise du profil
 * @var list<string>                    $available
 * @var ?string                         $timezone
 * @var ?string                         $discordUrl
 * @var ?string                         $adsenseClient
 */
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>" data-confirm-title="<?= $this->e($this->t('common.confirm_title')) ?>" data-confirm-ok="<?= $this->e($this->t('common.confirm')) ?>" data-confirm-cancel="<?= $this->e($this->t('common.cancel')) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $this->t('battery.title') . ' — ' . $this->t('app.title'),
    // tariffs.css d'abord : la page reprend la grammaire de formulaire des tarifs
    // (.form-card, .section-header, .btn, .alert) plutôt que de la dupliquer.
    'css'   => ['assets/css/app-header.css', 'assets/css/lang-switcher.css', 'assets/css/confirm.css', 'assets/css/tariffs.css', 'assets/css/batteries.css'],
    'adsenseClient' => $adsenseClient ?? null,
]) ?>
</head>
<body>
<div class="wrap">

<?= $this->partial('_header', [
    'subtitle'   => $this->t('battery.subtitle'),
    'current'    => 'batteries',
    'isAdmin'    => $isAdmin ?? false,
    'discordUrl' => $discordUrl ?? null,
    'available'  => $available,
    'timezone'   => $timezone ?? null,
]) ?>

<?php if ($success): ?>
<div class="alert alert-ok alert--push">✓ <?= $this->e($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-err alert--push">✗ <?= $this->e($error) ?></div>
<?php endif; ?>

<p class="bat-intro"><?= $this->te('battery.intro') ?></p>
<p class="bat-intro"><?= $this->te('battery.next_step') ?></p>

<!-- ── Formulaire ────────────────────────────────────────────────────────── -->
<div class="section-header">
  <span class="section-title"><?= $editing !== null ? $this->te('battery.edit_title') : $this->te('battery.add_title') ?></span>
  <span class="section-line"></span>
</div>

<div class="form-card">
  <form method="post">
    <input type="hidden" name="action" value="save">
    <?php if ($editing !== null): ?>
    <input type="hidden" name="battery_id" value="<?= $this->e((string) $editing->id) ?>">
    <?php endif; ?>
    <?= \App\Security\Csrf::field() ?>

    <div class="form-grid">
      <div class="form-row">
        <label class="form-label" for="bat-brand"><?= $this->te('battery.brand') ?> <span class="unit"><?= $this->te('common.optional') ?></span></label>
        <input type="text" id="bat-brand" name="brand" class="form-input" maxlength="<?= $this->e((string) Battery::MAX_BRAND) ?>"
               placeholder="<?= $this->e($this->t('battery.brand_placeholder')) ?>"
               value="<?= $this->e($editing->brand ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="bat-model"><?= $this->te('battery.model') ?> <span class="unit"><?= $this->te('common.optional') ?></span></label>
        <input type="text" id="bat-model" name="model" class="form-input" maxlength="<?= $this->e((string) Battery::MAX_MODEL) ?>"
               placeholder="<?= $this->e($this->t('battery.model_placeholder')) ?>"
               value="<?= $this->e($editing->model ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="bat-capacity"><?= $this->te('battery.capacity') ?> <span class="unit">kWh</span></label>
        <input type="number" id="bat-capacity" name="capacity_kwh" class="form-input" step="0.001" min="0.001"
               max="<?= $this->e($maxCapacity) ?>" required placeholder="10.000"
               value="<?= $editing !== null ? $this->e(number_format($editing->capacityKwh, 3, '.', '')) : '' ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="bat-usable"><?= $this->te('battery.usable_capacity') ?> <span class="unit">kWh · <?= $this->te('common.optional') ?></span></label>
        <input type="number" id="bat-usable" name="usable_capacity_kwh" class="form-input" step="0.001" min="0.001"
               max="<?= $this->e($maxCapacity) ?>" placeholder="9.200"
               value="<?= $editing?->usableCapacityKwh !== null ? $this->e(number_format($editing->usableCapacityKwh, 3, '.', '')) : '' ?>">
      </div>
      <div class="form-row full">
        <p class="dates-hint"><?= $this->te('battery.capacity_hint') ?></p>
      </div>

      <div class="form-row">
        <label class="form-label" for="bat-price"><?= $this->te('battery.purchase_price') ?> <span class="unit"><?= $this->e($this->currencySymbol($currency)) ?> · <?= $this->te('common.optional') ?></span></label>
        <input type="number" id="bat-price" name="purchase_price" class="form-input" step="0.01" min="0"
               max="<?= $this->e($maxPrice) ?>" placeholder="0.00"
               value="<?= $editing?->purchasePrice !== null ? $this->e(number_format($editing->purchasePrice, 2, '.', '')) : '' ?>">
      </div>
      <div class="form-row">
        <!-- `type=date` : le navigateur rend un sélecteur déjà localisé et poste un
             'YYYY-MM-DD' non ambigu. Le format est revalidé côté serveur. -->
        <label class="form-label" for="bat-commissioned"><?= $this->te('battery.commissioned_on') ?></label>
        <input type="date" id="bat-commissioned" name="commissioned_on" class="form-input" required
               value="<?= $editing !== null ? $this->e($editing->commissionedOn->format('Y-m-d')) : '' ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="bat-decommissioned"><?= $this->te('battery.decommissioned_on') ?> <span class="unit"><?= $this->te('common.optional') ?> · <?= $this->te('common.end_exclusive') ?></span></label>
        <input type="date" id="bat-decommissioned" name="decommissioned_on" class="form-input"
               value="<?= $editing?->decommissionedOn !== null ? $this->e($editing->decommissionedOn->format('Y-m-d')) : '' ?>">
      </div>
      <div class="form-row full">
        <p class="dates-hint"><?= $this->te('battery.price_hint') ?></p>
      </div>

      <div class="form-row">
        <label class="form-label" for="bat-warranty"><?= $this->te('battery.warranty_until') ?> <span class="unit"><?= $this->te('common.optional') ?></span></label>
        <input type="date" id="bat-warranty" name="warranty_until" class="form-input"
               value="<?= $editing?->warrantyUntil !== null ? $this->e($editing->warrantyUntil->format('Y-m-d')) : '' ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="bat-cycles"><?= $this->te('battery.rated_cycles') ?> <span class="unit"><?= $this->te('common.optional') ?></span></label>
        <input type="number" id="bat-cycles" name="rated_cycles" class="form-input" step="1" min="1"
               max="<?= $this->e((string) $maxCycles) ?>" placeholder="6000"
               value="<?= $editing?->ratedCycles !== null ? $this->e((string) $editing->ratedCycles) : '' ?>">
      </div>

      <div class="form-row full">
        <p class="bat-assumption"><?= $this->te('battery.assumptions_hint') ?></p>
      </div>
      <div class="form-row">
        <label class="form-label" for="bat-pv-share"><?= $this->te('battery.pv_charge_share') ?> <span class="unit">%</span></label>
        <input type="number" id="bat-pv-share" name="pv_charge_share" class="form-input" step="1" min="0" max="100" required
               value="<?= $this->e((string) ($editing->pvChargeShare ?? 100)) ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="bat-profile"><?= $this->te('battery.discharge_profile') ?></label>
        <select id="bat-profile" name="discharge_profile" class="form-input" required data-t1-target="bat-t1-row">
          <?php foreach ($profiles as $profile): ?>
          <option value="<?= $this->e($profile->value) ?>"
                  data-requires-t1="<?= $profile->requiresT1Share() ? '1' : '0' ?>"
                  <?= ($editing?->dischargeProfile ?? BatteryDischargeProfile::ImportMix) === $profile ? ' selected' : '' ?>><?= $this->te($profile->labelKey()) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php // Part T1 : masquée hors profil « ratio » par batteries.js. Sans JS elle
            // reste visible ; le serveur l'ignore alors, plutôt que de refuser le
            // formulaire pour un champ hors sujet. ?>
      <div class="form-row<?= ($editing?->dischargeProfile ?? BatteryDischargeProfile::ImportMix)->requiresT1Share() ? '' : ' bat-row-hidden' ?>" id="bat-t1-row">
        <label class="form-label" for="bat-t1-share"><?= $this->te('battery.discharge_t1_share') ?> <span class="unit">%</span></label>
        <input type="number" id="bat-t1-share" name="discharge_t1_share" class="form-input" step="1" min="0" max="100"
               value="<?= $editing?->dischargeT1Share !== null ? $this->e((string) $editing->dischargeT1Share) : '' ?>">
      </div>
      <div class="form-row full">
        <p class="dates-hint"><?= $this->te('battery.discharge_hint') ?></p>
      </div>

      <div class="form-row full">
        <label class="form-label" for="bat-note"><?= $this->te('battery.note') ?> <span class="unit"><?= $this->te('common.optional') ?></span></label>
        <input type="text" id="bat-note" name="note" class="form-input" maxlength="<?= $this->e((string) Battery::MAX_NOTE) ?>"
               placeholder="<?= $this->e($this->t('battery.note_placeholder')) ?>"
               value="<?= $this->e($editing->note ?? '') ?>">
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-amber"><?= $this->te('battery.save') ?></button>
      <?php if ($editing !== null): ?>
      <a class="btn btn-ghost" href="<?= $this->e($this->url('batteries')) ?>"><?= $this->te('common.cancel') ?></a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- ── Parc enregistré ───────────────────────────────────────────────────── -->
<div class="section-header">
  <span class="section-title"><?= $this->te('battery.list_title') ?></span>
  <span class="section-line"></span>
</div>

<?php if ($batteries === []): ?>
<p class="bat-empty-hint"><?= $this->te('battery.no_battery') ?></p>
<?php else: ?>
<div class="bat-table-wrap">
  <table class="bat-table">
    <thead>
      <tr>
        <th><?= $this->te('battery.col_battery') ?></th>
        <th class="num"><?= $this->te('battery.col_capacity') ?></th>
        <th><?= $this->te('battery.col_service') ?> <span class="unit"><?= $this->te('common.end_exclusive') ?></span></th>
        <th class="num"><?= $this->te('battery.col_price') ?></th>
        <th><?= $this->te('battery.col_assumptions') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($batteries as $battery): ?>
      <?php
        // Trois états mutuellement exclusifs, sur le jour civil de l'utilisateur :
        // en service, pas encore installée, déposée. Le liseré est doublé d'un badge
        // textuel — la couleur seule n'est pas une information.
        $inService = $battery->isInServiceOn($today);
        $retired   = $battery->isDecommissionedOn($today);
        $upcoming  = !$inService && !$retired;
        $rowClass  = array_filter([
            $editing !== null && $editing->id === $battery->id ? 'is-editing' : '',
            $inService ? 'is-current' : '',
            $upcoming ? 'is-upcoming' : '',
            $retired ? 'is-retired' : '',
        ]);
      ?>
      <tr class="<?= $this->e(implode(' ', $rowClass)) ?>">
        <td>
          <?= $this->e($battery->label()) ?>
          <?php if ($battery->note !== ''): ?>
          <span class="bat-note"><?= $this->e($battery->note) ?></span>
          <?php endif; ?>
        </td>
        <td class="num">
          <?= $this->e($this->num($battery->capacityKwh, 1)) ?> kWh
          <?php if ($battery->usableCapacityKwh !== null): ?>
          <span class="bat-note"><?= $this->te('battery.usable_short', ['value' => $this->num($battery->usableCapacityKwh, 1)]) ?></span>
          <?php endif; ?>
        </td>
        <td>
          <?= $this->e($battery->serviceLabel()) ?>
          <?php if ($inService): ?>
          <span class="bat-badge"><?= $this->te('battery.in_service') ?></span>
          <?php elseif ($upcoming): ?>
          <span class="bat-badge bat-badge--upcoming"><?= $this->te('battery.upcoming') ?></span>
          <?php endif; ?>
          <?php if ($battery->isOutOfWarrantyOn($today)): ?>
          <span class="bat-badge bat-badge--warranty"><?= $this->te('battery.out_of_warranty') ?></span>
          <?php endif; ?>
          <?php if ($battery->ratedCycles !== null): ?>
          <span class="bat-note"><?= $this->te('battery.cycles_short', ['value' => (string) $battery->ratedCycles]) ?></span>
          <?php endif; ?>
        </td>
        <td class="num">
          <?= $battery->purchasePrice !== null ? $this->e($this->money($battery->purchasePrice, $currency)) : '—' ?>
        </td>
        <td>
          <?= $this->te('battery.pv_share_short', ['value' => (string) $battery->pvChargeShare]) ?>
          <span class="bat-note">
            <?= $this->te($battery->dischargeProfile->labelKey()) ?><?php if ($battery->dischargeT1Share !== null): ?> · <?= $this->te('battery.t1_share_short', ['value' => (string) $battery->dischargeT1Share]) ?><?php endif; ?>
          </span>
        </td>
        <td class="actions">
          <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('batteries')) ?>?edit=<?= $this->e((string) $battery->id) ?>#bat-brand"><?= $this->te('battery.edit') ?></a>
          <form method="post" data-confirm="<?= $this->e($this->t('battery.delete_confirm')) ?>" data-confirm-ok="<?= $this->e($this->t('battery.delete')) ?>" data-confirm-danger>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="battery_id" value="<?= $this->e((string) $battery->id) ?>">
            <?= \App\Security\Csrf::field() ?>
            <button type="submit" class="btn btn-red btn-sm"><?= $this->te('battery.delete') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="page-footer">
  <span><?= $this->appName() ?> — <?= $this->te('battery.title') ?></span>
</div>

</div>

<script defer src="<?= \App\Support\Assets::url('assets/js/header.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/confirm.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/batteries.js') ?>"></script>
</body>
</html>
