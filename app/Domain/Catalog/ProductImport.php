<?php

namespace App\Domain\Catalog;

use App\Domain\Audit\AuditLog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\CatalogImport;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Import CSV d'articles (EPIC-03) : aperçu validé ligne par ligne, puis application.
 *
 * - Lecture tolérante aux fichiers Excel : séparateur « ; » ou « , », UTF-8 (avec ou
 *   sans BOM) ou Windows-1252, en-têtes français, montants « 12 500 ».
 * - Clé de rapprochement : le SKU. Existant → mise à jour, sinon création.
 * - Idempotent : un import ne s'applique qu'une fois ; réimporter le même fichier
 *   ne crée aucun doublon (rapprochement par SKU).
 * - Application tout-ou-rien, après revalidation complète (l'état a pu changer depuis l'aperçu).
 */
final class ProductImport
{
    public const int MAX_ROWS = 5000;

    /** En-têtes acceptés → champ interne. */
    private const array HEADERS = [
        'sku' => 'sku', 'reference' => 'sku', 'référence' => 'sku', 'ref' => 'sku',
        'nom' => 'name', 'name' => 'name', 'designation' => 'name', 'désignation' => 'name', 'libelle' => 'name', 'libellé' => 'name',
        'type' => 'type',
        'categorie' => 'category', 'catégorie' => 'category', 'category' => 'category',
        'unite' => 'unit', 'unité' => 'unit', 'unit' => 'unit',
        'tva' => 'tax', 'taxe' => 'tax', 'tax' => 'tax',
        'prix_vente' => 'sale_price', 'prix de vente' => 'sale_price', 'prix' => 'sale_price', 'sale_price' => 'sale_price',
        'cout' => 'standard_cost', 'coût' => 'standard_cost', 'prix_achat' => 'standard_cost', "prix d'achat" => 'standard_cost', 'standard_cost' => 'standard_cost',
        'code_barres' => 'barcode', 'code-barres' => 'barcode', 'code barres' => 'barcode', 'barcode' => 'barcode', 'ean' => 'barcode',
        'description' => 'description',
    ];

    private const array TYPES = [
        '' => 'stockable', 'article' => 'stockable', 'stockable' => 'stockable', 'produit' => 'stockable',
        'service' => 'service',
        'consommable' => 'consumable', 'consumable' => 'consumable',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly ProductService $products,
        private readonly AuditLog $audit,
    ) {}

    public function preview(UploadedFile $file, User $actor): CatalogImport
    {
        $this->access->assertCanWrite($this->context->tenant());

        $content = (string) file_get_contents($file->getRealPath());
        $rows = $this->parse($content);
        [$valid, $errors] = $this->validate($rows, $actor);

        return DB::transaction(function () use ($file, $content, $rows, $valid, $errors, $actor): CatalogImport {
            $import = new CatalogImport;
            $import->type = 'products';
            $import->status = CatalogImport::PREVIEWED;
            $import->file_name = mb_substr($file->getClientOriginalName(), 0, 200);
            $import->file_hash = hash('sha256', $content);
            $import->rows_total = count($rows);
            $import->rows_valid = count($valid);
            $import->errors = $errors;
            $import->summary = $this->summarize($valid);
            $import->created_by = $actor->id;
            $import->save();

            Storage::disk('local')->put($this->storagePath($import), $content);

            return $import;
        });
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, categories_created: int}
     */
    public function apply(CatalogImport $import, User $actor): array
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($import, $actor): array {
            /** @var CatalogImport $locked */
            $locked = CatalogImport::query()->lockForUpdate()->findOrFail($import->id);

            if ($locked->status !== CatalogImport::PREVIEWED) {
                throw ValidationException::withMessages(['import' => __('catalog.import.already_applied')]);
            }

            $rows = $this->parse((string) Storage::disk('local')->get($this->storagePath($locked)));
            [$valid, $errors] = $this->validate($rows, $actor);

            if ($errors !== []) {
                throw ValidationException::withMessages(['import' => __('catalog.import.has_errors', ['count' => count($errors)])]);
            }

            $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'categories_created' => 0];

