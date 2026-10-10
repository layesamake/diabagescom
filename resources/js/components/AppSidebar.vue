<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    Layers,
    LayoutGrid,
    Package,
    Truck,
    Users,
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
import { index as customersIndex } from '@/routes/customers';
import { index as productsIndex } from '@/routes/products';
import { index as suppliersIndex } from '@/routes/suppliers';
import { index as platformPlans } from '@/routes/platform/plans';
import { index as platformTenants } from '@/routes/platform/tenants';
import type { NavItem } from '@/types';

// Les rubriques métier (Ventes, Stock, Caisse…) s'ajouteront ici avec leurs
// permissions, au fil des EPIC suivants.
const page = usePage();
const { can } = useAppContext();

const mainNavItems = computed<NavItem[]>(() => [
    ...(page.props.context
        ? [{ title: t('nav.dashboard'), href: dashboard(), icon: LayoutGrid }]
        : []),
    // Rubriques métier : affichées selon les permissions (le serveur revérifie tout).
    ...(can('catalog.view')
        ? [{ title: t('catalog.nav'), href: productsIndex(), icon: Package }]
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
