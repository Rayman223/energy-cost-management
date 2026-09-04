<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\Domain\ComponentKind;
use App\Domain\SpotFormulaFit;
use App\Domain\TariffCategory;
use App\Domain\TariffTemplateCatalog;
use App\I18n\Translator;
use App\Infrastructure\MeterTopology;
use App\Service\BillReconciliationService;
use App\Service\Import\ImportMapping;
use App\Support\LegalIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Pendant PHP de {@see DashboardJsCatalogTest} (#224) : les templates appellent
 * `$this->t()` / `$this->te()` plusieurs centaines de fois, et rien à l'exécution
 * ne signale une clé manquante — `Translator::t()` retombe par conception sur la
 * clé brute, qui s'affiche telle quelle à l'écran.
 *
 * `TranslationParityTest` ne compare que les catalogues entre eux : une clé
 * absente des quatre à la fois (typo, renommage d'un seul côté) passe toute la
 * CI. Ce test ferme ce trou en confrontant les clés *référencées* au catalogue.
 *
 * Le sens inverse — clé de catalogue jamais référencée — est volontairement hors
 * périmètre : une clé peut légitimement n'être utilisée que depuis un script
 * client ou une route.
 */
final class TemplateCatalogTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** @var list<string> */
    private const LOCALES = ['fr', 'en', 'nl', 'de'];

    /**
     * Garde-fous anti-dérive, un par voie de collecte : une regex cassée rendrait
     * sinon ce test vert et muet. Un seuil global ne suffirait pas — les clés
     * d'appel sont un sous-ensemble des littéraux ressemblant à une clé, donc
     * neutraliser la première regex ne réduirait pas le total.
     *
     * Seuils placés sous les totaux observés (419 / 580 / 54) avec assez de marge
     * pour qu'une suppression de libellé ne fasse pas échouer la CI. À remonter
     * quand le catalogue grossit : laissés loin en arrière, ils cesseraient de
     * voir une regex qui ne rapporterait plus que la moitié des clés.
     *
     * @var array<string, int>
     */
    private const MINIMUM_KEYS_PER_SOURCE = [
        'callSiteKeys'    => 380,
        'keyLikeLiterals' => 520,
        'derivedKeys'     => 48,
    ];

    /**
     * Extensions de fichier : `'account.php'` (table de routes d'index.php) a la
     * forme d'une clé et un préfixe présent au catalogue. Sans ce filtre, déplacer
     * une table de routes sous app/src rendrait la CI rouge en réclamant la
     * traduction d'un nom de fichier.
     *
     * @var list<string>
     */
    private const FILE_EXTENSIONS = [
        'php', 'js', 'css', 'json', 'html', 'htm', 'svg', 'png', 'jpg', 'ico',
        'md', 'xml', 'csv', 'txt', 'yml', 'yaml', 'phar', 'lock', 'dist', 'neon',
        'sql', 'webmanifest',
    ];

    /**
     * Seule famille hors d'atteinte : `dashboard.php` rend `$card['label_key']`,
     * construit par DashboardCardsService::card() en `dash.card.<key>` à partir de
     * la constante privée ELECTRICITY_CARDS (app/src/Service/DashboardCardsService.php:52),
     * illisible sans réflexion. Les libellés gaz/eau de ce même service passent,
     * eux, par des littéraux (`dash.gas`, `dash.water`) déjà captés dans app/src.
     *
     * Toutes les autres concaténations sont reconstruites par {@see self::derivedKeys()} :
     * cette liste ne doit pas grossir sans que la source soit réellement illisible.
     *
     * @var list<string>
     */
    private const DYNAMIC_KEYS = [
        'dash.card.import_t1',
        'dash.card.import_t2',
        'dash.card.export_t1',
        'dash.card.export_t2',
        'dash.card.solar',
    ];

    /**
     * Repli sur la locale elle-même, et non sur le français : sinon
     * `allWithPrefix()` fusionne le catalogue fr par-dessus les trous de la locale
     * testée et une clé réellement absente de en/nl/de passerait inaperçue (bug
     * corrigé par #226 dans le test JS).
     */
    private function translator(string $locale): Translator
    {
        return new Translator(self::ROOT . '/app/translations', $locale, $locale);
    }

    /**
     * Toutes les clés qu'un rendu de template peut demander au catalogue.
     *
     * @return list<string>
     */
    private function referencedKeys(): array
    {
        $keys = array_merge(
            $this->callSiteKeys(),
            $this->keyLikeLiterals(),
            $this->derivedKeys(),
            self::DYNAMIC_KEYS,
        );

        sort($keys);

        return array_values(array_unique($keys));
    }

    /**
     * Clés passées littéralement à `$this->t('…')` / `$this->te('…')`.
     *
     * @return list<string>
     */
    private function callSiteKeys(): array
    {
        $keys = [];
        foreach ($this->templateFiles() as $file) {
            preg_match_all('/\$this->te?\(\s*\'([^\']+)\'/', $this->read($file), $matches);
            $keys = array_merge($keys, $matches[1]);
        }

        return $this->withoutConcatenationPrefixes($keys);
    }

    /**
     * Clés stockées dans un tableau puis traduites indirectement : `$features` de
     * welcome.php, `$titleKeys` et les listes de legal.php, la carte registre =>
     * libellé de meter_readings.php, les messages flash des routes.
     *
     * Le filtre est le préfixe : seul un littéral dont le premier segment est un
     * préfixe réellement présent au catalogue est retenu, ce qui écarte formats de
     * date et identifiants techniques sans liste d'exclusions à maintenir. Les
     * noms de fichiers, qui passent ce filtre, sont écartés par leur extension.
     *
     * @return list<string>
     */
    private function keyLikeLiterals(): array
    {
        $prefixes = [];
        foreach (array_keys($this->translator('fr')->allWithPrefix('')) as $key) {
            $prefixes[explode('.', $key)[0]] = true;
        }

        $keys = [];
        foreach (array_merge($this->templateFiles(), $this->sourceFiles()) as $file) {
            preg_match_all('/\'([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)\'/', $this->read($file), $matches);
            foreach ($matches[1] as $candidate) {
                $segments = explode('.', $candidate);
                if (!isset($prefixes[$segments[0]])) {
                    continue;
                }

                if (in_array(end($segments), self::FILE_EXTENSIONS, true)) {
                    continue;
                }

                $keys[] = $candidate;
            }
        }

        return $this->withoutConcatenationPrefixes($keys);
    }

    /**
     * Clés construites par concaténation d'un préfixe et d'une valeur énumérée.
     * Reconstruites depuis les sources elles-mêmes, jamais par regex : ajouter un
     * `case` d'enum ou une entrée de catalogue sans sa traduction fait donc échouer
     * ce test, ce qui est précisément l'intérêt.
     *
     * @return list<string>
     */
    private function derivedKeys(): array
    {
        $keys = [];

        // tariffs.php:359 — titre de chaque groupe de lignes tarifaires.
        foreach (TariffCategory::cases() as $category) {
            $keys[] = 'tariffs.group_' . $category->value;
        }

        // tariffs.php:390 et :393/:460 — libellé du kind et de son optgroup.
        foreach (ComponentKind::cases() as $kind) {
            $keys[] = 'tariffs.kind.' . $kind->value;
            $keys[] = 'tariffs.kind_group.' . $kind->group();
        }

        // tariffs.php:195 et :231 — nom du modèle de grille prédéfini.
        foreach (TariffTemplateCatalog::all() as $template) {
            $keys[] = $template['name_key'];
        }

        // account.php:263 et :337 — unités proposées à l'import.
        foreach (ImportMapping::UNITS as $units) {
            foreach ($units as $unit) {
                $keys[] = 'import.unit_' . $unit;
            }
        }

        // account.php:295 — un champ de mapping par registre électrique.
        foreach (MeterTopology::ELECTRICITY_REGISTERS as $register) {
            $keys[] = 'import.reg_' . $register;
        }

        // legal.php:187 — une ligne de définition par champ d'identité de l'éditeur.
        foreach (LegalIdentity::FIELDS as $field) {
            $keys[] = 'legal.notice.' . $field;
        }

        // reconciliation.php — mode du rapprochement et raison d'exclusion d'un mois (#229).
        // Dérivés des constantes publiques plutôt que listés : ajouter un mode ou une
        // raison sans sa traduction doit rendre la CI rouge.
        foreach (SpotFormulaFit::MODES as $mode) {
            $keys[] = 'reconciliation.mode.' . $mode;
        }
        foreach (BillReconciliationService::SKIP_REASONS as $reason) {
            $keys[] = 'reconciliation.skipped.' . $reason;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Une clé concaténée est capturée tronquée (`tariffs.kind.`, `import.unit_`) :
     * seule la forme complète, reconstruite par {@see self::derivedKeys()}, est
     * vérifiable. Même filtrage que `dash.meta.days_` côté JS.
     *
     * @param  list<string> $keys
     * @return list<string>
     */
    private function withoutConcatenationPrefixes(array $keys): array
    {
        $complete = array_filter(
            $keys,
            static fn (string $key): bool => !str_ends_with($key, '_') && !str_ends_with($key, '.'),
        );

        return array_values(array_unique($complete));
    }

    /** @return list<string> */
    private function templateFiles(): array
    {
        return array_merge(
            $this->glob(self::ROOT . '/app/templates/*.php'),
            $this->glob(self::ROOT . '/app/templates/partials/*.php'),
        );
    }

    /**
     * Sources PHP susceptibles de porter une clé destinée au rendu : les services
     * et objets-valeur, mais aussi les routes, qui posent les messages flash
     * (`tariffs.saved`, `admin.forbidden`, …) relus ensuite par un template.
     *
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];
        foreach (['/app/src', '/app/routes', '/app/public'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::ROOT . $directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function glob(string $pattern): array
    {
        $files = glob($pattern);
        self::assertIsArray($files, "Le motif {$pattern} doit être explorable.");
        self::assertNotEmpty($files, "Aucun fichier pour {$pattern} : l'arborescence a changé.");

        return array_values($files);
    }

    private function read(string $file): string
    {
        $source = file_get_contents($file);
        self::assertIsString($source, "{$file} doit être lisible.");

        return $source;
    }

    /**
     * Sans ce garde-fou, une regex qui cesserait de matcher rendrait tous les
     * autres tests verts sur un ensemble appauvri. Le contrôle porte sur chaque
     * voie de collecte séparément : les clés d'appel étant incluses dans les
     * littéraux ressemblant à une clé, un seuil sur le seul total ne verrait pas
     * la première regex se casser.
     */
    public function testEachExtractionSourceDidNotDrift(): void
    {
        $counts = [
            'callSiteKeys'    => count($this->callSiteKeys()),
            'keyLikeLiterals' => count($this->keyLikeLiterals()),
            'derivedKeys'     => count($this->derivedKeys()),
        ];

        foreach (self::MINIMUM_KEYS_PER_SOURCE as $source => $minimum) {
            self::assertGreaterThanOrEqual(
                $minimum,
                $counts[$source],
                "{$source}() ne rend plus que {$counts[$source]} clés (attendu ≥ {$minimum}) : la détection a dérivé.",
            );
        }
    }

    public function testEveryKeyReferencedByTemplatesIsTranslatedInEveryLocale(): void
    {
        $keys = $this->referencedKeys();

        foreach (self::LOCALES as $locale) {
            $catalog = $this->translator($locale)->allWithPrefix('');
            foreach ($keys as $key) {
                self::assertArrayHasKey(
                    $key,
                    $catalog,
                    "Clé {$key} référencée par le rendu mais absente de {$locale}.php",
                );
            }
        }
    }

    /**
     * Un `{name}` présent dans une traduction mais pas dans une autre laisse un
     * placeholder littéral à l'écran, ou perd une valeur silencieusement.
     */
    public function testPlaceholdersMatchTheFrenchReference(): void
    {
        $reference = $this->translator('fr')->allWithPrefix('');
        $keys      = $this->referencedKeys();

        foreach (['en', 'nl', 'de'] as $locale) {
            $catalog = $this->translator($locale)->allWithPrefix('');
            foreach ($keys as $key) {
                // Assertions explicites des deux côtés : sans elles, une clé
                // absente sort en `''`, donc en liste de paramètres vide, et
                // l'échec parlerait à tort de paramètres divergents — voire
                // passerait, les deux listes étant vides.
                self::assertArrayHasKey($key, $reference, "Clé {$key} absente de fr.php");
                self::assertArrayHasKey($key, $catalog, "Clé {$key} absente de {$locale}.php");

                self::assertSame(
                    $this->placeholders($reference[$key]),
                    $this->placeholders($catalog[$key]),
                    "Paramètres {name} divergents entre fr.php et {$locale}.php pour {$key}",
                );
            }
        }
    }

    /** @return list<string> Noms de paramètres `{name}` triés. */
    private function placeholders(string $message): array
    {
        preg_match_all('/\{(\w+)\}/', $message, $matches);
        $names = array_unique($matches[1]);
        sort($names);

        return array_values($names);
    }
}