            foreach ($valid as $row) {
                $categoryId = null;

                if ($row['category'] !== null) {
                    $category = ProductCategory::query()
                        ->whereNull('archived_at')
                        ->whereRaw('diaba_search_text(name) = diaba_search_text(?)', [$row['category']])
                        ->first();

                    if ($category === null) {
                        $category = ProductCategory::query()->create(['name' => $row['category']]);
                        $result['categories_created']++;
                    }

                    $categoryId = $category->id;
                }

                $data = [
                    'sku' => $row['sku'],
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'type' => $row['type'],
                    'category_id' => $categoryId,
                    'unit_id' => $row['unit_id'],
                    'tax_rate_id' => $row['tax_rate_id'],
                    'sale_price' => $row['sale_price'],
                    'standard_cost' => $row['standard_cost'] ?? 0,
                ];

                $existing = Product::query()->whereRaw('upper(sku) = upper(?)', [$row['sku']])->first();

                if ($existing === null) {
                    $product = $this->products->create([...$data, 'barcode' => $row['barcode']], $actor);
                    $result['created']++;

                    continue;
                }

                $data['standard_cost'] = $row['standard_cost'] ?? $existing->standard_cost;
                $version = $existing->version;
                $product = $this->products->update($existing, $data, $version, $actor);
                $result[$product->version === $version ? 'unchanged' : 'updated']++;

                if ($row['barcode'] !== null && ! $product->barcodes()->whereRaw('upper(code) = upper(?)', [$row['barcode']])->exists()) {
                    $this->products->addBarcode($product, $row['barcode'], primary: ! $product->barcodes()->exists());
                }
            }

            $locked->status = CatalogImport::APPLIED;
            $locked->applied_at = now();
            $locked->summary = [...($locked->summary ?? []), ...$result];
            $locked->save();

            $this->audit->record('catalog.import_applied', $locked, after: $result, metadata: ['file' => $locked->file_name, 'hash' => $locked->file_hash]);

