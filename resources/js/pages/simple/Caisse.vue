<script setup lang="ts">
/**
 * Écran Caisse du mode simple (lot 2).
 *
 * Une seule page pilotée par l'état de la session : fermée (proposer d'ouvrir),
 * ouverte (solde, entrées/sorties, proposer de fermer), résultat de fermeture.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';
import CarteMontant from '@/components/simple/CarteMontant.vue';
import Compteur from '@/components/simple/Compteur.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { formatFranc } from '@/lib/money';

type SessionData = {
    id: string;
    status: string;
    opening_amount: number;
    opened_by: string;
    opened_at: string;
    summary: {
        opening: number;
        income: number;
        expense: number;
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

const openForm = useForm({
    register_id: props.register?.id ?? '',
    opening_amount: 0,
});
const closeForm = useForm({ counted_amount: 0, note: '' });

function ouvrir(): void {
    openForm.post('/simple/caisse/ouvrir', { preserveScroll: true });
}

function fermer(): void {
    if (props.session) {
        closeForm.post(`/simple/caisse/${props.session.id}/fermer`, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Caisse" />

    <EnTeteEcran titre="Caisse" :icone="Wallet" :retour-visible="false" />

    <div class="grid gap-4 p-4">
        <!-- Pas de caisse configurée -->
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

        <!-- Caisse fermée : proposer d'ouvrir -->
        <template v-else-if="!session">
            <p
                class="text-center text-[17px] font-semibold"
                :style="{ color: 'var(--s-texte-doux)' }"
            >
                La caisse « {{ register.name }} » est fermée.
            </p>

            <div v-if="can.open" class="grid gap-3">
                <label class="text-[17px] font-semibold">Fond de caisse</label>
                <div class="flex justify-center">
                    <Compteur
                        v-model:valeur="openForm.opening_amount"
                        :min="0"
                        unite="F"
                    />
                </div>
                <button
                    type="button"
                    class="h-16 rounded-[24px] text-[20px] font-extrabold"
                    :style="{
                        backgroundColor: 'var(--s-vert)',
                        color: 'var(--s-sur-vert)',
                    }"
                    :disabled="openForm.processing"
                    @click="ouvrir"
                >
                    Ouvrir la caisse
                </button>
            </div>
        </template>

        <!-- Caisse ouverte -->
        <template v-else>
            <CarteMontant libelle="Dans la caisse" :montant="session.drawer" />

            <div class="grid grid-cols-2 gap-3">
                <div
                    class="rounded-[20px] p-3 text-center"
                    :style="{
                        backgroundColor: 'var(--s-vert-clair)',
                        color: 'var(--s-sur-vert-clair)',
                    }"
                >
                    <p class="text-[14px] font-semibold">Entrées</p>
                    <p class="text-[22px] font-extrabold tabular-nums">
                        {{ formatFranc(session.summary.income) }}
                    </p>
                </div>
                <div
                    class="rounded-[20px] p-3 text-center"
                    :style="{
                        backgroundColor: 'var(--s-rouge-clair)',
                        color: 'var(--s-sur-rouge-clair)',
                    }"
                >
                    <p class="text-[14px] font-semibold">Sorties</p>
                    <p class="text-[22px] font-extrabold tabular-nums">
                        {{ formatFranc(session.summary.expense) }}
                    </p>
                </div>
            </div>

            <div v-if="can.close" class="grid gap-3">
                <label class="text-[17px] font-semibold">Montant compté</label>
                <div class="flex justify-center">
                    <Compteur
                        v-model:valeur="closeForm.counted_amount"
                        :min="0"
                        unite="F"
                    />
                </div>
                <button
                    type="button"
                    class="h-16 rounded-[24px] text-[20px] font-extrabold"
                    :style="{
                        backgroundColor: 'var(--s-rouge)',
                        color: 'var(--s-sur-rouge)',
                    }"
                    :disabled="closeForm.processing"
                    @click="fermer"
                >
                    Fermer la caisse
                </button>
            </div>
        </template>
    </div>
</template>
