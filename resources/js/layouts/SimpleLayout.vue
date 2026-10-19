<script setup lang="ts">
/**
 * Cadre adaptatif du mode simple : navigation tactile en bas sur mobile,
 * espace de travail ordinateur avec menu latéral dès 1024 px.
 */
import {
    Boxes,
    Home,
    MoreHorizontal,
    ReceiptText,
    ShoppingCart,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import BarreNavBas from '@/components/simple/BarreNavBas.vue';
import { useAppContext } from '@/composables/useAppContext';
import {
    home as simpleHome,
    sell as simpleSell,
    sales as simpleSales,
} from '@/routes/simple';

const props = withDefaults(defineProps<{ actif?: string }>(), {
    actif: 'accueil',
});
const { context, can } = useAppContext();
const page = usePage();
const accueil = computed(() => simpleHome().url);
const actif = computed(() => {
    if (props.actif !== 'accueil') return props.actif;
    return (
        (
            {
                'simple/Accueil': 'accueil',
                'simple/Vendre': 'vendre',
                'simple/Caisse': 'caisse',
                'simple/Ventes': 'ventes',
                'simple/Stock': 'stock',
            } as Record<string, string>
        )[page.component] ?? 'accueil'
    );
});
const peutVoirStock = computed(() => can('inventory.view'));

const entrees = computed(
    () =>
        [
            {
                cle: 'accueil',
                libelle: 'Accueil',
                icone: Home,
                href: accueil.value,
            },
            {
                cle: 'ventes',
                libelle: 'Ventes',
                icone: ReceiptText,
                href: simpleSales().url,
            },
            {
                cle: 'vendre',
                libelle: 'Vendre',
                icone: ShoppingCart,
                href: simpleSell().url,
            },
            peutVoirStock.value && {
                cle: 'stock',
                libelle: 'Stock',
                icone: Boxes,
                href: '/simple/stock',
            },
            {
                cle: 'plus',
                libelle: 'Plus',
                icone: MoreHorizontal,
                href: accueil.value,
            },
        ].filter(Boolean) as {
            cle: string;
            libelle: string;
            icone: typeof Home;
            href: string;
        }[],
);

const navigationOrdinateur = computed(
    () =>
        [
            {
                libelle: 'Accueil',
                icone: Home,
                href: accueil.value,
                actif: actif.value === 'accueil',
            },
            {
                libelle: 'Vendre',
                icone: ShoppingCart,
                href: simpleSell().url,
                actif: actif.value === 'vendre',
            },
            {
                libelle: 'Caisse',
                icone: Wallet,
                href: '/simple/caisse',
                actif: actif.value === 'caisse',
            },
            {
                libelle: 'Mes ventes',
                icone: ReceiptText,
                href: simpleSales().url,
                actif: actif.value === 'ventes',
            },
            peutVoirStock.value && {
                libelle: 'Stock',
                icone: Boxes,
                href: '/simple/stock',
                actif: actif.value === 'stock',
            },
        ].filter(Boolean) as {
            libelle: string;
            icone: typeof Home;
            href: string;
            actif: boolean;
        }[],
);
</script>

<template>
    <div
        class="mode-simple min-h-dvh lg:grid lg:grid-cols-[248px_minmax(0,1fr)]"
    >
        <aside
            class="hidden min-h-dvh flex-col border-r p-4 lg:flex"
            :style="{
                backgroundColor: 'var(--s-surface)',
                borderColor: 'var(--s-bordure)',
            }"
        >
            <a :href="accueil" class="mb-8 flex items-center gap-3 px-3">
                <span
                    class="grid size-10 place-items-center rounded-[14px] text-xl font-black"
                    :style="{
                        backgroundColor: 'var(--s-marque)',
                        color: 'var(--s-sur-marque)',
                    }"
                    >D</span
                >
                <span class="text-lg font-extrabold tracking-tight">DIABA</span>
            </a>
            <nav aria-label="Navigation principale" class="grid gap-1">
                <a
                    v-for="entree in navigationOrdinateur"
                    :key="entree.libelle"
                    :href="entree.href"
                    :aria-current="entree.actif ? 'page' : undefined"
                    class="flex min-h-12 items-center gap-3 rounded-[14px] px-3 text-[16px] font-bold transition-colors"
                    :style="
                        entree.actif
                            ? {
                                  backgroundColor: 'var(--s-vert-clair)',
                                  color: 'var(--s-sur-vert-clair)',
                              }
                            : { color: 'var(--s-texte-doux)' }
                    "
                >
                    <component
                        :is="entree.icone"
                        :size="21"
                        aria-hidden="true"
                    />
                    {{ entree.libelle }}
                </a>
            </nav>
            <div
                class="mt-auto rounded-[18px] p-3"
                :style="{ backgroundColor: 'var(--s-fond)' }"
            >
                <p class="truncate text-sm font-extrabold">
                    {{ context?.tenant?.name ?? 'Mon entreprise' }}
                </p>
                <p
                    class="mt-1 text-xs font-semibold"
                    :style="{ color: 'var(--s-texte-doux)' }"
                >
                    Mode simple
                </p>
            </div>
        </aside>

        <main class="min-w-0">
            <div
                class="mx-auto w-full max-w-[1440px] pb-[calc(var(--s-barre-bas)+16px)] lg:px-8 lg:py-8 lg:pb-10"
            >
                <slot />
            </div>
        </main>

        <BarreNavBas class="lg:hidden" :actif="actif" :entrees="entrees" />
    </div>
</template>
