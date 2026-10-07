<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\LoadProfileRepository;
use App\Service\Import\RowSource;

/**
 * Importe un profil de charge (RLP Synergrid et équivalents) depuis un CSV (#93).
 *
 * Le profil est le deuxième niveau de la cascade de pondération du tarif indexé
 * mensuel : il permet de reproduire la facture d'un contrat à prix variable, puisque
 * c'est ce profil que le fournisseur applique — et non la courbe réelle du client.
 *
 * FORMAT ATTENDU — deux colonnes, en-tête obligatoire :
 *
 *     timestamp;fraction
 *     2026-01-01 00:00;0.000021
 *     2026-01-01 00:15;0.000020
 *
 * Le délimiteur (`,` `;` tabulation) et la virgule décimale sont détectés
 * automatiquement, comme pour l'import de relevés. Les noms de colonnes sont
 * surchargeables (`--ts-col`, `--value-col`), car les exports Synergrid ne sont pas
 * normalisés d'un millésime à l'autre : convertir la feuille en CSV et désigner les
 * deux colonnes est plus robuste que de parier sur une mise en page.
 *
 * L'ÉCHELLE DES VALEURS EST INDIFFÉRENTE : le calcul en fait une moyenne pondérée,
 * qui divise par la somme des poids. Des fractions normalisées à 1, des pourcentages
 * ou des kWh bruts donnent donc le même prix. Rien n'est renormalisé à l'import.
 *
 * FUSEAU : les exports Synergrid sont horodatés en heure locale belge. Les instants
 * sont donc interprétés dans `--timezone` (défaut Europe/Brussels) puis convertis en
 * UTC pour le stockage, comme les cotations. Un horodatage portant déjà un offset
 * (`2026-01-01T00:00:00+01:00`) est respecté tel quel.
 *
 * LIMITE ASSUMÉE : à l'heure répétée du retour à l'heure d'hiver, un horodatage local
 * nu est ambigu ; PHP retient la première occurrence. Les quatre créneaux concernés
 * reçoivent donc le poids du premier passage. Fournir des horodatages avec offset
 * lève l'ambiguïté.
 *
 * USAGE :
 *   php app/scripts/import_load_profile.php --file=rlp0n-2026.csv [options]
 *
 *   --file=<chemin>      CSV à importer (requis)
 *   --code=<code>        Code du profil (défaut RLP0N)
 *   --country=<ISO2>     Pays (défaut BE)
 *   --resolution=<min>   Résolution des points, 15 ou 60 (défaut 15)
 *   --timezone=<tz>      Fuseau des horodatages nus (défaut Europe/Brussels)
 *   --ts-col=<nom>       Colonne d'horodatage (défaut timestamp)
 *   --value-col=<nom>    Colonne de poids (défaut fraction)
 *   --source=<libellé>   Provenance notée en base (défaut synergrid)
 *   --execute            Écrit en base ; sans ce drapeau, le script se contente de
 *                        valider le fichier et d'afficher un résumé (dry-run).
 */

$config = require __DIR__ . '/../bootstrap.php';

/**
 * @param list<string> $argv
 * @return array<string, string>
 */
$parseArgs = static function (array $argv): array {
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $pair = explode('=', substr($arg, 2), 2);
        $out[$pair[0]] = $pair[1] ?? '1';
    }

    return $out;
};

$args       = $parseArgs($argv);
$file       = (string) ($args['file'] ?? '');
$code       = strtoupper(trim((string) ($args['code'] ?? LoadProfileRepository::DEFAULT_CODE)));
$country    = strtoupper(trim((string) ($args['country'] ?? LoadProfileRepository::DEFAULT_COUNTRY)));
$resolution = (int) ($args['resolution'] ?? 15);
$timezone   = (string) ($args['timezone'] ?? 'Europe/Brussels');
$tsCol      = strtolower(trim((string) ($args['ts-col'] ?? 'timestamp')));
$valueCol   = strtolower(trim((string) ($args['value-col'] ?? 'fraction')));
$source     = (string) ($args['source'] ?? 'synergrid');
$execute    = isset($args['execute']);

if ($file === '') {
    fwrite(STDERR, "[ERROR] --file=<chemin> est requis.\n");
    exit(1);
}
if (!is_readable($file)) {
    fwrite(STDERR, '[ERROR] Fichier illisible : ' . $file . "\n");
    exit(1);
}
if ($code === '' || mb_strlen($code) > 32) {
    fwrite(STDERR, "[ERROR] --code doit faire 1 à 32 caractères.\n");
    exit(1);
}
if (!preg_match('/^[A-Z]{2}$/', $country)) {
    fwrite(STDERR, "[ERROR] --country doit être un code ISO 3166-1 alpha-2.\n");
    exit(1);
}
// 15 et 60 seulement : ce sont les deux résolutions que le calcul sait joindre aux
// cotations. Accepter un pas arbitraire produirait des poids qui ne tomberaient sur
// aucun créneau coté, donc une pondération silencieusement ignorée.
if ($resolution !== 15 && $resolution !== 60) {
    fwrite(STDERR, "[ERROR] --resolution doit valoir 15 ou 60.\n");
    exit(1);
}

