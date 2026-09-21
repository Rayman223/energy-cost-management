<?php

declare(strict_types=1);

/**
 * Jeu de données de DÉVELOPPEMENT pour la page /stats (#85).
 *
 * Les agrégats publics sont k-anonymisés en SQL : un pays n'est publié qu'à
 * partir de cinq foyers contributeurs ({@see StatisticsRepositoryInterface::MIN_HOUSEHOLDS}).
 * Sur une base de développement, aucun pays n'atteint ce seuil — la page affiche
 * donc « pas encore assez de données », la liste déroulante des pays disparaît
 * (un `<select>` vide serait une impasse) et le récapitulatif global reste
 * masqué. C'est le comportement attendu, mais il rend l'interface intestable à
 * la main.
 *
 * Ce script crée les foyers manquants : des comptes marqués `oidc_iss =
 * 'dev-seed'`, leur profil (pays, contribution active) et une grille
 * électricité fixe à un tarif choisi. De quoi faire apparaître la liste des
 * pays, le récapitulatif tous pays et les écarts par pays.
 *
 * Il ne crée PAS de relevés : les consommations exigent douze mois d'historique
 * (et au moins trois mois pour l'électricité, quatre-vingt-dix jours pour le gaz
 * et l'eau). Les tarifs suffisent à exercer l'interface.
 *
 * Usage :
 *   php app/scripts/seed_dev_stats.php                    # dry-run, n'écrit rien
 *   php app/scripts/seed_dev_stats.php --execute
 *   php app/scripts/seed_dev_stats.php --purge --execute  # retire le jeu de test
 *
 * Options :
 *   --countries=BE:0.34,FR:0.24  Pays et tarif TTC au kWh (défaut : ce couple).
 *   --households=5               Foyers par pays (défaut : le seuil k lui-même).
 *   --purge                      Supprime le jeu de test, sans rien recréer.
 *   --execute                    Écrit réellement (défaut : dry-run).
 *   --force                      Saute la confirmation (usage non interactif).
 *
 * GARDE-FOU : le script insère des comptes fictifs qui COMPTENT dans les
 * statistiques publiques — les lâcher en production fausserait des chiffres
 * présentés comme réels. Une écriture demande donc une confirmation : retaper
 * le nom de la base. Un nom qui contient déjà « test », « dev », « local » ou
 * « sandbox » en dispense, et `--force` la saute (nécessaire hors terminal,
 * par exemple depuis un Makefile).
 *
 * Le nom n'est volontairement pas un critère de refus : rien ne garantit qu'une
 * base de développement s'appelle autrement qu'en production, et un garde-fou
 * qui laisse passer les deux vaut moins que pas de garde-fou du tout.
 *
 * Rejouable : chaque exécution purge d'abord le jeu précédent. Les grilles
 * tarifaires n'ont pas de clé unique, un réensemencement sans purge les
 * dupliquerait.
 */

use App\Infrastructure\Database;
use App\Repository\Contract\StatisticsRepositoryInterface;
use App\Support\CliArguments;

require_once __DIR__ . '/../../vendor/autoload.php';

/** Marqueur d'appartenance au jeu de test : la purge ne s'appuie que sur lui. */
const SEED_ISSUER = 'dev-seed';

/** Noms de base assez explicites pour dispenser de la confirmation. */
const OBVIOUSLY_DISPOSABLE_PATTERN = '/(test|dev|local|sandbox)/i';

$cliArgs = $argv ?? [];
$arg     = static fn (string $name): ?string => CliArguments::value($cliArgs, $name);

$dryRun   = !in_array('--execute', $cliArgs, true);
$purgeOnly = in_array('--purge', $cliArgs, true);
$force    = in_array('--force', $cliArgs, true);

