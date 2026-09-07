<?php
declare(strict_types=1);

use App\Domain\Meter;
use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\Exception\LimitReachedException;
use App\Repository\MeterRepository;
use App\Repository\UserRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\Security\UserContext;
use App\Support\Adsense;
use App\Support\Dates;
use App\Support\DiscordLink;
use App\Support\DonateLink;
use App\Support\Limits;
use App\Support\LocaleContext;

// Bootstrap isolé : une configuration injoignable (ex. config.php absent) dégrade
// en 503 propre plutôt qu'en fatal exposant un stack trace (#130 C6).
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

$db      = new Database($config['database']);
$pdo     = $db->pdo();
$userId  = UserContext::currentWebUserId($pdo, $config);
$users   = new UserRepository($pdo);
$isAdmin = ($users->findById($userId)?->isAdmin()) ?? false;

$profile = $users->getProfile($userId);
$view    = LocaleContext::viewFor($config, $users, $userId, $profile?->locale, __DIR__ . '/../templates');

$error   = null;
$success = null;

$maxPerEnergy = Limits::metersPerEnergy($config);
$meterRepo    = new MeterRepository($pdo, $userId, $maxPerEnergy);

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (Csrf::validate($_POST['_csrf'] ?? null) === false) {
            throw new \RuntimeException($view->t('common.csrf_invalid'));
        }

        if ($action === 'save') {
            // Le libellé est FACULTATIF : vide, il est dérivé à l'affichage dans la
            // langue du lecteur (cf. App\Domain\Meter). Le tronquer plutôt que le
            // refuser suit la saisie des batteries — un nom trop long est une
            // maladresse, pas une erreur.
            $label = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, Meter::MAX_LABEL);

            // Fermeture (#55) : facultative, et réversible — la vider rouvre le
            // compteur. Le format est revérifié ici, `<input type="date">` n'étant
            // qu'une aide de saisie : un POST direct peut envoyer n'importe quoi.
            // Parsée en UTC comme toutes les dates du projet ; c'est au moment de
            // l'opposer à une écriture qu'elle se lit dans le fuseau du foyer.
            $closedRaw = trim((string) ($_POST['closed_on'] ?? ''));
            $closedOn  = null;
            if ($closedRaw !== '') {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $closedRaw, Dates::utc());
                if ($parsed === false || $parsed->format('Y-m-d') !== $closedRaw) {
                    throw new \InvalidArgumentException($view->t('meters.invalid_closed_on'));
                }
                $closedOn = $parsed;
            }

            $editId = filter_var($_POST['meter_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $editId = $editId === false ? null : $editId;

            if ($editId !== null) {
                // Sans ce contrôle, une édition ne visant aucune ligne — compteur
                // supprimé depuis un autre onglet, identifiant appartenant à
                // quelqu'un d'autre — afficherait « ✓ enregistré » sans rien écrire.
                $existing = $meterRepo->find($editId);
                if ($existing === null) {
                    throw new \InvalidArgumentException($view->t('meters.invalid_meter'));
                }

                // Énergie reprise de l'existant : elle est immuable — elle décide
                // de quelle table viennent les relevés — et la relire ici évite
                // qu'un POST forgé l'écrase.
                $meterRepo->update($editId, new Meter(
                    id:         $editId,
                    energyType: $existing->energyType,
                    label:      $label,
                    closedOn:   $closedOn,
                ));
            } else {
                $energyType = (string) ($_POST['energy_type'] ?? '');
                if (!Meter::isEnergy($energyType)) {
                    throw new \InvalidArgumentException($view->t('meters.invalid_energy'));
                }

                $meterRepo->insert(new Meter(id: 0, energyType: $energyType, label: $label, closedOn: $closedOn));
            }

            $success = $view->t('meters.saved');
        }

        if ($action === 'delete') {
            $id = filter_var($_POST['meter_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || !$meterRepo->owns($id)) {
                // Même garde qu'à l'édition : une suppression sans cible ne doit pas
                // se féliciter d'un travail qu'elle n'a pas fait.
                throw new \InvalidArgumentException($view->t('meters.invalid_meter'));
            }

            // La cascade FK emporte registres, index électriques et relevés gaz/eau
            // du compteur : c'est voulu, irréversible, et annoncé chiffré dans la
            // confirmation.
            $meterRepo->delete($id);
            $success = $view->t('meters.deleted');
        }
    } catch (LimitReachedException $e) {
        // Le repository porte le refus, la route porte la phrase : lui seul connaît
        // la langue du lecteur.
        $error = $view->t('meters.limit_reached', ['limit' => $e->limit]);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Compteur en cours d'édition ───────────────────────────────────────────
// Un enregistrement réussi referme le formulaire : le POST reposte sur l'URL
// courante, `?edit=` compris, et rouvrirait sinon indéfiniment la même édition
// (même comportement que /batteries et /advances).
$editId  = $success !== null
    ? null
    : filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editing = ($editId !== false && $editId !== null) ? $meterRepo->find($editId) : null;

$meters = $meterRepo->listAll();

// Compteurs déjà déclarés par énergie : la page affiche « 2 / 5 » et retire du
// sélecteur les énergies saturées. Compté en PHP sur le parc déjà chargé — une
// requête par énergie pour trois entiers serait du gaspillage.
$countsByEnergy = array_fill_keys(Meter::ENERGIES, 0);
foreach ($meters as $meter) {
    ++$countsByEnergy[$meter->energyType];
}

echo $view->render('meters', [
    'oidcEnabled'    => AuthGuard::isOidcEnabled($config),
    'discordUrl'     => DiscordLink::inviteUrl($config),
    'donateUrl'      => DonateLink::url($config),
    'adsenseClient'  => Adsense::clientId($config),
    'error'          => $error,
    'success'        => $success,
    'isAdmin'        => $isAdmin,
    'meters'         => $meters,
    'readingCounts'  => $meterRepo->readingCounts(),
    'countsByEnergy' => $countsByEnergy,
    'maxPerEnergy'   => $maxPerEnergy,
    'editing'        => $editing,
    // Jour civil de l'utilisateur : c'est lui qui décide si un compteur est
    // « fermé » à l'écran. Le fuseau de stockage (UTC) donnerait un verdict
    // décalé pour qui vit à l'est de Greenwich le soir d'une fermeture.
    'today'          => Dates::todayIn($profile->timezone ?? 'UTC'),
    'available'      => Locale::available($config),
    'timezone'       => $profile->timezone ?? null,
]);
