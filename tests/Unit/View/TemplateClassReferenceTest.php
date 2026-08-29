<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * `app/templates/` est le seul répertoire de code exécutable que PHPStan
 * n'analyse pas (cf. phpstan.dist.neon) : les templates y référencent pourtant des
 * classes et des constantes du domaine. Une constante mal nommée y passe donc
 * toutes les vérifications locales et n'explose qu'au rendu de la page — c'est-à-
 * dire chez l'utilisateur, et seulement sur la page concernée.
 *
 * Ce test ferme cet angle mort de la façon la plus légère possible : il relit les
 * références `\App\…::CONSTANTE` écrites dans les templates et vérifie qu'elles
 * existent réellement. Il ne rend aucune page — le rendu d'un template comme
 * `account.php` demanderait une vingtaine de variables et testerait surtout la
 * fixture.
 */
final class TemplateClassReferenceTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../../app/templates';

    /** @return list<string> */
    private function templateFiles(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::TEMPLATES, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        self::assertNotEmpty($files, 'Aucun template trouvé : l\'arborescence a changé.');

        return $files;
    }

    public function testEveryClassConstantReferencedByTemplatesExists(): void
    {
        $checked = 0;

        foreach ($this->templateFiles() as $file) {
            $source = (string) file_get_contents($file);
            $name   = basename($file);

            // Références pleinement qualifiées uniquement : les templates n'ont pas
            // de `use`, ils écrivent toujours le chemin complet.
            preg_match_all('/\\\\(App\\\\[A-Za-z0-9_\\\\]+)::([A-Z][A-Z0-9_]*)\b/', $source, $matches, PREG_SET_ORDER);

            foreach ($matches as [$reference, $class, $constant]) {
                self::assertTrue(
                    class_exists('\\' . $class) || interface_exists('\\' . $class),
                    "{$name} référence {$reference}, mais {$class} n'existe pas.",
                );
                self::assertTrue(
                    \defined('\\' . $class . '::' . $constant),
                    "{$name} référence {$reference}, mais cette constante n'existe pas.",
                );
                $checked++;
            }
        }

        // Garde anti-dérive : une regex cassée rendrait ce test vert et muet.
        self::assertGreaterThanOrEqual(5, $checked, 'La détection des références a dérivé.');
    }

    public function testEveryClassMethodReferencedByTemplatesExists(): void
    {
        $checked = 0;

        foreach ($this->templateFiles() as $file) {
            $source = (string) file_get_contents($file);
            $name   = basename($file);

            preg_match_all('/\\\\(App\\\\[A-Za-z0-9_\\\\]+)::([a-z][A-Za-z0-9_]*)\s*\(/', $source, $matches, PREG_SET_ORDER);

            foreach ($matches as [$reference, $class, $method]) {
                self::assertTrue(
                    class_exists('\\' . $class) || interface_exists('\\' . $class),
                    "{$name} référence {$reference}, mais {$class} n'existe pas.",
                );
                self::assertTrue(
                    method_exists('\\' . $class, $method),
                    "{$name} appelle {$reference}(), mais cette méthode n'existe pas.",
                );
                $checked++;
            }
        }

        self::assertGreaterThanOrEqual(5, $checked, 'La détection des appels a dérivé.');
    }
}
