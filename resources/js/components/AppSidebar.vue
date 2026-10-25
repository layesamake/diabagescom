<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    BookOpen,
    Building2,
    ChartColumn,
    Compass,
    FileText,
    Layers,
    LayoutGrid,
    LifeBuoy,
    Package,
    Receipt,
    Settings,
    ClipboardList,
    ShoppingBasket,
    ShoppingBag,
    ShoppingCart,
    Truck,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed, watch } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import type { NavGroup } from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { t } from '@/i18n';
import { useAppContext } from '@/composables/useAppContext';
import { useGuide } from '@/composables/useGuide';
import { dashboard } from '@/routes';
import { index as accountingIndex } from '@/routes/accounting';
import { index as cashIndex } from '@/routes/cash';
import { index as customersIndex } from '@/routes/customers';
import { index as guideIndex } from '@/routes/guide';
import { index as helpIndex } from '@/routes/help';
import { create as posCreate } from '@/routes/pos';
import { index as productsIndex } from '@/routes/products';
import { index as reportsIndex } from '@/routes/reports';
import { index as settingsIndex } from '@/routes/settings';
import { index as purchaseInvoicesIndex } from '@/routes/purchases/invoices';
import { index as purchaseOrdersIndex } from '@/routes/purchases/orders';
import { index as purchaseRequestsIndex } from '@/routes/purchases/requests';
import { index as salesIndex } from '@/routes/sales';
import { index as stockIndex } from '@/routes/stock';
import { index as storefrontIndex } from '@/routes/storefront';
import { index as suppliersIndex } from '@/routes/suppliers';
import { dashboard as platformDashboard } from '@/routes/platform';
import { index as platformBilling } from '@/routes/platform/billing';
import { index as platformPlans } from '@/routes/platform/plans';
import { index as platformTenants } from '@/routes/platform/tenants';
import type { NavItem } from '@/types';

const page = usePage();
const { can } = useAppContext();
const guide = useGuide();
const { isMobile, setOpen, setOpenMobile } = useSidebar();

// Après un choix dans le menu, le volet laisse la place à la page : fermé sur
// téléphone, réduit aux icônes sur tablette et petit écran (moins de 1280 px).
watch(
    () => page.url,
    () => {
        if (isMobile.value) {
            setOpenMobile(false);
        } else if (window.matchMedia('(max-width: 1279px)').matches) {
            setOpen(false);
        }
    },
);

// Rubriques regroupées par métier, affichées selon les permissions
// (le serveur revérifie tout) ; un groupe vide n'est pas affiché.
const navGroups = computed<NavGroup[]>(() => {
    const item = (
        show: boolean,
        title: string,
        href: NavItem['href'],
        icon: NavItem['icon'],
        extra: Pick<NavItem, 'badge' | 'tour' | 'external' | 'tooltip'> = {},
    ) => (show ? [{ title, href, icon, ...extra }] : []);
    const accounting = Boolean(
        (
            page.props.subscription as {
                features?: { accounting?: boolean };
            } | null
        )?.features?.accounting,
    );

    return [
        {
            items: [
                ...item(
                    Boolean(page.props.context),
                    t('nav.dashboard'),
                    dashboard(),
                    LayoutGrid,
                ),
                // Guide : progression visible tant qu'il reste des étapes à faire.
                ...item(
                    guide.total.value > 0,
                    t('nav.guide'),
                    guideIndex(),
                    Compass,
                    {
                        tour: 'nav-guide',
                        badge: guide.complete.value
                            ? undefined
                            : `${guide.handled.value}/${guide.total.value}`,
                    },
                ),
            ],
        },
        {
            label: t('nav.group_sell'),
            tour: 'nav-sell',
            items: [
                ...item(
                    can('sale.create') && can('sale.validate'),
                    t('sales.pos'),
                    posCreate(),
                    ShoppingCart,
                ),
                ...item(
                    can('sale.view'),
                    t('sales.nav'),
                    salesIndex(),
                    FileText,
                ),
                ...item(
                    can('cash.view_own') || can('cash.view_all'),
                    t('cash.nav'),
                    cashIndex(),
                    Wallet,
                ),
                ...item(
                    can('customer.view'),
                    t('parties.customers'),
                    customersIndex(),
                    Users,
                ),
            ],
        },
        {
            label: t('nav.group_buy'),
            tour: 'nav-buy',
            items: [
                ...item(
                    can('purchase_request.view'),
                    t('purchases.requests.nav'),
                    purchaseRequestsIndex(),
                    ClipboardList,
                ),
                ...item(
                    can('purchase.view'),
                    t('purchases.nav'),
                    purchaseOrdersIndex(),
                    ShoppingBasket,
                ),
                ...item(
                    can('purchase.view'),
                    t('purchases.invoices'),
                    purchaseInvoicesIndex(),
                    Receipt,
                ),
                ...item(
                    can('supplier.view'),
                    t('parties.suppliers'),
                    suppliersIndex(),
                    Truck,
                ),
            ],
        },
        {
            label: t('nav.group_stock'),
            tour: 'nav-stock',
            items: [
                ...item(
                    can('catalog.view'),
                    t('catalog.nav'),
                    productsIndex(),
                    Package,
                ),
                ...item(
                    Boolean(
                        (
                            page.props.subscription as {
                                features?: { online_store?: boolean };
                            } | null
                        )?.features?.online_store,
                    ) && can('catalog.view'),
                    t('storefront.nav'),
                    storefrontIndex(),
                    ShoppingBag,
                ),
                ...item(
                    can('inventory.view'),
                    t('stock.nav'),
                    stockIndex(),
                    Boxes,
                ),
            ],
        },
        {
            label: t('nav.group_manage'),
            tour: 'nav-manage',
            items: [
                ...item(
                    can('report.view_own') || can('report.view_all'),
                    t('reports.nav'),
                    reportsIndex(),
                    ChartColumn,
                ),
                // Comptabilité : formule « Compta » et permission (revérifiées côté serveur).
                ...item(
                    can('accounting.view') && accounting,
                    t('accounting.nav'),
                    accountingIndex(),
                    BookOpen,
                ),
                // Paramètres (profil, sécurité, dépôts, sauvegarde…) : visibles de tous.
                ...item(
                    Boolean(page.props.context),
                    t('nav.settings'),
                    settingsIndex(),
                    Settings,
                ),
                ...item(
                    Boolean(page.props.context),
                    t('nav.help_center'),
                    helpIndex(),
                    LifeBuoy,
                ),
                ...item(
                    Boolean(page.props.context),
                    'Idées & signalements',
                    'https://forms.gle/U7MjCuVzzAiTsXQX6',
                    LifeBuoy,
                    {
                        external: true,
                        tooltip:
                            'Signalez un problème ou partagez une idée pour améliorer Diaba Cloud.',
                    },
                ),
            ],
        },
        {
            // Console plateforme : super-administrateur uniquement (vérifié côté serveur).
            label: t('nav.group_platform'),
            items: page.props.platform?.can_access
                ? [
                      {
                          title: t('platform.dashboard_title'),
                          href: platformDashboard(),
                          icon: LayoutGrid,
                      },
                      {
                          title: t('platform.tenants_title'),
                          href: platformTenants(),
                          icon: Building2,
                      },
                      {
                          title: t('platform.plans_title'),
                          href: platformPlans(),
                          icon: Layers,
                      },
                      {
                          title: t('billing.console_title'),
                          href: platformBilling(),
                          icon: Receipt,
                      },
                  ]
                : [],
        },
    ];
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :groups="navGroups" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
