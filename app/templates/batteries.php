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
 * @var array{batteries: list<array<string,mixed>>, fleet: array<string,mixed>|null}|null $balance Bilan d'économie (#26), null sans batterie
 * @var array<int, array<string,mixed>> $paybacks Amortissement par identifiant de batterie
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
<?php if ($batteries === []): ?>
<p class="bat-intro"><?= $this->te('battery.next_step') ?></p>
<?php endif; ?>

<?php
// ── Bilan d'économie ──────────────────────────────────────────────────────
// Trois chiffres, dans cet ordre : l'économie BRUTE (celle qui amortit), le coût
// d'opportunité (comparatif) et l'économie nette. Les deux derniers sont
// visuellement en retrait : les confondre avec le premier ferait lire une
// comparaison comme la réponse à « combien ai-je économisé ».
$fleetBalance = $balance['fleet'] ?? null;
$perBattery   = $balance['batteries'] ?? [];
$currency     = $fleetBalance['currency'] ?? $currency;
?>
<?php if ($fleetBalance !== null): ?>
<div class="section-header">
  <span class="section-title"><?= $this->te('battery.balance_title') ?></span>
  <span class="section-line"></span>
</div>

<?php if ($fleetBalance['months'] === []): ?>
<p class="bat-empty-hint"><?= $this->te('battery.balance_no_data') ?></p>
<?php else: ?>
<div class="bat-summary">
  <div class="bat-summary-item">
    <span class="bat-summary-label"><?= $this->te('battery.gross_savings') ?></span>
    <span class="bat-summary-value"><?= $this->e($this->money($fleetBalance['gross_savings'], $currency)) ?></span>
  </div>
  <div class="bat-summary-item">
    <span class="bat-summary-label"><?= $this->te('battery.discharged') ?></span>
    <span class="bat-summary-value"><?= $this->e($this->num($fleetBalance['discharge_kwh'], 0)) ?> kWh</span>
  </div>
  <div class="bat-summary-item">
    <span class="bat-summary-label"><?= $this->te('battery.efficiency') ?></span>
    <span class="bat-summary-value">
      <?= $fleetBalance['efficiency'] !== null ? $this->e($this->num($fleetBalance['efficiency'] * 100.0, 1)) . ' %' : '—' ?>
    </span>
  </div>
  <div class="bat-summary-item bat-summary-item--muted">
    <span class="bat-summary-label"><?= $this->te('battery.opportunity_cost') ?></span>
    <span class="bat-summary-value"><?= $this->e($this->money($fleetBalance['opportunity_cost'], $currency)) ?></span>
  </div>
  <div class="bat-summary-item bat-summary-item--muted">
    <span class="bat-summary-label"><?= $this->te('battery.net_savings') ?></span>
    <span class="bat-summary-value"><?= $this->e($this->money($fleetBalance['net_savings'], $currency)) ?></span>
  </div>
  <p class="bat-summary-note"><?= $this->te('battery.balance_method') ?></p>
</div>

<?php
// ── Amortissement ─────────────────────────────────────────────────────────
// Une carte par batterie : investissement, garantie et cycles sont propres à
// chaque matériel, il n'existe pas de projection « du parc » qui voudrait dire
// quelque chose.
?>
<?php foreach ($batteries as $battery): ?>
<?php $payback = $paybacks[$battery->id] ?? null; ?>
<?php if ($payback !== null): ?>
<div class="bat-payback">
  <div class="bat-payback-head">
    <span class="bat-payback-title"><?= $this->te('battery.payback_title') ?></span>
    <?php if (count($batteries) > 1): ?>
    <span class="bat-note"><?= $this->e($battery->label()) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($payback['investment'] === null): ?>
  <p class="bat-gap"><?= $this->te('battery.payback_no_price') ?></p>
  <?php elseif ($payback['paid_back']): ?>
  <p class="bat-payback-verdict bat-payback-verdict--done">
    <?= $this->te('battery.payback_done', ['amount' => $this->money($payback['investment'], $currency)]) ?>
  </p>
  <?php else: ?>
  <div class="bat-payback-bar" role="img"
       aria-label="<?= $this->e($this->t('battery.payback_progress', ['pct' => $this->num($payback['progress_pct'] ?? 0.0, 0)])) ?>">
    <span style="width: <?= $this->e($this->num(min(100.0, max(0.0, $payback['progress_pct'] ?? 0.0)), 1)) ?>%"></span>
  </div>
  <p class="bat-payback-figures">
    <?= $this->te('battery.payback_progress_detail', [
        'saved'      => $this->money($payback['savings'], $currency),
        'investment' => $this->money($payback['investment'], $currency),
        'remaining'  => $this->money($payback['remaining'] ?? 0.0, $currency),
    ]) ?>
  </p>

  <?php if ($payback['payback_on'] !== null): ?>
  <p class="bat-payback-verdict">
    <?= $this->te('battery.payback_eta', [
        'month' => $payback['payback_on'],
        'rate'  => $this->money($payback['monthly_rate'] ?? 0.0, $currency),
    ]) ?>
  </p>
  <?php elseif ($payback['beyond_horizon']): ?>
  <p class="bat-payback-verdict"><?= $this->te('battery.payback_beyond_horizon') ?></p>
  <?php else: ?>
  <p class="bat-payback-verdict"><?= $this->te('battery.payback_no_rate') ?></p>
  <?php endif; ?>

  <?php // L'avertissement de biais saisonnier n'est PAS optionnel : une moyenne
        // prise sur quelques mois d'été promettrait un amortissement qui ne
        // viendra pas. Il disparaît de lui-même dès qu'une année est couverte. ?>
  <?php if ($payback['seasonally_biased'] && $payback['payback_on'] !== null): ?>
  <p class="bat-warn">⚠ <?= $this->te('battery.payback_biased', ['months' => (string) $payback['months_observed']]) ?></p>
  <?php endif; ?>
  <?php if ($payback['after_warranty']): ?>
  <p class="bat-warn">⚠ <?= $this->te('battery.payback_after_warranty') ?></p>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($payback['cycles_used'] !== null): ?>
  <p class="bat-payback-cycles">
    <?= $payback['cycles_pct'] !== null
        ? $this->te('battery.cycles_used_of', [
            'used'  => $this->num($payback['cycles_used'], 0),
            'rated' => (string) $payback['rated_cycles'],
            'pct'   => $this->num($payback['cycles_pct'], 1),
        ])
        : $this->te('battery.cycles_used', ['used' => $this->num($payback['cycles_used'], 0)]) ?>
  </p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php if ($fleetBalance['has_unsupported_months']): ?>
