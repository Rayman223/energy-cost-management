<?php

use App\Domain\SpotFormula;
use App\Domain\SpotFormulaFit;
use App\Service\BillReconciliationService;

/**
 * Rapprochement facture du tarif dynamique (#229) : saisie des montants énergie réellement
 * facturés, écart avec le coût calculé, et couple (coefficient, offset) qui l'annule.
 *
 * @var string|null          $error
 * @var string|null          $success
 * @var bool                 $isAdmin
 * @var bool                 $isDynamic       Tarif dynamique actif — sinon la page n'a pas d'objet
 * @var list<array{id:int,year:int,month:int,period:string,billed_ttc:?float,computed_ttc:?float,gap:?float,covered_kwh:float,avg_indexed_price:?float,note:string,skipped:?string}> $rows
 * @var int                  $page            Page courante (1-indexée), déjà bornée
 * @var int                  $pages           Nombre total de pages
 * @var int                  $total           Nombre total de factures saisies
 * @var int                  $pageSize        Factures par page
 * @var SpotFormulaFit|null  $fit
 * @var SpotFormula|null     $current         Formule actuellement appliquée par la grille
 * @var string               $currency
 * @var float                $coefficientMax  Borne haute acceptée par /tariffs
 * @var float                $maxAmount       Plafond de saisie d'un montant (capacité de la colonne)
 * @var string               $defaultPeriod   'YYYY-MM' pré-rempli dans le formulaire
 * @var list<string>         $available
 * @var ?string              $timezone
 * @var ?string              $discordUrl
 * @var ?string              $adsenseClient
 */

/** Décimal affiché sans zéros de queue, comme les montants de /tariffs. */
$trim = static fn (float $value, int $decimals): string
    => rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');

// Symbole de la devise de la grille : « € » n'est jamais écrit en dur (cf. dash.*).
$cur = $this->currencySymbol($currency);
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>" data-confirm-title="<?= $this->e($this->t('common.confirm_title')) ?>" data-confirm-ok="<?= $this->e($this->t('common.confirm')) ?>" data-confirm-cancel="<?= $this->e($this->t('common.cancel')) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $this->t('reconciliation.title') . ' — ' . $this->t('app.title'),
    // tariffs.css d'abord : le rapprochement reprend la grammaire de formulaire de la
    // page tarifs (.form-card, .section-header, .btn, .alert) plutôt que de la dupliquer.
    // reconciliation.css n'ajoute que le tableau d'écarts et le bloc de formule déduite.
    'css'   => ['assets/css/app-header.css', 'assets/css/lang-switcher.css', 'assets/css/confirm.css', 'assets/css/tariffs.css', 'assets/css/reconciliation.css'],
    'adsenseClient' => $adsenseClient ?? null,
]) ?>
</head>
<body>
<div class="wrap">

