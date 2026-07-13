<?php

use App\Domain\ComponentKind;
use App\Domain\TariffGrid;
use App\Domain\TariffLineCatalog;

/**
 * Template de gestion des grilles tarifaires (parcours guidé, multi-énergies).
 *
 * @var string|null                                                          $error
 * @var string|null                                                          $success
 * @var string                                                               $energy      Énergie active (electricity|gas|water)
 * @var list<string>                                                         $energyTypes
 * @var TariffGrid[]                                                         $grids       Grilles de l'énergie active
 * @var TariffGrid|null                                                      $editGrid
 * @var list<array{key:string,kind:string,label:string,amount:string,custom:bool,category:string}> $formFields
 * @var string|null                                                          $formCountry
 * @var string                                                               $formCurrency
 * @var float                                                                $formVat
 * @var array<string,string>                                                 $countries   ISO2 => nom localisé, trié
 * @var list<string>                                                         $currencies
 * @var list<array{code:string,energy_type:string,country:?string,name_key:string,fields:list<array{key:string,kind:ComponentKind,label:string}>}> $builtinTemplates
 * @var list<array{id:int,energy_type:string,country:?string,name:string,fields:list<array{key:string,kind:string,label:?string,sort:int}>}> $userTemplates
 * @var list<string>                                                         $groupOrder
 * @var list<string>                                                         $relevantCategories  catégories affichées même vides pour l'énergie active
 * @var array<string,string>                                                 $categoryOptions  value catégorie => libellé
 * @var array<string,string>                                                 $kindOptions
 * @var string                                                               $today
 * @var bool                                                                 $isAdmin
 * @var bool                                                                 $isDynamic  Tarif dynamique actif → lignes d'énergie fournisseur ignorées
 */

// Regroupement des lignes du formulaire par catégorie choisie (défaut : dérivée du kind).
$grouped = [];
foreach ($formFields as $i => $f) {
    $group = $f['category'] ?? ComponentKind::fromStringOrDefault($f['kind'])->group();
    $grouped[$group][] = ['i' => $i] + $f;
}
$nextIndex = count($formFields);

// Devise éditée hors référentiel → on l'ajoute en option.
$currencyOptions = $currencies;
if (!in_array($formCurrency, $currencyOptions, true)) {
    array_unshift($currencyOptions, $formCurrency);
}

$energyIcons  = ['electricity' => '⚡', 'gas' => '🔥', 'water' => '💧'];
$energyLabels = [
    'electricity' => $this->t('tariffs.energy_electricity'),
    'gas'         => $this->t('tariffs.energy_gas'),
    'water'       => $this->t('tariffs.energy_water'),
];
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>" data-confirm-title="<?= $this->e($this->t('common.confirm_title')) ?>" data-confirm-ok="<?= $this->e($this->t('common.confirm')) ?>" data-confirm-cancel="<?= $this->e($this->t('common.cancel')) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $this->t('tariffs.title') . ' — ' . $this->t('app.title'),
    'css'   => ['assets/css/confirm.css', 'assets/css/tariffs.css'],
]) ?>
</head>
<body>
<div class="wrap">

<header>
  <div class="logo">
    <div class="logo-icon">⚡</div>
    <div>
      <div class="logo-text">Manage Energy</div>
      <div class="logo-sub"><?= $this->te('tariffs.subtitle') ?></div>
    </div>
  </div>
  <div class="header-right">
    <a href="<?= $this->url() ?>" class="back"><?= $this->te('nav.back_dashboard') ?></a>
    <?php if (!empty($oidcEnabled)): ?><form method="post" action="<?= $this->url('auth/logout') ?>" class="logout-form"><?= \App\Security\Csrf::field() ?><button type="submit" class="theme-toggle" title="<?= $this->te('auth.sign_out') ?>">🚪</button></form><?php endif; ?>
    <button type="button" class="theme-toggle" id="theme-toggle" aria-label="<?= $this->e($this->t('common.theme')) ?>">🌙</button>
  </div>
</header>

