<?php

namespace App\Domain\Backup;

use App\Models\Tenant;
use App\Models\TenantExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Sauvegarde complète d'une entreprise dans une archive ZIP :
 *
 * - data/<table>.jsonl : toutes les lignes de l'entreprise, table par table
 *   (format machine, utilisé par la restauration dans une entreprise vide) ;
 * - lisible/*.csv : articles, clients, fournisseurs, stock et ventes, à ouvrir
 *   dans Excel ;
 * - fichiers/ : photos des articles et pièces justificatives ;
 * - manifest.json : version du format, schéma, nombre de lignes et empreinte
 *   SHA-256 de chaque fichier (contrôle d'intégrité à la restauration).
 *
 * Sont exclus : les données de la plateforme (abonnement, facturation SaaS,
 * accès support), les clés d'appareils hors connexion et la file de
 * synchronisation, qui n'ont pas de sens hors de ce serveur.
 */
final class TenantExporter
{
    public const string FORMAT = 'diaba-tenant-export';

    public const int VERSION = 1;

    /** Tables métier sauvegardées (toutes portent tenant_id). */
    public const array TABLES = [
        'units', 'tax_rates', 'tenant_profiles', 'warehouses', 'roles', 'tenant_memberships', 'warehouse_memberships', 'tenant_sequences',
        'product_categories', 'products', 'product_barcodes', 'product_warehouse_settings', 'customers', 'suppliers',
        'cash_registers', 'cash_sessions', 'stock_operations', 'inventory_movements', 'inventory_balances',
        'stock_transfers', 'stock_transfer_lines', 'inventory_counts', 'inventory_count_lines',
        'purchase_orders', 'purchase_order_lines', 'goods_receipts', 'goods_receipt_lines',
        'supplier_invoices', 'supplier_invoice_lines', 'supplier_returns', 'supplier_return_lines',
        'storefronts', 'storefront_products', 'storefront_orders', 'storefront_order_items',
        'sales_documents', 'sales_document_lines', 'payments', 'cash_movements',
        'chart_accounts', 'accounting_settings', 'accounting_journals', 'account_mappings', 'fiscal_years', 'accounting_periods',
        'journal_entries', 'journal_entry_lines', 'journal_entry_attachments', 'accounting_postings', 'bank_reconciliations', 'bank_reconciliation_lines',
        'audit_events',
    ];

    /** Tables volontairement exclues (voir le commentaire de la classe). */
    public const array EXCLUDED = [
        'subscriptions', 'subscription_addons', 'saas_invoices', 'saas_payments', 'saas_credit_notes', 'support_grants',
        'offline_devices', 'sync_commands', 'notification_preferences', 'alert_digests', 'catalog_imports', 'tenant_exports', 'tenant_restores', 'cloud_backup_connections',
    ];

    private const string DISK = 'local';

    /**
     * Construit l'archive et renvoie son chemin (disque privé), sa taille,
     * son empreinte et le nombre de lignes par table.
     *
     * @return array{path: string, size: int, sha256: string, summary: array<string, int>}
     */
    public function build(Tenant $tenant, TenantExport $export): array
    {
        $relative = "exports/{$tenant->id}/{$export->id}.zip";
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(dirname($relative));
        $zipPath = $disk->path($relative);
        $work = sys_get_temp_dir().'/diaba-export-'.$export->id;
        @mkdir($work, 0700, true);

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Archive impossible à créer.');
        }

        try {
            $files = [];
            $summary = [];

            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $file = "{$work}/{$table}.jsonl";
                $summary[$table] = $this->dumpTable($tenant->id, $table, $file);
                $files["data/{$table}.jsonl"] = $file;
            }

            // Permissions des rôles personnalisés (table sans tenant_id).
            $file = "{$work}/role_permissions.jsonl";
            $handle = fopen($file, 'w') ?: throw new RuntimeException('Écriture impossible : role_permissions');
            $summary['role_permissions'] = 0;

