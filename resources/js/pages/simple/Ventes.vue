<script setup lang="ts">
/**
 * Mes ventes du mode simple (lot 3) : jour choisi, total, répartition par
 * mode de paiement, liste des ventes du jour. Annuler reste réservé à
 * l'administrateur (sale.cancel), via une feuille de confirmation.
 */
import { Head, router } from '@inertiajs/vue3';
import { ReceiptText } from '@lucide/vue';
import { ref } from 'vue';
import CarteMontant from '@/components/simple/CarteMontant.vue';
import CompteursFiltres, {
    type CaseFiltre,
} from '@/components/simple/CompteursFiltres.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { formatFranc } from '@/lib/money';

type Vente = {
    id: string;
    number: string | null;
    time: string | null;
    customer: string | null;
    total: number;
    balance: number;
    seller: string;
    ticket_url: string;
};

const props = defineProps<{
    day: string;
    total: number;
    count: number;
    by_method: { cash: number; mobile: number; credit: number };
    sales: Vente[];
    can: { cancel: boolean };
}>();

const filtre = ref<string | null>(null);
const motif = ref('');
const venteAAnnuler = ref<Vente | null>(null);

const cases: CaseFiltre[] = [
    {
        cle: 'cash',
        libelle: 'Espèces',
        valeur: formatFranc(props.by_method.cash),
        couleur: 'vert',
    },
    {
        cle: 'mobile',
        libelle: 'Téléphone',
        valeur: formatFranc(props.by_method.mobile),
        couleur: 'bleu',
    },
    {
        cle: 'credit',
        libelle: 'Crédit',
        valeur: formatFranc(props.by_method.credit),
        couleur: 'orange',
    },
];

function visible(vente: Vente): boolean {
    if (filtre.value === 'credit') {
        return vente.balance > 0;
    }
    if (filtre.value === null) {
        return true;
    }
    // Espèces / téléphone : approché par l'absence de reste à payer, le
    // détail par mode précis reste dans le ticket.
    return vente.balance === 0;
}

function changerJour(delta: number): void {
    const next = new Date(`${props.day}T00:00:00`);
    next.setDate(next.getDate() + delta);
    router.get('/simple/ventes', { day: next.toISOString().slice(0, 10) });
}

function demanderAnnulation(vente: Vente): void {
    motif.value = '';
    venteAAnnuler.value = vente;
}

function confirmerAnnulation(): void {
    if (!venteAAnnuler.value) {
        return;
    }
    router.post(
        `/simple/ventes/${venteAAnnuler.value.id}/annuler`,
        { reason: motif.value },
        { onSuccess: () => (venteAAnnuler.value = null) },
    );
}
</script>

<template>
    <Head title="Mes ventes" />

    <EnTeteEcran titre="Mes ventes" :icone="ReceiptText" :retour-visible="false" />

    <div class="grid gap-4 p-4">
        <div class="flex items-center justify-between gap-2">
            <button
                type="button"
                class="min-h-11 rounded-[16px] px-4 font-bold"
                :style="{ backgroundColor: 'var(--s-surface)', border: '1px solid var(--s-bordure)' }"
                @click="changerJour(-1)"
            >
                ← Hier
            </button>
            <span class="text-[17px] font-bold">{{ day }}</span>
            <button
                type="button"
                class="min-h-11 rounded-[16px] px-4 font-bold"
                :style="{ backgroundColor: 'var(--s-surface)', border: '1px solid var(--s-bordure)' }"
                @click="changerJour(1)"
            >
                Demain →
            </button>
        </div>

        <CarteMontant libelle="Vendu" :montant="total" :pastilles="[`${count} ventes`]" />

        <CompteursFiltres :cases="cases" :actif="filtre" @filtrer="filtre = $event" />

        <p
            v-if="sales.length === 0"
            class="rounded-[24px] p-4 text-center text-[17px] font-semibold"
            :style="{ backgroundColor: 'var(--s-surface)', border: '1px solid var(--s-bordure)' }"
        >
            Aucune vente ce jour-là.
        </p>

        <ul v-else class="grid gap-2">
            <li
                v-for="vente in sales.filter(visible)"
                :key="vente.id"
                class="flex items-center gap-3 rounded-[20px] p-3"
                :style="{ backgroundColor: 'var(--s-surface)', border: '1px solid var(--s-bordure)' }"
            >
                <div class="min-w-0 flex-1">
                    <p class="text-[15px] font-semibold" :style="{ color: 'var(--s-texte-doux)' }">
                        {{ vente.time }} · {{ vente.customer ?? 'Client comptoir' }}
                    </p>
                    <p class="text-[20px] font-extrabold tabular-nums">
                        {{ formatFranc(vente.total) }}
                    </p>
                </div>
                <a
                    :href="vente.ticket_url"
                    target="_blank"
                    rel="noopener"
                    class="min-h-11 rounded-[16px] px-3 text-[15px] font-bold"
                    :style="{ backgroundColor: 'var(--s-vert-clair)', color: 'var(--s-sur-vert-clair)' }"
                >
                    Ticket
                </a>
                <button
                    v-if="can.cancel"
                    type="button"
                    class="min-h-11 rounded-[16px] px-3 text-[15px] font-bold"
                    :style="{ backgroundColor: 'var(--s-rouge-clair)', color: 'var(--s-sur-rouge-clair)' }"
                    @click="demanderAnnulation(vente)"
                >
                    Annuler
                </button>
            </li>
        </ul>
    </div>

    <div
        v-if="venteAAnnuler"
        class="fixed inset-0 z-50 flex items-end justify-center bg-black/40"
    >
        <div
            class="w-full max-w-[430px] rounded-t-[24px] p-4"
            :style="{ backgroundColor: 'var(--s-surface)' }"
        >
            <h2 class="mb-3 text-[20px] font-extrabold">Annuler la vente ?</h2>
            <label for="simple-cancel-reason" class="mb-1 block text-[15px] font-semibold">
                Pourquoi ?
            </label>
            <textarea
                id="simple-cancel-reason"
                v-model="motif"
                rows="2"
                maxlength="500"
                class="mb-3 w-full rounded-[16px] border p-3 text-[17px]"
            />
            <div class="grid grid-cols-2 gap-3">
                <button
                    type="button"
                    class="h-14 rounded-[20px] text-[17px] font-bold"
                    :style="{ backgroundColor: 'var(--s-bordure)' }"
                    @click="venteAAnnuler = null"
                >
                    Retour
                </button>
                <button
                    type="button"
                    class="h-14 rounded-[20px] text-[17px] font-extrabold"
                    :style="{ backgroundColor: 'var(--s-rouge)', color: 'var(--s-sur-rouge)' }"
                    @click="confirmerAnnulation"
                >
                    Annuler la vente
                </button>
            </div>
        </div>
    </div>
</template>
