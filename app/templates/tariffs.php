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
 * @var TariffGrid|null                                                      $duplicateGrid  Grille reprise comme point de départ (#205)
 * @var list<array{key:string,kind:string,label:string,amount:string,category:string}> $formFields
 * @var string|null                                                          $formCountry
 * @var string                                                               $formCurrency
 * @var float                                                                $formVat
 * @var string                                                               $formName
 * @var string                                                               $formValidFrom
 * @var string|null                                                          $formValidTo
 * @var float|null                                                           $formPcs
 * @var array<string,string>                                                 $countries   ISO2 => nom localisé, trié
 * @var list<string>                                                         $currencies
 * @var list<array{code:string,energy_type:string,country:?string,name_key:string,fields:list<array{key:string,kind:ComponentKind,label:string}>}> $builtinTemplates
 * @var list<array{id:int,energy_type:string,country:?string,name:string,fields:list<array{key:string,kind:string,label:?string,sort:int}>}> $userTemplates
 * @var list<string>                                                         $groupOrder
 * @var list<string>                                                         $relevantCategories  catégories affichées même vides pour l'énergie active
 * @var array<string,string>                                                 $categoryOptions  value catégorie => libellé
 * @var array<string,array<string,string>>                                   $kindOptions  groupe => (value kind => libellé), rendu en <optgroup>

 * @var bool                                                                 $isAdmin
 * @var bool                                                                 $isDynamic  Grille éditée en tarif dynamique → lignes d'énergie fournisseur ignorées
 * @var bool                                                                 $modeEditable  Le mode est-il choisissable (électricité + dynamique activé serveur) ?
 * @var string                                                               $formMode   Mode de la grille éditée (#245)
 * @var array<string,string>                                                 $pricingModeOptions  value mode => libellé
 * @var list<string>                                                         $supplierEnergyKinds  kinds grisés en dynamique
 * @var list<string>                                                         $spotFormulaKinds     kinds grisés en fixe
 * @var ?string                                                              $discordUrl
 * @var ?string                                                              $adsenseClient Identifiant éditeur AdSense (#185)
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
    'css'   => ['assets/css/app-header.css', 'assets/css/lang-switcher.css', 'assets/css/confirm.css', 'assets/css/tariffs.css'],
    'adsenseClient' => $adsenseClient ?? null,
]) ?>
</head>
<body>
<div class="wrap">

<?= $this->partial('_header', [
    'subtitle'    => $this->t('tariffs.subtitle'),
    'current'     => 'tariffs',
    'isAdmin'     => $isAdmin ?? false,
    'discordUrl'  => $discordUrl ?? null,
    'available'   => $available,
    'timezone'    => $timezone ?? null,
]) ?>

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

<p class="start-hint"><?= $this->te('tariffs.shared_hint') ?></p>

<div class="grids-wrap">
<?php if (empty($grids)): ?>
  <div class="empty"><?= $this->te('tariffs.none') ?></div>
<?php else: foreach ($grids as $g):
  $today0  = new \DateTimeImmutable('today');
  $active  = $g->isActiveOn($today0);
  // `valid_to` est le premier jour NON couvert (#1) : la grille est échue dès ce
  // jour-là, pas le lendemain.
  $expired = $g->validTo !== null && $g->validTo <= $today0;
  $rowId   = 'lines-' . $g->id;
  $catalog = TariffLineCatalog::forType($g->energyType);
?>
  <div class="grid-row<?= $active ? ' grid-row--active' : '' ?><?= $expired ? ' grid-row--expired' : '' ?>">
    <div>
      <div class="grid-name"><?= $this->e($g->name) ?></div>
      <div class="grid-dates">
        <?= $g->validFrom->format('d/m/Y') ?> →
        <?= $g->validTo ? $g->validTo->format('d/m/Y') : '∞' ?>
        <span class="grid-meta">· <?= $this->e($g->currency) ?> · TVA <?= $this->e(rtrim(rtrim(number_format($g->vatRate, 2, '.', ''), '0'), '.')) ?>%<?= $g->country ? ' · ' . $this->e($g->country) : '' ?></span>
        <?php if ($g->pcsCoefficient ?? null): ?>
          <span class="grid-meta grid-meta--blue">· PCS <?= number_format($g->pcsCoefficient, 4) ?> kWh/m³</span>
        <?php endif; ?>
        <?php // Mode du contrat (#245) : signalé seulement quand il est dynamique — le
              // tarif fixe est le cas ordinaire, l'annoncer sur chaque ligne n'apprend
              // rien. Voir d'un coup d'œil QUELLES périodes sont indexées est justement
              // ce que l'ancien réglage global rendait impossible. ?>
        <?php if ($g->isDynamic()): ?>
          <span class="grid-meta grid-meta--blue">· <?= $this->e($this->t('tariffs.pricing_mode_' . $g->pricingMode)) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <span class="grid-active <?= $active ? 'yes' : 'no' ?>"><?= $active ? $this->te('common.active') : $this->te('common.inactive') ?></span>
    <?php if ($g->isShared()): ?><span class="grid-active yes" title="<?= $this->e($this->t('tariffs.shared_grid')) ?>"><?= $this->te('tariffs.shared') ?></span><?php endif; ?>
    <button class="btn btn-ghost btn-sm" data-toggle-lines="<?= $this->e($rowId) ?>"><?= $this->te('tariffs.detail') ?></button>
    <div class="grid-actions">
      <!-- #205 : reprise d'une grille (montants inclus) comme point de départ. Seul
           moyen pour un non-admin de réutiliser une grille du catalogue partagé. -->
      <!-- `energy` explicite : le formulaire poste sur cette URL, où la ref de
           duplication est ignorée — l'onglet doit rester celui de la grille. -->
      <a href="?energy=<?= $this->e($energy) ?>&amp;duplicate=<?= $g->id ?>#form" class="btn btn-ghost btn-sm"><?= $this->te('tariffs.duplicate') ?></a>
      <?php
        // Une grille du catalogue partagé n'est modifiable que par un admin
        // (TariffRepository::assertCanModify) : on masque des actions qui
        // échoueraient plutôt que d'afficher un faux droit.
        $canModify = !$g->isShared() || $isAdmin;
      ?>
      <?php if ($canModify): ?>
      <a href="?edit=<?= $g->id ?>#form" class="btn btn-ghost btn-sm"><?= $this->te('tariffs.edit') ?></a>
      <form method="post" data-confirm="<?= $this->e($this->t('tariffs.delete_confirm')) ?>" data-confirm-ok="<?= $this->e($this->t('tariffs.delete')) ?>" data-confirm-danger>
        <input type="hidden" name="action"  value="delete">
        <input type="hidden" name="grid_id" value="<?= $g->id ?>">
        <?= \App\Security\Csrf::field() ?>
        <button type="submit" class="btn btn-red btn-sm"><?= $this->te('tariffs.delete') ?></button>
      </form>
      <?php endif; ?>
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
        <td><?= number_format($line->amount, 7) ?> <?= $this->e($kind->unit($g->energyType, $g->currency)) ?></td>
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
  <span class="section-title"><?php
    if ($editGrid)            { echo $this->te('tariffs.editing'); }
    elseif ($duplicateGrid)   { echo $this->te('tariffs.duplicating'); }
    else                      { echo $this->te('tariffs.new'); }
  ?></span>
  <span class="section-line"></span>
  <?php if ($editGrid || $duplicateGrid): ?>
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
               maxlength="120" value="<?= $this->e($formName) ?>">
      </div>
      <?php if ($energy === 'gas'): ?>
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.pcs') ?> <span class="unit"><?= $this->te('tariffs.pcs_unit') ?></span></label>
        <input type="number" name="pcs_coefficient" step="0.0001" class="form-input" placeholder="10.5500"
               value="<?= $this->e($formPcs !== null ? number_format($formPcs, 4, '.', '') : '') ?>">
      </div>
      <?php endif; ?>
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.valid_from') ?></label>
        <input type="date" name="valid_from" class="form-input" required
               value="<?= $this->e($formValidFrom) ?>">
      </div>
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.valid_to') ?> <span class="unit"><?= $this->te('common.optional') ?> · <?= $this->te('common.end_exclusive') ?></span></label>
        <input type="date" name="valid_to" class="form-input"
               value="<?= $this->e($formValidTo ?? '') ?>">
      </div>
      <div class="form-row full">
        <p class="dates-hint"><?= $this->te('tariffs.dates_hint') ?></p>
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
      <?php if ($modeEditable): ?>
      <?php // Mode du contrat porté par CETTE grille (#245) : il vaut pour sa seule
            // période de validité, ce qui permet de dater une bascule fixe ↔ dynamique
            // sans toucher aux périodes déjà facturées. ?>
      <div class="form-row full">
        <label class="form-label"><?= $this->te('tariffs.pricing_mode') ?></label>
        <select name="pricing_mode" class="form-select" data-pricing-mode>
          <?php foreach ($pricingModeOptions as $mv => $mlabel): ?>
          <option value="<?= $this->e($mv) ?>" <?= $formMode === $mv ? 'selected' : '' ?>><?= $this->e($mlabel) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="dates-hint"><?= $this->te('tariffs.pricing_mode_hint') ?></p>
      </div>
      <?php elseif ($energy === 'electricity'): ?>
      <?php // Tarif dynamique coupé côté serveur : le select est absent (donc non
            // soumis) et la route reconduit le mode enregistré. Dire pourquoi évite de
            // faire croire que le contrat a été perdu. ?>
      <div class="form-row full">
        <label class="form-label"><?= $this->te('tariffs.pricing_mode') ?></label>
        <p class="dates-hint"><?= $this->te('tariffs.pricing_mode_server_disabled') ?></p>
      </div>
      <?php endif; ?>
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
    <?php
      // #192 : compteur « rempli/total » par groupe (une seule passe) ; $anyFilled
      // en découle. Garde-fou du repli : si AUCUNE ligne n'est remplie (nouvelle
      // grille depuis template), on n'auto-replie rien pour ne pas cacher un
      // formulaire vide.
      $filledByGroup = [];
      foreach ($grouped as $gk => $gRows) {
          $filledByGroup[$gk] = count(array_filter($gRows, static fn (array $r): bool => $r['amount'] !== ''));
      }
      $anyFilled = array_sum($filledByGroup) > 0;
    ?>
    <?php // Les deux textes de grisage sont exportés au JS : il les repose sur les
          // lignes concernées quand le mode change, sans recharger la page. ?>
    <div class="lines-wrap" data-edit-field="<?= $this->e($this->t('tariffs.edit_field')) ?>"
         data-hint-dynamic="<?= $this->e($this->t('tariffs.energy_ignored_dynamic')) ?>"
         data-hint-fixed="<?= $this->e($this->t('tariffs.spot_ignored_fixed')) ?>">
      <?php foreach ($groupOrder as $group): ?>
        <?php // Catégorie vide et non pertinente pour cette énergie (ex. Injection en gaz/eau) : masquée.
              if (empty($grouped[$group]) && !in_array($group, $relevantCategories, true)) continue; ?>
        <?php
          $total    = count($grouped[$group] ?? []);
          $filled   = $filledByGroup[$group] ?? 0;
          $collapse = $anyFilled && $filled === 0;
        ?>
        <div class="line-group<?= $collapse ? ' is-collapsed' : '' ?>" data-group="<?= $this->e($group) ?>">
          <button type="button" class="line-group-title" data-toggle-group aria-expanded="<?= $collapse ? 'false' : 'true' ?>">
            <?= $this->te('tariffs.group_' . $group) ?>
            <span class="line-group-count" data-group-count title="<?= $this->e($this->t('tariffs.group_filled', ['filled' => (string) $filled, 'total' => (string) $total])) ?>"><?= $filled ?>/<?= $total ?></span>
          </button>
          <div class="form-grid line-group-body">
            <?php foreach (($grouped[$group] ?? []) as $f):
              $i = $f['i'];
              // Tarif dynamique : les lignes d'énergie fournisseur (energy_simple/t1/t2)
              // sont ignorées au calcul (prix ENTSO-E) → grisées + note, mais conservées
              // (valeurs toujours postées) pour rebasculer en fixe sans re-saisie.
              $ignoredInDynamic = $isDynamic && ComponentKind::fromStringOrDefault($f['kind'])->isSupplierEnergy();
              // Cas symétrique (#228) : coefficient et marge sur le prix de marché ne
              // servent qu'en tarif dynamique → grisés en tarif fixe, également conservés.
              $ignoredInFixed = !$isDynamic && ComponentKind::fromStringOrDefault($f['kind'])->isSpotFormula();
            ?>
            <?php
              // Toute ligne est pleinement éditable (libellé, type, catégorie,
              // montant) et supprimable — plus de distinction catalogue/custom.
              // Une ligne peut porter un kind hors liste pour cette énergie (ex.
              // grille eau importée avec per_kwh) : on réinjecte alors l'option
              // courante, sinon la soumission remplacerait silencieusement son kind.
              $currentKind = ComponentKind::fromStringOrDefault($f['kind']);
              $kindListed  = false;
              foreach ($kindOptions as $groupOpts) { if (isset($groupOpts[$currentKind->value])) { $kindListed = true; break; } }
            ?>
            <div class="form-row line-item is-custom<?= $ignoredInDynamic || $ignoredInFixed ? ' is-dynamic-ignored' : '' ?>">
              <input type="hidden" name="lines[<?= $i ?>][key]" value="<?= $this->e($f['key']) ?>">
              <label class="form-label">
                <input type="text" name="lines[<?= $i ?>][label]" class="form-input line-label-input" placeholder="<?= $this->e($this->t('tariffs.field_label')) ?>" value="<?= $this->e($f['label']) ?>">
              </label>
              <select name="lines[<?= $i ?>][kind]" class="form-select line-kind-select">
                <?php if (!$kindListed): ?>
                <option value="<?= $this->e($currentKind->value) ?>" selected><?= $this->e($this->t('tariffs.kind.' . $currentKind->value)) ?></option>
                <?php endif; ?>
                <?php foreach ($kindOptions as $groupKey => $groupOpts): ?>
                <optgroup label="<?= $this->e($this->t('tariffs.kind_group.' . $groupKey)) ?>">
                  <?php foreach ($groupOpts as $kv => $klabel): ?>
                  <option value="<?= $this->e($kv) ?>" <?= $currentKind->value === $kv ? 'selected' : '' ?>><?= $this->e($klabel) ?></option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
              </select>
              <select name="lines[<?= $i ?>][category]" class="form-select line-category-select" aria-label="<?= $this->e($this->t('tariffs.category')) ?>">
                <?php foreach ($categoryOptions as $cv => $clabel): ?>
                <option value="<?= $this->e($cv) ?>" <?= $f['category'] === $cv ? 'selected' : '' ?>><?= $this->e($clabel) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="line-amount-row">
                <input type="number" name="lines[<?= $i ?>][amount]" step="0.0000001" class="form-input" placeholder="0.0000000" value="<?= $this->e($f['amount']) ?>">
                <button type="button" class="btn btn-ghost btn-sm line-remove" data-remove-line aria-label="<?= $this->e($this->t('tariffs.remove_field')) ?>">×</button>
              </div>
              <?php if ($ignoredInDynamic): ?><p class="dynamic-ignored-hint"><?= $this->te('tariffs.energy_ignored_dynamic') ?></p><?php endif; ?>
              <?php if ($ignoredInFixed): ?><p class="dynamic-ignored-hint"><?= $this->te('tariffs.spot_ignored_fixed') ?></p><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <div class="form-row full add-field-row">
              <button type="button" class="btn btn-ghost btn-sm" data-add-line><?= $this->te('tariffs.add_field') ?></button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Légende du sélecteur « type de composante » (issue #179) : concepts propres
         à l'électricité (T1/T2 jour-nuit, injection) → masquée pour gaz et eau. -->
    <?php if ($energy === 'electricity'): ?>
    <p class="kind-legend"><?= $this->te('tariffs.kind_legend') ?></p>
    <?php endif; ?>

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
      <?php foreach ($kindOptions as $groupKey => $groupOpts): ?>
      <optgroup label="<?= $this->e($this->t('tariffs.kind_group.' . $groupKey)) ?>">
        <?php foreach ($groupOpts as $kv => $klabel): ?>
        <option value="<?= $this->e($kv) ?>"><?= $this->e($klabel) ?></option>
        <?php endforeach; ?>
      </optgroup>
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
  <span><?= $this->appName() ?> — <?= $this->te('tariffs.title') ?></span>
</div>

</div>

<script defer src="<?= \App\Support\Assets::url('assets/js/header.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/confirm.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/tariffs.js') ?>" data-next-index="<?= $nextIndex ?>"
        data-supplier-kinds="<?= $this->e(implode(',', $supplierEnergyKinds)) ?>"
        data-spot-kinds="<?= $this->e(implode(',', $spotFormulaKinds)) ?>"></script>
</body>
</html>
