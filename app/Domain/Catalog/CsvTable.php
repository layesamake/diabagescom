<?php

namespace App\Domain\Catalog;

use Illuminate\Validation\ValidationException;

/**
 * Lecture d'un fichier CSV d'import, tolérante aux fichiers Excel : séparateur
 * « ; » ou « , », UTF-8 (avec ou sans BOM) ou Windows-1252, champs entre
 * guillemets pouvant contenir des retours à la ligne, en-têtes reconnus par
 * alias (insensibles à la casse).
 */
final class CsvTable
{
    /**
     * @param  array<string, string>  $headers  en-tête (minuscules) → champ interne
     * @param  list<string>  $required  champs internes obligatoires
     * @return list<array<string, string>> lignes avec leur numéro tel que vu dans le tableur (clé « _line »)
     *
     * @throws ValidationException fichier vide, trop long ou colonne obligatoire absente
     */
    public static function parse(string $content, array $headers, array $required, int $maxRows): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? '';

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        // Lecteur CSV natif : gère les champs entre guillemets contenant des retours à la ligne.
        $stream = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Unable to open a temporary stream.');
        fwrite($stream, $content);
        rewind($stream);

        $records = [];
        while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $records[] = $record;

            if (count($records) > $maxRows + 1) {
                fclose($stream);

                throw ValidationException::withMessages(['file' => __('catalog.import.too_many_rows', ['max' => $maxRows])]);
            }
        }
        fclose($stream);

        if (count($records) < 2) {
            throw ValidationException::withMessages(['file' => __('catalog.import.empty')]);
        }

        $columns = array_map(
            fn (?string $h) => $headers[mb_strtolower(trim((string) $h))] ?? null,
            $records[0],
        );

        foreach ($required as $field) {
            if (! in_array($field, $columns, true)) {
                throw ValidationException::withMessages(['file' => __('catalog.import.missing_column', ['column' => __("catalog.import.columns.{$field}")])]);
            }
        }

        $rows = [];

        foreach (array_slice($records, 1) as $index => $values) {
            if ($values === [null] || implode('', array_map('strval', $values)) === '') {
                continue;
            }

            // En-tête = ligne 1.
            $row = ['_line' => (string) ($index + 2)];

            foreach ($columns as $position => $field) {
                if ($field !== null) {
                    $row[$field] = trim((string) ($values[$position] ?? ''));
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Fichier CSV à télécharger comme modèle : BOM UTF-8 et séparateur « ; » (ouverture directe dans Excel).
     *
     * @param  list<list<string>>  $rows  en-têtes puis lignes d'exemple
     */
    public static function template(array $rows): string
    {
        $out = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Unable to open a temporary stream.');

        foreach ($rows as $row) {
            fputcsv($out, $row, ';', '"', '');
        }

        rewind($out);

        return "\xEF\xBB\xBF".stream_get_contents($out);
    }
}
