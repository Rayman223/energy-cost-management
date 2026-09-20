<?php

declare(strict_types=1);

namespace App\View;

/**
 * Chargement du contenu éditorial des guides (#85).
 *
 * Le texte vit dans `app/content/guides/<slug>/<locale>.php` et non dans les
 * catalogues de traduction : un guide fait plusieurs centaines de mots répartis
 * en sections, ce qui se relit et se traduit mal en clés plates au milieu de
 * quinze cents libellés d'interface. Les catalogues gardent ce pour quoi ils
 * sont faits — les libellés courts de l'interface des guides.
 *
 * Forme d'un fichier de contenu :
 *
 *     return [
 *         'title'       => 'Titre du guide',
 *         'description' => 'Résumé pour la meta description et l'index.',
 *         'intro'       => 'Chapô introductif.',
 *         'sections'    => [
 *             ['h' => 'Titre de section', 'p' => ['…'], 'ul' => ['…']],
 *         ],
 *     ];
 *
 * Aucune balise : le texte est du texte, échappé au rendu. Un guide ne peut
 * donc pas injecter de HTML, et sa structure reste imposée par le template.
 *
 * @phpstan-type GuideSection array{h: string, p: list<string>, ul: list<string>}
 * @phpstan-type Guide array{slug: string, title: string, description: string,
 *     intro: string, sections: list<GuideSection>}
 */
final class GuideContent
{
    /**
     * Guides publiés, dans l'ordre d'affichage de l'index.
     *
     * Liste blanche : le slug vient de l'URL et sert à construire un chemin de
     * fichier. Rien d'autre que ces valeurs ne doit pouvoir l'atteindre.
     *
     * @var list<string>
     */
    public const SLUGS = ['kwh-price', 'meter-reading', 'fixed-vs-dynamic'];

    /**
     * Contenu d'un guide dans une locale, avec repli sur la locale par défaut
     * quand la traduction manque — mieux vaut un guide en français qu'une page
     * d'erreur.
     *
     * @return Guide|null null si le slug est inconnu ou si aucun fichier n'existe.
     */
    public static function load(string $slug, string $locale, string $fallbackLocale = 'fr'): ?array
    {
        if (!in_array($slug, self::SLUGS, true)) {
            return null;
        }

        $data = self::read($slug, $locale) ?? self::read($slug, $fallbackLocale);
        if ($data === null) {
            return null;
        }

        $sections = [];
        foreach ($data['sections'] ?? [] as $section) {
            if (!is_array($section)) {
                continue;
            }
            $sections[] = [
                'h'  => (string) ($section['h'] ?? ''),
                'p'  => array_values(array_filter((array) ($section['p'] ?? []), 'is_string')),
                'ul' => array_values(array_filter((array) ($section['ul'] ?? []), 'is_string')),
            ];
        }

        return [
            'slug'        => $slug,
            'title'       => (string) ($data['title'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'intro'       => (string) ($data['intro'] ?? ''),
            'sections'    => $sections,
        ];
    }

    /**
     * Tous les guides d'une locale, pour l'index `/guides`.
     *
     * @return list<Guide>
     */
    public static function all(string $locale, string $fallbackLocale = 'fr'): array
    {
        $guides = [];
        foreach (self::SLUGS as $slug) {
            $guide = self::load($slug, $locale, $fallbackLocale);
            if ($guide !== null) {
                $guides[] = $guide;
            }
        }

        return $guides;
    }

    /**
     * Lecture brute d'un fichier de contenu. Le slug est déjà validé par
     * l'appelant ; la locale est filtrée ici, elle vient de la négociation de
     * langue et doit rester un code à deux lettres.
     *
     * @return array<string, mixed>|null
     */
    private static function read(string $slug, string $locale): ?array
    {
        if (preg_match('/^[a-z]{2}$/', $locale) !== 1) {
            return null;
        }

        $path = \dirname(__DIR__, 2) . '/content/guides/' . $slug . '/' . $locale . '.php';
        if (!is_file($path)) {
            return null;
        }

        $data = require $path;

        return is_array($data) ? $data : null;
    }
}
