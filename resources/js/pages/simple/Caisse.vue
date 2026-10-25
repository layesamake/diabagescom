<script setup lang="ts">
/**
 * Écran Caisse du mode simple : ouverture, synthèse et fermeture. L'argent du
 * tiroir se compte billet par billet (maquette 06b) et tout écart exige une
 * justification.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed } from 'vue';
import CarteMontant from '@/components/simple/CarteMontant.vue';
import ComptageBillets from '@/components/simple/ComptageBillets.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { emptyCount, isEmpty, total } from '@/lib/cashCount';
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
// Le serveur recalcule le total à partir du comptage : l'écran n'envoie que les billets.
const openForm = useForm({ count: emptyCount() });
const closeForm = useForm({ count: emptyCount(), note: '' });
const totalOuverture = computed(() => total(openForm.count));
const totalCompte = computed(() => total(closeForm.count));
const ecartDeCaisse = computed(() =>
    props.session ? totalCompte.value - props.session.drawer : 0,
);
const fermetureValide = computed(
    () =>
        (!isEmpty(closeForm.count) || props.session?.drawer === 0) &&
        (ecartDeCaisse.value === 0 || closeForm.note.trim().length >= 3),
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
                <h2 class="text-[22px] font-extrabold">
                    Comptez l’argent du tiroir
                </h2>
                <p
                    class="text-[17px] font-semibold"
                    :style="{ color: 'var(--s-texte-doux)' }"
                >
                    Touchez + pour chaque billet
                </p>
                <ComptageBillets v-model="openForm.count" />
                <div class="flex items-baseline justify-between gap-3">
                    <span
                        class="text-[17px] font-semibold"
                        :style="{ color: 'var(--s-texte-doux)' }"
                        >Dans le tiroir</span
                    >
                    <strong class="text-[32px] font-extrabold tabular-nums">{{
                        formatFranc(totalOuverture)
                    }}</strong>
                </div>
                <p
                    v-if="
                        openForm.errors.count || openForm.errors.opening_amount
                    "
                    role="alert"
                    class="font-bold text-red-700"
                >
                    {{
                        openForm.errors.count ?? openForm.errors.opening_amount
                    }}
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
                    Ouvrir avec {{ formatFranc(totalOuverture) }}
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
                <h2 class="text-[22px] font-extrabold">
                    Comptez l’argent du tiroir
                </h2>
                <ComptageBillets v-model="closeForm.count" />
                <div class="flex items-baseline justify-between gap-3">
                    <span
                        class="text-[17px] font-semibold"
                        :style="{ color: 'var(--s-texte-doux)' }"
                        >Compté</span
                    >
                    <strong class="text-[32px] font-extrabold tabular-nums">{{
                        formatFranc(totalCompte)
                    }}</strong>
                </div>

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
                        closeForm.errors.count ||
                        closeForm.errors.counted_amount ||
                        closeForm.errors.note
                    "
                    role="alert"
                    class="font-bold text-red-700"
                >
                    {{
                        closeForm.errors.count ??
                        closeForm.errors.counted_amount ??
                        closeForm.errors.note
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
