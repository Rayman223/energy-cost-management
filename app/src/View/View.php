<?php

declare(strict_types=1);

namespace App\View;

/**
 * Moteur de rendu minimal pour templates PHP (sans build tooling).
 *
 * Un template est un fichier PHP sous `app/templates/`. Il est inclus dans le
 * contexte de l'instance `View` : les clés du tableau `$data` deviennent des
 * variables locales, et `$this->e()` fournit l'échappement HTML centralisé.
 *
 * Exemple de template :
 *     <h1><?= $this->e($title) ?></h1>
 *
 * Le contrôleur (app/public/*.php) prépare les données puis fait :
 *     echo (new View(__DIR__ . '/../templates'))->render('login', [...]);
 */
final class View
{
    public function __construct(private readonly string $templateDir)
    {
    }

    /**
     * Rend un template et renvoie le HTML produit.
     *
     * @param array<string,mixed> $data Variables exposées au template.
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->templateDir . '/' . $template . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('Template introuvable : %s', $template));
        }

        ob_start();
        try {
            // Closure liée à $this : le template accède à $this->e() et aux
            // variables extraites de $data. Noms internes préfixés pour éviter
            // toute collision avec les clés de $data.
            (function (string $__tpl, array $__data): void {
                extract($__data, EXTR_OVERWRITE);
                require $__tpl;
            })->call($this, $file, $data);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Échappement HTML centralisé (contexte texte/attribut).
     */
    public function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
