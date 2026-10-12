<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Boxes, FileText, LayoutGrid, Menu, ShoppingCart } from '@lucide/vue';
import { computed } from 'vue';
import { useSidebar } from '@/components/ui/sidebar';
import { useAppContext } from '@/composables/useAppContext';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { t } from '@/i18n';
import { dashboard } from '@/routes';
import { create as posCreate } from '@/routes/pos';
import { index as salesIndex } from '@/routes/sales';
import { index as stockIndex } from '@/routes/stock';

/**
 * Barre du bas sur téléphone (docs/07) : les quatre écrans du quotidien à
 * portée de pouce, libellés toujours visibles, et « Menu » pour le reste.
 */
const page = usePage();
const { can } = useAppContext();
const { setOpenMobile } = useSidebar();
const { isCurrentUrl } = useCurrentUrl();

const items = computed(
    () =>
        [
            page.props.context && {
                href: dashboard(),
                icon: LayoutGrid,
                label: t('nav.dashboard'),
            },
            can('sale.create') &&
                can('sale.validate') && {
                    href: posCreate(),
                    icon: ShoppingCart,
                    label: t('mobile_nav.sell'),
                },
            can('sale.view') && {
                href: salesIndex(),
                icon: FileText,
                label: t('sales.nav'),
            },
            can('inventory.view') && {
                href: stockIndex(),
                icon: Boxes,
                label: t('stock.nav'),
            },
        ].filter((item) => Boolean(item)) as {
            href: ReturnType<typeof dashboard>;
            icon: typeof Menu;
            label: string;
        }[],
);
</script>

<template>
    <!-- Vente comptoir : sa barre « Encaisser » occupe le bas de l'écran. -->
    <nav
        v-if="page.component !== 'sales/Pos'"
        class="fixed inset-x-0 bottom-0 z-40 border-t bg-sidebar/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
        :aria-label="t('mobile_nav.label')"
    >
        <ul class="flex">
            <li v-for="item in items" :key="item.label" class="flex-1">
                <Link
                    :href="item.href"
                    class="flex min-h-16 flex-col items-center justify-center gap-1 text-xs font-medium"
                    :class="
                        isCurrentUrl(item.href)
                            ? 'text-primary'
                            : 'text-muted-foreground'
                    "
                    :aria-current="isCurrentUrl(item.href) ? 'page' : undefined"
                >
                    <component
                        :is="item.icon"
                        class="size-6"
                        aria-hidden="true"
                    />
                    {{ item.label }}
                </Link>
            </li>
            <li class="flex-1">
                <button
                    type="button"
                    class="flex min-h-16 w-full flex-col items-center justify-center gap-1 text-xs font-medium text-muted-foreground"
                    @click="setOpenMobile(true)"
                >
                    <Menu class="size-6" aria-hidden="true" />
                    {{ t('mobile_nav.menu') }}
                </button>
            </li>
        </ul>
    </nav>
</template>
