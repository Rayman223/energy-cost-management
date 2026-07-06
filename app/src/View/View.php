<?php

declare(strict_types=1);

namespace App\View;

use App\I18n\Formatter;
use App\I18n\Translator;

/**
 * Moteur de rendu minimal pour templates PHP (sans build tooling).
 *
 * Un template est un fichier PHP sous `app/templates/`. Il est inclus dans le
 * contexte de l'instance `View` : les clés du tableau `$data` deviennent des
 * variables locales, et l'instance expose l'échappement (`e()`), la traduction
 * (`t()`/`te()`) et le formatage localisé (`money()`, `num()`, `dt()`).
 *
 * Exemple de template :
 *     <h1><?= $this->te('app.title') ?></h1>
 *
 * Le contrôleur (app/public/*.php) construit une View configurée via
 * {@see ViewFactory::create()} puis fait `echo $view->render('login', [...])`.
 */
final class View
{
    public function __construct(
        private readonly string $templateDir,
        private readonly ?Translator $translator = null,
        private readonly ?Formatter $formatter = null,
    ) {
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
     * Rend un partiel réutilisable depuis `app/templates/partials/<name>.php`
     * (mutualise des fragments partagés entre templates). Même contexte que
     * {@see self::render()} : le partiel accède à `$this->e()`/`$this->te()` et
     * aux variables de `$data`.
     *
     * @param array<string,mixed> $data Variables exposées au partiel.
     */
    public function partial(string $name, array $data = []): string
    {
        return $this->render('partials/' . $name, $data);
    }

    /**
     * Échappement HTML centralisé (contexte texte/attribut).
     */
    public function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Traduit une clé (retourne la clé brute si aucun catalogue n'est câblé).
     *
     * @param array<string, string|int|float> $params
     */
    public function t(string $key, array $params = []): string
    {
        return $this->translator?->t($key, $params) ?? $key;
    }

    /**
     * Traduit ET échappe (usage le plus courant dans les templates).
     *
     * @param array<string, string|int|float> $params
     */
    public function te(string $key, array $params = []): string
    {
        return $this->e($this->t($key, $params));
    }

    public function money(float $amount, string $currency = 'EUR'): string
    {
        return $this->formatter?->money($amount, $currency)
            ?? (number_format($amount, 2, '.', ' ') . ' ' . $currency);
    }

    public function num(float $value, int $decimals = 2): string
    {
        return $this->formatter?->number($value, $decimals) ?? number_format($value, $decimals, '.', ' ');
    }

    public function dt(\DateTimeInterface $date): string
    {
        return $this->formatter?->date($date) ?? $date->format('Y-m-d');
    }

    public function locale(): string
    {
        return $this->formatter?->locale() ?? 'fr';
    }
}