            foreach (DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->where('r.tenant_id', $tenant->id)
                ->orderBy('rp.role_id')->orderBy('rp.permission_code')->select(['rp.role_id', 'rp.permission_code'])->cursor() as $row) {
                fwrite($handle, json_encode(['role_id' => $row->role_id, 'permission_code' => $row->permission_code], JSON_THROW_ON_ERROR).'
');
                $summary['role_permissions']++;
            }

            fclose($handle);
            $files['data/role_permissions.jsonl'] = $file;

            $members = "{$work}/members.json";
            file_put_contents($members, json_encode(DB::table('tenant_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.tenant_id', $tenant->id)->orderBy('u.name')->get(['u.id', 'u.name', 'u.email'])->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $files['data/users.json'] = $members;

            foreach ($this->readableCsv($tenant->id, $work) as $name => $file) {
                $files["lisible/{$name}"] = $file;
            }

            foreach ($disk->allFiles("tenants/{$tenant->id}") as $stored) {
                $files['fichiers/'.substr($stored, strlen("tenants/{$tenant->id}/"))] = $disk->path($stored);
            }

            $manifest = [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'created_at' => now()->toIso8601String(),
                'schema' => (int) DB::table('migrations')->max('batch').':'.DB::table('migrations')->count(),
                'tenant' => ['id' => $tenant->id, 'legal_name' => $tenant->legal_name, 'display_name' => $tenant->display_name,
                    'country_code' => $tenant->country_code, 'currency_code' => $tenant->currency_code, 'timezone' => $tenant->timezone],
                'tables' => $summary,
                'files' => collect($files)->map(fn (string $path) => hash_file('sha256', $path))->all(),
            ];

            foreach ($files as $name => $path) {
                $zip->addFile($path, $name);
            }

            $manifestJson = (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $zip->addFromString('manifest.json', $manifestJson);
            // Signature du serveur : seules ses propres archives, intactes, sont restaurables.
            $zip->addFromString('manifest.sig', ArchiveSignature::sign($manifestJson));
            $zip->addFromString('LISEZ-MOI.txt', $this->readme($tenant));

            if (! $zip->close()) {
                throw new RuntimeException('Archive impossible à finaliser.');
            }
        } finally {
            array_map('unlink', glob("{$work}/*") ?: []);
            @rmdir($work);
        }

        return ['path' => $relative, 'size' => (int) filesize($zipPath), 'sha256' => (string) hash_file('sha256', $zipPath), 'summary' => $summary];
    }

    /**
     * Une ligne JSON par enregistrement, sans charger la table en mémoire.
     */
    private function dumpTable(string $tenantId, string $table, string $file): int
    {
        $handle = fopen($file, 'w') ?: throw new RuntimeException("Écriture impossible : {$table}");
        $count = 0;
        $query = DB::table($table)->where('tenant_id', $tenantId);
        $rows = Schema::hasColumn($table, 'id') ? $query->lazyById(500) : $query->orderByRaw('1')->cursor();

        foreach ($rows as $row) {
            fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            $count++;
        }

        fclose($handle);

        return $count;
    }

    /**
     * Extraits lisibles dans un tableur (séparateur « ; », UTF-8 avec BOM).
     *
     * @return array<string, string>
     */
    private function readableCsv(string $tenantId, string $work): array
    {
        $sheets = [
            'articles.csv' => [['Code', 'Désignation', 'Catégorie', 'Unité', 'Prix de vente TTC (XOF)', 'Coût standard (XOF)', 'Archivé'],
                DB::table('products as p')->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')->join('units as u', 'u.id', '=', 'p.unit_id')
                    ->where('p.tenant_id', $tenantId)->orderBy('p.sku')
                    ->get(['p.sku', 'p.name', 'c.name as category', 'u.code as unit', 'p.sale_price', 'p.standard_cost', 'p.archived_at'])
                    ->map(fn ($r) => [$r->sku, $r->name, $r->category, $r->unit, $r->sale_price, $r->standard_cost, $r->archived_at ? 'oui' : 'non'])],
            'clients.csv' => [['Code', 'Nom', 'Téléphone', 'Email', 'Adresse', 'Ville', 'NINEA', 'Plafond de crédit (XOF)'],
                DB::table('customers')->where('tenant_id', $tenantId)->orderBy('name')
                    ->get()->map(fn ($r) => [$r->code, $r->name, $r->phone, $r->email, $r->address_line, $r->city, $r->tax_number, $r->credit_limit])],
            'fournisseurs.csv' => [['Code', 'Nom', 'Téléphone', 'Email', 'Adresse', 'Ville', 'NINEA'],
                DB::table('suppliers')->where('tenant_id', $tenantId)->orderBy('name')
                    ->get()->map(fn ($r) => [$r->code, $r->name, $r->phone, $r->email, $r->address_line, $r->city, $r->tax_number])],
            'stock.csv' => [['Dépôt', 'Code', 'Désignation', 'Quantité', 'Coût moyen (XOF)'],
                DB::table('inventory_balances as b')->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')->join('products as p', 'p.id', '=', 'b.product_id')
                    ->where('b.tenant_id', $tenantId)->orderBy('w.name')->orderBy('p.sku')
                    ->get(['w.name as warehouse', 'p.sku', 'p.name', 'b.quantity_on_hand', 'b.average_cost'])
                    ->map(fn ($r) => [$r->warehouse, $r->sku, $r->name, str_replace('.', ',', rtrim(rtrim((string) $r->quantity_on_hand, '0'), '.')), $r->average_cost])],
            'ventes.csv' => [['Numéro', 'Type', 'Date', 'Client', 'Total TTC (XOF)', 'Payé (XOF)', 'Avoirs (XOF)', 'État'],
                DB::table('sales_documents as d')->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
                    ->where('d.tenant_id', $tenantId)->whereNotNull('d.number')->orderBy('d.number')
                    ->get(['d.number', 'd.type', 'd.validated_at', 'c.name as customer', 'd.total', 'd.amount_paid', 'd.amount_credited', 'd.status'])
                    ->map(fn ($r) => [$r->number, __("sales.types.{$r->type}"), $r->validated_at ? substr((string) $r->validated_at, 0, 10) : '', $r->customer ?? 'Client comptoir', $r->total, $r->amount_paid, $r->amount_credited, $r->status])],
        ];

        $files = [];

        foreach ($sheets as $name => [$header, $rows]) {
            $path = "{$work}/{$name}";
            $out = fopen($path, 'w') ?: throw new RuntimeException("Écriture impossible : {$name}");
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, $row, ';', '"', '');
            }

            fclose($out);
            $files[$name] = $path;
        }

        return $files;
    }

    private function readme(Tenant $tenant): string
    {
        return implode("\n", [
            "Sauvegarde DIABA GESCOM — {$tenant->display_name}",
            'Créée le '.now()->timezone($tenant->timezone)->format('d/m/Y à H:i'),
            '',
            'Dossier « lisible » : vos articles, clients, fournisseurs, stock et ventes,',
            'à ouvrir avec Excel ou LibreOffice.',
            '',
            'Dossiers « data » et « fichiers » et fichier manifest.json : sauvegarde complète',
            'utilisée par DIABA GESCOM pour restaurer vos données. Ne les modifiez pas :',
            'toute modification rend la sauvegarde inutilisable.',
            '',
            'Ce fichier contient des données confidentielles (clients, ventes) :',
            'conservez-le en lieu sûr.',
            '',
        ]);
    }
}
