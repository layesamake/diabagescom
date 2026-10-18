<?php

namespace App\Domain\Onboarding;

use App\Domain\Identity\Authorizer;
use App\Domain\Identity\PermissionCatalog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Guide de démarrage : les étapes qui orientent chaque utilisateur vers ce
 * qu'il doit faire, adaptées à ses permissions (donc à son rôle) et à la
 * formule de l'entreprise. Une étape se coche d'elle-même quand la donnée
 * existe (un article, une vente…) ; sinon l'utilisateur la coche ou la passe.
 *
 * Pour un non-administrateur, « avoir fait » signifie « l'avoir fait soi-même » :
 * un nouveau vendeur apprend à ouvrir sa caisse même si celle de l'entreprise
 * a déjà été ouverte par d'autres.
 */
final class StartGuide
{
    public const string PHASE_BEFORE = 'before';

    public const string PHASE_GOAL = 'goal';

    public const string PHASE_AFTER = 'after';

    public const string DONE = 'done';

    public const string SKIPPED = 'skipped';

    public const string TODO = 'todo';

    /**
     * Étapes dans l'ordre d'affichage.
     *
     * - permissions : l'étape est proposée à qui en a au moins une ;
     * - except : parcours à qui l'étape n'est pas proposée (l'administrateur a toutes
     *   les permissions : sans cela il recevrait aussi les étapes des autres métiers) ;
     * - feature : formule requise (comptabilité, boutique) ;
     * - route / pages : où mener l'étape, et pages où le bandeau s'affiche ;
     * - remind_on : pages où un rappel doux s'affiche tant que l'étape n'est pas faite ;
     * - table / where / owner : détection automatique (existence d'une ligne),
     *   `owner` étant la colonne « fait par » ; `sql` : détection sur mesure ;
     *   aucune des deux : l'utilisateur coche lui-même l'étape.
     *
     * @var array<string, array<string, mixed>>
     */
    private const array STEPS = [
        'company' => [
            'phase' => self::PHASE_BEFORE, 'permissions' => ['organization.manage'],
            'route' => 'company.edit', 'pages' => ['settings/Company'],
            'sql' => "EXISTS (SELECT 1 FROM tenant_profiles WHERE tenant_id = {T} AND (logo_path IS NOT NULL OR COALESCE(ninea, '') <> '' OR COALESCE(address, '') <> '' OR COALESCE(phone, '') <> ''))",
        ],
        // Mode simple (lot 4) : enregistrer la voix dans la langue locale de
        // l'entreprise. Pas de détection automatique (table/sql) : le
        // contrôleur coche lui-même l'étape dès que les 71 enregistrements
        // « cœur » (Communs, Chiffres, Vendre, Caisse) existent.
        'voice' => [
            'phase' => self::PHASE_BEFORE, 'permissions' => ['organization.manage'],
            'route' => 'simple.voice.index', 'pages' => ['simple/Voix'],
        ],
        'products' => [
            'phase' => self::PHASE_BEFORE, 'permissions' => ['catalog.create'],
            'route' => 'products.create', 'pages' => ['catalog/Products', 'catalog/ProductForm', 'catalog/ProductImport'],
            'remind_on' => ['sales/Pos'],
            'table' => 'products',
        ],
        'stock' => [
            'phase' => self::PHASE_BEFORE, 'permissions' => ['inventory.adjust'],
            'route' => 'stock.index', 'pages' => ['inventory/Stock', 'inventory/Movements'],
            'table' => 'inventory_movements',
        ],
        'cash' => [
            'phase' => self::PHASE_BEFORE, 'permissions' => ['cash.open_close'],
            'route' => 'cash.index', 'pages' => ['cash/Registers', 'cash/SessionShow'],
            'table' => 'cash_sessions', 'owner' => 'opened_by',
        ],
        'sale' => [
            'phase' => self::PHASE_GOAL, 'permissions' => ['sale.validate'],
            'route' => 'pos.create', 'pages' => ['sales/Pos'],
            'table' => 'sales_documents', 'where' => "status <> 'draft'", 'owner' => 'created_by',
        ],
        'customer' => [
            'phase' => self::PHASE_AFTER, 'permissions' => ['customer.manage'],
            'route' => 'customers.index', 'pages' => [],
            'table' => 'customers',
        ],
        'team' => [
            'phase' => self::PHASE_AFTER, 'permissions' => ['member.invite'],
            'route' => 'members.index', 'pages' => ['settings/Members'],
            'sql' => '(SELECT COUNT(*) FROM tenant_memberships WHERE tenant_id = {T}) > 1',
        ],
        'backup' => [
            'phase' => self::PHASE_AFTER, 'permissions' => ['organization.manage'],
            'route' => 'backup.index', 'pages' => ['settings/Backup'],
            'sql' => "(EXISTS (SELECT 1 FROM tenant_exports WHERE tenant_id = {T} AND status IN ('ready', 'expired')) OR EXISTS (SELECT 1 FROM cloud_backup_connections WHERE tenant_id = {T}))",
        ],
        'supplier' => [
            'phase' => self::PHASE_AFTER, 'permissions' => ['supplier.manage'],
            'route' => 'suppliers.index', 'pages' => [],
            'table' => 'suppliers',
        ],
        'order' => [
            'phase' => self::PHASE_AFTER, 'except' => ['admin'], 'permissions' => ['purchase.create'],
            'route' => 'purchases.orders.create', 'pages' => ['purchases/OrderForm'],
            'table' => 'purchase_orders', 'owner' => 'created_by',
        ],
        'receipt' => [
            'phase' => self::PHASE_AFTER, 'except' => ['admin'], 'permissions' => ['purchase.receive'],
            'route' => 'purchases.orders.index', 'pages' => ['purchases/Orders'],
            'table' => 'goods_receipts', 'owner' => 'created_by',
        ],
        'count' => [
            'phase' => self::PHASE_AFTER, 'except' => ['admin'], 'permissions' => ['inventory.count_create'],
            'route' => 'counts.index', 'pages' => ['inventory/Counts'],
            'table' => 'inventory_counts', 'owner' => 'created_by',
        ],
        'accounting' => [
            'phase' => self::PHASE_AFTER, 'permissions' => ['accounting.settings'], 'feature' => FeatureAccess::FEATURE_ACCOUNTING,
            'route' => 'accounting.index', 'pages' => ['accounting/Overview'],
        ],
        'entry' => [
            'phase' => self::PHASE_AFTER, 'except' => ['admin'], 'permissions' => ['accounting.manual_entry'], 'feature' => FeatureAccess::FEATURE_ACCOUNTING,
            'route' => 'accounting.index', 'pages' => [],
            'table' => 'journal_entries', 'owner' => 'created_by',
        ],
        'reports' => [
            'phase' => self::PHASE_AFTER, 'except' => ['admin'], 'permissions' => ['report.view_own', 'report.view_all'],
            'route' => 'reports.index', 'pages' => ['reports/Sales'],
        ],
    ];