// ── Pays et tarifs demandés ──────────────────────────────────────────────────
/** @var array<string, float> $countries code ISO => tarif TTC au kWh */
$countries = [];
foreach (explode(',', (string) ($arg('countries') ?? 'BE:0.34,FR:0.24')) as $pair) {
    $parts = explode(':', trim($pair), 2);
    $iso   = strtoupper(trim($parts[0]));
    if (preg_match('/^[A-Z]{2}$/', $iso) !== 1) {
        fwrite(STDERR, '[FATAL] --countries attend des codes ISO 3166-1 alpha-2 : ' . $pair . PHP_EOL);
        exit(1);
    }
    $rate = isset($parts[1]) ? (float) $parts[1] : 0.30;
    if ($rate <= 0.0) {
        fwrite(STDERR, '[FATAL] tarif invalide pour ' . $iso . ' : il doit être strictement positif.' . PHP_EOL);
        exit(1);
    }
    $countries[$iso] = $rate;
}

$households = (int) ($arg('households') ?? StatisticsRepositoryInterface::MIN_HOUSEHOLDS);
if ($households < 1 || $households > 500) {
    fwrite(STDERR, '[FATAL] --households doit être compris entre 1 et 500.' . PHP_EOL);
    exit(1);
}
if ($households < StatisticsRepositoryInterface::MIN_HOUSEHOLDS) {
    // Pas une erreur : c'est précisément ainsi qu'on teste l'état « sous le seuil ».
    fwrite(STDOUT, sprintf(
        "[NOTE] %d foyers par pays, sous le seuil de %d : aucun pays ne sera publié.\n",
        $households,
        StatisticsRepositoryInterface::MIN_HOUSEHOLDS,
    ));
}

// ── Bootstrap et garde-fou ───────────────────────────────────────────────────
try {
    /** @var array<string, mixed> $config */
    $config = require __DIR__ . '/../bootstrap.php';
} catch (\Throwable $e) {
    fwrite(STDERR, '[FATAL] configuration illisible : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/** @var array<string, mixed> $dbConfig */
$dbConfig = is_array($config['database'] ?? null) ? $config['database'] : [];
$dbName   = (string) ($dbConfig['name'] ?? '');

// Confirmation : demandée dès que le nom ne dit pas de lui-même que la base est
// jetable. Un dry-run n'écrit rien, il s'en dispense.
$needsConfirmation = !$dryRun
    && !$force
    && preg_match(OBVIOUSLY_DISPOSABLE_PATTERN, $dbName) !== 1;

if ($needsConfirmation) {
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, sprintf(
            "[FATAL] « %s » ne s'annonce pas comme une base jetable, et il n'y a pas de terminal\n"
            . "        pour confirmer. Ce script insère des foyers fictifs qui COMPTENT dans les\n"
            . "        statistiques publiques : en production, il fausserait des chiffres présentés\n"
            . "        comme réels. Relancer depuis un terminal, ou avec --force en connaissance de cause.\n",
            $dbName,
        ));
        exit(1);
    }

    fwrite(STDOUT, sprintf(
        "Des foyers fictifs vont être insérés dans « %s » et compteront dans les\n"
        . "statistiques publiques de cette instance.\n"
        . "Retaper le nom de la base pour confirmer (Ctrl-C pour abandonner) : ",
        $dbName,
    ));

    $answer = fgets(STDIN);
    if ($answer === false || trim($answer) !== $dbName) {
        fwrite(STDERR, "[ABANDON] nom non confirmé, rien n'a été écrit.\n");
        exit(1);
    }
}

