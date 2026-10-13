<?php

namespace App\Domain\Reports;

/**
 * Rapport sous forme de tableaux, indépendant du format de sortie (Excel, PDF).
 *
 * Chaque section a un titre facultatif, des en-têtes et des lignes. Une cellule
 * entière est un montant (aligné à droite, séparateur de milliers) ; toute autre
 * valeur est convertie en texte, jamais interprétée comme une formule. « strong »
 * liste les numéros de lignes à mettre en évidence (sous-totaux, totaux).
 */
final readonly class TabularReport
{
    /** @var list<array{title: string|null, headers: list<string>, rows: list<list<int|string|null>>, strong: list<int>}> */
    public array $sections;

    /**
     * @param  array<int, array{title: string|null, headers: list<string>, rows: list<list<int|string|null>>, strong: list<int>}>  $sections
     */
    public function __construct(
        public string $title,
        public string $subtitle,
        array $sections,
    ) {
        $this->sections = array_values($sections);
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<mixed>  $rows  lignes (listes de cellules) ; les cellules non entières deviennent du texte
     * @param  list<int>  $strong
     * @return array{title: string|null, headers: list<string>, rows: list<list<int|string|null>>, strong: list<int>}
     */
    public static function section(?string $title, array $headers, iterable $rows, array $strong = []): array
    {
        $clean = [];

        foreach ($rows as $row) {
            $cells = [];

            foreach ((array) $row as $cell) {
                $cells[] = is_int($cell) || $cell === null ? $cell : (is_scalar($cell) ? (string) $cell : '');
            }

            $clean[] = $cells;
        }

        return ['title' => $title, 'headers' => $headers, 'rows' => $clean, 'strong' => $strong];
    }

    public function maxColumns(): int
    {
        $max = 1;

        foreach ($this->sections as $section) {
            $max = max($max, count($section['headers']));

            foreach ($section['rows'] as $row) {
                $max = max($max, count($row));
            }
        }

        return $max;
    }
}
