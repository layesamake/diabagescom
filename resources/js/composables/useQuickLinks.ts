import { usePage } from '@inertiajs/vue3';
import {
    Boxes,
    BookOpen,
    Building2,
    ChartColumn,
    ClipboardCheck,
    ClipboardList,
    Compass,
    DatabaseBackup,
    FilePlus2,
    FileText,
    LayoutGrid,
    Package,
    PackagePlus,
    Receipt,
    Settings,
    ShoppingBasket,
    ShoppingCart,
    Truck,
    Users,
    Wallet,
    ArrowLeftRight,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useAppContext } from '@/composables/useAppContext';
import { useGuide } from '@/composables/useGuide';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import { dashboard } from '@/routes';
import { index as accountingIndex } from '@/routes/accounting';
import { index as backupIndex } from '@/routes/backup';
import { index as cashIndex } from '@/routes/cash';
import { edit as companyEdit } from '@/routes/company';
import { index as countsIndex } from '@/routes/counts';
import { index as customersIndex } from '@/routes/customers';
import { index as guideIndex } from '@/routes/guide';
import { index as membersIndex } from '@/routes/members';
import { create as posCreate } from '@/routes/pos';
import {
    create as productCreate,
    index as productsIndex,
} from '@/routes/products';
import { index as purchaseInvoicesIndex } from '@/routes/purchases/invoices';
import { index as purchaseOrdersIndex } from '@/routes/purchases/orders';
import { index as purchaseRequestsIndex } from '@/routes/purchases/requests';
import { index as reportsIndex } from '@/routes/reports';
import { create as salesCreate, index as salesIndex } from '@/routes/sales';
import { index as settingsIndex } from '@/routes/settings';
import { index as stockIndex } from '@/routes/stock';
import { index as suppliersIndex } from '@/routes/suppliers';
import { index as transfersIndex } from '@/routes/transfers';

export type QuickLink = {
    key: string;
    title: string;
    icon: Component;
    href: string;
    /** Mots que l'on pourrait taper pour chercher cette page (sans accents). */
    keywords: string;
};

/**
 * Pages et actions que l'utilisateur peut ouvrir, selon ses permissions
 * (le serveur revérifie chaque accès). Sert à la recherche globale.
 */
export function useQuickLinks() {
    const page = usePage();
    const { context, can } = useAppContext();
    const guide = useGuide();

    const links = computed<QuickLink[]>(() => {
        const accounting = Boolean(
            (
                page.props.subscription as {
                    features?: { accounting?: boolean };
                } | null
            )?.features?.accounting,
        );
        const any = (...permissions: string[]) => permissions.some(can);
        const keywords = (key: string) =>
            t(`search.keywords.${key}` as TranslationKey);

        const all: (Omit<QuickLink, 'keywords'> & { show: boolean })[] = [
            {
                key: 'dashboard',
                title: t('nav.dashboard'),
                icon: LayoutGrid,
                href: dashboard().url,
                show: Boolean(context.value),
            },
            {
                key: 'pos',
                title: t('sales.pos'),
                icon: ShoppingCart,
                href: posCreate().url,
                show: can('sale.create') && can('sale.validate'),
            },
            {
                key: 'sales',
                title: t('sales.nav'),
                icon: FileText,
                href: salesIndex().url,
                show: can('sale.view'),
            },
            {
                key: 'quote',
                title: t('sales.new_quote'),
                icon: FilePlus2,
                href: salesCreate('quote').url,
                show: can('sale.create'),
            },
            {
                key: 'cash',
                title: t('cash.nav'),
                icon: Wallet,
                href: cashIndex().url,
                show: any('cash.view_own', 'cash.view_all'),
            },
            {
                key: 'customers',
                title: t('parties.customers'),
                icon: Users,
                href: customersIndex().url,
                show: can('customer.view'),
            },
            {
                key: 'suppliers',
                title: t('parties.suppliers'),
                icon: Truck,
                href: suppliersIndex().url,
                show: can('supplier.view'),
            },
            {
                key: 'orders',
                title: t('purchases.nav'),
                icon: ShoppingBasket,
                href: purchaseOrdersIndex().url,
                show: can('purchase.view'),
            },
            {
                key: 'invoices',
                title: t('purchases.invoices'),
                icon: Receipt,
                href: purchaseInvoicesIndex().url,
                show: can('purchase.view'),
            },
            {
                key: 'requests',
                title: t('purchases.requests.nav'),
                icon: ClipboardList,
                href: purchaseRequestsIndex().url,
                show: can('purchase_request.view'),
            },
            {
                key: 'products',
                title: t('catalog.nav'),
                icon: Package,
                href: productsIndex().url,
                show: can('catalog.view'),
            },
            {
                key: 'new_product',
                title: t('search.new_product'),
                icon: PackagePlus,
                href: productCreate().url,
                show: can('catalog.create'),
            },
            {
                key: 'stock',
                title: t('stock.nav'),
                icon: Boxes,
                href: stockIndex().url,
                show: can('inventory.view'),
            },
            {
                key: 'transfers',
                title: t('stock.transfers'),
                icon: ArrowLeftRight,
                href: transfersIndex().url,
                show: can('inventory.view'),
            },
            {
                key: 'counts',
                title: t('stock.counts'),
                icon: ClipboardCheck,
                href: countsIndex().url,
                show: can('inventory.view'),
            },
            {
                key: 'reports',
                title: t('reports.nav'),
                icon: ChartColumn,
                href: reportsIndex().url,
                show: any('report.view_own', 'report.view_all'),
            },
            {
                key: 'accounting',
                title: t('accounting.nav'),
                icon: BookOpen,
                href: accountingIndex().url,
                show: can('accounting.view') && accounting,
            },
            {
                key: 'company',
                title: t('company.nav'),
                icon: Building2,
                href: companyEdit().url,
                show: can('organization.view'),
            },
            {
                key: 'members',
                title: t('team.nav'),
                icon: Users,
                href: membersIndex().url,
                show: can('member.view'),
            },
            {
                key: 'backup',
                title: t('backup.nav'),
                icon: DatabaseBackup,
                href: backupIndex().url,
                show: can('organization.manage'),
            },
            {
                key: 'settings',
                title: t('nav.settings'),
                icon: Settings,
                href: settingsIndex().url,
                show: Boolean(context.value),
            },
            {
                key: 'guide',
                title: t('nav.guide'),
                icon: Compass,
                href: guideIndex().url,
                show: guide.total.value > 0,
            },
        ];

        return all
            .filter((link) => link.show)
            .map((link) => ({
                key: link.key,
                title: link.title,
                icon: link.icon,
                href: link.href,
                keywords: keywords(link.key),
            }));
    });

    return { links };
}