            return $result;
        });
    }

    /**
     * @return list<array<string, string>> lignes avec leur numéro (clé « _line »)
     */
    private function parse(string $content): array
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

            if (count($records) > self::MAX_ROWS + 1) {
                fclose($stream);

                throw ValidationException::withMessages(['file' => __('catalog.import.too_many_rows', ['max' => self::MAX_ROWS])]);
            }
        }
        fclose($stream);

        if (count($records) < 2) {
            throw ValidationException::withMessages(['file' => __('catalog.import.empty')]);
        }

        $headers = array_map(
            fn (?string $h) => self::HEADERS[mb_strtolower(trim((string) $h))] ?? null,
            $records[0],
        );

        foreach (['sku', 'name', 'unit', 'sale_price'] as $required) {
            if (! in_array($required, $headers, true)) {
                throw ValidationException::withMessages(['file' => __('catalog.import.missing_column', ['column' => __("catalog.import.columns.{$required}")])]);
            }
        }

        $rows = [];

        foreach (array_slice($records, 1) as $index => $values) {
            if ($values === [null] || implode('', array_map('strval', $values)) === '') {
                continue;
            }

            // Numéro de ligne tel que vu dans le tableur (en-tête = ligne 1).
            $row = ['_line' => (string) ($index + 2)];

            foreach ($headers as $position => $field) {
                if ($field !== null) {
                    $row[$field] = trim((string) ($values[$position] ?? ''));
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<array{line: int, messages: list<string>}>}
     */
    private function validate(array $rows, User $actor): array
    {
        $units = Unit::query()->get(['id', 'code'])->mapWithKeys(fn (Unit $u) => [mb_strtoupper($u->code) => $u->id])->all();
        $taxes = TaxRate::query()->where('active', true)->get(['id', 'name', 'rate_basis_points', 'is_default']);
        $existing = Product::query()->get(['id', 'sku', 'sale_price', 'standard_cost'])->keyBy(fn (Product $p) => mb_strtoupper($p->sku));
        $usedBarcodes = ProductBarcode::query()->with('product:id,sku')->get(['code', 'product_id'])
            ->mapWithKeys(fn (ProductBarcode $b) => [mb_strtoupper($b->code) => mb_strtoupper($b->product->sku)])->all();
        $canOverridePrice = Gate::forUser($actor)->allows('catalog.price_override');

        $valid = [];
        $errors = [];
        $seenSkus = [];
        $seenBarcodes = [];

        foreach ($rows as $row) {
            $messages = [];
            $sku = $row['sku'] ?? '';
            $skuKey = mb_strtoupper($sku);

            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,39}$/', $sku) !== 1) {
                $messages[] = __('catalog.import.errors.sku');
            } elseif (isset($seenSkus[$skuKey])) {
                $messages[] = __('catalog.import.errors.duplicate_sku', ['line' => $seenSkus[$skuKey]]);
            }

            $name = $row['name'] ?? '';
            if ($name === '' || mb_strlen($name) > 160) {
                $messages[] = __('catalog.import.errors.name');
            }

            $type = self::TYPES[mb_strtolower($row['type'] ?? '')] ?? null;
            if ($type === null) {
                $messages[] = __('catalog.import.errors.type');
            }

            $unitId = $units[mb_strtoupper($row['unit'] ?? '')] ?? null;
            if ($unitId === null) {
                $messages[] = __('catalog.import.errors.unit', ['unit' => $row['unit'] ?? '']);
            }

            $taxId = $this->resolveTax($row['tax'] ?? '', $taxes);
            if ($taxId === null) {
                $messages[] = __('catalog.import.errors.tax', ['tax' => $row['tax'] ?? '']);
            }

            $salePrice = $this->amount($row['sale_price'] ?? '');
            if ($salePrice === null) {
                $messages[] = __('catalog.import.errors.price');
            }

            $cost = ($row['standard_cost'] ?? '') === '' ? null : $this->amount($row['standard_cost']);
            if (($row['standard_cost'] ?? '') !== '' && $cost === null) {
                $messages[] = __('catalog.import.errors.cost');
            }

            $barcode = null;
            if (($row['barcode'] ?? '') !== '') {
                try {
                    $barcode = Barcode::parse($row['barcode'])->code;
                    $barcodeKey = mb_strtoupper($barcode);

                    if (isset($usedBarcodes[$barcodeKey]) && $usedBarcodes[$barcodeKey] !== $skuKey) {
                        $messages[] = __('catalog.import.errors.barcode_used', ['sku' => $usedBarcodes[$barcodeKey]]);
                    } elseif (isset($seenBarcodes[$barcodeKey])) {
                        $messages[] = __('catalog.import.errors.duplicate_barcode', ['line' => $seenBarcodes[$barcodeKey]]);
                    }

                    $seenBarcodes[$barcodeKey] = $row['_line'];
                } catch (InvalidArgumentException $e) {
                    $messages[] = $e->getMessage();
                }
            }

            $current = $existing[$skuKey] ?? null;
            if ($current !== null && ! $canOverridePrice
                && (($salePrice !== null && $salePrice !== $current->sale_price) || ($cost !== null && $cost !== $current->standard_cost))) {
                $messages[] = __('catalog.import.errors.price_override');
            }

            $seenSkus[$skuKey] ??= $row['_line'];

            if ($messages !== []) {
                $errors[] = ['line' => (int) $row['_line'], 'messages' => $messages];

                continue;
            }

            $valid[] = [
                'line' => (int) $row['_line'],
                'sku' => $sku,
                'name' => $name,
                'description' => ($row['description'] ?? '') !== '' ? $row['description'] : null,
                'type' => $type,
                'category' => ($row['category'] ?? '') !== '' ? mb_substr($row['category'], 0, 80) : null,
                'unit_id' => $unitId,
                'tax_rate_id' => $taxId,
                'sale_price' => $salePrice,
                'standard_cost' => $cost,
                'barcode' => $barcode,
                'exists' => $current !== null,
            ];
        }

        return [$valid, $errors];
    }

    /**
     * Montant XOF entier : « 12500 », « 12 500 », « 12.500 » ; refuse les décimales.
     */
    private function amount(string $raw): ?int
    {
        $normalized = preg_replace('/[\s\x{00A0}\x{202F}.]/u', '', $raw) ?? '';

        if (preg_match('/^\d{1,13}$/', $normalized) !== 1) {
            return null;
        }

        return (int) $normalized;
    }

    /**
     * @param  Collection<int, TaxRate>  $taxes
     */
    private function resolveTax(string $raw, $taxes): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return $taxes->firstWhere('is_default', true)?->id;
        }

        $percent = str_replace([' ', '%', ','], ['', '', '.'], $raw);

        if (is_numeric($percent)) {
            $basisPoints = (int) round(((float) $percent) * 100);

            return $taxes->firstWhere('rate_basis_points', $basisPoints)?->id;
        }

        return $taxes->first(fn (TaxRate $t) => mb_strtolower($t->name) === mb_strtolower($raw))?->id;
    }

    /**
     * @param  list<array<string, mixed>>  $valid
     * @return array<string, int>
     */
    private function summarize(array $valid): array
    {
        $updates = count(array_filter($valid, fn (array $row) => $row['exists']));

        return ['to_create' => count($valid) - $updates, 'to_update' => $updates];
    }

    private function storagePath(CatalogImport $import): string
    {
        return "tenants/{$import->tenant_id}/imports/{$import->id}.csv";
    }
}
