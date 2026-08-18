<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\AppName;
use PHPUnit\Framework\TestCase;

/**
 * Garde anti-dérive du renommage (#280) : le nom du produit ne doit exister
 * qu'à un seul endroit. L'ancien nom réapparaissait facilement en dur, chaque
 * page ayant sa propre copie dans son pied de page et son titre d'onglet.
 */
final class AppNameTest extends TestCase
{
    private const APP_DIR = __DIR__ . '/../../../app';

    /** Fichiers scannés : tout ce qui peut afficher le nom à l'utilisateur. */
    private const SCANNED_EXTENSIONS = ['php', 'webmanifest', 'js', 'html'];

    /**
     * `config/config.php` est gitignoré et propre à chaque déploiement : son
     * contenu n'est pas du ressort du dépôt, et il porte souvent des libellés
     * hérités que le renommage n'a pas à contraindre.
     */
    private const IGNORED = ['config/config.php'];

    public function testNameIsTheCurrentProductName(): void
    {
        self::assertSame('Energy cost management', AppName::NAME);
    }

    public function testFormerNameIsGoneFromTheApplication(): void
    {
        $offenders = [];

        foreach ($this->scannedFiles() as $file) {
            $relative = substr($file, strlen((string) realpath(self::APP_DIR)) + 1);
            if (in_array($relative, self::IGNORED, true)) {
                continue;
            }

            if (stripos((string) file_get_contents($file), 'Manage Energy') !== false) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'Ancien nom du produit encore codé en dur — utiliser AppName::NAME.');
    }

    /**
     * @return list<string>
     */
    private function scannedFiles(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator((string) realpath(self::APP_DIR), \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }

            if (in_array(strtolower($entry->getExtension()), self::SCANNED_EXTENSIONS, true)) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