    /** Parcours selon le rôle intégré ; un rôle personnalisé suit le parcours « member ». */
    private const array TRACKS = [
        'admin' => 'admin',
        'cashier' => 'cashier',
        'warehouse_manager' => 'warehouse',
        'purchaser' => 'purchaser',
        'accountant' => 'accountant',
        'management' => 'management',
    ];

    /** Étapes de mise en route mesurées pour toute une entreprise (console plateforme). */
    public const array MILESTONES = ['company', 'products', 'stock', 'cash', 'sale', 'customer', 'team', 'backup'];

    /** Entreprises comptées : actives, avec un abonnement non résilié. */
    private const string LIVE_TENANT = "t.status = 'active' AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.tenant_id = t.id AND s.status <> 'cancelled')";

    public function __construct(
        private readonly TenantContext $context,
        private readonly Authorizer $authorizer,
        private readonly FeatureAccess $features,
    ) {}

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::STEPS);
    }

    /**
     * Le guide de l'utilisateur dans l'entreprise courante ; null hors entreprise.
     *
     * @return array{track: string, steps: list<array<string, mixed>>, next: string|null, dismissed: bool, tour_seen: bool}|null
     */
    public function summary(User $user): ?array
    {
        $membership = $this->context->membership();

        if (! $this->context->has() || $membership === null) {
            return null;
        }

        $tenantId = $this->context->tenantId();
        $steps = $this->visibleSteps($user);
        $detected = $this->detect($steps, $tenantId, $user->id, own: $membership->role->code !== PermissionCatalog::ADMIN_ROLE);
        $marks = DB::table('guide_steps')->where('tenant_id', $tenantId)->where('user_id', $user->id)->pluck('status', 'step_key');
        $state = DB::table('guide_states')->where('tenant_id', $tenantId)->where('user_id', $user->id)->first();

        $list = [];
        $next = null;

        foreach ($steps as $key => $step) {
            $auto = $detected[$key] ?? false;
            $mark = $marks[$key] ?? null;
            $status = $auto || $mark === self::DONE ? self::DONE : ($mark === self::SKIPPED ? self::SKIPPED : self::TODO);
            $next ??= $status === self::TODO ? $key : null;

            $list[] = [
                'key' => $key,
                'phase' => $step['phase'],
                'status' => $status,
                'auto' => $auto,
                'detectable' => isset($step['table']) || isset($step['sql']),
                'href' => route($step['route'], absolute: false),
                'pages' => $step['pages'],
                'remind_on' => $step['remind_on'] ?? [],
            ];
        }

        return [
            'track' => $this->track(),
            'steps' => $list,
            'next' => $next,
            'dismissed' => $state?->dismissed_at !== null,
            'tour_seen' => $state?->tour_seen_at !== null,
        ];
    }

    /**
     * Coche, passe ou rouvre une étape (self::DONE, SKIPPED ou TODO).
     */
    public function mark(User $user, string $key, string $status): void
    {
        if (! array_key_exists($key, $this->visibleSteps($user))) {
            throw new InvalidArgumentException("Étape inconnue ou non proposée : {$key}");
        }

        $where = ['tenant_id' => $this->context->tenantId(), 'user_id' => $user->id, 'step_key' => $key];

        if ($status === self::TODO) {
            DB::table('guide_steps')->where($where)->delete();

            return;
        }

        if (! in_array($status, [self::DONE, self::SKIPPED], true)) {
            throw new InvalidArgumentException("Statut inconnu : {$status}");
        }

        DB::table('guide_steps')->upsert([...$where, 'status' => $status, 'created_at' => now(), 'updated_at' => now()], ['tenant_id', 'user_id', 'step_key'], ['status', 'updated_at']);
    }

    /**
     * Masque ou réaffiche la carte de l'accueil ; note que la visite a été vue.
     */
    public function setState(User $user, ?bool $dismissed = null, ?bool $tourSeen = null): void
    {
        $values = [];

        if ($dismissed !== null) {
            $values['dismissed_at'] = $dismissed ? now() : null;
        }

        if ($tourSeen !== null) {
            $values['tour_seen_at'] = $tourSeen ? now() : null;
        }

        if ($values === []) {
            return;
        }

        DB::table('guide_states')->upsert(
            ['tenant_id' => $this->context->tenantId(), 'user_id' => $user->id, 'created_at' => now(), ...$values, 'updated_at' => now()],
            ['tenant_id', 'user_id'],
            [...array_keys($values), 'updated_at'],
        );
    }

    /**
     * Parcours de l'utilisateur dans l'entreprise courante (selon son rôle).
     */
    public function track(): string
    {
        $code = $this->context->membership()?->role->code;

        return $code !== null ? (self::TRACKS[$code] ?? 'member') : 'member';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function visibleSteps(User $user): array
    {
        $tenant = $this->context->tenant();
        $track = $this->track();

        return array_filter(self::STEPS, function (array $step) use ($user, $tenant, $track): bool {
            if (in_array($track, $step['except'] ?? [], true)) {
                return false;
            }

            if (isset($step['feature']) && ! $this->features->hasFeature($tenant, $step['feature'])) {
                return false;
            }

            foreach ($step['permissions'] as $permission) {
                if ($this->authorizer->allows($user, $permission)) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Étapes détectables déjà réalisées : une seule requête pour toutes.
     *
     * @param  array<string, array<string, mixed>>  $steps
     * @return array<string, bool>
     */
    private function detect(array $steps, string $tenantId, string $userId, bool $own): array
    {
        $pdo = DB::connection()->getPdo();
        $tenant = (string) $pdo->quote($tenantId);
        $user = $own ? (string) $pdo->quote($userId) : null;
        $selects = [];

        foreach ($steps as $key => $step) {
            $sql = $this->existsSql($step, $tenant, $user);

            if ($sql !== null) {
                $selects[] = "{$sql} AS \"{$key}\"";
            }
        }

        if ($selects === []) {
            return [];
        }

        return array_map(
            fn (mixed $value): bool => in_array($value, [true, 1, '1', 't', 'true'], true),
            (array) DB::selectOne('SELECT '.implode(', ', $selects)),
        );
    }

    /**
     * Condition SQL « cette étape est faite » : `$tenant` est une valeur déjà
     * échappée ou une colonne (t.id), `$user` limite à ce que cet utilisateur a fait.
     * Null pour une étape que l'utilisateur coche lui-même.
     *
     * @param  array<string, mixed>  $step
     */
    private function existsSql(array $step, string $tenant, ?string $user): ?string
    {
        if (isset($step['sql'])) {
            return str_replace('{T}', $tenant, $step['sql']);
        }

        if (isset($step['table'])) {
            return "EXISTS (SELECT 1 FROM {$step['table']} WHERE tenant_id = {$tenant}"
                .(isset($step['where']) ? " AND {$step['where']}" : '')
                .($user !== null && isset($step['owner']) ? " AND {$step['owner']} = {$user}" : '').')';
        }

        return null;
    }

    /**
     * Jalons d'activation d'une entreprise (console plateforme) : pour chaque
     * étape de mise en route, oui ou non. Rien d'autre n'est lu : ni noms, ni quantités, ni montants.
     *
     * @return array<string, bool>
     */
    public function milestones(string $tenantId): array
    {
        $steps = array_intersect_key(self::STEPS, array_flip(self::MILESTONES));
        $done = $this->detect($steps, $tenantId, '', false);

        return array_combine(self::MILESTONES, array_map(fn (string $key): bool => $done[$key] ?? false, self::MILESTONES));
    }

    /**
     * Combien d'entreprises actives ont atteint chaque jalon.
     *
     * @return array{total: int, steps: array<string, int>}
     */
    public function funnel(): array
    {
        $selects = ['COUNT(*) AS total'];

        foreach (self::MILESTONES as $key) {
            $selects[] = 'COUNT(*) FILTER (WHERE '.$this->existsSql(self::STEPS[$key], 't.id', null).") AS \"{$key}\"";
        }

        $row = (array) DB::selectOne('SELECT '.implode(', ', $selects).' FROM tenants t WHERE '.self::LIVE_TENANT);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'steps' => array_combine(self::MILESTONES, array_map(fn (string $key): int => (int) ($row[$key] ?? 0), self::MILESTONES)),
        ];
    }

    /**
     * Entreprises actives inscrites depuis plus de `$days` jours sans première
     * vente : à relancer, avec la prochaine étape qui leur manque.
     *
     * @return list<array{id: string, name: string, created_at: string, next: string|null}>
     */
    public function stalled(int $days = 3, int $limit = 10): array
    {
        $sale = $this->existsSql(self::STEPS['sale'], 't.id', null);
        /** @var list<\stdClass> $rows */
        $rows = DB::select(
            'SELECT t.id, t.display_name, t.created_at FROM tenants t WHERE '.self::LIVE_TENANT." AND t.created_at <= ? AND NOT {$sale} ORDER BY t.created_at LIMIT ?",
            [now()->subDays($days), $limit],
        );

        return array_map(function (\stdClass $row): array {
            $next = array_search(false, $this->milestones((string) $row->id), true);

            return [
                'id' => (string) $row->id,
                'name' => (string) $row->display_name,
                'created_at' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
                'next' => $next === false ? null : (string) $next,
            ];
        }, $rows);
    }
}