try {
    $tz = new DateTimeZone($timezone);
} catch (\Throwable $e) {
    fwrite(STDERR, '[ERROR] Fuseau inconnu : ' . $timezone . "\n");
    exit(1);
}

$utc    = new DateTimeZone('UTC');
$handle = fopen($file, 'rb');
if ($handle === false) {
    fwrite(STDERR, '[ERROR] Ouverture impossible : ' . $file . "\n");
    exit(1);
}

$weights  = [];
$seen     = [];
$merged   = 0;
$rejected = 0;
$line     = 1; // l'en-tête est consommé par RowSource

try {
    foreach (RowSource::fromCsv($handle) as $row) {
        $line++;

        $rawTs    = trim((string) ($row[$tsCol] ?? ''));
        $rawValue = (string) ($row[$valueCol] ?? '');

        if ($rawTs === '' || $rawValue === '') {
            $rejected++;
            continue;
        }

        // Un pourcentage reste un poids : seule la pondération relative compte, donc
        // le signe « % » est retiré sans conversion.
        $rawValue = str_replace(['%', ' ', "\u{A0}"], '', $rawValue);
        if (!is_numeric($rawValue)) {
            $rejected++;
            continue;
        }

        $fraction = (float) $rawValue;
        if ($fraction < 0.0) {
            $rejected++;
            continue;
        }

        try {
            // Horodatage nu => fuseau local du profil ; horodatage avec offset => tel quel.
            $instant = new DateTimeImmutable($rawTs, $tz);
        } catch (\Throwable $e) {
            $rejected++;
            continue;
        }

        $slot = $instant->setTimezone($utc)->setTime(
            (int) $instant->setTimezone($utc)->format('G'),
            $resolution === 60 ? 0 : intdiv((int) $instant->setTimezone($utc)->format('i'), 15) * 15,
            0
        );
        $key = $slot->format('Y-m-d H:i:00');

        // Plusieurs lignes dans le même créneau : les poids se SOMMENT, ils ne
        // s'écrasent pas. C'est le cas d'un CSV quart-horaire importé en --resolution=60,
        // où les quatre quarts d'une heure doivent former le poids de cette heure —
        // retenir la dernière valeur en perdrait les trois autres en silence. Même
        // agrégation que côté calcul, dans MonthlyIndexedPriceCalculator.
        $weights[$key] = [
            'slot_start' => $slot,
            'fraction'   => ($weights[$key]['fraction'] ?? 0.0) + $fraction,
        ];
        $merged += isset($seen[$key]) ? 1 : 0;
        $seen[$key] = true;
    }
} finally {
    fclose($handle);
}

if ($weights === []) {
    fwrite(STDERR, "[ERROR] Aucun point exploitable. Vérifiez --ts-col / --value-col.\n");
    exit(1);
}

$values = array_map(static fn (array $w): float => $w['fraction'], $weights);
$keys   = array_keys($weights);
sort($keys);

fwrite(STDOUT, sprintf(
    "[PROFIL] %s / %s — %d point(s) au pas de %d min, %s → %s%s\n",
    $code,
    $country,
    count($weights),
    $resolution,
    $keys[0],
    $keys[count($keys) - 1],
    $rejected > 0 ? sprintf(' (%d ligne(s) rejetée(s))', $rejected) : ''
));
if ($merged > 0) {
    fwrite(STDOUT, sprintf(
        "[PROFIL] %d ligne(s) agrégée(s) dans un créneau déjà rencontré (poids sommés).\n",
        $merged
    ));
}
fwrite(STDOUT, sprintf(
    "[PROFIL] somme des poids = %.6f, min = %.9f, max = %.9f\n",
    array_sum($values),
    min($values),
    max($values)
));

if (!$execute) {
    fwrite(STDOUT, "[DRY-RUN] Rien n'a été écrit. Relancez avec --execute pour importer.\n");
    exit(0);
}

try {
    $pdo   = (new Database($config['database']))->pdo();
    $count = (new LoadProfileRepository($pdo))
        ->upsertWeights($code, $country, $resolution, array_values($weights), $source);
} catch (\Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("[OK] %d point(s) enregistré(s) pour %s / %s.\n", $count, $code, $country));
exit(0);
