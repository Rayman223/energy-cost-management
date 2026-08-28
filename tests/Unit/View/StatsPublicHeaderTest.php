<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\Support\Url;
use App\View\ViewFactory;
use PHPUnit\Framework\TestCase;

/**
 * /stats est la première page du site rendue à un visiteur ANONYME tout en
 * partageant la coquille applicative (#8). L'en-tête mutualisé supposait jusqu'ici
 * un utilisateur connecté : il porte la navigation vers les pages privées et un
 * formulaire de déconnexion avec jeton CSRF.
 *
 * Ce test n'est donc pas cosmétique. Il vérifie qu'un anonyme ne reçoit ni ces
 * liens (qui le renverraient tous vers /login, au mieux inutiles, au pire
 * trompeurs) ni un formulaire de déconnexion pour une session inexistante — et,
 * symétriquement, que le paramètre par défaut laisse les huit pages appelantes
 * historiques strictement inchangées.
 */
final class StatsPublicHeaderTest extends TestCase
{
    /** Pages privées dont aucun lien ne doit apparaître pour un anonyme. */
    private const PRIVATE_PAGES = ['meter-readings', 'tariffs', 'reconciliation', 'advances', 'account'];

    /**
     * Lien tel que le template le produit. Construit via {@see Url::to()} et non
     * codé en dur : sous PHPUnit la racine dérive de SCRIPT_NAME (« vendor/bin »),
     * et un href littéral « /account » ne matcherait jamais rien — le test
     * passerait au vert sans rien vérifier.
     */
    private function link(string $page): string
    {
        return 'href="' . Url::to($page) . '"';
    }

    private function header(?bool $authenticated): string
    {
        $view = ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', 'fr');
        $data = [
            'subtitle'  => 'Sous-titre',
            'current'   => 'stats',
            'isAdmin'   => false,
            'available' => ['fr', 'en'],
            'timezone'  => 'UTC',
        ];

        if ($authenticated !== null) {
            $data['authenticated'] = $authenticated;
        }

        return $view->render('partials/_header', $data);
    }

    public function testAnonymousHeaderExposesNoPrivateNavigation(): void
    {
        $html = $this->header(false);

        foreach (self::PRIVATE_PAGES as $page) {
            self::assertStringNotContainsString(
                $this->link($page),
                $html,
                "Un visiteur anonyme ne doit pas se voir proposer /{$page}.",
            );
        }
    }

    public function testAnonymousHeaderHasNoSignOutForm(): void
    {
        $html = $this->header(false);

        self::assertStringNotContainsString('logout-form', $html);
        self::assertStringNotContainsString('auth/logout', $html);
        // Le champ CSRF part avec le formulaire : rien à protéger, rien à émettre.
        self::assertStringNotContainsString('name="_csrf"', $html);
    }

    public function testAnonymousHeaderOffersSignInAndStats(): void
    {
        $html = $this->header(false);

        self::assertStringContainsString($this->link('login'), $html);
        self::assertStringContainsString($this->link('stats'), $html);
    }

    public function testAdminIconNeverLeaksToAnonymousVisitors(): void
    {
        $view = ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', 'fr');
        // isAdmin à true ET authenticated à false : un état incohérent ne doit pas
        // suffire à faire apparaître le back-office.
        $html = $view->render('partials/_header', [
            'subtitle'      => 'Sous-titre',
            'current'       => 'stats',
            'isAdmin'       => true,
            'available'     => ['fr'],
            'authenticated' => false,
        ]);

        self::assertStringNotContainsString($this->link('admin'), $html);
    }

    public function testOmittingTheFlagKeepsTheAuthenticatedHeaderUnchanged(): void
    {
        // Les huit pages appelantes historiques omettent le paramètre : leur rendu
        // doit rester octet pour octet celui d'avant l'ajout du mode anonyme.
        self::assertSame($this->header(true), $this->header(null));

        $html = $this->header(null);
        foreach (self::PRIVATE_PAGES as $page) {
            self::assertStringContainsString($this->link($page), $html);
        }
        self::assertStringContainsString('logout-form', $html);
    }
}
