<?php
declare(strict_types=1);

use App\Domain\ComponentKind;
use App\Domain\EuropeanCountries;
use App\Domain\TariffCategory;
use App\Domain\TariffGrid;
use App\Domain\TariffLineCatalog;
use App\Domain\TariffTemplateCatalog;
use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\TariffRepository;
use App\Repository\TariffTemplateRepository;
use App\Repository\TariffTemplateUsageRepository;
use App\Repository\UserRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\Security\UserContext;
use App\Service\SpotFormulaResolver;
use App\Support\Adsense;
use App\Support\DiscordLink;
use App\Support\DynamicPricing;
use App\Support\LocaleContext;

// Bootstrap isolé : une configuration injoignable (ex. config.php absent) dégrade
// en 503 propre plutôt qu'en fatal exposant un stack trace (#130 C6). bootstrap.php
// charge l'autoloader avant de valider la config, donc SecurityHeaders reste
// disponible dans le catch pour poser les en-têtes de sécurité sur l'erreur.
try {
    $config = require __DIR__ . '/../bootstrap.php';
} catch (\Throwable $e) {
    SecurityHeaders::send();
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Service indisponible : configuration manquante.';

    return;
}

SecurityHeaders::send($config);
AuthGuard::protect($config);

$db          = new Database($config['database']);
$pdo         = $db->pdo();
$userId      = UserContext::currentWebUserId($pdo, $config);
$users       = new UserRepository($pdo);
$isAdmin     = ($users->findById($userId)?->isAdmin()) ?? false;

$profile     = $users->getProfile($userId);
$view        = LocaleContext::viewFor($config, $users, $userId, $profile?->locale, __DIR__ . '/../templates');

$tariffRepo   = new TariffRepository($pdo, $userId, $isAdmin);
$templateRepo = new TariffTemplateRepository($pdo, $userId);
$usageRepo    = new TariffTemplateUsageRepository($pdo);
$error        = null;
$success      = null;

$energyTypes = ['electricity', 'gas', 'water'];

/**
 * Référence de template valide/visible pour l'utilisateur courant, sinon null.
 * Empêche d'enregistrer une utilisation pour un template inexistant ou un
 * template privé d'un autre compte (findById renvoie null).
 */
$resolveTemplateRef = static function (string $ref) use ($templateRepo): ?string {
    if (str_starts_with($ref, 'builtin:')) {
        return TariffTemplateCatalog::find(substr($ref, 8)) !== null ? $ref : null;
    }
    if (str_starts_with($ref, 'user:')) {
        $id = (int) substr($ref, 5);

        return $id > 0 && $templateRepo->findById($id) !== null ? 'user:' . $id : null;
    }

    return null;
};

/**
 * Libellé d'affichage d'une ligne : libellé custom sinon libellé du catalogue.
 */
