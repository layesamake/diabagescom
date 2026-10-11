<?php

namespace App\Domain\Backup;

use App\Domain\Audit\AuditLog;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Subscriptions\SubscriptionLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Restauration d'une sauvegarde dans une NOUVELLE entreprise : les données
 * existantes ne sont jamais touchées.
 *
 * - L'archive doit être signée par ce serveur, intacte (empreintes du
 *   manifeste) et contenir l'utilisateur qui restaure parmi ses membres.
 * - Chaque identifiant reçoit un nouvel UUID ; les liens sont réécrits.
 * - Les protections de la base restent actives : les documents validés
 *   (factures, commandes, écritures…) sont insérés en brouillon avec leurs
 *   lignes, puis passent à leur état d'origine — transition autorisée par
 *   les déclencheurs. Les périodes comptables sont rouvertes le temps
 *   d'enregistrer les écritures, puis remises dans leur état.
 * - Seul l'utilisateur qui restaure reçoit l'accès (administrateur) ; les
 *   autres membres seront réinvités.
 * - Tout se fait dans une seule transaction : en cas d'erreur, rien n'est créé.
 */
final class TenantRestorer
{
    /** Tables dont l'en-tête est inséré dans un état transitoire puis finalisé. */
    private const array STAGED = [
        'sales_documents' => 'draft',
        'purchase_orders' => 'draft',
        'supplier_invoices' => 'draft',
        'journal_entries' => 'draft',
        'accounting_periods' => 'open',
    ];

    /** Ordre de finalisation (les périodes en dernier). */
    private const array FINALIZE_ORDER = ['sales_documents', 'purchase_orders', 'supplier_invoices', 'journal_entries', 'accounting_periods'];

    /** Références internes à une même table : lignes triées parent d'abord. */
    private const array SELF_REFS = [
        'product_categories' => 'parent_id',
        'sales_documents' => 'source_document_id',
        'sales_document_lines' => 'source_line_id',
        'journal_entries' => 'reversal_of_id',
    ];

    /** Accès : seul l'utilisateur qui restaure est rattaché à la nouvelle entreprise. */
    private const array SKIPPED = ['tenant_memberships', 'warehouse_memberships'];

    /** @var array<string, string> ancien identifiant => nouveau */
    private array $map = [];

    /** @var array<string, array<string, bool>> colonnes référençant users => facultative, par table */
    private array $userColumns = [];

    /** @var array<string, list<string>> colonnes JSON, par table */
    private array $jsonColumns = [];

    /** @var array<string, bool> */
    private array $existingUsers = [];

    private string $oldTenantId = '';

    private string $newTenantId = '';

