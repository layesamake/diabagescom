<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    Building2,
    FileText,
    Layers,
    LayoutGrid,
    Package,
    ShoppingBasket,
    ShoppingCart,
    Truck,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { t } from '@/i18n';
import { useAppContext } from '@/composables/useAppContext';
import { dashboard } from '@/routes';
import { index as cashIndex } from '@/routes/cash';
import { index as customersIndex } from '@/routes/customers';
import { create as posCreate } from '@/routes/pos';
import { index as productsIndex } from '@/routes/products';
import { index as purchaseInvoicesIndex } from '@/routes/purchases/invoices';
import { index as purchaseOrdersIndex } from '@/routes/purchases/orders';
import { index as salesIndex } from '@/routes/sales';
import { index as stockIndex } from '@/routes/stock';
import { index as suppliersIndex } from '@/routes/suppliers';
import { index as platformPlans } from '@/routes/platform/plans';
import { index as platformTenants } from '@/routes/platform/tenants';
import type { NavItem } from '@/types';

const page = usePage();
const { can } = useAppContext();

const mainNavItems = computed<NavItem[]>(() => [
    ...(page.props.context
        ? [{ title: t('nav.dashboard'), href: dashboard(), icon: LayoutGrid }]
        : []),
    // Rubriques métier : affichées selon les permissions (le serveur revérifie tout).
    ...(can('sale.create') && can('sale.validate')
        ? [{ title: t('sales.pos'), href: posCreate(), icon: ShoppingCart }]
        : []),
    ...(can('sale.view')
        ? [{ title: t('sales.nav'), href: salesIndex(), icon: FileText }]
        : []),
    ...(can('cash.view_own') || can('cash.view_all')
        ? [{ title: t('cash.nav'), href: cashIndex(), icon: Wallet }]
        : []),
    ...(can('purchase.view')
        ? [
              {
                  title: t('purchases.nav'),
                  href: purchaseOrdersIndex(),
                  icon: ShoppingBasket,
              },
              {
                  title: t('purchases.invoices'),
                  href: purchaseInvoicesIndex(),
                  icon: FileText,
              },
          ]
        : []),
    ...(can('catalog.view')
        ? [{ title: t('catalog.nav'), href: productsIndex(), icon: Package }]
        : []),
    ...(can('inventory.view')
        ? [{ title: t('stock.nav'), href: stockIndex(), icon: Boxes }]
        : []),
    ...(can('customer.view')
        ? [
              {
                  title: t('parties.customers'),
                  href: customersIndex(),
                  icon: Users,
              },
          ]
        : []),
    ...(can('supplier.view')
        ? [
              {
                  title: t('parties.suppliers'),
                  href: suppliersIndex(),
                  icon: Truck,
              },
          ]
        : []),
    // Console plateforme : super-administrateur uniquement (vérifié côté serveur).
    ...(page.props.platform?.can_access
        ? [
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
          ]
        : []),
]);
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
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
