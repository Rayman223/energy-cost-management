<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Hypothèse de répartition jour/nuit de la DÉCHARGE d'une batterie (#26).
 *
 * Un relevé journalier ne dit pas à quelle heure la batterie s'est vidée. Or le
 * kWh qu'elle a évité de prélever ne vaut pas le même prix selon qu'il aurait été
 * facturé en T1 (jour) ou en T2 (nuit) : il faut donc décider ce qu'on suppose,
 * plutôt que de deviner en silence.
 *
 * Les quatre profils :
 *   ImportMix  la décharge suit le mix T1/T2 des kWh RÉELLEMENT importés sur la
 *              période — aucune hypothèse supplémentaire, mais ignore qu'une
 *              batterie est justement pilotée pour couvrir la pointe ;
 *   T1         tout est supposé remplacer de l'import en heures pleines (optimiste) ;
 *   T2         tout est supposé remplacer de l'import en heures creuses (prudent) ;
 *   Ratio      part T1 fixée par l'utilisateur, pour un pilotage connu.
 *
 * Volontairement une hypothèse DÉCLARÉE et non une déduction : le chiffre affiché
 * doit rester explicable à l'utilisateur qui l'a paramétré.
 *
 * @see Battery le matériel qui porte le profil et, pour {@see self::Ratio}, sa part T1.
 */
enum BatteryDischargeProfile: string
{
    case ImportMix = 'import_mix';
    case T1        = 't1';
    case T2        = 't2';
    case Ratio     = 'ratio';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    /**
     * Repli sûr : une valeur inconnue devient le mix réel des imports, le seul
     * profil qui n'ajoute aucune hypothèse par-dessus les données mesurées.
     */
    public static function fromStringOrDefault(string $value): self
    {
        return self::tryFrom($value) ?? self::ImportMix;
    }

    /**
     * Ce profil exige-t-il une part T1 saisie ? Seul {@see self::Ratio} la
     * consomme : la laisser obligatoire ailleurs ferait saisir un chiffre qui
     * n'entrerait dans aucun calcul.
     */
    public function requiresT1Share(): bool
    {
        return $this === self::Ratio;
    }

    /** Clé de traduction du libellé, pour le formulaire et le bilan. */
    public function labelKey(): string
    {
        return 'battery.discharge_profile.' . $this->value;
    }
}
