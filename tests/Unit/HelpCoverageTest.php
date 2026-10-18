<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Chaque page de l'espace connecté explique à quoi elle sert (bouton « ? »).
 * Une nouvelle page sans texte d'aide fait échouer ce test : ajoutez son entrée
 * dans la rubrique `help` de resources/js/i18n/fr.ts.
 */
class HelpCoverageTest extends TestCase
{
    /** Pages publiques ou sans barre d'en-tête : pas de bouton d'aide. */
    private const array WITHOUT_HELP = ['Welcome', 'billing/SandboxCheckout', 'storefront/Public', 'storefront/Confirmation', 'simple/Accueil'];

    private const array PREFIXES_WITHOUT_HELP = ['auth/', 'legal/', 'onboarding/'];

    public function test_every_page_of_the_signed_in_area_has_a_help_text(): void
    {
        $root = dirname(__DIR__, 2).'/resources/js';
        $dictionary = (string) file_get_contents($root.'/i18n/fr.ts');

        $this->assertSame(1, preg_match('/^    help: \{(.*?)^    \},/ms', $dictionary, $block), 'La rubrique help est introuvable.');
        preg_match_all('/^        \'?([A-Za-z]+(?:\/[A-Za-z]+)?)\'?:/m', $block[1], $found);
        $documented = $found[1];

        $pages = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/pages', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'vue') {
                $pages[] = substr(str_replace('\\', '/', substr($file->getPathname(), strlen($root.'/pages/'))), 0, -4);
            }
        }

        $missing = array_values(array_filter($pages, function (string $page) use ($documented): bool {
            if (in_array($page, self::WITHOUT_HELP, true) || in_array($page, $documented, true)) {
                return false;
            }

            foreach (self::PREFIXES_WITHOUT_HELP as $prefix) {
                if (str_starts_with($page, $prefix)) {
                    return false;
                }
            }

            return true;
        }));

        sort($missing);
        $this->assertSame([], $missing, 'Pages sans texte d\'aide : '.implode(', ', $missing));
    }
}