<?= $this->partial('_header', [
    'subtitle'   => $this->t('reconciliation.subtitle'),
    'current'    => 'reconciliation',
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

<?php if (!$isDynamic): ?>
<!-- ── Tarif fixe : la page n'a pas d'objet ──────────────────────────────── -->
<div class="rec-empty">
  <p class="rec-empty-title"><?= $this->te('reconciliation.dynamic_required') ?></p>
  <p class="rec-empty-hint"><?= $this->te('reconciliation.dynamic_required_hint') ?></p>
  <a class="btn btn-ghost" href="<?= $this->e($this->url('account')) ?>"><?= $this->te('reconciliation.go_to_account') ?></a>
</div>
<?php else: ?>

<p class="rec-intro"><?= $this->te('reconciliation.intro') ?></p>

<!-- ── Saisie d'une facture ──────────────────────────────────────────────── -->
<div class="section-header">
  <span class="section-title"><?= $this->te('reconciliation.add_title') ?></span>
  <span class="section-line"></span>
</div>

<div class="form-card">
  <form method="post">
    <input type="hidden" name="action" value="save">
    <?= \App\Security\Csrf::field() ?>

    <div class="form-grid">
      <div class="form-row">
        <!-- `type=month` : le navigateur rend un sélecteur déjà localisé, et poste un
             'YYYY-MM' non ambigu — inutile de traduire douze noms de mois. -->
        <label class="form-label" for="rec-period"><?= $this->te('reconciliation.period') ?></label>
        <input type="month" id="rec-period" name="period" class="form-input" required
               min="2000-01" max="2100-12" value="<?= $this->e($defaultPeriod) ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="rec-htva"><?= $this->te('reconciliation.amount_htva') ?> <span class="unit"><?= $this->te('common.optional') ?></span></label>
        <input type="number" id="rec-htva" name="amount_htva" class="form-input" step="0.01" min="0" max="<?= $this->e($maxAmount) ?>" placeholder="0.00">
      </div>
      <div class="form-row">
        <label class="form-label" for="rec-ttc"><?= $this->te('reconciliation.amount_ttc') ?> <span class="unit"><?= $this->te('common.optional') ?></span></label>
        <input type="number" id="rec-ttc" name="amount_ttc" class="form-input" step="0.01" min="0" max="<?= $this->e($maxAmount) ?>" placeholder="0.00">
      </div>
      <div class="form-row full">
        <label class="form-label" for="rec-note"><?= $this->te('reconciliation.note') ?> <span class="unit"><?= $this->te('common.optional') ?></span></label>
        <input type="text" id="rec-note" name="note" class="form-input" maxlength="255"
               placeholder="<?= $this->e($this->t('reconciliation.note_placeholder')) ?>">
      </div>
      <div class="form-row full">
        <p class="dates-hint"><?= $this->te('reconciliation.amount_hint') ?></p>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-amber"><?= $this->te('reconciliation.save') ?></button>
    </div>
  </form>
</div>

<!-- ── Écarts mois par mois ──────────────────────────────────────────────── -->
<div class="section-header">
  <span class="section-title"><?= $this->te('reconciliation.gaps_title') ?></span>
  <span class="section-line"></span>
</div>

<?php if ($rows === []): ?>
<p class="rec-empty-hint"><?= $this->te('reconciliation.no_bill') ?></p>
<?php else: ?>
<div class="rec-table-wrap">
  <table class="rec-table">
    <thead>
      <tr>
        <th><?= $this->te('reconciliation.col_period') ?></th>
        <th class="num"><?= $this->te('reconciliation.col_billed') ?></th>
        <th class="num"><?= $this->te('reconciliation.col_computed') ?></th>
        <th class="num"><?= $this->te('reconciliation.col_gap') ?></th>
        <th class="num"><?= $this->te('reconciliation.col_covered_kwh') ?></th>
        <th class="num"><?= $this->te('reconciliation.col_avg_price') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr class="<?= $row['skipped'] !== null ? 'is-skipped' : '' ?>">
        <td>
          <?= $this->e($row['period']) ?>
          <?php if ($row['note'] !== ''): ?>
          <span class="rec-note"><?= $this->e($row['note']) ?></span>
          <?php endif; ?>
        </td>
        <?php if ($row['skipped'] !== null): ?>
        <td colspan="5" class="rec-skip"><?= $this->te('reconciliation.skipped.' . $row['skipped']) ?></td>
        <?php else: ?>
        <td class="num"><?= $this->e($this->money((float) $row['billed_ttc'], $currency)) ?></td>
        <td class="num"><?= $this->e($this->money((float) $row['computed_ttc'], $currency)) ?></td>
        <td class="num <?= (float) $row['gap'] >= 0.0 ? 'gap-over' : 'gap-under' ?>">
          <?= $this->e($this->money((float) $row['gap'], $currency)) ?>
        </td>
        <td class="num"><?= $this->e($this->num($row['covered_kwh'], 1)) ?></td>
        <td class="num"><?= $row['avg_indexed_price'] !== null ? $this->e($trim($row['avg_indexed_price'], 5)) : '—' ?></td>
        <?php endif; ?>
        <td class="actions">
          <form method="post" data-confirm="<?= $this->e($this->t('reconciliation.delete_confirm')) ?>" data-confirm-ok="<?= $this->e($this->t('reconciliation.delete')) ?>" data-confirm-danger>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="bill_id" value="<?= $this->e((string) $row['id']) ?>">
            <?= \App\Security\Csrf::field() ?>
            <button type="submit" class="btn btn-red btn-sm"><?= $this->te('reconciliation.delete') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
<!-- Pagination : la résolution ne porte que sur la page affichée, d'où le rappel. -->
<nav class="rec-pager" aria-label="<?= $this->e($this->t('reconciliation.pager_label')) ?>">
  <?php if ($page > 1): ?>
  <a class="btn btn-ghost btn-sm" href="?page=<?= $page - 1 ?>"><?= $this->te('reconciliation.pager_newer') ?></a>
  <?php endif; ?>
  <span class="rec-pager-state"><?= $this->te('reconciliation.pager_state', ['page' => (string) $page, 'pages' => (string) $pages, 'total' => (string) $total]) ?></span>
  <?php if ($page < $pages): ?>
  <a class="btn btn-ghost btn-sm" href="?page=<?= $page + 1 ?>"><?= $this->te('reconciliation.pager_older') ?></a>
  <?php endif; ?>
</nav>
<p class="rec-pager-hint"><?= $this->te('reconciliation.pager_hint', ['size' => (string) $pageSize]) ?></p>
<?php endif; ?>
<?php endif; ?>

<!-- ── Formule déduite ───────────────────────────────────────────────────── -->
<?php if ($fit !== null && $fit->mode !== SpotFormulaFit::MODE_UNDETERMINED): ?>
<div class="section-header">
  <span class="section-title"><?= $this->te('reconciliation.fit_title') ?></span>
  <span class="section-line"></span>
</div>

<div class="rec-fit">
  <p class="rec-fit-mode rec-fit-mode--<?= $this->e($fit->mode) ?>">
    <?= $this->te('reconciliation.mode.' . $fit->mode) ?>
  </p>

  <?php if ($fit->isDetermined()): ?>
  <div class="rec-fit-values">
    <div class="rec-fit-value">
      <span class="rec-fit-label"><?= $this->te('reconciliation.fit_coefficient') ?></span>
      <span class="rec-fit-number"><?= $this->e($trim((float) $fit->coefficient, 4)) ?></span>
    </div>
    <div class="rec-fit-value">
      <span class="rec-fit-label"><?= $this->te('reconciliation.fit_offset') ?></span>
      <span class="rec-fit-number"><?= $this->e($trim((float) $fit->offsetTtc, 5)) ?> <span class="unit"><?= $this->e($cur) ?>/kWh</span></span>
    </div>
  </div>

  <?php if ($fit->coefficientOutOfBounds): ?>
  <p class="rec-warn"><?= $this->te('reconciliation.coefficient_out_of_bounds', ['max' => $trim($coefficientMax, 2)]) ?></p>
  <?php endif; ?>

  <?php if ($fit->residualTtc !== null && $fit->residualTtc >= 1.0): ?>
  <p class="rec-warn"><?= $this->te('reconciliation.residual', ['amount' => $this->money($fit->residualTtc, $currency)]) ?></p>
  <?php endif; ?>
  <?php endif; ?>

  <?php if (!$fit->isDetermined() && ($fit->offsetAtCurrentCoefficient !== null || $fit->coefficientAtCurrentOffset !== null)): ?>
  <p class="rec-alt-intro"><?= $this->te('reconciliation.alternatives_intro') ?></p>
  <ul class="rec-alt">
    <?php if ($fit->offsetAtCurrentCoefficient !== null && $current !== null): ?>
    <li><?= $this->te('reconciliation.alt_offset', [
        'coefficient' => $trim($current->coefficient, 4),
        'offset'      => $trim($fit->offsetAtCurrentCoefficient, 5),
        'currency'    => $cur,
    ]) ?></li>
    <?php endif; ?>
    <?php if ($fit->coefficientAtCurrentOffset !== null && $current !== null): ?>
    <li><?= $this->te('reconciliation.alt_coefficient', [
        'offset'      => $trim($current->offsetTtc, 5),
        'coefficient' => $trim($fit->coefficientAtCurrentOffset, 4),
        'currency'    => $cur,
    ]) ?></li>
    <?php endif; ?>
  </ul>
  <?php endif; ?>

  <?php if ($fit->mode === SpotFormulaFit::MODE_ILL_CONDITIONED): ?>
  <p class="rec-warn"><?= $this->te('reconciliation.ill_conditioned_hint', [
      'spread' => $trim($fit->priceSpreadPct, 1),
      'min'    => $trim(\App\Service\SpotFormulaFitter::PRICE_SPREAD_MIN_PCT, 1),
  ]) ?></p>
  <?php endif; ?>

  <p class="rec-apply-hint">
    <?= $this->te('reconciliation.apply_hint') ?>
    <a href="<?= $this->e($this->url('tariffs')) ?>?energy=electricity"><?= $this->te('nav.tariffs') ?></a>
  </p>
</div>
<?php endif; ?>

<?php endif; /* $isDynamic */ ?>

<div class="page-footer">
  <span><?= $this->appName() ?> — <?= $this->te('reconciliation.title') ?></span>
</div>

</div>

<script defer src="<?= \App\Support\Assets::url('assets/js/header.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/confirm.js') ?>"></script>
</body>
</html>