try {
    $pdo = (new Database($dbConfig))->pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, '[FATAL] base injoignable : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf("Base : %s%s\n", $dbName, $dryRun ? '  (DRY-RUN, aucune écriture)' : ''));

/**
 * Supprime le jeu de test. `tariff_grids` n'a volontairement pas de clé
 * étrangère vers `users` (cf. schema.sql) : ses lignes ne partent donc pas en
 * cascade et doivent être retirées explicitement, avant les comptes.
 */
$purge = static function (PDO $pdo): int {
    $pdo->prepare(
        'DELETE l FROM tariff_grid_lines l
         JOIN tariff_grids g ON g.id = l.tariff_grid_id
         JOIN users u ON u.id = g.user_id
         WHERE u.oidc_iss = :iss',
    )->execute(['iss' => SEED_ISSUER]);

    $pdo->prepare(
        'DELETE g FROM tariff_grids g
         JOIN users u ON u.id = g.user_id
         WHERE u.oidc_iss = :iss',
    )->execute(['iss' => SEED_ISSUER]);

    // user_profiles suit en cascade (FK vers users).
    $statement = $pdo->prepare('DELETE FROM users WHERE oidc_iss = :iss');
    $statement->execute(['iss' => SEED_ISSUER]);

    return $statement->rowCount();
};

$existing = (int) $pdo->query(
    'SELECT COUNT(*) FROM users WHERE oidc_iss = ' . $pdo->quote(SEED_ISSUER),
)->fetchColumn();

$pdo->beginTransaction();

try {
    $removed = $purge($pdo);

    if ($purgeOnly) {
        fwrite(STDOUT, sprintf("Purge : %d compte(s) de test retiré(s).\n", $removed));
    } else {
        if ($existing > 0) {
            fwrite(STDOUT, sprintf("Jeu précédent retiré (%d compte(s)).\n", $removed));
        }

        $insertUser = $pdo->prepare(
            'INSERT INTO users (oidc_iss, oidc_sub, provider, display_name, status)
             VALUES (:iss, :sub, :provider, :name, \'active\')',
        );
        $insertProfile = $pdo->prepare(
            'INSERT INTO user_profiles (user_id, country, currency, locale, stats_opt_out)
             VALUES (:user_id, :country, \'EUR\', \'fr\', 0)',
        );
        $insertGrid = $pdo->prepare(
            'INSERT INTO tariff_grids
                 (user_id, energy_type, pricing_mode, country, currency, vat_rate, name, valid_from)
             VALUES (:user_id, \'electricity\', \'fixed\', :country, \'EUR\', 6.00, :name,
                     DATE_SUB(CURDATE(), INTERVAL 1 YEAR))',
        );
        // `per_kwh` porte un poids de 1 dans le tarif unitaire publié : le
        // montant saisi est exactement le prix TTC au kWh qu'affichera /stats.
        $insertLine = $pdo->prepare(
            'INSERT INTO tariff_grid_lines (tariff_grid_id, line_key, component_kind, amount_per_kwh)
             VALUES (:grid_id, \'energy_flat\', \'per_kwh\', :amount)',
        );

        $created = 0;
        foreach ($countries as $iso => $rate) {
            for ($n = 1; $n <= $households; $n++) {
                $insertUser->execute([
                    'iss'      => SEED_ISSUER,
                    'sub'      => $iso . '-' . $n,
                    'provider' => 'dev',
                    'name'     => sprintf('Foyer de test %s %d', $iso, $n),
                ]);
                $userId = (int) $pdo->lastInsertId();

                $insertProfile->execute(['user_id' => $userId, 'country' => $iso]);

                $insertGrid->execute([
                    'user_id' => $userId,
                    'country' => $iso,
                    'name'    => sprintf('Grille de test %s', $iso),
                ]);
                $insertLine->execute([
                    'grid_id' => (int) $pdo->lastInsertId(),
                    'amount'  => $rate,
                ]);

                $created++;
            }

            fwrite(STDOUT, sprintf("  %s : %d foyers à %.4f €/kWh TTC\n", $iso, $households, $rate));
        }

        fwrite(STDOUT, sprintf("Total : %d foyer(s) contributeur(s).\n", $created));
    }

    if ($dryRun) {
        $pdo->rollBack();
        fwrite(STDOUT, "DRY-RUN : transaction annulée, rien n'a été écrit. Ajouter --execute.\n");
    } else {
        $pdo->commit();
        fwrite(STDOUT, "Écrit.\n");
    }
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, '[FATAL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

exit(0);
