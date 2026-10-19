<script setup lang="ts">
/**
 * Accueil du mode simple (lot 3) : chiffres du jour (toujours les siennes
 * pour un vendeur, tout le dépôt pour un administrateur), tuiles d'action
 * limitées aux écrans qui existent déjà, alertes partagées avec le tableau
 * de bord expert.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { PackageX, Settings2, ShoppingCart, Wallet, Wrench } from '@lucide/vue';
import { computed } from 'vue';
import CarteMontant from '@/components/simple/CarteMontant.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import LigneAlerte from '@/components/simple/LigneAlerte.vue';
import TuileAction from '@/components/simple/TuileAction.vue';
import { useAppContext } from '@/composables/useAppContext';
import {
    cash as simpleCash,
    demo as simpleDemo,
    mode as simpleMode,
    sell as simpleSell,
} from '@/routes/simple';

type Jour = { total: number; count: number };
type Tile = 'sell' | 'cash';

const props = defineProps<{
    figures: { today: Jour; yesterday: Jour };
    tiles: Tile[];
    alerts: {
        low_stock?: number;
        cash_to_close?: number;
    };
}>();

const { context } = useAppContext();

const tendance = computed<'hausse' | 'baisse' | null>(() => {
    if (props.figures.today.total === props.figures.yesterday.total) {
        return null;
    }

    return props.figures.today.total > props.figures.yesterday.total
        ? 'hausse'
        : 'baisse';
});

const tuiles = computed(() =>
    (
        [
            {
                cle: 'sell' as const,
                libelle: 'Vendre',
                icone: ShoppingCart,
                couleur: 'vert' as const,
                href: simpleSell().url,
            },
            {
                cle: 'cash' as const,
                libelle: 'Caisse',
                icone: Wallet,
                couleur: 'orange' as const,
                href: simpleCash().url,
            },
        ] satisfies {
            cle: Tile;
            libelle: string;
            icone: typeof ShoppingCart;
            couleur: 'vert' | 'orange';
            href: string;
        }[]
    ).filter((tuile) => props.tiles.includes(tuile.cle)),
);

function ouvrir(href: string): void {
    router.get(href);
}

function versModeExpert(): void {
    router.post(simpleMode().url, { mode: 'expert' });
}
</script>

<template>
    <Head title="Accueil" />

    <EnTeteEcran class="lg:hidden" titre="Accueil" :retour-visible="false" />

    <div
        class="grid max-w-[1080px] gap-5 p-4 pb-28 lg:grid-cols-2 lg:p-0 lg:pb-0"
    >
        <h1
            class="sr-only lg:not-sr-only lg:col-span-2 lg:text-[32px] lg:font-extrabold"
        >
            Accueil
        </h1>
        <CarteMontant
            libelle="Aujourd'hui"
            :montant="figures.today.total"
            :pastilles="[`${figures.today.count} ventes`]"
            :tendance="tendance"
        />

        <div class="grid grid-cols-2 gap-3">
            <TuileAction
                v-for="tuile in tuiles"
                :key="tuile.cle"
                :libelle="tuile.libelle"
                :icone="tuile.icone"
                :couleur="tuile.couleur"
                @appui="ouvrir(tuile.href)"
            />
        </div>

        <section
            v-if="Object.keys(alerts).length > 0"
            class="overflow-hidden rounded-[24px]"
            :style="{ border: '1px solid var(--s-bordure)' }"
        >
            <LigneAlerte
                v-if="alerts.low_stock"
                :icone="PackageX"
                couleur="rouge"
                :valeur="String(alerts.low_stock)"
                texte="articles presque finis"
            />
            <LigneAlerte
                v-if="alerts.cash_to_close"
                :icone="Wrench"
                couleur="orange"
                :valeur="String(alerts.cash_to_close)"
                texte="caisse à fermer"
            />
        </section>

        <Link
            v-if="context?.role.code === 'admin'"
            :href="simpleDemo()"
            class="flex min-h-14 items-center justify-center gap-2 rounded-[24px] text-[17px] font-bold"
            :style="{
                backgroundColor: 'var(--s-surface)',
                color: 'var(--s-texte)',
                border: '1px solid var(--s-bordure)',
            }"
        >
            <Settings2 :size="22" :stroke-width="2" aria-hidden="true" />
            Voir les composants
        </Link>

        <button
            v-if="context?.role.code === 'admin'"
            type="button"
            class="min-h-14 rounded-[24px] text-[17px] font-bold"
            :style="{
                backgroundColor: 'var(--s-surface)',
                color: 'var(--s-texte-doux)',
                border: '1px solid var(--s-bordure)',
            }"
            @click="versModeExpert"
        >
            Revenir au mode expert
        </button>
    </div>
</template>
