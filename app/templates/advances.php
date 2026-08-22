<?php

use App\Domain\AdvanceBalance;
use App\Domain\AdvanceSchedule;

/**
 * Acomptes (#241) : barèmes mensuels payés au fournisseur, et bilan sur une
 * période à dates exactes — ce qui a été payé, ce qui a été consommé, et le solde
 * de régularisation qui en découle.
 *
 * @var string|null              $error
 * @var string|null              $success
 * @var bool                     $isAdmin
 * @var list<AdvanceSchedule>    $schedules     Barèmes saisis, toutes énergies
 * @var AdvanceSchedule|null     $editing       Barème rouvert pour modification
 * @var array{from:DateTimeImmutable,to:DateTimeImmutable,balances:list<AdvanceBalance>,total_paid:float,total_cost:float,total_balance:float,currency:?string,has_gaps:bool,has_partial_data:bool,has_partial_advances:bool,has_cost_without_advance:bool,has_advance_without_instalment:bool,has_short_advance_without_instalment:bool,mixed_currency:bool}|null $balance
 * @var string|null              $periodError   Période demandée invalide
 * @var bool                     $futureClamped Fin ramenée à aujourd'hui (acompte à échoir)
 * @var string                   $periodFrom    'YYYY-MM-DD' du formulaire de période
 * @var string                   $periodTo      'YYYY-MM-DD' du formulaire de période
 * @var DateTimeImmutable        $highlightFrom Début du bilan affiché (référentiel du liseré)
 * @var DateTimeImmutable        $highlightTo   Fin EXCLUE du bilan affiché
 * @var list<string>             $energyTypes
 * @var float                    $maxAmount     Plafond de saisie (capacité de la colonne)
 * @var string                   $currency      Devise du profil
 * @var list<string>             $available
 * @var ?string                  $timezone
 * @var ?string                  $discordUrl
 * @var ?string                  $adsenseClient
 */

$currency = $balance['currency'] ?? $currency;
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>" data-confirm-title="<?= $this->e($this->t('common.confirm_title')) ?>" data-confirm-ok="<?= $this->e($this->t('common.confirm')) ?>" data-confirm-cancel="<?= $this->e($this->t('common.cancel')) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $this->t('advances.title') . ' — ' . $this->t('app.title'),
    // tariffs.css d'abord : la page reprend la grammaire de formulaire des tarifs
    // (.form-card, .section-header, .btn, .alert) plutôt que de la dupliquer.
    'css'   => ['assets/css/app-header.css', 'assets/css/lang-switcher.css', 'assets/css/confirm.css', 'assets/css/tariffs.css', 'assets/css/advances.css'],
    'adsenseClient' => $adsenseClient ?? null,
]) ?>
</head>
<body>
<div class="wrap">