$fieldLabel = static function (string $energy, string $key, ?string $custom): string {
    if ($custom !== null && $custom !== '') {
        return $custom;
    }
    $catalog = TariffLineCatalog::forType($energy);

    return $catalog[$key]['label'] ?? $key;
};

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (Csrf::validate($_POST['_csrf'] ?? null) === false) {
            throw new \RuntimeException($view->t('common.csrf_invalid'));
        }

        if ($action === 'save') {
            $editId     = filter_var($_POST['edit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $energyType = $_POST['energy_type'] ?? '';
            $name       = trim($_POST['name'] ?? '');
            $validFrom  = $_POST['valid_from'] ?? '';
            $validTo    = trim($_POST['valid_to'] ?? '') ?: null;

            if (!in_array($energyType, $energyTypes, true)) throw new \InvalidArgumentException($view->t('tariffs.invalid_energy'));
            if ($name === '')      throw new \InvalidArgumentException($view->t('tariffs.name_required'));
            if ($validFrom === '') throw new \InvalidArgumentException($view->t('tariffs.from_required'));

            // Lignes dynamiques : lines[N][key|kind|label|amount].
            $lines    = [];
            $usedKeys = [];
            foreach ((array) ($_POST['lines'] ?? []) as $row) {
                if (!is_array($row)) continue;
                $raw = trim((string) ($row['amount'] ?? ''));
                if ($raw === '') continue; // montant vide → ligne ignorée

                $val = filter_var($raw, FILTER_VALIDATE_FLOAT);
                if ($val === false) throw new \InvalidArgumentException($view->t('tariffs.invalid_value', ['key' => (string) ($row['key'] ?? '')]));

                $kind = ComponentKind::tryFrom((string) ($row['kind'] ?? ''));
                if ($kind === null) throw new \InvalidArgumentException($view->t('tariffs.invalid_kind'));

                // Le coefficient d'indexation multiplie le prix spot (#228) : hors bornes,
                // il annulerait le coût énergie (≤ 0) ou trahirait un pourcentage saisi tel
                // quel (108 au lieu de 1,08). Refus explicite plutôt que repli silencieux.
                if ($kind === ComponentKind::SpotCoefficient
                    && ($val <= SpotFormulaResolver::COEFFICIENT_MIN || $val > SpotFormulaResolver::COEFFICIENT_MAX)) {
                    throw new \InvalidArgumentException($view->t('tariffs.invalid_coefficient', [
                        'max' => (string) SpotFormulaResolver::COEFFICIENT_MAX,
                    ]));
                }

                $label = trim((string) ($row['label'] ?? ''));
                $key   = strtolower(trim((string) ($row['key'] ?? '')));
                if ($key === '') {
                    // clé auto depuis le libellé (champ custom sans clé)
                    $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($label)) ?? '';
                    $slug = trim($slug, '_');
                    $key  = 'custom_' . ($slug !== '' ? $slug : substr(md5($label . $val), 0, 8));
                }
                if (preg_match('/^[a-z][a-z0-9_]{0,99}$/', $key) !== 1) {
                    throw new \InvalidArgumentException($view->t('tariffs.invalid_value', ['key' => $key]));
                }

                // Toute ligne est éditable : un libellé est requis (les lignes du
                // catalogue arrivent avec leur libellé pré-rempli).
                if ($label === '') throw new \InvalidArgumentException($view->t('tariffs.label_required'));

                // Déduplication : la persistance et fetchLines indexent par line_key ;
                // deux champs custom au même libellé (donc même clé) perdraient une ligne.
                if (isset($usedKeys[$key])) {
                    $base = substr($key, 0, 96);
                    $n    = 2;
                    while (isset($usedKeys[$base . '_' . $n])) {
                        $n++;
                    }
                    $key = $base . '_' . $n;
                }
                $usedKeys[$key] = true;

                // Catégorie d'affichage choisie ; valeur inconnue/vide → défaut dérivé de la clé/kind.
                $category = TariffCategory::tryFrom(strtolower(trim((string) ($row['category'] ?? ''))))
                    ?? TariffCategory::defaultForLine($key, $kind);

                $lines[] = [
                    'key'      => $key,
                    'amount'   => $val,
                    'kind'     => $kind->value,
                    'label'    => $label,
                    'category' => $category->value,
                ];
            }

            $pcs = null;
            if ($energyType === 'gas' && ($_POST['pcs_coefficient'] ?? '') !== '') {
                $pcs = (float) $_POST['pcs_coefficient'];
            }

            $country = strtoupper(trim((string) ($_POST['country'] ?? '')));
            $country = EuropeanCountries::isValid($country) ? $country : null;

            $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'EUR')));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new \InvalidArgumentException($view->t('account.invalid_currency'));
            }

            $vatRate = filter_var($_POST['vat_rate'] ?? '', FILTER_VALIDATE_FLOAT);
            if ($vatRate === false) {
                $vatRate = ($country !== null ? EuropeanCountries::vatRate($country) : null) ?? 21.0;
            }
            if ($vatRate < 0.0 || $vatRate > 100.0) {
                throw new \InvalidArgumentException($view->t('tariffs.invalid_vat'));
            }

            $shared = $isAdmin && ($_POST['shared'] ?? '') === '1';

            // Mode tarifaire porté par la grille (#245). Le repository normalise la
            // valeur et la force à 'fixed' hors électricité — ici on se contente de
            // décider CE QU'ON LIT. Tarif dynamique désactivé côté serveur : le select
            // est absent du formulaire, on reconduit donc le mode déjà enregistré au
            // lieu de le remettre à 'fixed', qui effacerait le contrat d'un utilisateur
            // le temps que le flag revienne (même garde que /account pour la zone de
            // marché). Neutralise aussi un POST forgé qui activerait le dynamique sur
            // un serveur qui n'en importe pas les prix.
            if (DynamicPricing::isEnabled($config)) {
                $pricingMode = (string) ($_POST['pricing_mode'] ?? TariffGrid::PRICING_MODE_DEFAULT);
            } else {
                $stored      = $editId !== null ? $tariffRepo->findById($editId) : null;
                $pricingMode = $stored->pricingMode ?? TariffGrid::PRICING_MODE_DEFAULT;
            }

            // Sauvegarde optionnelle comme template : on valide le nom AVANT de
            // persister la grille, pour ne pas laisser une grille enregistrée alors
            // que l'action signale une erreur.
            $saveAsTemplate = ($_POST['save_as_template'] ?? '') === '1';
            $tplName        = trim($_POST['template_name'] ?? '');
            $tplVisibility  = ($_POST['template_visibility'] ?? '') === 'public' ? 'public' : 'private';
            if ($saveAsTemplate && $tplName === '') {
                throw new \InvalidArgumentException($view->t('tariffs.template_name_required'));
            }

            if ($editId !== null) {
                $tariffRepo->updateGrid(
                    $editId, $energyType, $name,
                    new \DateTimeImmutable($validFrom),
                    $validTo ? new \DateTimeImmutable($validTo) : null,
                    $lines, $pcs, $country, $currency, $vatRate, $pricingMode,
                );
            } else {
                $tariffRepo->saveGrid(
                    $energyType, $name,
                    new \DateTimeImmutable($validFrom),
                    $validTo ? new \DateTimeImmutable($validTo) : null,
                    $lines, $pcs, $country, $currency, $shared, $vatRate, $pricingMode,
                );

                // Compteur de popularité : une nouvelle grille issue d'un template
                // compte une utilisation (idempotent, un user = une unité).
                $sourceRef = $resolveTemplateRef(trim((string) ($_POST['source_template'] ?? '')));
                if ($sourceRef !== null) {
                    $usageRepo->record($sourceRef, $userId);
                }
            }
            $success = $view->t('tariffs.saved', ['name' => $name]);

            // Structure seule (sans montants) enregistrée comme template réutilisable.
            if ($saveAsTemplate) {
                $fields = array_map(
                    static fn (array $l): array => ['key' => $l['key'], 'kind' => $l['kind'], 'label' => $l['label'], 'category' => $l['category']],
                    $lines,
                );
                $templateRepo->save($energyType, $country, $tplName, $fields, $tplVisibility);
                $success .= ' ' . $view->t('tariffs.template_saved');
            }
        }

        if ($action === 'close') {
            $id      = (int) ($_POST['grid_id'] ?? 0);
            $validTo = $_POST['valid_to_close'] ?? '';
            if ($id <= 0)        throw new \InvalidArgumentException($view->t('tariffs.invalid_id'));
            if ($validTo === '') throw new \InvalidArgumentException($view->t('tariffs.end_required'));
            $tariffRepo->closeGrid($id, new \DateTimeImmutable($validTo));
            $success = $view->t('tariffs.closed');
        }

        if ($action === 'delete') {
            $id = (int) ($_POST['grid_id'] ?? 0);
            if ($id <= 0) throw new \InvalidArgumentException($view->t('tariffs.invalid_id'));
            $tariffRepo->deleteGrid($id);
            $success = $view->t('tariffs.deleted');
        }

        if ($action === 'template_delete') {
            $id = (int) ($_POST['template_id'] ?? 0);
            if ($id <= 0) throw new \InvalidArgumentException($view->t('tariffs.invalid_id'));
            $templateRepo->delete($id);
            $success = $view->t('tariffs.template_deleted');
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Active energy (onglet) ──────────────────────────────────────────────────
$editGrid = null;
if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($editId === false) {
        $error = $view->t('tariffs.invalid_id');
    } else {
        try {
            $editGrid = $tariffRepo->findById($editId);
            if ($editGrid === null) $error = $view->t('tariffs.not_found');
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

// Duplication (#205) : reprise d'une grille existante — y compris une grille du
// catalogue partagé — comme point de départ d'une grille personnelle. Purement
// GET : rien n'est écrit tant que l'utilisateur n'a pas soumis le formulaire, et
// la grille créée est la sienne (edit_id vide ⇒ saveGrid sans `shared`).
// findById filtre déjà sur (user_id = :uid OR user_id IS NULL) : pas de fuite
// cross-tenant. L'édition prime si les deux paramètres sont passés.
//
// Le formulaire poste sur l'URL courante, donc sur ?duplicate=<id> : la ref est
// volontairement ignorée en POST. Sinon la page réaffichée après enregistrement
// repartirait de la grille SOURCE — un second envoi (ou un F5) créerait une copie
// de plus, et une erreur de validation écraserait la saisie. En POST on retombe
// donc sur le parcours de création normal (reprise de $latest, c.-à-d. la copie
// qui vient d'être enregistrée).
$duplicateGrid = null;
if ($editGrid === null && isset($_GET['duplicate']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $dupId = filter_var($_GET['duplicate'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($dupId === false) {
        $error = $view->t('tariffs.invalid_id');
    } else {
        try {
            $duplicateGrid = $tariffRepo->findById($dupId);
            if ($duplicateGrid === null) $error = $view->t('tariffs.not_found');
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$energy = $_GET['energy'] ?? 'electricity';
if (!in_array($energy, $energyTypes, true)) $energy = 'electricity';
if ($editGrid !== null) $energy = $editGrid->energyType; // l'édition force l'énergie de la grille
if ($duplicateGrid !== null) $energy = $duplicateGrid->energyType; // idem pour la duplication

// ── Grilles de l'énergie active ─────────────────────────────────────────────
$grids  = $tariffRepo->findAll($energy);
$latest = $grids[0] ?? null;

// ── Templates disponibles (fournis + utilisateur) ───────────────────────────
$builtinTemplates = TariffTemplateCatalog::forEnergy($energy);
$userTemplates    = $templateRepo->findForEnergy($energy);
$usageCounts      = $usageRepo->countsByRef();

// Ref du template importé (préréglé dans le formulaire, tracé au save). Sur un
// POST de création en échec (ex. validation), on conserve la ref postée : sans
// ça, le champ caché repartirait vide au ré-envoi et l'utilisation ne serait
// jamais comptée. La ref est de toute façon revalidée (resolveTemplateRef) au save.
$sourceTemplate = ($editGrid === null && $_SERVER['REQUEST_METHOD'] === 'POST')
    ? trim((string) ($_POST['source_template'] ?? ''))
    : '';

$today = date('Y-m-d');

// ── Résolution de la structure du formulaire ────────────────────────────────
// Priorité : grille éditée > grille dupliquée > template importé > dernière
// grille > template défaut.
/** @var list<array{key:string,kind:string,label:string,amount:string,category:string}> $formFields */
$formFields = [];
// En-tête du formulaire : l'édition rejoue la grille telle quelle, la
// duplication la reprend sous un nouveau nom et sans date de fin héritée
// (la copie repart d'aujourd'hui, la grille source restant intacte).
$formName      = '';
$formValidFrom = $today;
$formValidTo   = null;
$formPcs       = null;
if ($editGrid !== null) {
    $formCountry  = $editGrid->country;
    $formCurrency = $editGrid->currency;
    $formVat      = $editGrid->vatRate;

    $formName      = $editGrid->name;
    $formValidFrom = $editGrid->validFrom->format('Y-m-d');
    $formValidTo   = $editGrid->validTo?->format('Y-m-d');
    $formPcs       = $editGrid->pcsCoefficient;
    $formMode      = $editGrid->pricingMode;
} elseif ($duplicateGrid !== null) {
    $formCountry  = $duplicateGrid->country;
    $formCurrency = $duplicateGrid->currency;
    $formVat      = $duplicateGrid->vatRate;

    // Nom tronqué à la largeur de la colonne (tariff_grids.name VARCHAR(120)).
    $formName = mb_substr($view->t('tariffs.duplicate_name', ['name' => $duplicateGrid->name]), 0, 120);
    $formPcs  = $duplicateGrid->pcsCoefficient;
    $formMode = $duplicateGrid->pricingMode;
} else {
    // #189 : continuité avec la reprise de structure/montants depuis $latest — le
    // pays de la dernière grille prime, le profil ne sert qu'à la toute première.
    $formCountry  = $latest !== null ? $latest->country : $profile?->country;
    $formCurrency = $profile->currency ?? 'EUR';
    $formVat      = ($formCountry !== null ? EuropeanCountries::vatRate($formCountry) : null) ?? 21.0;
    // Nouvelle grille : on reprend le mode de la dernière grille de l'énergie (#245).
    // Créer la grille du contrat suivant, c'est presque toujours prolonger le même
    // régime ; forcer 'fixed' ferait retomber en tarif fixe à chaque renouvellement,
    // silencieusement.
    $formMode     = $latest !== null ? $latest->pricingMode : TariffGrid::PRICING_MODE_DEFAULT;
}

$buildFieldsFromSpecs = static function (array $specs, array $amounts, string $energy) use ($fieldLabel): array {
    $out = [];
    foreach ($specs as $spec) {
        $key    = $spec['key'];
        $kind   = ComponentKind::fromStringOrDefault((string) $spec['kind']);
        // Catégorie stockée si valide, sinon défaut dérivé de la clé/kind (legacy / template fourni).
        $category = TariffCategory::tryFrom((string) ($spec['category'] ?? '')) ?? TariffCategory::defaultForLine($key, $kind);
        $out[] = [
            'key'      => $key,
            'kind'     => $spec['kind'],
            'label'    => $fieldLabel($energy, $key, $spec['label'] ?? null),
            'amount'   => isset($amounts[$key]) ? rtrim(rtrim(number_format($amounts[$key], 7, '.', ''), '0'), '.') : '',
            'category' => $category->value,
        ];
    }

    return $out;
};

/**
 * Champs de formulaire (structure + montants) repris d'une grille existante :
 * édition, duplication (#205) et reprise de la dernière grille.
 *
 * @return list<array{key:string,kind:string,label:string,amount:string,category:string}>
 */
$fieldsFromGrid = static function (\App\Domain\TariffGrid $grid, string $energy) use ($buildFieldsFromSpecs): array {
    $specs = $amounts = [];
    foreach ($grid->lines as $line) {
        $specs[]             = ['key' => $line->key, 'kind' => $line->kind->value, 'label' => $line->label, 'category' => $line->category()->value];
        $amounts[$line->key] = $line->amount;
    }

    return $buildFieldsFromSpecs($specs, $amounts, $energy);
};

if ($editGrid !== null) {
    $formFields = $fieldsFromGrid($editGrid, $energy);
} elseif ($duplicateGrid !== null) {
    $formFields = $fieldsFromGrid($duplicateGrid, $energy);
} else {
    // Import d'un template : ?template=builtin:<code> | user:<id>
    $templateParam = (string) ($_GET['template'] ?? '');
    $imported = null;
    if (str_starts_with($templateParam, 'builtin:')) {
        $tpl = TariffTemplateCatalog::find(substr($templateParam, 8));
        if ($tpl !== null && $tpl['energy_type'] === $energy) {
            $imported = array_map(
                static fn (array $f): array => ['key' => $f['key'], 'kind' => $f['kind']->value, 'label' => null],
                $tpl['fields'],
            );
            if ($tpl['country'] !== null) {
                $formCountry  = $tpl['country'];
                $formCurrency = EuropeanCountries::currencyOf($tpl['country']) ?? $formCurrency;
                $formVat      = EuropeanCountries::vatRate($tpl['country']) ?? $formVat;
            }
        }
    } elseif (str_starts_with($templateParam, 'user:')) {
        $tpl = $templateRepo->findById((int) substr($templateParam, 5));
        if ($tpl !== null && $tpl['energy_type'] === $energy) {
            $imported = array_map(
                static fn (array $f): array => ['key' => $f['key'], 'kind' => $f['kind'], 'label' => $f['label'], 'category' => $f['category'] ?? null],
                $tpl['fields'],
            );
            if ($tpl['country'] !== null) $formCountry = $tpl['country'];
        }
    }

    if ($imported !== null) {
        $sourceTemplate = $templateParam; // ref validée par les branches ci-dessus
        $formFields = $buildFieldsFromSpecs($imported, [], $energy);
    } elseif ($latest !== null) {
        // Reprise de la dernière grille (structure + valeurs).
        $formFields = $fieldsFromGrid($latest, $energy);
    } else {
        // Template par défaut selon le pays du profil.
        $tpl = TariffTemplateCatalog::defaultFor($energy, $formCountry);
        $specs = array_map(
            static fn (array $f): array => ['key' => $f['key'], 'kind' => $f['kind']->value, 'label' => null],
            $tpl['fields'],
        );
        $formFields = $buildFieldsFromSpecs($specs, [], $energy);
    }
}

// Catégories d'affichage (ordre fixe, jeu fermé) + libellés des kinds pour le sélecteur custom.
// $relevantCategories : celles affichées même vides pour l'énergie active (l'injection
// ne concerne que l'électricité) ; les autres n'apparaissent que si elles portent des lignes.
$groupOrder = TariffCategory::values();
$relevantCategories = TariffCategory::relevantFor($energy);
$categoryOptions = [];
foreach (TariffCategory::cases() as $c) {
    $categoryOptions[$c->value] = $view->t('tariffs.group_' . $c->value);
}
// Options du sélecteur de type de composante, regroupées par famille (energy /
// taxes / fixed / injection) pour être rendues en <optgroup> : réduit la
// confusion des 11 choix techniques (issue #179). L'ordre des groupes suit
// celui des cases() de l'enum. Filtrées par énergie (issue #191) : ni per_m3 en
// électricité, ni kWh/injection en eau, gaz réduit à l'énergie simple + fixes.
$kindOptions = [];
foreach (ComponentKind::forEnergy($energy) as $k) {
    $kindOptions[$k->group()][$k->value] = $view->t('tariffs.kind.' . $k->value);
}

// Le mode appartient désormais à la grille (#245) : le grisage des lignes suit donc
// CELLE qu'on édite, et non plus un réglage global. Le sélecteur n'est proposé que
// pour l'électricité (aucun prix de marché ailleurs) et si le tarif dynamique est
// activé côté serveur — sinon le calcul retombe en fixe et proposer le choix, comme
// griser des lignes, induirait en erreur.
$modeEditable = $energy === 'electricity' && DynamicPricing::isEnabled($config);
$isDynamic    = $modeEditable && $formMode !== TariffGrid::PRICING_MODE_DEFAULT;

// Modes proposés par le sélecteur, libellés traduits.
$pricingModeOptions = [];
foreach (TariffGrid::PRICING_MODES as $mode) {
    $pricingModeOptions[$mode] = $view->t('tariffs.pricing_mode_' . $mode);
}

// Kinds concernés par le grisage, exportés vers le JS pour qu'il suive le mode en
// direct : ComponentKind reste la source unique, plutôt qu'une liste recopiée dans
// le script — une nouvelle composante spot y serait sinon oubliée en silence.
$supplierEnergyKinds = [];
$spotFormulaKinds    = [];
foreach (ComponentKind::cases() as $k) {
    if ($k->isSupplierEnergy()) {
        $supplierEnergyKinds[] = $k->value;
    }
    if ($k->isSpotFormula()) {
        $spotFormulaKinds[] = $k->value;
    }
}

echo $view->render('tariffs', [
    'oidcEnabled'      => AuthGuard::isOidcEnabled($config),
    'discordUrl'       => DiscordLink::inviteUrl($config),
    'adsenseClient'    => Adsense::clientId($config),
    'error'            => $error,
    'success'          => $success,
    'energy'           => $energy,
    'energyTypes'      => $energyTypes,
    'grids'            => $grids,
    'editGrid'         => $editGrid,
    'duplicateGrid'    => $duplicateGrid,
    'formFields'       => $formFields,
    'formCountry'      => $formCountry,
    'formCurrency'     => $formCurrency,
    'formVat'          => $formVat,
    'formName'         => $formName,
    'formValidFrom'    => $formValidFrom,
    'formValidTo'      => $formValidTo,
    'formPcs'          => $formPcs,
    'countries'        => EuropeanCountries::sortedForLocale($view->locale()),
    'currencies'       => EuropeanCountries::currencies(),
    'builtinTemplates' => $builtinTemplates,
    'userTemplates'    => $userTemplates,
    'usageCounts'      => $usageCounts,
    'sourceTemplate'   => $sourceTemplate,
    'groupOrder'          => $groupOrder,
    'relevantCategories'  => $relevantCategories,
    'categoryOptions'     => $categoryOptions,
    'kindOptions'         => $kindOptions,
    'isAdmin'          => $isAdmin,
    'isDynamic'        => $isDynamic,
    'modeEditable'     => $modeEditable,
    'formMode'         => $formMode,
    'pricingModeOptions' => $pricingModeOptions,
    'supplierEnergyKinds' => $supplierEnergyKinds,
    'spotFormulaKinds'    => $spotFormulaKinds,
    'available'        => Locale::available($config),
    // Fuseau BRUT du profil pour l'horloge (null ⇒ repli navigateur).
    'timezone'         => $profile->timezone ?? null,
]);
