<?php

namespace App\Domain\Catalog;

use App\Domain\Audit\AuditLog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\CatalogImport;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Import CSV de clients ou de fournisseurs : aperçu validé ligne par ligne, puis
 * application (même parcours que l'import d'articles).
 *
 * Rapprochement avec l'existant, pour qu'un même fichier réimporté ne crée aucun
 * doublon : par code s'il est fourni (code inconnu = nouveau contact) ; sinon par
 * NINEA, puis téléphone, puis e-mail (le premier qui retrouve un contact) ; sans
 * aucun de ces repères, par nom. Un contact
 * trouvé est mis à jour : les cellules vides ne remplacent jamais une valeur
 * existante, et un contact archivé le reste. Application tout-ou-rien après
 * revalidation complète.
 */
final class PartyImport
{
    public const int MAX_ROWS = 5000;

    public const array TYPES = ['customer' => 'customers', 'supplier' => 'suppliers'];

    /** En-têtes acceptés → champ interne. */
    private const array HEADERS = [
        'code' => 'code', 'code client' => 'code', 'code fournisseur' => 'code', 'code_client' => 'code', 'code_fournisseur' => 'code',
        'nom' => 'name', 'name' => 'name', 'raison sociale' => 'name', 'raison_sociale' => 'name', 'client' => 'name', 'fournisseur' => 'name',
        'telephone' => 'phone', 'téléphone' => 'phone', 'tel' => 'phone', 'tél' => 'phone', 'phone' => 'phone', 'mobile' => 'phone',
        'email' => 'email', 'e-mail' => 'email', 'mail' => 'email', 'courriel' => 'email',
        'adresse' => 'address_line', 'address' => 'address_line', 'address_line' => 'address_line',
        'ville' => 'city', 'city' => 'city',
        'ninea' => 'tax_number', 'tax_number' => 'tax_number', 'n° ninea' => 'tax_number',
        'delai_paiement' => 'payment_term_days', 'délai de paiement' => 'payment_term_days', 'delai de paiement' => 'payment_term_days', 'delai' => 'payment_term_days', 'délai' => 'payment_term_days', 'payment_term_days' => 'payment_term_days',
        'notes' => 'notes', 'note' => 'notes', 'remarque' => 'notes', 'commentaire' => 'notes',
        'plafond_credit' => 'credit_limit', 'plafond de crédit' => 'credit_limit', 'plafond de credit' => 'credit_limit', 'plafond' => 'credit_limit', 'credit_limit' => 'credit_limit',
    ];

