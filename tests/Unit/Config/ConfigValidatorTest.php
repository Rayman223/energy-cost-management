<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\ConfigIssue;
use App\Config\ConfigValidator;
use PHPUnit\Framework\TestCase;

final class ConfigValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fullDatabase(): array
    {
        return [
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'name'     => 'energy',
            'user'     => 'energy_user',
            'password' => 's3cret',
            'charset'  => 'utf8mb4',
        ];
    }

    /**
     * @param list<ConfigIssue> $issues
     * @return list<ConfigIssue>
     */
    private function errorsOf(array $issues): array
    {
        return array_values(array_filter($issues, static fn (ConfigIssue $i): bool => $i->isError()));
    }

    /**
     * @param list<ConfigIssue> $issues
     */
    private function pathsWith(array $issues, string $severity): array
    {
        $paths = [];
        foreach ($issues as $issue) {
            if ($issue->severity === $severity) {
                $paths[] = $issue->path;
            }
        }

        return $paths;
    }

    public function testConfigExampleHasNoError(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../app/config/config.example.php';

        self::assertSame([], $this->errorsOf(ConfigValidator::validate($config)));
    }

    /**
     * Le test le plus important du lot : le config `database`-only généré par la
     * CI (ci.yml) ne doit produire AUCUNE ERROR. Garde anti-récidive du piège
     * « validateur trop strict → CI rouge ».
     */
    public function testDatabaseOnlyConfigHasNoError(): void
    {
        $issues = ConfigValidator::validate(['database' => $this->fullDatabase()]);

        self::assertSame([], $this->errorsOf($issues));
    }

    public function testMissingDatabaseIsError(): void
    {
        $issues = ConfigValidator::validate(['timezone' => 'Europe/Brussels']);

        self::assertNotSame([], $this->errorsOf($issues));
    }

    public function testIncompleteDatabaseIsError(): void
    {
        $issues = ConfigValidator::validate(['database' => ['host' => '127.0.0.1']]);
        $errors = $this->errorsOf($issues);

        self::assertNotSame([], $errors);
        self::assertContains('database.password', $this->pathsWith($errors, ConfigIssue::ERROR));
    }

    public function testMissingOptionalSectionIsWarningNotError(): void
    {
        $issues = ConfigValidator::validate(['database' => $this->fullDatabase()]);

        self::assertSame([], $this->errorsOf($issues));
        self::assertContains('dynamic_prices', $this->pathsWith($issues, ConfigIssue::WARNING));
        self::assertContains('oidc', $this->pathsWith($issues, ConfigIssue::WARNING));
    }

    public function testUnknownKeyIsWarning(): void
    {
        $issues = ConfigValidator::validate([
            'database' => $this->fullDatabase(),
            'i18n'     => ['default_locale' => 'fr', 'availabel' => ['fr']], // typo
        ]);

        self::assertSame([], $this->errorsOf($issues));
        self::assertContains('i18n.availabel', $this->pathsWith($issues, ConfigIssue::WARNING));
    }

    public function testSentinelInActiveSectionIsWarning(): void
    {
        $issues = ConfigValidator::validate([
            'database'       => $this->fullDatabase(),
            'dynamic_prices' => ['enabled' => true, 'security_token' => 'change_me'],
        ]);

        self::assertContains('dynamic_prices.security_token', $this->pathsWith($issues, ConfigIssue::WARNING));
    }

    public function testRuntimeSignalCoversSentinelButNotMissingSection(): void
    {
        // Verrouille le contrat que bootstrap.php exploite : une sentinelle en
        // section active est un signal runtime (journalisée), une section
        // optionnelle absente ne l'est pas (muette en prod, sinon spam CI).
        $issues = ConfigValidator::validate([
            'database'       => $this->fullDatabase(),
            'dynamic_prices' => ['enabled' => true, 'security_token' => 'change_me'],
            // 'oidc' etc. absentes → WARNING missing_section
        ]);

        $byPath = [];
        foreach ($issues as $issue) {
            $byPath[$issue->path] = $issue;
        }

        self::assertArrayHasKey('dynamic_prices.security_token', $byPath);
        self::assertSame(ConfigIssue::KIND_SENTINEL, $byPath['dynamic_prices.security_token']->kind);
        self::assertTrue($byPath['dynamic_prices.security_token']->isRuntimeSignal());

        self::assertArrayHasKey('oidc', $byPath);
        self::assertSame(ConfigIssue::KIND_MISSING_SECTION, $byPath['oidc']->kind);
        self::assertFalse($byPath['oidc']->isRuntimeSignal());
    }

    public function testMovedKeyIsRuntimeSignal(): void
    {
        // Une clé déplacée (P3 : vat_rate/supplier_markup_per_kwh) encore présente
        // en config voit sa valeur ignorée au runtime → doit être un signal
        // journalisé (finding #1), même en section désactivée.
        $issues = ConfigValidator::validate([
            'database'       => $this->fullDatabase(),
            'dynamic_prices' => ['enabled' => false, 'vat_rate' => 0.21, 'supplier_markup_per_kwh' => 0.01],
        ]);

        $byPath = [];
        foreach ($issues as $issue) {
            $byPath[$issue->path] = $issue;
        }

        self::assertArrayHasKey('dynamic_prices.vat_rate', $byPath);
        self::assertSame(ConfigIssue::KIND_MOVED, $byPath['dynamic_prices.vat_rate']->kind);
        self::assertTrue($byPath['dynamic_prices.vat_rate']->isRuntimeSignal());
        // La TVA a rejoint la grille tarifaire (#232), la marge est restée au profil (#228) :
        // chaque message doit pointer la bonne destination, sinon la valeur est reportée au
        // mauvais endroit et ignorée en silence.
        self::assertStringContainsString('/tariffs', $byPath['dynamic_prices.vat_rate']->message);
        self::assertStringContainsString('/account', $byPath['dynamic_prices.supplier_markup_per_kwh']->message);
    }

    public function testSentinelInDisabledSectionIsIgnored(): void
    {
        $issues = ConfigValidator::validate([
            'database'       => $this->fullDatabase(),
            'dynamic_prices' => ['enabled' => false, 'security_token' => 'change_me'],
        ]);

        self::assertNotContains('dynamic_prices.security_token', $this->pathsWith($issues, ConfigIssue::WARNING));
    }

    public function testOidcSentinelIgnoredWhenDisabled(): void
    {
        // oidc.enabled=false ⇒ providers.google.client_id='change_me' sans importance.
        $issues = ConfigValidator::validate([
            'database' => $this->fullDatabase(),
            'oidc'     => [
                'enabled'   => false,
                'providers' => ['google' => ['issuer' => 'https://accounts.google.com', 'client_id' => 'change_me']],
            ],
        ]);

        self::assertNotContains('oidc.providers.google.client_id', $this->pathsWith($issues, ConfigIssue::WARNING));
    }

    public function testOidcSentinelReportedWhenEnabled(): void
    {
        $issues = ConfigValidator::validate([
            'database' => $this->fullDatabase(),
            'oidc'     => [
                'enabled'   => true,
                'providers' => ['google' => ['issuer' => 'https://accounts.google.com', 'client_id' => 'change_me']],
            ],
        ]);

        self::assertContains('oidc.providers.google.client_id', $this->pathsWith($issues, ConfigIssue::WARNING));
    }

    public function testLegacyFlatOidcFormIsNotFlaggedAsUnknownKey(): void
    {
        // Forme plate historique : issuer/client_id directement sous 'oidc'.
        $issues = ConfigValidator::validate([
            'database' => $this->fullDatabase(),
            'oidc'     => [
                'enabled'      => true,
                'issuer'       => 'https://accounts.google.com',
                'client_id'    => 'real-client-id',
                'client_secret'=> 'real-secret',
                'redirect_uri' => '',
                'scopes'       => ['openid', 'profile'],
            ],
        ]);

        foreach ($this->pathsWith($issues, ConfigIssue::WARNING) as $path) {
            self::assertStringStartsNotWith('oidc.issuer', $path);
            self::assertStringStartsNotWith('oidc.client_id', $path);
            self::assertStringStartsNotWith('oidc.client_secret', $path);
        }
    }

    public function testSchemaOnlyDisablesSentinelChecks(): void
    {
        $issues = ConfigValidator::validate([
            'database'       => ['host' => 'h', 'port' => 1, 'name' => 'n', 'user' => 'u', 'password' => 'change_me'],
            'dynamic_prices' => ['enabled' => true, 'security_token' => 'change_me'],
        ], false);

        self::assertNotContains('dynamic_prices.security_token', $this->pathsWith($issues, ConfigIssue::WARNING));
        self::assertNotContains('database.password', $this->pathsWith($issues, ConfigIssue::WARNING));
    }

    // ── Garde-fou timezone (#16) ──────────────────────────────────────────────

    /**
     * `config.timezone` alimente `date_default_timezone_set()` au bootstrap, donc
     * l'interprétation de toute date construite sans fuseau explicite. Le fichier
     * étant gitignoré, la valeur est réglée par machine : rien ne signalait une
     * dérive avant #16.
     */
    public function testNonUtcTimezoneIsWarned(): void
    {
        $issues = ConfigValidator::validate(['database' => $this->fullDatabase(), 'timezone' => 'Europe/Brussels']);

        self::assertContains('timezone', $this->pathsWith($issues, ConfigIssue::WARNING));
        self::assertSame([], $this->errorsOf($issues));
    }

    /** Identifiant inconnu : `date_default_timezone_set()` le rejette sans rien changer. */
    public function testUnknownTimezoneIsWarned(): void
    {
        $issues = ConfigValidator::validate(['database' => $this->fullDatabase(), 'timezone' => 'Mars/Olympus_Mons']);

        self::assertContains('timezone', $this->pathsWith($issues, ConfigIssue::WARNING));
    }

    public function testUtcTimezoneIsSilent(): void
    {
        $issues = ConfigValidator::validate(['database' => $this->fullDatabase(), 'timezone' => 'UTC']);

        self::assertNotContains('timezone', $this->pathsWith($issues, ConfigIssue::WARNING));
    }

    /**
     * Clé absente : le bootstrap applique déjà `?? 'UTC'`, il n'y a donc pas de
     * dérive à signaler — et le config `database`-only de la CI resterait muet.
     */
    public function testAbsentTimezoneIsSilent(): void
    {
        $issues = ConfigValidator::validate(['database' => $this->fullDatabase()]);

        self::assertNotContains('timezone', $this->pathsWith($issues, ConfigIssue::WARNING));
    }

    /**
     * Le contrôle ne dépend pas de `$checkSentinels` : `--schema-only` désactive la
     * chasse aux `change_me`, pas la validation d'un fuseau — un template dérivé
     * doit casser la CI.
     */
    public function testTimezoneIsCheckedEvenInSchemaOnlyMode(): void
    {
        $issues = ConfigValidator::validate(['database' => $this->fullDatabase(), 'timezone' => 'Europe/Brussels'], false);

        self::assertContains('timezone', $this->pathsWith($issues, ConfigIssue::WARNING));
    }

    /** Le constat doit remonter au runtime : c'est une dérive silencieuse, pas du bruit. */
    public function testTimezoneWarningIsARuntimeSignal(): void
    {
        $issues = ConfigValidator::validate(['database' => $this->fullDatabase(), 'timezone' => 'Europe/Brussels']);

        foreach ($issues as $issue) {
            if ($issue->path === 'timezone') {
                self::assertTrue($issue->isRuntimeSignal());

                return;
            }
        }

        self::fail('aucun constat sur timezone');
    }
}
