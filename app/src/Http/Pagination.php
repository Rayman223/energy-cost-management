<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Fenêtre de pagination d'une action « données » (#257) : page demandée, taille
 * de page bornée, et enveloppe de réponse `{ items, total, page, per_page }`.
 *
 * Extrait des contrôleurs pour rester testable unitairement : les repositories
 * concrets sont `final` (non mockables), donc la logique de bornage/clamp ne peut
 * pas être couverte à travers eux.
 */
final class Pagination
{
    /** Taille de page par défaut des historiques de relevés. */
    public const DEFAULT_PER_PAGE = 25;

    /**
     * Plafond de `per_page` : borne le volume d'une réponse même si le paramètre
     * est forgé à la main dans l'URL.
     */
    public const MAX_PER_PAGE = 200;

    private function __construct(
        private readonly int $page,
        private readonly int $perPage,
    ) {
    }

    /**
     * Lit `page` (défaut 1, minimum 1) et `per_page` (défaut $defaultPerPage,
     * plafonné à MAX_PER_PAGE).
     *
     * `queryInt()` caste, donc `per_page=abc` comme `per_page=` (vide) valent 0 :
     * on retombe alors sur le défaut, et non sur le plancher 1 — une page d'un
     * seul relevé pour une valeur qui n'exprime aucune intention serait un piège,
     * et le contrat annonce « défaut 25 ». Une valeur négative suit la même règle.
     */
    public static function fromRequest(Request $request, int $defaultPerPage = self::DEFAULT_PER_PAGE): self
    {
        $perPage = $request->queryInt('per_page', $defaultPerPage);
        // `max(1, …)` ne couvre plus que le cas d'un $defaultPerPage fautif : sans
        // lui, un défaut ≤ 0 ferait diviser par zéro dans clampTo()/offset().
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage > 0 ? $perPage : $defaultPerPage));

        return new self(max(1, $request->queryInt('page', 1)), $perPage);
    }

    public function page(): int
    {
        return $this->page;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * Ramène la page demandée à la dernière page non vide. Sans ce clamp, la
     * suppression du dernier relevé d'une page (ou un `page=` forgé) afficherait
     * un tableau vide alors que l'historique en contient encore.
     */
    public function clampTo(int $total): self
    {
        $lastPage = max(1, (int) ceil($total / $this->perPage));

        return $this->page <= $lastPage ? $this : new self($lastPage, $this->perPage);
    }

    /**
     * L'enveloppe est écrite en premier : l'union de tableaux garde la valeur de
     * gauche, donc `$extra` ne peut qu'AJOUTER des champs. Dans l'autre sens, un
     * appelant passant `page` ou `total` écraserait le contrat en silence.
     *
     * @param  list<mixed>         $items
     * @param  array<string,mixed> $extra Champs additionnels (ex. `previous` pour
     *                                    l'électricité, cf. deltas de frontière).
     * @return array<string,mixed>
     */
    public function envelope(array $items, int $total, array $extra = []): array
    {
        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $this->page,
            'per_page' => $this->perPage,
        ] + $extra;
    }
}