<p class="bat-warn">⚠ <?= $this->te('battery.dynamic_unsupported') ?></p>
<?php endif; ?>
<?php if ($fleetBalance['has_untariffed_months']): ?>
<p class="bat-warn">⚠ <?= $this->te('battery.no_tariff_warning') ?></p>
<?php endif; ?>

<?php // Détail par batterie : masqué tant qu'il n'y en a qu'une, où il répéterait
      // mot pour mot le bandeau du parc.
      if (count($perBattery) > 1): ?>
<div class="bat-table-wrap">
  <table class="bat-table">
    <thead>
      <tr>
        <th><?= $this->te('battery.col_battery') ?></th>
        <th class="num"><?= $this->te('battery.charged') ?></th>
        <th class="num"><?= $this->te('battery.discharged') ?></th>
        <th class="num"><?= $this->te('battery.efficiency') ?></th>
        <th class="num"><?= $this->te('battery.gross_savings') ?></th>
        <th class="num"><?= $this->te('battery.net_savings') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($perBattery as $row): ?>
      <tr>
        <td>
          <?= $this->e($row['label']) ?>
          <span class="bat-note"><?= $this->te('battery.pv_share_short', ['value' => (string) $row['pv_charge_share']]) ?> · <?= $this->te('battery.discharge_profile.' . $row['discharge_profile']) ?></span>
        </td>
        <td class="num"><?= $this->e($this->num($row['charge_kwh'], 0)) ?> kWh</td>
        <td class="num"><?= $this->e($this->num($row['discharge_kwh'], 0)) ?> kWh</td>
        <td class="num"><?= $row['efficiency'] !== null ? $this->e($this->num($row['efficiency'] * 100.0, 1)) . ' %' : '—' ?></td>
        <td class="num"><?= $this->e($this->money($row['gross_savings'], $row['currency'] ?? $currency)) ?></td>
        <td class="num"><?= $this->e($this->money($row['net_savings'], $row['currency'] ?? $currency)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="bat-table-wrap">
  <table class="bat-table">
    <thead>
      <tr>
        <th><?= $this->te('battery.col_month') ?></th>
        <th class="num"><?= $this->te('battery.charged') ?></th>
        <th class="num"><?= $this->te('battery.discharged') ?></th>
        <th class="num"><?= $this->te('battery.efficiency') ?></th>
        <th class="num"><?= $this->te('battery.gross_savings') ?></th>
        <th class="num"><?= $this->te('battery.opportunity_cost') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach (array_reverse($fleetBalance['months']) as $month): ?>
      <tr class="<?= $month['gross_savings'] === null ? 'is-unvalued' : '' ?>">
        <td>
          <?= $this->e($month['month']) ?>
          <?php if ($month['partial']): ?>
          <span class="bat-note"><?= $this->te('battery.partial_month') ?></span>
          <?php endif; ?>
        </td>
        <td class="num"><?= $month['charge_kwh'] !== null ? $this->e($this->num($month['charge_kwh'], 0)) . ' kWh' : '—' ?></td>
        <td class="num"><?= $month['discharge_kwh'] !== null ? $this->e($this->num($month['discharge_kwh'], 0)) . ' kWh' : '—' ?></td>
        <td class="num"><?= $month['efficiency'] !== null ? $this->e($this->num($month['efficiency'] * 100.0, 1)) . ' %' : '—' ?></td>
        <?php if ($month['gross_savings'] === null): ?>
        <?php // Motif affiché plutôt qu'un tiret muet : « pas de chiffre » sans
              // raison est indiagnosticable depuis l'écran. ?>
        <td colspan="2" class="bat-gap">
          <?= $month['unsupported_mode'] ? $this->te('battery.month_dynamic') : $this->te('battery.month_no_tariff') ?>
        </td>
        <?php else: ?>
        <td class="num"><?= $this->e($this->money($month['gross_savings'], $month['currency'] ?? $currency)) ?></td>
        <td class="num bat-muted"><?= $month['opportunity_cost'] !== null ? $this->e($this->money($month['opportunity_cost'], $month['currency'] ?? $currency)) : '—' ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>

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