    private string $ownerId = '';

    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionLifecycle $subscriptions,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Vérifie l'archive et renvoie son manifeste.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $zipPath, User $owner): array
    {
        $zip = $this->open($zipPath);

        try {
            $manifestJson = $zip->getFromName('manifest.json');
            $signature = $zip->getFromName('manifest.sig');

            if ($manifestJson === false || $signature === false) {
                throw InvalidBackup::because('not_a_backup');
            }

            if (! ArchiveSignature::verify($manifestJson, $signature)) {
                throw InvalidBackup::because('signature');
            }

            /** @var array<string, mixed> $manifest */
            $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);

            if (($manifest['format'] ?? null) !== TenantExporter::FORMAT || ($manifest['version'] ?? null) !== TenantExporter::VERSION) {
                throw InvalidBackup::because('version');
            }

            foreach ((array) $manifest['files'] as $name => $hash) {
                $stream = $zip->getStream((string) $name);

                if ($stream === false) {
                    throw InvalidBackup::because('corrupted');
                }

                $context = hash_init('sha256');
                hash_update_stream($context, $stream);
                fclose($stream);

                if (! hash_equals((string) $hash, hash_final($context))) {
                    throw InvalidBackup::because('corrupted');
                }
            }

            $members = json_decode((string) $zip->getFromName('data/users.json'), true);
            $emails = array_map(fn (mixed $m): string => mb_strtolower((string) (is_array($m) ? ($m['email'] ?? '') : '')), is_array($members) ? $members : []);

            if (! in_array(mb_strtolower($owner->email), $emails, true)) {
                throw InvalidBackup::because('not_a_member');
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    /**
     * Crée la nouvelle entreprise à partir de l'archive (vérifiée au préalable).
     *
     * @return array{tenant: Tenant, counts: array<string, int>}
     */
    public function restore(string $zipPath, User $owner, Plan $plan): array
    {
        $manifest = $this->inspect($zipPath, $owner);
        $zip = $this->open($zipPath);

        try {
            return DB::transaction(function () use ($zip, $manifest, $owner, $plan): array {
                $source = (array) $manifest['tenant'];
                $this->oldTenantId = (string) $source['id'];
                $this->ownerId = $owner->id;
                $this->loadSchema();

                $tenant = new Tenant([
                    'legal_name' => (string) $source['legal_name'],
                    'display_name' => Str::limit((string) $source['display_name'], 100, '').' '.__('organization.restore.suffix', ['date' => now()->format('d/m/Y')]),
                    'country_code' => (string) $source['country_code'],
                    'timezone' => (string) $source['timezone'],
                    'locale' => 'fr',
                ]);
                $tenant->created_by = $owner->id;
                $tenant->save();
                $this->newTenantId = $tenant->id;
                $this->map[$this->oldTenantId] = $tenant->id;

                $membership = new TenantMembership;
                $membership->tenant_id = $tenant->id;
                $membership->user_id = $owner->id;
                $membership->role_id = Role::system(PermissionCatalog::SCOPE_TENANT, PermissionCatalog::ADMIN_ROLE)->id;
                $membership->status = TenantMembership::STATUS_ACTIVE;
                $membership->save();
                $this->subscriptions->startTrial($tenant, $plan, $owner);

                return $this->context->runAs($tenant, function () use ($zip, $tenant, $owner, $manifest): array {
                    $tables = array_values(array_filter(TenantExporter::TABLES,
                        fn (string $t) => ! in_array($t, self::SKIPPED, true) && $zip->locateName("data/{$t}.jsonl") !== false));

                    foreach ($tables as $table) {
                        $this->collectIds($zip, $table);
                    }

                    $counts = [];
                    $staged = [];

                    foreach ($tables as $table) {
                        [$counts[$table], $staged[$table]] = $this->insertTable($zip, $table);
                    }

                    // Documents et écritures : retour à leur état d'origine (transition autorisée depuis le brouillon).
                    foreach (self::FINALIZE_ORDER as $table) {
                        foreach ($staged[$table] ?? [] as $status => $ids) {
                            foreach (array_chunk($ids, 500) as $chunk) {
                                DB::table($table)->whereIn('id', $chunk)->update(['status' => $status]);
                            }
                        }
                    }

                    $counts['role_permissions'] = $this->insertRolePermissions($zip);

                    foreach ((array) $manifest['tables'] as $table => $expected) {
                        if (! in_array($table, self::SKIPPED, true) && ($counts[$table] ?? 0) !== (int) $expected) {
                            throw InvalidBackup::because('incomplete', ['table' => $table]);
                        }
                    }

                    $this->copyFiles($zip);

                    $this->audit->record('backup.restored', $tenant, metadata: [
                        'source_tenant' => $this->oldTenantId,
                        'archive_created_at' => $manifest['created_at'] ?? null,
                        'rows' => array_sum($counts),
                    ], actor: $owner, tenant: $tenant);

                    return ['tenant' => $tenant, 'counts' => $counts];
                });
            });
        } catch (\Throwable $e) {
            // Transaction annulée : on retire aussi les photos déjà copiées.
            if ($this->newTenantId !== '') {
                Storage::disk('local')->deleteDirectory("tenants/{$this->newTenantId}");
            }

            throw $e;
        } finally {
            $zip->close();
        }
    }

    private function open(string $zipPath): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw InvalidBackup::because('not_a_backup');
        }

        return $zip;
    }

