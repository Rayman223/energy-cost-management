<?php

declare(strict_types=1);

namespace App\Integration;

use DateTimeImmutable;
use PDO;

/**
 * Contrat d'un « connecteur d'export » : une intégration sortante (push) opt-in
 * par utilisateur vers un site externe (EnergyID, …). Le registre expose la
 * liste des modules ; le cron d'export appelle {@see self::syncUser()} pour
 * chaque utilisateur ayant activé le module, et la page « Mon compte » utilise
 * {@see self::statusFor()} / {@see self::defaultSettings()} pour l'opt-in.
 */
interface ExportModuleInterface
{
    /** Clé stable du module = user_integrations.module_key + préfixe i18n `integration.<key>.*`. */
    public function key(): string;

    /** Kill-switch global (config) : le cron saute le module si false. N'affecte pas l'opt-in web. */
    public function isGloballyEnabled(): bool;

    /**
     * Réglages initiaux appliqués à l'activation (ex. deviceId dérivé de l'user).
     * Réappliqués (fusionnés) à chaque ré-enable — source unique de dérivation.
     *
     * @return array<string, mixed>
     */
    public function defaultSettings(int $userId): array;

    /**
     * Statut affiché sur la carte « Mon compte ».
     *
     * @param array{enabled: bool, settings: array<string, mixed>}|null $row ligne user_integrations, ou null si jamais activé
     */
    public function statusFor(int $userId, ?array $row): IntegrationStatus;

    /**
     * Synchronise UN utilisateur (appelée par le cron d'export). Retourne un
     * patch de settings que le cron persiste (ex. claimed_at au premier succès).
     * Le PDO est fourni ici (et non à la construction) pour que les modules
     * restent instanciables sans connexion — le cron peut ainsi filtrer sur
     * {@see self::isGloballyEnabled()} avant d'ouvrir la base.
     *
     * @param array<string, mixed>   $settings settings JSON courants de l'utilisateur
     * @param \Closure(string): void $logger   journalisation préfixée par le cron
     * @return array{ok: bool, settingsPatch: array<string, mixed>}
     */
    public function syncUser(PDO $pdo, int $userId, array $settings, DateTimeImmutable $until, \Closure $logger): array;
}