    /** Champs repris dans la fiche du contact. */
    private const array DATA_FIELDS = ['code', 'name', 'phone', 'email', 'address_line', 'city', 'tax_number', 'payment_term_days', 'notes', 'credit_limit'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly PartyService $parties,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  'customer'|'supplier'  $type
     */
    public function preview(string $type, UploadedFile $file, User $actor): CatalogImport
    {
        $this->access->assertCanWrite($this->context->tenant());

        $content = (string) file_get_contents($file->getRealPath());
        $rows = $this->parse($content);
        [$valid, $errors] = $this->validate($type, $rows);

        return DB::transaction(function () use ($type, $file, $content, $rows, $valid, $errors, $actor): CatalogImport {
            $import = new CatalogImport;
            $import->type = self::TYPES[$type];
            $import->status = CatalogImport::PREVIEWED;
            $import->file_name = mb_substr($file->getClientOriginalName(), 0, 200);
            $import->file_hash = hash('sha256', $content);
            $import->rows_total = count($rows);
            $import->rows_valid = count($valid);
            $import->errors = $errors;
            $updates = count(array_filter($valid, fn (array $row) => $row['existing'] !== null));
            $import->summary = ['to_create' => count($valid) - $updates, 'to_update' => $updates];
            $import->created_by = $actor->id;
            $import->save();

            Storage::disk('local')->put($this->storagePath($import), $content);

            return $import;
        });
    }

    /**
     * @param  'customer'|'supplier'  $type
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function apply(string $type, CatalogImport $import): array
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($type, $import): array {
            /** @var CatalogImport $locked */
            $locked = CatalogImport::query()->lockForUpdate()->findOrFail($import->id);

            if ($locked->status !== CatalogImport::PREVIEWED || $locked->type !== self::TYPES[$type]) {
                throw ValidationException::withMessages(['import' => __('catalog.import.already_applied')]);
            }

            $rows = $this->parse((string) Storage::disk('local')->get($this->storagePath($locked)));
            [$valid, $errors] = $this->validate($type, $rows);

            if ($errors !== []) {
                throw ValidationException::withMessages(['import' => __('catalog.import.has_errors', ['count' => count($errors)])]);
            }

            $class = $this->model($type);
            $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

            foreach ($valid as $row) {
                $existing = $row['existing'] !== null ? $class::query()->find($row['existing']) : null;

                if ($existing === null) {
                    $this->parties->create($class, $row['data']);
                    $result['created']++;

                    continue;
                }

                $party = $this->parties->update($existing, $row['data']);
                $result[$party->wasChanged() ? 'updated' : 'unchanged']++;
            }

            $locked->status = CatalogImport::APPLIED;
            $locked->applied_at = now();
            $locked->summary = [...($locked->summary ?? []), ...$result];
            $locked->save();

            $this->audit->record('catalog.import_applied', $locked, after: $result, metadata: ['file' => $locked->file_name, 'hash' => $locked->file_hash, 'type' => $locked->type]);

            return $result;
        });
    }

    /**
     * @return list<array<string, string>>
     */
    private function parse(string $content): array
    {
        return CsvTable::parse($content, self::HEADERS, ['name'], self::MAX_ROWS);
    }

    /**
     * @param  'customer'|'supplier'  $type
     * @param  list<array<string, string>>  $rows
     * @return array{0: list<array{line: int, data: array<string, mixed>, existing: string|null}>, 1: list<array{line: int, messages: list<string>}>}
     */
    private function validate(string $type, array $rows): array
    {
        $existing = $this->model($type)::query()->get();
        $index = ['code' => [], 'tax_number' => [], 'email' => [], 'phone' => [], 'name' => []];

        foreach ($existing as $party) {
            foreach (array_keys($index) as $key) {
                $value = $this->key($key, (string) $party->getAttribute($key === 'tax_number' ? 'tax_number' : $key));

                if ($value !== '') {
                    $index[$key][$value][] = (string) $party->getKey();
                }
            }
        }

        $valid = [];
        $errors = [];
        $seen = [];

        foreach ($rows as $row) {
            $messages = [];
            $line = (int) $row['_line'];

            $name = $row['name'] ?? '';
            if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
                $messages[] = __('catalog.import.party_errors.name');
            }

            $code = $row['code'] ?? '';
            if ($code !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,19}$/', $code) !== 1) {
                $messages[] = __('catalog.import.party_errors.code');
            }

            $phone = $row['phone'] ?? '';
            if ($phone !== '' && preg_match('/^[0-9+() .-]{6,32}$/', $phone) !== 1) {
                $messages[] = __('catalog.import.party_errors.phone');
            }

            $email = $row['email'] ?? '';
            if ($email !== '' && (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                $messages[] = __('catalog.import.party_errors.email');
            }

            foreach (['address_line' => 200, 'city' => 80, 'tax_number' => 40, 'notes' => 2000] as $field => $max) {
                if (mb_strlen($row[$field] ?? '') > $max) {
                    $messages[] = __('catalog.import.party_errors.too_long', ['field' => __("catalog.import.party_fields.{$field}"), 'max' => $max]);
                }
            }

            $terms = null;
            if (($row['payment_term_days'] ?? '') !== '') {
                $terms = preg_match('/^\d{1,3}$/', $row['payment_term_days']) === 1 ? (int) $row['payment_term_days'] : null;

                if ($terms === null || $terms > 365) {
                    $messages[] = __('catalog.import.party_errors.terms');
                    $terms = null;
                }
            }

            $credit = null;
            if ($type === 'customer' && ($row['credit_limit'] ?? '') !== '') {
                $normalized = preg_replace('/[\s\x{00A0}\x{202F}.]/u', '', $row['credit_limit']) ?? '';
                $credit = preg_match('/^\d{1,13}$/', $normalized) === 1 ? (int) $normalized : null;

                if ($credit === null) {
                    $messages[] = __('catalog.import.party_errors.credit');
                }
            }

            // Rapprochement : le code fait foi s'il est donné (inconnu = nouveau contact) ; sinon NINEA,
            // puis téléphone, puis e-mail (premier qui retrouve un contact) ; sans aucun repère, le nom.
            $provided = array_filter([
                'code' => $this->key('code', $code),
                'tax_number' => $this->key('tax_number', $row['tax_number'] ?? ''),
                'phone' => $this->key('phone', $phone),
                'email' => $this->key('email', $email),
            ], fn (string $value) => $value !== '');
            $lookup = isset($provided['code']) ? ['code' => $provided['code']] : ($provided !== [] ? $provided : ['name' => $this->key('name', $name)]);

            // Un même repère ne peut pas servir deux fois dans le fichier.
            foreach ($provided !== [] ? $provided : $lookup as $key => $value) {
                if ($value === '') {
                    continue;
                }

                if (isset($seen[$key][$value])) {
                    $messages[] = __('catalog.import.party_errors.duplicate', ['line' => $seen[$key][$value]]);

                    break;
                }

                $seen[$key][$value] = $line;
            }

            $match = null;
            foreach ($lookup as $key => $value) {
                if ($value === '') {
                    continue;
                }

                $found = $index[$key][$value] ?? [];

                if (count($found) > 1) {
                    $messages[] = __('catalog.import.party_errors.ambiguous', ['field' => __("catalog.import.party_fields.{$key}")]);

                    break;
                }

                if (count($found) === 1) {
                    $match = $found[0];

                    break;
                }
            }

            if ($messages !== []) {
                $errors[] = ['line' => $line, 'messages' => $messages];

                continue;
            }

            // Cellules vides : ne rien écrire (une valeur existante n'est jamais effacée).
            $data = ['name' => $name];
            foreach (['code' => $code, 'phone' => $phone, 'email' => $email, 'address_line' => $row['address_line'] ?? '', 'city' => $row['city'] ?? '', 'tax_number' => $row['tax_number'] ?? '', 'notes' => $row['notes'] ?? ''] as $field => $value) {
                if ($value !== '') {
                    $data[$field] = $value;
                }
            }
            if ($terms !== null) {
                $data['payment_term_days'] = $terms;
            }
            if ($credit !== null) {
                $data['credit_limit'] = $credit;
            }

            $valid[] = ['line' => $line, 'data' => array_intersect_key($data, array_flip(self::DATA_FIELDS)), 'existing' => $match];
        }

        return [$valid, $errors];
    }

    /** Forme normalisée d'un repère (comparaison sans casse, accents, espaces ni ponctuation du téléphone). */
    private function key(string $field, string $value): string
    {
        $value = trim($value);

        return match ($field) {
            'phone' => $this->phoneKey($value),
            'name' => mb_strtolower(Str::ascii($value)),
            default => mb_strtolower($value),
        };
    }

    /**
     * Chiffres seuls, sans préfixe international du Sénégal : « +221 76 111 22 33 »,
     * « 00221761112233 » et « 76 111 22 33 » désignent le même numéro.
     */
    private function phoneKey(string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);
        $digits = (string) preg_replace('/^00/', '', $digits);

        return str_starts_with($digits, '221') && strlen($digits) > 9 ? substr($digits, 3) : $digits;
    }

    /**
     * @param  'customer'|'supplier'  $type
     * @return class-string<Customer>|class-string<Supplier>
     */
    private function model(string $type): string
    {
        return $type === 'customer' ? Customer::class : Supplier::class;
    }

    private function storagePath(CatalogImport $import): string
    {
        return "tenants/{$import->tenant_id}/imports/{$import->id}.csv";
    }
}
