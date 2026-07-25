<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\View\ViewFactory;
use PHPUnit\Framework\TestCase;

/**
 * Icône du bouton de connexion OIDC : elle est choisie par la clé du fournisseur
 * (celle de `config.php`, stockée en `users.provider`) et non par son libellé. Un
 * IdP sans logo dédié doit retomber sur l'icône « clé » neutre.
 */
final class OidcProviderIconTest extends TestCase
{
    private function render(string $key): string
    {
        $view = ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', 'fr');

        return $view->partial('oidc-provider-icon', ['key' => $key]);
    }

    public function testAuthentikUsesItsBrandColour(): void
    {
        $html = $this->render('authentik');

        self::assertStringContainsString('#FD4B2D', $html);
        self::assertStringNotContainsString('currentColor', $html);
    }

    public function testGoogleKeepsItsOwnLogo(): void
    {
        $html = $this->render('google');

        self::assertStringContainsString('#4285F4', $html);
        self::assertStringNotContainsString('#FD4B2D', $html);
    }

    public function testUnknownProviderFallsBackToTheNeutralKeyIcon(): void
    {
        $html = $this->render('zitadel');

        self::assertStringContainsString('currentColor', $html);
        self::assertStringNotContainsString('#FD4B2D', $html);
    }

    /**
     * Toutes les variantes restent un SVG décoratif : masqué aux lecteurs d'écran
     * (le libellé textuel du bouton porte l'information) et non focusable.
     */
    public function testEveryVariantIsADecorativeSvg(): void
    {
        foreach (['authentik', 'google', 'microsoft', 'microsoftonline', 'keycloak'] as $key) {
            $html = $this->render($key);

            self::assertStringContainsString('class="btn-provider-icon"', $html, $key);
            self::assertStringContainsString('aria-hidden="true"', $html, $key);
            self::assertStringContainsString('focusable="false"', $html, $key);
        }
    }
}
