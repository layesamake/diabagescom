<script setup lang="ts">
/**
 * Mes ventes du mode simple (lot 3) aligné sur la maquette 04a :
 *
 * - Sélecteur de jour en pilule (« Aujourd'hui » + chevrons)
 * - Total du jour + compteur de ventes
 * - Trois cartes par mode (espèces, téléphone, crédit) avec icône médaillon
 * - Liste des ventes avec initiales d'article et badge quantité > 1
 * - Annulation toujours réservée à l'administrateur (sale.cancel)
 */
import { Head, router } from '@inertiajs/vue3';
import {
    Banknote,
    Calendar,
    ChevronLeft,
    ChevronRight,
    Clock,
    ReceiptText,
    Smartphone,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CarteMontant from '@/components/simple/CarteMontant.vue';
import CompteursFiltres, {
    type CaseFiltre,
} from '@/components/simple/CompteursFiltres.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { formatFranc } from '@/lib/money';

type ProductBadge = {
    id: string;
    initials: string;
    quantity: string;
    label: string;
};

type Vente = {
    id: string;
    number: string | null;
    time: string | null;
    customer: string | null;
    total: number;
    balance: number;
    seller: string;
    method: 'cash' | 'mobile' | 'credit';
    products: ProductBadge[];
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

function appliquerFiltre(cle: string | null): void {
    filtre.value =
        cle === 'cash' || cle === 'mobile' || cle === 'credit' ? cle : null;
}
const motif = ref('');
const venteAAnnuler = ref<Vente | null>(null);

const aujourdhui = computed(() => new Date().toISOString().slice(0, 10));
const libelleJour = computed(() =>
    props.day === aujourdhui.value
        ? "Aujourd'hui"
        : new Date(`${props.day}T00:00:00`).toLocaleDateString('fr-FR', {
              weekday: 'long',
              day: 'numeric',
              month: 'long',
          }),
);

const cases: CaseFiltre[] = [
    {
        cle: 'cash',
        libelle: 'Espèces',
        valeur: formatFranc(props.by_method.cash),
        couleur: 'vert',
        icone: Banknote,
    },
    {
        cle: 'mobile',
        libelle: 'Téléphone',
        valeur: formatFranc(props.by_method.mobile),
        couleur: 'bleu',
        icone: Smartphone,
    },
    {
        cle: 'credit',
        libelle: 'Crédit',
        valeur: formatFranc(props.by_method.credit),
        couleur: 'orange',
        icone: Clock,
    },
];

function ventesFiltrees(vente: Vente): boolean {
    if (filtre.value === null) {
        return true;
    }
    return vente.method === filtre.value;
}

function changerJour(delta: number): void {
    const next = new Date(`${props.day}T00:00:00`);
    next.setDate(next.getDate() + delta);
    router.get('/simple/ventes', { day: next.toISOString().slice(0, 10) });
}

function iconePaiement(method: Vente['method']) {
    if (method === 'cash') {
        return Banknote;
    }
    if (method === 'mobile') {
        return Smartphone;
    }
    return Clock;
}

function couleurPaiement(method: Vente['method']): string {
    if (method === 'cash') {
        return 'var(--s-vert-clair)';
    }
    if (method === 'mobile') {
        return 'var(--s-bleu-clair)';
    }
    return 'var(--s-orange-clair)';
}

function texteMethode(method: Vente['method']): string {
    if (method === 'cash') {
        return 'Espèces';
    }
    if (method === 'mobile') {
        return 'Mobile';
    }
    return 'Crédit';
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
        { idempotency_key: crypto.randomUUID(), reason: motif.value },
        { onSuccess: () => (venteAAnnuler.value = null) },
    );
}
</script>

<template>
    <Head title="Mes ventes" />

    <EnTeteEcran
        class="lg:hidden"
        titre="Mes ventes"
        :icone="ReceiptText"
        :retour-visible="false"
    />

    <div class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_360px] lg:p-0">
        <h1
            class="sr-only lg:not-sr-only lg:col-span-2 lg:text-[32px] lg:font-extrabold"
        >
            Mes ventes
        </h1>

        <div
            class="flex items-center gap-3 lg:col-span-2"
            role="group"
            aria-label="Choisir le jour"
        >
            <button
                type="button"
                class="flex size-12 shrink-0 items-center justify-center rounded-full"
                aria-label="Jour précédent"
                :style="{
                    backgroundColor: 'var(--s-surface)',
                    border: '1px solid var(--s-bordure)',
                }"
                @click="changerJour(-1)"
            >
                <ChevronLeft :size="22" aria-hidden="true" />
            </button>
            <div
                class="flex flex-1 items-center justify-center gap-2 rounded-full px-4 py-3"
                :style="{
                    backgroundColor: 'var(--s-surface)',
                    border: '1px solid var(--s-bordure)',
                }"
            >
                <Calendar :size="22" :stroke-width="2" aria-hidden="true" />
                <span class="text-[17px] font-extrabold capitalize">
                    {{ libelleJour }}
                </span>
            </div>
            <button
                type="button"
                class="flex size-12 shrink-0 items-center justify-center rounded-full"
                aria-label="Jour suivant"
                :style="{
                    backgroundColor: 'var(--s-surface)',
                    border: '1px solid var(--s-bordure)',
                }"
                @click="changerJour(1)"
            >
                <ChevronRight :size="22" aria-hidden="true" />
            </button>
        </div>

        <CarteMontant
            libelle="Vendu"
            :montant="total"
            :pastilles="[`${count} ventes`]"
        />

        <CompteursFiltres
            :cases="cases"
            :actif="filtre"
            @filtrer="appliquerFiltre"
        />

        <p
            v-if="sales.length === 0"
            class="rounded-[24px] p-4 text-center text-[17px] font-semibold"
            :style="{
                backgroundColor: 'var(--s-surface)',
                border: '1px solid var(--s-bordure)',
            }"
        >
            Aucune vente ce jour-là.
        </p>

        <ul v-else class="grid gap-2 lg:col-start-1">
            <li
                v-for="vente in sales.filter(ventesFiltrees)"
                :key="vente.id"
                class="flex items-center gap-3 rounded-[20px] p-3"
                :style="{
                    backgroundColor: 'var(--s-surface)',
                    border: '1px solid var(--s-bordure)',
                }"
            >
                <p
                    class="w-12 shrink-0 text-[15px] font-semibold tabular-nums"
                    :style="{ color: 'var(--s-texte-doux)' }"
                >
                    {{ vente.time }}
                </p>
                <div class="flex min-w-0 flex-1 items-center gap-2">
                    <span
                        v-for="article in vente.products.slice(0, 2)"
                        :key="article.id"
                        class="relative inline-flex h-9 items-center justify-center rounded-full px-2 text-[15px] font-extrabold"
                        :style="{
                            backgroundColor: 'var(--s-orange-clair)',
                            color: 'var(--s-sur-orange-clair)',
                        }"
                    >
                        {{ article.initials }}
                        <span
                            v-if="Number(article.quantity) > 1"
                            class="absolute -top-1 -right-1 inline-flex min-w-5 items-center justify-center rounded-full px-1 text-[15px] font-extrabold"
                            :style="{
                                backgroundColor: 'var(--s-vert-fonce)',
                                color: 'var(--s-sur-vert-fonce)',
                            }"
                        >
                            {{ article.quantity }}
                        </span>
                    </span>
                </div>
                <p class="shrink-0 text-[18px] font-extrabold tabular-nums">
                    {{ formatFranc(vente.total) }}
                </p>
                <span
                    class="inline-flex size-10 shrink-0 items-center justify-center rounded-full"
                    :style="{
                        backgroundColor: couleurPaiement(vente.method),
                        color: 'var(--s-sur-vert-clair)',
                    }"
                    :aria-label="`Payé en ${texteMethode(vente.method)}`"
                >
                    <component
                        :is="iconePaiement(vente.method)"
                        :size="20"
                        :stroke-width="2"
                    />
                </span>
                <button
                    v-if="can.cancel"
                    type="button"
                    class="flex size-12 shrink-0 items-center justify-center rounded-full"
                    aria-label="Annuler la vente"
                    :style="{
                        backgroundColor: 'var(--s-rouge-clair)',
                        color: 'var(--s-sur-rouge-clair)',
                    }"
                    @click="demanderAnnulation(vente)"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="22"
                        height="22"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M3 6h18" />
                        <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                        <path
                            d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"
                        />
                        <path d="M10 11v6" />
                        <path d="M14 11v6" />
                    </svg>
                </button>
            </li>
        </ul>

        <aside
            class="hidden rounded-[24px] border p-5 lg:col-start-2 lg:row-span-4 lg:row-start-1 lg:block"
            :style="{
                backgroundColor: 'var(--s-surface)',
                borderColor: 'var(--s-bordure)',
            }"
        >
            <p
                class="text-[15px] font-bold"
                :style="{ color: 'var(--s-texte-doux)' }"
            >
                Détail de la vente
            </p>
            <p class="mt-3 text-[20px] font-extrabold">
                Sélectionnez une vente
            </p>
            <p
                class="mt-2 text-[16px] leading-relaxed"
                :style="{ color: 'var(--s-texte-doux)' }"
            >
                Le ticket et les actions disponibles apparaîtront ici.
            </p>
        </aside>
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
            <label
                for="simple-cancel-reason"
                class="mb-1 block text-[15px] font-semibold"
            >
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
                    :style="{
                        backgroundColor: 'var(--s-rouge)',
                        color: 'var(--s-sur-rouge)',
                    }"
                    @click="confirmerAnnulation"
                >
                    Annuler la vente
                </button>
            </div>
        </div>
    </div>
</template>
