<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\LoadProfileRepository;
use App\Service\Import\LoadProfileCsvParser;

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
 * PENDANT WEB : la page /admin/load-profiles importe le même format, par le même
 * parseur ({@see LoadProfileCsvParser}, #101), et liste les mois manquants.
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

$args    = $parseArgs($argv);
$file    = (string) ($args['file'] ?? '');
$source  = (string) ($args['source'] ?? 'synergrid');
$execute = isset($args['execute']);

if ($file === '') {
    fwrite(STDERR, "[ERROR] --file=<chemin> est requis.\n");
    exit(1);
}
if (!is_readable($file)) {
    fwrite(STDERR, '[ERROR] Fichier illisible : ' . $file . "\n");
    exit(1);
}

// Validation et lecture partagées avec la page /admin/load-profiles (#101).
try {
    $code       = LoadProfileCsvParser::normalizeCode((string) ($args['code'] ?? LoadProfileRepository::DEFAULT_CODE));
    $country    = LoadProfileCsvParser::normalizeCountry((string) ($args['country'] ?? LoadProfileRepository::DEFAULT_COUNTRY));
    $resolution = LoadProfileCsvParser::checkResolution((int) ($args['resolution'] ?? 15));
    $tz         = LoadProfileCsvParser::timezone((string) ($args['timezone'] ?? LoadProfileCsvParser::DEFAULT_TIMEZONE));
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    exit(1);
}

$handle = fopen($file, 'rb');
if ($handle === false) {
    fwrite(STDERR, '[ERROR] Ouverture impossible : ' . $file . "\n");
    exit(1);
}

try {
    $result = (new LoadProfileCsvParser())->parse(
        $handle,
        (string) ($args['ts-col'] ?? LoadProfileCsvParser::DEFAULT_TS_COL),
        (string) ($args['value-col'] ?? LoadProfileCsvParser::DEFAULT_VALUE_COL),
        $resolution,
        $tz,
    );
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    fclose($handle);
}

fwrite(STDOUT, sprintf(
    "[PROFIL] %s / %s — %d point(s) au pas de %d min, %s → %s%s\n",
    $code,
    $country,
    $result->count(),
    $resolution,
    $result->firstSlot(),
    $result->lastSlot(),
    $result->rejected > 0 ? sprintf(' (%d ligne(s) rejetée(s))', $result->rejected) : ''
));
if ($result->merged > 0) {
    fwrite(STDOUT, sprintf(
        "[PROFIL] %d ligne(s) agrégée(s) dans un créneau déjà rencontré (poids sommés).\n",
        $result->merged
    ));
}
fwrite(STDOUT, sprintf(
    "[PROFIL] somme des poids = %.6f, min = %.9f, max = %.9f\n",
    $result->sum(),
    $result->min(),
    $result->max()
));

if (!$execute) {
    fwrite(STDOUT, "[DRY-RUN] Rien n'a été écrit. Relancez avec --execute pour importer.\n");
    exit(0);
}

try {
    $pdo   = (new Database($config['database']))->pdo();
    $count = (new LoadProfileRepository($pdo))
        ->upsertWeights($code, $country, $resolution, $result->weights, $source);
} catch (\Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("[OK] %d point(s) enregistré(s) pour %s / %s.\n", $count, $code, $country));
exit(0);
