<script setup lang="ts">
/**
 * Écran Caisse du mode simple : ouverture, synthèse et fermeture. Les grands
 * montants sont saisis directement et tout écart exige une justification.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed } from 'vue';
import CarteMontant from '@/components/simple/CarteMontant.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { formatFranc } from '@/lib/money';

type SessionData = {
    id: string;
    status: string;
    opening_amount: number;
    opened_by: string;
    opened_at: string;
    summary: {
        incomes: number;
        expenses: number;
        refunds: number;
        supplier_payments: number;
        drawer: number;
        by_method: Record<string, number>;
    };
    drawer: number;
};

type Props = {
    warehouse: { id: string; code: string; name: string } | null;
    register: { id: string; name: string } | null;
    session: SessionData | null;

    can: {
        open: boolean;
        close: boolean;
        income: boolean;
        expense: boolean;
    };
};

const props = defineProps<Props>();
const openForm = useForm({ opening_amount: 0 });
const closeForm = useForm({
    counted_amount: props.session?.drawer ?? 0,
    note: '',
});
const ecartDeCaisse = computed(() =>
    props.session ? closeForm.counted_amount - props.session.drawer : 0,
);
const fermetureValide = computed(
    () => ecartDeCaisse.value === 0 || closeForm.note.trim().length >= 3,
);

function ouvrir(): void {
    if (props.register) {
        openForm.post(`/simple/caisse/${props.register.id}/ouvrir`, {
            preserveScroll: true,
        });
    }
}

function fermer(): void {
    if (props.session && fermetureValide.value) {
        closeForm.post(`/simple/caisse/${props.session.id}/fermer`, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Caisse" />

    <EnTeteEcran
        class="lg:hidden"
        titre="Caisse"
        :icone="Wallet"
        :retour-visible="false"
    />

    <div
        class="grid max-w-[880px] gap-4 p-4 pb-28 lg:grid-cols-2 lg:p-0 lg:pb-0"
    >
        <h1
            class="sr-only lg:not-sr-only lg:col-span-2 lg:text-[32px] lg:font-extrabold"
        >
            Caisse
        </h1>
        <p
            v-if="!register"
            class="rounded-[24px] p-4 text-center text-[17px] font-semibold"
            :style="{
                backgroundColor: 'var(--s-orange-clair)',
                color: 'var(--s-sur-orange-clair)',
            }"
        >
            Aucune caisse configurée pour ce dépôt.
        </p>

        <template v-else-if="!session">
            <p
                class="text-center text-[17px] font-semibold"
                :style="{ color: 'var(--s-texte-doux)' }"
            >
                La caisse « {{ register?.name }} » est fermée.
            </p>

            <form v-if="can.open" class="grid gap-3" @submit.prevent="ouvrir">
                <label for="opening-amount" class="text-[17px] font-semibold">
                    Fond de caisse
                </label>
                <input
                    id="opening-amount"
                    v-model.number="openForm.opening_amount"
                    type="number"
                    min="0"
                    inputmode="numeric"
                    class="h-16 rounded-[20px] border px-4 text-center text-[26px] font-extrabold tabular-nums"
                />
                <p
                    v-if="openForm.errors.opening_amount"
                    class="font-bold text-red-700"
                >
                    {{ openForm.errors.opening_amount }}
                </p>
                <button
                    type="submit"
                    class="h-16 rounded-[24px] text-[20px] font-extrabold"
                    :style="{
                        backgroundColor: 'var(--s-vert)',
                        color: 'var(--s-sur-vert)',
                    }"
                    :disabled="openForm.processing"
                >
                    Ouvrir avec {{ formatFranc(openForm.opening_amount) }}
                </button>
            </form>
        </template>

        <template v-else>
            <CarteMontant
                class="lg:col-span-2"
                libelle="Dans la caisse"
                :montant="session.drawer"
            />

            <div class="grid grid-cols-2 gap-3">
                <div
                    class="rounded-[20px] p-3 text-center"
                    :style="{
                        backgroundColor: 'var(--s-vert-clair)',
                        color: 'var(--s-sur-vert-clair)',
                    }"
                >
                    <p class="text-[15px] font-semibold">Entrées</p>
                    <p class="text-[22px] font-extrabold tabular-nums">
                        {{ formatFranc(session.summary.incomes) }}
                    </p>
                </div>
                <div
                    class="rounded-[20px] p-3 text-center"
                    :style="{
                        backgroundColor: 'var(--s-rouge-clair)',
                        color: 'var(--s-sur-rouge-clair)',
                    }"
                >
                    <p class="text-[15px] font-semibold">Sorties</p>
                    <p class="text-[22px] font-extrabold tabular-nums">
                        {{ formatFranc(session.summary.expenses) }}
                    </p>
                </div>
            </div>

            <form v-if="can.close" class="grid gap-3" @submit.prevent="fermer">
                <label for="counted-amount" class="text-[17px] font-semibold">
                    Montant compté
                </label>
                <input
                    id="counted-amount"
                    v-model.number="closeForm.counted_amount"
                    type="number"
                    min="0"
                    inputmode="numeric"
                    class="h-16 rounded-[20px] border px-4 text-center text-[26px] font-extrabold tabular-nums"
                />

                <div
                    v-if="ecartDeCaisse !== 0"
                    class="grid gap-2 rounded-[20px] p-3"
                    :style="{
                        backgroundColor: 'var(--s-orange-clair)',
                        color: 'var(--s-sur-orange-clair)',
                    }"
                >
                    <p class="text-[17px] font-extrabold">
                        Écart : {{ formatFranc(ecartDeCaisse) }}
                    </p>
                    <label for="closing-note" class="font-bold">
                        Pourquoi y a-t-il un écart ?
                    </label>
                    <textarea
                        id="closing-note"
                        v-model="closeForm.note"
                        rows="3"
                        maxlength="500"
                        class="rounded-[16px] border bg-white p-3 text-[17px] text-black"
                        placeholder="Écrivez au moins 3 caractères"
                    />
                </div>

                <p
                    v-if="
                        closeForm.errors.counted_amount || closeForm.errors.note
                    "
                    class="font-bold text-red-700"
                >
                    {{
                        closeForm.errors.counted_amount ?? closeForm.errors.note
                    }}
                </p>
                <button
                    type="submit"
                    class="h-16 rounded-[24px] text-[20px] font-extrabold disabled:opacity-40"
                    :style="{
                        backgroundColor: 'var(--s-rouge)',
                        color: 'var(--s-sur-rouge)',
                    }"
                    :disabled="closeForm.processing || !fermetureValide"
                >
                    Fermer la caisse
                </button>
            </form>
        </template>
    </div>
</template>