<?= $this->partial('_header', [
    'subtitle'   => $this->t('advances.subtitle'),
    'current'    => 'advances',
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

<p class="adv-intro"><?= $this->te('advances.intro') ?></p>

<!-- ── Bilan de la période ───────────────────────────────────────────────── -->
<div class="section-header">
  <span class="section-title"><?= $this->te('advances.balance_title') ?></span>
  <span class="section-line"></span>
</div>

<div class="form-card">
  <form method="get">
    <div class="form-grid">
      <div class="form-row">
        <label class="form-label" for="adv-from"><?= $this->te('advances.period_from') ?></label>
        <input type="date" id="adv-from" name="from" class="form-input" required min="2000-01-01" max="2100-12-31" value="<?= $this->e($periodFrom) ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="adv-to"><?= $this->te('advances.period_to') ?> <span class="unit"><?= $this->te('common.end_exclusive') ?></span></label>
        <input type="date" id="adv-to" name="to" class="form-input" required min="2000-01-01" max="2100-12-31" value="<?= $this->e($periodTo) ?>">
      </div>
      <div class="form-row full">
        <p class="dates-hint"><?= $this->te('advances.period_hint') ?></p>
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-amber"><?= $this->te('advances.compute') ?></button>
    </div>
  </form>
</div>

<?php if ($periodError !== null): ?>
<div class="alert alert-err alert--push">✗ <?= $this->e($periodError) ?></div>
<?php elseif ($balance !== null && $balance['balances'] === []): ?>
<p class="adv-empty-hint"><?= $this->te('advances.no_data') ?></p>
<?php elseif ($balance !== null): ?>

<div class="adv-table-wrap">
  <table class="adv-table">
    <thead>
      <tr>
        <th><?= $this->te('advances.col_energy') ?></th>
        <th class="num"><?= $this->te('advances.col_due_count') ?></th>
        <th class="num"><?= $this->te('advances.col_paid') ?></th>
        <th class="num"><?= $this->te('advances.col_cost') ?></th>
        <th class="num"><?= $this->te('advances.col_balance') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($balance['balances'] as $row): ?>
      <?php // Hors solde : coût manquant, ou coût connu mais aucun acompte à comparer.
            $inTotal = $row->isComparable() && $row->dueCount > 0; ?>
      <tr class="<?= $inTotal ? '' : 'is-gap' ?>">
        <td><?= $this->te('advances.energy.' . $row->energyType) ?></td>
        <td class="num"><?= $this->e((string) $row->dueCount) ?></td>
        <td class="num">
          <?= $this->e($this->money($row->paid, $currency)) ?>
          <?php if (!$inTotal): ?>
          <span class="adv-note"><?= $this->te('advances.excluded_from_total') ?></span>
          <?php endif; ?>
        </td>
        <?php if ($row->isComparable()): ?>
        <td class="num"><?= $this->e($this->money((float) $row->cost, $currency)) ?></td>
        <?php if ($inTotal): ?>
        <td class="num <?= (float) $row->balance() >= 0.0 ? 'adv-balance-credit' : 'adv-balance-debit' ?>">
          <?= $this->e($this->money((float) $row->balance(), $currency)) ?>
          <?php if ($row->partialData): ?>
          <span class="adv-note" title="<?= $this->e($this->t('advances.partial_warning')) ?>"><?= $this->te('advances.partial_flag') ?></span>
          <?php endif; ?>
        </td>
        <?php else: ?>
        <?php // Consommation chiffrée, mais aucun prélèvement à comparer : pas de solde
              // à établir. Le motif est distingué (#254) — barème absent, ou barème
              // bien présent dont aucune échéance ne tombe dans la fenêtre — sinon
              // l'écran réclame un acompte que l'utilisateur a déjà saisi. ?>
        <td class="adv-gap">
          <?= $row->hasScheduleWithoutInstalment()
              ? $this->te('advances.no_instalment_in_period')
              : $this->te('advances.no_advance_for_energy') ?>
        </td>
        <?php endif; ?>
        <?php else: ?>
        <?php // La raison technique est AFFICHÉE : sans elle, « non calculable » est un
              // mur muet, impossible à diagnostiquer depuis l'écran (prix de marché
              // manquants, grille absente, aucun relevé sur la fenêtre…). ?>
        <td colspan="2" class="adv-gap">
          <?= $this->te('advances.unavailable') ?>
          <?php if ($row->unavailable !== null && $row->unavailable !== ''): ?>
          <span class="adv-note"><?= $this->e($row->unavailable) ?></span>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2"><?= ($balance['has_gaps'] || $balance['has_cost_without_advance'] || $balance['has_advance_without_instalment'] || $balance['has_short_advance_without_instalment']) ? $this->te('advances.col_total_partial') : $this->te('advances.col_total') ?></td>
        <td class="num"><?= $this->e($this->money($balance['total_paid'], $currency)) ?></td>
        <td class="num"><?= $this->e($this->money($balance['total_cost'], $currency)) ?></td>
        <td class="num <?= $balance['total_balance'] >= 0.0 ? 'adv-balance-credit' : 'adv-balance-debit' ?>">
          <?= $this->e($this->money($balance['total_balance'], $currency)) ?>
        </td>
      </tr>
    </tfoot>
  </table>
</div>

<div class="adv-summary">
  <div class="adv-summary-item">
    <span class="adv-summary-label"><?= $this->te('advances.col_paid') ?></span>
    <span class="adv-summary-value"><?= $this->e($this->money($balance['total_paid'], $currency)) ?></span>
  </div>
  <div class="adv-summary-item">
    <span class="adv-summary-label"><?= $this->te('advances.col_cost') ?></span>
    <span class="adv-summary-value"><?= $this->e($this->money($balance['total_cost'], $currency)) ?></span>
  </div>
  <div class="adv-summary-item">
    <span class="adv-summary-label"><?= $this->te('advances.col_balance') ?></span>
    <span class="adv-summary-value <?= $balance['total_balance'] >= 0.0 ? 'adv-balance-credit' : 'adv-balance-debit' ?>">
      <?= $this->e($this->money($balance['total_balance'], $currency)) ?>
    </span>
  </div>
  <?php // Deux impasses distinctes derrière l'absence de solde (#254) : rien n'a pu
        // être chiffré, ou bien des coûts existent mais aucun n'a d'acompte en face.
        // Le second verdict ne peut pas dire « aucune consommation chiffrée » sans
        // contredire le montant affiché juste au-dessus. ?>
  <?php $hasComparable = false; $hasCosted = false;
        foreach ($balance['balances'] as $row) {
            $hasComparable = $hasComparable || ($row->isComparable() && $row->dueCount > 0);
            $hasCosted     = $hasCosted || $row->isComparable();
        } ?>
  <p class="adv-verdict">
    <?php if (!$hasComparable && $hasCosted): ?>
    <?= $this->te('advances.verdict_not_comparable') ?>
    <?php elseif (!$hasComparable): ?>
    <?= $this->te('advances.verdict_unavailable') ?>
    <?php elseif ($balance['total_balance'] >= 0.0): ?>
    <?= $this->te('advances.verdict_refund', ['amount' => $this->money($balance['total_balance'], $currency)]) ?>
    <?php else: ?>
    <?= $this->te('advances.verdict_due', ['amount' => $this->money(abs($balance['total_balance']), $currency)]) ?>
    <?php endif; ?>
  </p>
  <?php if ($balance['has_gaps']): ?>
  <p class="adv-warn"><?= $this->te('advances.gaps_warning') ?></p>
  <?php endif; ?>
  <?php if ($balance['has_partial_data']): ?>
  <p class="adv-warn"><?= $this->te('advances.partial_warning') ?></p>
  <?php endif; ?>
  <?php if ($balance['has_cost_without_advance']): ?>
  <p class="adv-warn"><?= $this->te('advances.cost_without_advance_warning') ?></p>
  <?php endif; ?>
  <?php // Deux conseils distincts (#254) : élargir la fenêtre n'aide que si le
        // barème la couvre déjà entièrement. Sinon c'est sa plage de validité qui
        // borne l'intersection, et l'élargissement ne produira aucune échéance. ?>
  <?php if ($balance['has_advance_without_instalment']): ?>
  <p class="adv-warn"><?= $this->te('advances.no_instalment_warning') ?></p>
  <?php endif; ?>
  <?php if ($balance['has_short_advance_without_instalment']): ?>
  <p class="adv-warn"><?= $this->te('advances.short_advance_no_instalment_warning') ?></p>
  <?php endif; ?>
  <?php if ($balance['has_partial_advances']): ?>
  <p class="adv-warn"><?= $this->te('advances.partial_advances_warning') ?></p>
  <?php endif; ?>
  <?php if ($futureClamped): ?>
  <p class="adv-warn"><?= $this->te('advances.future_clamped') ?></p>
  <?php endif; ?>
  <?php if ($balance['mixed_currency']): ?>
  <p class="adv-warn"><?= $this->te('advances.mixed_currency_warning') ?></p>
  <?php endif; ?>
</div>

<?php endif; ?>

<!-- ── Saisie d'un barème ────────────────────────────────────────────────── -->
<div class="section-header">
  <span class="section-title"><?= $editing !== null ? $this->te('advances.edit_title') : $this->te('advances.add_title') ?></span>
  <span class="section-line"></span>
</div>

<div class="form-card">
  <form method="post">
    <input type="hidden" name="action" value="save">
    <?php if ($editing !== null): ?>
    <input type="hidden" name="schedule_id" value="<?= $this->e((string) $editing->id) ?>">
    <?php endif; ?>
    <?= \App\Security\Csrf::field() ?>

    <div class="form-grid">
      <div class="form-row">
        <label class="form-label" for="adv-energy"><?= $this->te('advances.energy_type') ?></label>
        <select id="adv-energy" name="energy_type" class="form-input" required>
          <?php foreach ($energyTypes as $type): ?>
          <option value="<?= $this->e($type) ?>"<?= $editing !== null && $editing->energyType === $type ? ' selected' : '' ?>><?= $this->te('advances.energy.' . $type) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label" for="adv-amount"><?= $this->te('advances.amount_monthly') ?></label>
        <input type="number" id="adv-amount" name="amount_monthly" class="form-input" step="0.01" min="0.01"
               max="<?= $this->e($maxAmount) ?>" required placeholder="0.00"
               value="<?= $editing !== null ? $this->e(number_format($editing->amountMonthly, 2, '.', '')) : '' ?>">
      </div>
      <div class="form-row">
        <!-- `type=date` : le navigateur rend un sélecteur déjà localisé et poste un
             'YYYY-MM-DD' non ambigu. Le format est revalidé côté serveur. -->
        <label class="form-label" for="adv-valid-from"><?= $this->te('advances.valid_from') ?></label>
        <input type="date" id="adv-valid-from" name="valid_from" class="form-input" required
               value="<?= $editing !== null ? $this->e($editing->validFrom->format('Y-m-d')) : '' ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="adv-valid-to"><?= $this->te('advances.valid_to') ?> <span class="unit"><?= $this->te('common.optional') ?> · <?= $this->te('common.end_exclusive') ?></span></label>
        <input type="date" id="adv-valid-to" name="valid_to" class="form-input"
               value="<?= $editing?->validTo !== null ? $this->e($editing->validTo->format('Y-m-d')) : '' ?>">
      </div>
      <div class="form-row full">
        <p class="dates-hint"><?= $this->te('advances.validity_hint') ?></p>
      </div>
      <div class="form-row">
        <label class="form-label" for="adv-due-day"><?= $this->te('advances.due_day') ?></label>
        <input type="number" id="adv-due-day" name="due_day" class="form-input" min="1" max="31" required
               value="<?= $this->e((string) ($editing->dueDay ?? 1)) ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="adv-note"><?= $this->te('advances.note') ?> <span class="unit"><?= $this->te('common.optional') ?></span></label>
        <input type="text" id="adv-note" name="note" class="form-input" maxlength="255"
               placeholder="<?= $this->e($this->t('advances.note_placeholder')) ?>"
               value="<?= $this->e($editing->note ?? '') ?>">
      </div>
      <div class="form-row full">
        <p class="dates-hint"><?= $this->te('advances.form_hint') ?></p>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-amber"><?= $this->te('advances.save') ?></button>
      <?php if ($editing !== null): ?>
      <a class="btn btn-ghost" href="<?= $this->e($this->url('advances')) ?>?from=<?= rawurlencode($periodFrom) ?>&amp;to=<?= rawurlencode($periodTo) ?>"><?= $this->te('common.cancel') ?></a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- ── Barèmes enregistrés ───────────────────────────────────────────────── -->
<div class="section-header">
  <span class="section-title"><?= $this->te('advances.list_title') ?></span>
  <span class="section-line"></span>
</div>

<?php if ($schedules === []): ?>
<p class="adv-empty-hint"><?= $this->te('advances.no_schedule') ?></p>
<?php else: ?>
<div class="adv-table-wrap">
  <table class="adv-table">
    <thead>
      <tr>
        <th><?= $this->te('advances.col_energy') ?></th>
        <th class="num"><?= $this->te('advances.amount_monthly') ?></th>
        <th><?= $this->te('advances.col_validity') ?> <span class="unit"><?= $this->te('common.end_exclusive') ?></span></th>
        <th class="num"><?= $this->te('advances.due_day') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($schedules as $schedule): ?>
      <?php
        // #4 : le barème qui participe au bilan affiché porte un liseré ET un badge
        // — la couleur seule ne dirait rien à qui ne la perçoit pas (#252). Il
        // suffit d'un jour commun avec la période : un barème qui n'en couvre
        // qu'une partie compte quand même dans le montant payé. Les barèmes déjà
        // clos AVANT le début de la période passent en retrait pour que les
        // pertinents ressortent dans un historique long ; ceux entièrement
        // postérieurs restent neutres — ils ne sont pas échus, ils n'ont pas
        // commencé. Le fond ambré de `is-editing` n'entre pas en concurrence : le
        // liseré est un box-shadow, pas un background.
        $isCurrent = $schedule->overlaps($highlightFrom, $highlightTo);
        $isExpired = $schedule->isExpiredOn($highlightFrom);
        $rowClass  = array_filter([
            $editing !== null && $editing->id === $schedule->id ? 'is-editing' : '',
            $isCurrent ? 'is-current' : '',
            $isExpired ? 'is-expired' : '',
        ]);
      ?>
      <tr class="<?= $this->e(implode(' ', $rowClass)) ?>">
        <td>
          <?= $this->te('advances.energy.' . $schedule->energyType) ?>
          <?php if ($schedule->note !== ''): ?>
          <span class="adv-note"><?= $this->e($schedule->note) ?></span>
          <?php endif; ?>
        </td>
        <td class="num"><?= $this->e($this->money($schedule->amountMonthly, $currency)) ?></td>
        <td>
          <?= $this->e($schedule->validityLabel()) ?>
          <?php if ($isCurrent): ?>
          <span class="adv-badge"><?= $this->te('advances.active_in_period') ?></span>
          <?php endif; ?>
        </td>
        <td class="num"><?= $this->e((string) $schedule->dueDay) ?></td>
        <td class="actions">
          <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('advances')) ?>?edit=<?= $this->e((string) $schedule->id) ?>&amp;from=<?= rawurlencode($periodFrom) ?>&amp;to=<?= rawurlencode($periodTo) ?>#adv-energy"><?= $this->te('advances.edit') ?></a>
          <form method="post" data-confirm="<?= $this->e($this->t('advances.delete_confirm')) ?>" data-confirm-ok="<?= $this->e($this->t('advances.delete')) ?>" data-confirm-danger>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="schedule_id" value="<?= $this->e((string) $schedule->id) ?>">
            <?= \App\Security\Csrf::field() ?>
            <button type="submit" class="btn btn-red btn-sm"><?= $this->te('advances.delete') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="page-footer">
  <span><?= $this->appName() ?> — <?= $this->te('advances.title') ?></span>
</div>

</div>

<script defer src="<?= \App\Support\Assets::url('assets/js/header.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/confirm.js') ?>"></script>
</body>
</html>