    private function loadSchema(): void
    {
        foreach (DB::select("SELECT c1.relname AS t, a.attname AS c, NOT a.attnotnull AS nullable FROM pg_constraint con
            JOIN pg_class c1 ON c1.oid = con.conrelid JOIN pg_class c2 ON c2.oid = con.confrelid
            JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = con.conkey[1]
            WHERE con.contype = 'f' AND c2.relname = 'users' AND array_length(con.conkey, 1) = 1") as $row) {
            $this->userColumns[$row->t][$row->c] = (bool) $row->nullable;
        }

        foreach (DB::select("SELECT table_name AS t, column_name AS c FROM information_schema.columns
            WHERE table_schema = 'public' AND data_type IN ('json', 'jsonb')") as $row) {
            $this->jsonColumns[$row->t][] = $row->c;
        }
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function rows(ZipArchive $zip, string $table): iterable
    {
        $stream = $zip->getStream("data/{$table}.jsonl");

        if ($stream === false) {
            return;
        }

        while (($line = fgets($stream)) !== false) {
            if (trim($line) !== '') {
                yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        fclose($stream);
    }

    private function collectIds(ZipArchive $zip, string $table): void
    {
        foreach ($this->rows($zip, $table) as $row) {
            if (isset($row['id']) && is_string($row['id'])) {
                $this->map[$row['id']] = (string) Str::uuid7();
            }
        }
    }

    /**
     * @return array{0: int, 1: array<string, list<string>>} nombre de lignes, identifiants à finaliser par état
     */
    private function insertTable(ZipArchive $zip, string $table): array
    {
        $rows = $this->rows($zip, $table);

        if (isset(self::SELF_REFS[$table])) {
            $rows = $this->parentsFirst(iterator_to_array($rows, false), self::SELF_REFS[$table]);
        }

        $count = 0;
        $staged = [];
        $batch = [];

        foreach ($rows as $row) {
            $row = $this->remapRow($table, $row);

            if (isset(self::STAGED[$table]) && $row['status'] !== self::STAGED[$table]) {
                $staged[$row['status']][] = $row['id'];
                $row['status'] = self::STAGED[$table];
            }

            $batch[] = $row;
            $count++;

            // Lignes d'une même table liées entre elles : insertion une à une dans l'ordre.
            if (count($batch) >= (isset(self::SELF_REFS[$table]) ? 1 : 200)) {
                DB::table($table)->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table($table)->insert($batch);
        }

        return [$count, $staged];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function parentsFirst(array $rows, string $parentColumn): array
    {
        $byId = [];

        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }

        $sorted = [];
        $done = [];
        $visit = function (string $id, int $depth) use (&$visit, &$sorted, &$done, $byId, $parentColumn): void {
            if (isset($done[$id]) || $depth > 1000) {
                return;
            }

            $parent = $byId[$id][$parentColumn] ?? null;

            if (is_string($parent) && isset($byId[$parent])) {
                $visit($parent, $depth + 1);
            }

            $done[$id] = true;
            $sorted[] = $byId[$id];
        };

        foreach (array_keys($byId) as $id) {
            $visit((string) $id, 0);
        }

        return $sorted;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function remapRow(string $table, array $row): array
    {
        foreach ($row as $column => $value) {
            if (isset($this->userColumns[$table][$column]) && is_string($value)) {
                // Auteur absent de ce serveur : facultatif → vide ; obligatoire → la personne qui restaure.
                $row[$column] = $this->userExists($value) ? $value : ($this->userColumns[$table][$column] ? null : $this->ownerId);
            } elseif (in_array($column, $this->jsonColumns[$table] ?? [], true) && is_string($value)) {
                $row[$column] = json_encode($this->remapValue(json_decode($value, true)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $row[$column] = $this->remapValue($value);
            }
        }

        $row['tenant_id'] = $this->newTenantId;

        return $row;
    }

    private function remapValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->remapValue($v), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        if (isset($this->map[$value])) {
            return $this->map[$value];
        }

        // Chemins de fichiers rangés par entreprise (photos des articles).
        $prefix = "tenants/{$this->oldTenantId}/";

        return str_starts_with($value, $prefix) ? "tenants/{$this->newTenantId}/".substr($value, strlen($prefix)) : $value;
    }

    private function userExists(string $id): bool
    {
        return $this->existingUsers[$id] ??= DB::table('users')->where('id', $id)->exists();
    }

    private function insertRolePermissions(ZipArchive $zip): int
    {
        $count = 0;

        foreach ($this->rows($zip, 'role_permissions') as $row) {
            if (! isset($this->map[$row['role_id']])) {
                throw InvalidBackup::because('corrupted');
            }

            DB::table('role_permissions')->insert(['role_id' => $this->map[$row['role_id']], 'permission_code' => $row['permission_code']]);
            $count++;
        }

        return $count;
    }

    private function copyFiles(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (! str_starts_with($name, 'fichiers/') || str_ends_with($name, '/')) {
                continue;
            }

            $relative = substr($name, strlen('fichiers/'));

            // Aucun chemin qui sortirait du dossier de l'entreprise.
            if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
                throw InvalidBackup::because('corrupted');
            }

            Storage::disk('local')->put("tenants/{$this->newTenantId}/{$relative}", (string) $zip->getFromIndex($i));
        }
    }
}
