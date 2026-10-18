<script setup lang="ts">
/**
 * Écran Vendre du mode simple (lot 2).
 *
 * Façade simplifiée du POS expert : grille d'articles avec photo, panier,
 * choix du paiement (espèces, téléphone, crédit) et écran de résultat.
 * Même service métier, mêmes données, même idempotence.
 */
import { Head, router, usePage } from '@inertiajs/vue3';
import { ShoppingCart } from '@lucide/vue';
import { computed, ref } from 'vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import EcranResultat from '@/components/simple/EcranResultat.vue';
import { formatFranc } from '@/lib/money';
import type { FlashData } from '@/types';

type Props = {
    warehouses: { id: string; code: string; name: string }[];
    warehouse: { id: string; code: string; name: string } | null;
    sessions: { id: string; register: string; opened_by: string }[];
    registers: { id: string; name: string }[];
    methods: string[];
    frequent: unknown[];
    can: {
        price_override: boolean;
        discount: boolean;
        negative_stock: boolean;
        open_cash: boolean;
    };
};

const props = defineProps<Props>();

const page = usePage();
const flash = computed(() => page.props.flash as FlashData | undefined);
const saleResult = computed(
    () =>
        flash.value?.sale as
            | { number: string; total: number; amount_paid: number }
            | undefined,
);

const etat = ref<'catalogue' | 'resultat'>(
    saleResult.value ? 'resultat' : 'catalogue',
);

function nouvelleVente(): void {
    etat.value = 'catalogue';
}
</script>

<template>
    <Head title="Vendre" />

    <template v-if="etat === 'resultat' && saleResult">
        <EcranResultat
            etat="reussi"
            titre="Vente faite"
            :montant="saleResult.total"
            :detail="
                saleResult.total > saleResult.amount_paid
                    ? `Reste à payer : ${formatFranc(saleResult.total - saleResult.amount_paid)}`
                    : undefined
            "
            action-principale="Nouvelle vente"
            action-secondaire="Ticket"
            @principale="nouvelleVente"
        />
    </template>

    <template v-else>
        <EnTeteEcran
            titre="Vendre"
            :icone="ShoppingCart"
            :retour-visible="false"
        />

        <div class="p-4">
            <p
                v-if="sessions.length === 0"
                class="rounded-[24px] p-4 text-center text-[17px] font-semibold"
                :style="{
                    backgroundColor: 'var(--s-orange-clair)',
                    color: 'var(--s-sur-orange-clair)',
                }"
            >
                La caisse est fermée. Ouvrez-la d'abord.
            </p>

            <p
                v-else
                class="text-[17px] font-semibold"
                :style="{ color: 'var(--s-texte-doux)' }"
            >
                Écran de vente en cours de construction (lot 2). La grille
                d'articles, le panier et le paiement arrivent ici.
            </p>
        </div>
    </template>
</template>