<?php if ($success): ?>
<div class="alert alert-ok alert--push">✓ <?= $this->e($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-err alert--push">✗ <?= $this->e($error) ?></div>
<?php endif; ?>

<!-- ── Onglets énergie (liens : état côté serveur) ───────────────────────── -->
<div class="energy-tabs">
  <?php foreach ($energyTypes as $et): ?>
  <a href="?energy=<?= $this->e($et) ?>" class="energy-tab <?= $energy === $et ? 'active' : '' ?>">
    <span class="energy-tab-icon"><?= $energyIcons[$et] ?></span>
    <?= $this->e($energyLabels[$et]) ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- ── Grilles existantes ────────────────────────────────────────────────── -->
<div class="section-header">
  <span class="section-title"><?= $this->te('tariffs.existing') ?></span>
  <span class="section-line"></span>
</div>

<div class="grids-wrap">
<?php if (empty($grids)): ?>
  <div class="empty"><?= $this->te('tariffs.none') ?></div>
<?php else: foreach ($grids as $g):
  $active = $g->isActiveOn(new \DateTimeImmutable('today'));
  $rowId  = 'lines-' . $g->id;
  $catalog = TariffLineCatalog::forType($g->energyType);
?>
  <div class="grid-row">
    <div>
      <div class="grid-name"><?= $this->e($g->name) ?></div>
      <div class="grid-dates">
        <?= $g->validFrom->format('d/m/Y') ?> →
        <?= $g->validTo ? $g->validTo->format('d/m/Y') : '∞' ?>
        <span class="grid-meta">· <?= $this->e($g->currency) ?> · TVA <?= $this->e(rtrim(rtrim(number_format($g->vatRate, 2, '.', ''), '0'), '.')) ?>%<?= $g->country ? ' · ' . $this->e($g->country) : '' ?></span>
        <?php if ($g->pcsCoefficient ?? null): ?>
          <span class="grid-meta grid-meta--blue">· PCS <?= number_format($g->pcsCoefficient, 4) ?> kWh/m³</span>
        <?php endif; ?>
      </div>
    </div>
    <span class="grid-active <?= $active ? 'yes' : 'no' ?>"><?= $active ? $this->te('common.active') : $this->te('common.inactive') ?></span>
    <?php if ($g->isShared()): ?><span class="grid-active yes" title="<?= $this->e($this->t('tariffs.shared_grid')) ?>"><?= $this->te('tariffs.shared') ?></span><?php endif; ?>
    <button class="btn btn-ghost btn-sm" data-toggle-lines="<?= $this->e($rowId) ?>"><?= $this->te('tariffs.detail') ?></button>
    <div class="grid-actions">
      <a href="?edit=<?= $g->id ?>#form" class="btn btn-ghost btn-sm"><?= $this->te('tariffs.edit') ?></a>
      <form method="post" data-confirm="<?= $this->e($this->t('tariffs.delete_confirm')) ?>" data-confirm-ok="<?= $this->e($this->t('tariffs.delete')) ?>" data-confirm-danger>
        <input type="hidden" name="action"  value="delete">
        <input type="hidden" name="grid_id" value="<?= $g->id ?>">
        <?= \App\Security\Csrf::field() ?>
        <button type="submit" class="btn btn-red btn-sm"><?= $this->te('tariffs.delete') ?></button>
      </form>
    </div>
  </div>
  <div class="lines-detail" id="<?= $rowId ?>">
    <table class="lines-table">
      <?php foreach ($g->lines as $key => $line):
        $kind  = $line->kind;
        $lbl   = $line->label ?? ($catalog[$key]['label'] ?? $key);
      ?>
      <tr>
        <td><?= $this->e($lbl) ?></td>
        <td><?= number_format($line->amount, 7) ?> <?= $this->e($kind->unit($g->energyType)) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endforeach; endif; ?>
</div>

<!-- ── Point de départ (import de template) ──────────────────────────────── -->
<?php if (!$editGrid): ?>
<div class="section-header">
  <span class="section-title"><?= $this->te('tariffs.start_title') ?></span>
  <span class="section-line"></span>
</div>
<div class="start-panel">
  <p class="start-hint"><?= $this->te('tariffs.start_hint') ?></p>
  <form method="get" class="start-form">
    <input type="hidden" name="energy" value="<?= $this->e($energy) ?>">
    <div class="form-row">
      <label class="form-label"><?= $this->te('tariffs.country') ?></label>
      <select name="country" class="form-select" data-start-country>
        <option value=""><?= $this->te('tariffs.country_none') ?></option>
        <?php foreach ($countries as $iso => $cname): ?>
        <option value="<?= $this->e($iso) ?>" <?= $formCountry === $iso ? 'selected' : '' ?>><?= $this->e($cname) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label class="form-label"><?= $this->te('tariffs.template') ?></label>
      <select name="template" class="form-select" data-start-template>
        <optgroup label="<?= $this->e($this->t('tariffs.template_builtin')) ?>">
          <?php foreach ($builtinTemplates as $tpl): ?>
          <option value="builtin:<?= $this->e($tpl['code']) ?>" data-country="<?= $this->e((string) $tpl['country']) ?>"><?= $this->te($tpl['name_key']) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <?php $ownTemplates = array_filter($userTemplates, static fn (array $t): bool => !empty($t['is_owner'])); ?>
        <?php $publicTemplates = array_filter($userTemplates, static fn (array $t): bool => empty($t['is_owner'])); ?>
        <?php if (!empty($ownTemplates)): ?>
        <optgroup label="<?= $this->e($this->t('tariffs.my_templates')) ?>">
          <?php foreach ($ownTemplates as $tpl): ?>
          <option value="user:<?= $tpl['id'] ?>" data-country="<?= $this->e((string) $tpl['country']) ?>"><?= $this->e($tpl['name']) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <?php endif; ?>
        <?php if (!empty($publicTemplates)): ?>
        <optgroup label="<?= $this->e($this->t('tariffs.public_templates')) ?>">
          <?php foreach ($publicTemplates as $tpl): ?>
          <option value="user:<?= $tpl['id'] ?>" data-country="<?= $this->e((string) $tpl['country']) ?>"><?= $this->e($tpl['name']) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <?php endif; ?>
      </select>
    </div>
    <div class="form-row start-actions">
      <button type="submit" class="btn btn-ghost"><?= $this->te('tariffs.import_template') ?></button>
    </div>
  </form>
  <div class="tpl-chips">
    <?php
    /** Badge « nb d'utilisateurs distincts » pour un template. */
    $usageBadge = function (string $ref) use ($usageCounts): string {
        $n = $usageCounts[$ref] ?? 0;
        return '<span class="tpl-count" title="' . $this->e($this->t('tariffs.template_uses', ['count' => (string) $n]))
            . '">' . $this->e((string) $n) . '</span>';
    };
    ?>
    <?php foreach ($builtinTemplates as $tpl): ?>
    <span class="tpl-chip">
      <?= $this->te($tpl['name_key']) ?>
      <?= $usageBadge('builtin:' . $tpl['code']) ?>
    </span>
    <?php endforeach; ?>
    <?php foreach ($userTemplates as $tpl): ?>
    <span class="tpl-chip">
      <?= $this->e($tpl['name']) ?>
      <?php if (($tpl['visibility'] ?? 'private') === 'public'): ?><span class="tpl-tag"><?= $this->te('tariffs.template_public') ?></span><?php endif; ?>
      <?= $usageBadge('user:' . $tpl['id']) ?>
      <?php if (!empty($tpl['is_owner'])): ?>
      <form method="post" class="form-inline" data-confirm="<?= $this->e($this->t('tariffs.template_delete_confirm')) ?>" data-confirm-ok="<?= $this->e($this->t('tariffs.delete')) ?>" data-confirm-danger>
        <input type="hidden" name="action" value="template_delete">
        <input type="hidden" name="template_id" value="<?= $tpl['id'] ?>">
        <?= \App\Security\Csrf::field() ?>
        <button type="submit" class="tpl-chip-x" aria-label="<?= $this->e($this->t('tariffs.delete')) ?>">×</button>
      </form>
      <?php endif; ?>
    </span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Formulaire de grille ──────────────────────────────────────────────── -->
<div class="section-header" id="form">
  <span class="section-title"><?= $editGrid ? $this->te('tariffs.editing') : $this->te('tariffs.new') ?></span>
  <span class="section-line"></span>
  <?php if ($editGrid): ?>
  <a href="?energy=<?= $this->e($energy) ?>" class="back"><?= $this->te('common.cancel') ?></a>
  <?php endif; ?>
</div>

<div class="form-card">
  <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="edit_id" value="<?= $this->e((string) ($editGrid?->id ?? '')) ?>">
    <input type="hidden" name="energy_type" value="<?= $this->e($energy) ?>">
    <input type="hidden" name="source_template" value="<?= $this->e($sourceTemplate) ?>">
    <?= \App\Security\Csrf::field() ?>

    <div class="form-grid">
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.name') ?></label>
        <input type="text" name="name" class="form-input" required placeholder="ex. Engie bihoraire fév. 2026"
               value="<?= $this->e($editGrid?->name ?? '') ?>">
      </div>
      <?php if ($energy === 'gas'): ?>
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.pcs') ?> <span class="unit"><?= $this->te('tariffs.pcs_unit') ?></span></label>
        <input type="number" name="pcs_coefficient" step="0.0001" class="form-input" placeholder="10.5500"
               value="<?= $this->e($editGrid?->pcsCoefficient !== null ? number_format($editGrid->pcsCoefficient, 4, '.', '') : '') ?>">
      </div>
      <?php endif; ?>
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.valid_from') ?></label>
        <input type="date" name="valid_from" class="form-input" required
               value="<?= $this->e($editGrid?->validFrom->format('Y-m-d') ?? $today) ?>">
      </div>
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.valid_to') ?> <span class="unit"><?= $this->te('common.optional') ?></span></label>
        <input type="date" name="valid_to" class="form-input"
               value="<?= $this->e($editGrid?->validTo?->format('Y-m-d') ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.country') ?></label>
        <select name="country" class="form-select" data-country-select>
          <option value=""><?= $this->te('tariffs.country_none') ?></option>
          <?php foreach ($countries as $iso => $cname): ?>
          <option value="<?= $this->e($iso) ?>"
                  data-vat="<?= $this->e((string) (\App\Domain\EuropeanCountries::vatRate($iso) ?? '')) ?>"
                  data-currency="<?= $this->e((string) (\App\Domain\EuropeanCountries::currencyOf($iso) ?? '')) ?>"
                  <?= $formCountry === $iso ? 'selected' : '' ?>><?= $this->e($cname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.currency') ?></label>
        <select name="currency" class="form-select" data-currency-select>
          <?php foreach ($currencyOptions as $cur): ?>
          <option value="<?= $this->e($cur) ?>" <?= $formCurrency === $cur ? 'selected' : '' ?>><?= $this->e($cur) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.vat_rate') ?> <span class="unit">%</span></label>
        <input type="number" name="vat_rate" step="0.01" min="0" max="100" class="form-input" data-vat-input
               value="<?= $this->e(rtrim(rtrim(number_format($formVat, 2, '.', ''), '0'), '.')) ?>">
      </div>
      <?php if ($isAdmin && !$editGrid): ?>
      <div class="form-row">
        <label class="form-label">
          <input type="checkbox" name="shared" value="1">
          <?= $this->te('tariffs.shared_grid') ?> <span class="unit"><?= $this->te('tariffs.shared_grid_hint') ?></span>
        </label>
      </div>
      <?php endif; ?>
    </div>

    <!-- Lignes tarifaires groupées par catégorie (choisie par l'utilisateur) -->
    <div class="lines-wrap">
      <?php foreach ($groupOrder as $group): ?>
        <?php // Catégorie vide et non pertinente pour cette énergie (ex. Injection en gaz/eau) : masquée.
              if (empty($grouped[$group]) && !in_array($group, $relevantCategories, true)) continue; ?>
        <div class="line-group" data-group="<?= $this->e($group) ?>">
          <div class="line-group-title"><?= $this->te('tariffs.group_' . $group) ?></div>
          <div class="form-grid line-group-body">
            <?php foreach (($grouped[$group] ?? []) as $f):
              $i = $f['i'];
              // Tarif dynamique : les lignes d'énergie fournisseur (energy_simple/t1/t2)
              // sont ignorées au calcul (prix ENTSO-E) → grisées + note, mais conservées
              // (valeurs toujours postées) pour rebasculer en fixe sans re-saisie.
              $ignoredInDynamic = $isDynamic && ComponentKind::fromStringOrDefault($f['kind'])->isSupplierEnergy();
            ?>
            <div class="form-row line-item <?= $f['custom'] ? 'is-custom' : '' ?><?= $ignoredInDynamic ? ' is-dynamic-ignored' : '' ?>">
              <input type="hidden" name="lines[<?= $i ?>][key]" value="<?= $this->e($f['key']) ?>">
              <?php if ($f['custom']): ?>
              <label class="form-label">
                <input type="text" name="lines[<?= $i ?>][label]" class="form-input line-label-input" placeholder="<?= $this->e($this->t('tariffs.field_label')) ?>" value="<?= $this->e($f['label']) ?>">
              </label>
              <select name="lines[<?= $i ?>][kind]" class="form-select line-kind-select">
                <?php foreach ($kindOptions as $kv => $klabel): ?>
                <option value="<?= $this->e($kv) ?>" <?= $f['kind'] === $kv ? 'selected' : '' ?>><?= $this->e($klabel) ?></option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <label class="form-label"><?= $this->e($f['label']) ?> <span class="unit"><?= $this->e(ComponentKind::fromStringOrDefault($f['kind'])->unit($energy)) ?></span></label>
              <input type="hidden" name="lines[<?= $i ?>][kind]" value="<?= $this->e($f['kind']) ?>">
              <?php endif; ?>
              <select name="lines[<?= $i ?>][category]" class="form-select line-category-select" aria-label="<?= $this->e($this->t('tariffs.category')) ?>">
                <?php foreach ($categoryOptions as $cv => $clabel): ?>
                <option value="<?= $this->e($cv) ?>" <?= $f['category'] === $cv ? 'selected' : '' ?>><?= $this->e($clabel) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="line-amount-row">
                <input type="number" name="lines[<?= $i ?>][amount]" step="0.0000001" class="form-input" placeholder="0.0000000" value="<?= $this->e($f['amount']) ?>">
                <?php if ($f['custom']): ?><button type="button" class="btn btn-ghost btn-sm line-remove" data-remove-line aria-label="<?= $this->e($this->t('tariffs.remove_field')) ?>">×</button><?php endif; ?>
              </div>
              <?php if ($ignoredInDynamic): ?><p class="dynamic-ignored-hint"><?= $this->te('tariffs.energy_ignored_dynamic') ?></p><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <div class="form-row full add-field-row">
              <button type="button" class="btn btn-ghost btn-sm" data-add-line><?= $this->te('tariffs.add_field') ?></button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Sauvegarde comme template -->
    <div class="save-tpl-row">
      <label class="form-label">
        <input type="checkbox" name="save_as_template" value="1" data-save-tpl-toggle>
        <?= $this->te('tariffs.save_as_template') ?> <span class="unit"><?= $this->te('tariffs.save_as_template_hint') ?></span>
      </label>
      <input type="text" name="template_name" class="form-input save-tpl-name is-hidden" placeholder="<?= $this->e($this->t('tariffs.template_name')) ?>">
      <span class="save-tpl-visibility is-hidden">
        <label class="form-label"><input type="radio" name="template_visibility" value="private" checked> <?= $this->te('tariffs.template_private') ?></label>
        <label class="form-label"><input type="radio" name="template_visibility" value="public"> <?= $this->te('tariffs.template_public') ?></label>
      </span>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-amber">
        <?= $editGrid ? $this->te('tariffs.update') : $this->te('tariffs.save') ?>
      </button>
      <?php if ($editGrid): ?>
      <a href="?energy=<?= $this->e($energy) ?>" class="btn btn-ghost"><?= $this->te('common.cancel') ?></a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- Gabarit d'un champ personnalisé (cloné par tariffs.js, hors CSP inline). -->
<template id="custom-line-row">
  <div class="form-row line-item is-custom">
    <input type="hidden" name="lines[__IDX__][key]" value="">
    <label class="form-label">
      <input type="text" name="lines[__IDX__][label]" class="form-input line-label-input" placeholder="<?= $this->e($this->t('tariffs.field_label')) ?>">
    </label>
    <select name="lines[__IDX__][kind]" class="form-select line-kind-select">
      <?php foreach ($kindOptions as $kv => $klabel): ?>
      <option value="<?= $this->e($kv) ?>"><?= $this->e($klabel) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="lines[__IDX__][category]" class="form-select line-category-select" aria-label="<?= $this->e($this->t('tariffs.category')) ?>">
      <?php foreach ($categoryOptions as $cv => $clabel): ?>
      <option value="<?= $this->e($cv) ?>"><?= $this->e($clabel) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="line-amount-row">
      <input type="number" name="lines[__IDX__][amount]" step="0.0000001" class="form-input" placeholder="0.0000000">
      <button type="button" class="btn btn-ghost btn-sm line-remove" data-remove-line aria-label="<?= $this->e($this->t('tariffs.remove_field')) ?>">×</button>
    </div>
  </div>
</template>

<div class="page-footer">
  <span>Manage Energy — <?= $this->te('tariffs.title') ?></span>
</div>

</div>

<script defer src="<?= \App\Support\Assets::url('assets/js/confirm.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/tariffs.js') ?>" data-next-index="<?= $nextIndex ?>"></script>
</body>
</html>
