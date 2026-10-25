<script setup lang="ts">
/**
 * Mode simple — lot 6 : liste tactile des commandes fournisseurs.
 *
 * Les commandes ouvertes restent dans le mode simple : leur détail et leur
 * réception sont ajoutés dans une tranche suivante. Les données proviennent du
 * contrôleur simple, filtrées par les dépôts accessibles à l'utilisateur.
 */
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, PackageOpen, Truck } from '@lucide/vue';
import { computed } from 'vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { useAudio } from '@/composables/useAudio';
import { useAppContext } from '@/composables/useAppContext';
import { formatFranc } from '@/lib/money';

type Commande = {
    id: string;
    number: string | null;
    status: 'confirmed' | 'partially_received' | 'received';
    supplier: string;
    total: number;
    expected_on: string | null;
};

const audio = useAudio();
const props = defineProps<{
    open_orders: Commande[];
    received_orders: Commande[];
    audio_code: string;
}>();

const { can } = useAppContext();
const peutCommander = computed(() => can('purchase.create'));
const aucuneCommande = computed(
    () => props.open_orders.length + props.received_orders.length === 0,
);

function datePrevue(order: Commande): string | null {
    if (order.expected_on === null) return null;

    return new Intl.DateTimeFormat('fr-FR', {
        day: 'numeric',
        month: 'long',
    }).format(new Date(`${order.expected_on}T00:00:00`));
}
</script>

<template>
    <Head title="Achats" />

    <EnTeteEcran
        titre="Achats"
        :icone="Truck"
        :code-audio="audio_code"
        :audio-disponible="audio.disponible(audio_code)"
        @jouer="audio.jouer"
        @retour="router.visit('/simple')"
    />

    <main class="grid gap-5 p-4 pb-28 lg:max-w-4xl lg:p-8">
        <div class="hidden items-center justify-between gap-4 lg:flex">
            <h1 class="text-[32px] font-extrabold">Achats</h1>
            <a
                v-if="peutCommander"
                href="/purchases/orders/create"
                class="inline-flex min-h-12 items-center rounded-[18px] px-5 text-[17px] font-extrabold"
                :style="{
                    backgroundColor: 'var(--s-bleu-clair)',
                    color: 'var(--s-sur-bleu-clair)',
                }"
            >
                Commander
            </a>
        </div>

        <section v-if="open_orders.length > 0" aria-labelledby="en-route">
            <div class="mb-3 flex items-center gap-2">
                <Truck
                    :size="24"
                    :stroke-width="2.25"
                    :style="{ color: 'var(--s-orange)' }"
                    aria-hidden="true"
                />
                <h2 id="en-route" class="text-[22px] font-extrabold">
                    Commandes en route
                </h2>
            </div>

            <div class="grid gap-3">
                <a
                    v-for="order in open_orders"
                    :key="order.id"
                    :href="`/simple/achats/${order.id}`"
                    class="block rounded-[22px] border p-4"
                    :style="{
                        borderColor: 'var(--s-bordure)',
                        backgroundColor: 'var(--s-surface)',
                    }"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[17px] font-extrabold">
                                {{ order.supplier }}
                            </p>
                            <p
                                v-if="order.number"
                                class="mt-0.5 text-[15px] font-semibold"
                                :style="{ color: 'var(--s-texte-doux)' }"
                            >
                                {{ order.number }}
                            </p>
                        </div>
                        <span
                            class="shrink-0 rounded-full px-3 py-1 text-[15px] font-extrabold"
                            :style="{
                                backgroundColor: 'var(--s-orange-clair)',
                                color: 'var(--s-sur-orange-clair)',
                            }"
                        >
                            Commande en route
                        </span>
                    </div>
                    <p class="mt-3 text-[20px] font-extrabold tabular-nums">
                        {{ formatFranc(order.total) }}
                    </p>
                    <p
                        v-if="datePrevue(order)"
                        class="mt-1 text-[15px] font-semibold"
                        :style="{ color: 'var(--s-texte-doux)' }"
                    >
                        Prévue le {{ datePrevue(order) }}
                    </p>
                </a>
            </div>
        </section>

        <section v-if="received_orders.length > 0" aria-labelledby="recues">
            <div class="mb-3 flex items-center gap-2">
                <CheckCircle2
                    :size="24"
                    :stroke-width="2.25"
                    :style="{ color: 'var(--s-vert)' }"
                    aria-hidden="true"
                />
                <h2 id="recues" class="text-[22px] font-extrabold">
                    Commandes reçues
                </h2>
            </div>

            <div class="grid gap-3">
                <article
                    v-for="order in received_orders"
                    :key="order.id"
                    class="rounded-[22px] border p-4"
                    :style="{
                        borderColor: 'var(--s-bordure)',
                        backgroundColor: 'var(--s-surface)',
                    }"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[17px] font-extrabold">
                                {{ order.supplier }}
                            </p>
                            <p
                                v-if="order.number"
                                class="mt-0.5 text-[15px] font-semibold"
                                :style="{ color: 'var(--s-texte-doux)' }"
                            >
                                {{ order.number }}
                            </p>
                        </div>
                        <span
                            class="shrink-0 rounded-full px-3 py-1 text-[15px] font-extrabold"
                            :style="{
                                backgroundColor: 'var(--s-vert-clair)',
                                color: 'var(--s-sur-vert-clair)',
                            }"
                        >
                            Commande reçue
                        </span>
                    </div>
                    <p class="mt-3 text-[20px] font-extrabold tabular-nums">
                        {{ formatFranc(order.total) }}
                    </p>
                </article>
            </div>
        </section>

        <section
            v-if="aucuneCommande"
            class="grid min-h-64 place-items-center rounded-[24px] border p-6 text-center"
            :style="{ borderColor: 'var(--s-bordure)' }"
        >
            <div>
                <PackageOpen
                    class="mx-auto mb-3 size-14"
                    :style="{ color: 'var(--s-texte-doux)' }"
                    aria-hidden="true"
                />
                <p class="text-[19px] font-extrabold">Aucune commande</p>
                <p
                    class="mt-1 text-[16px] font-semibold"
                    :style="{ color: 'var(--s-texte-doux)' }"
                >
                    Les commandes aux fournisseurs apparaîtront ici.
                </p>
            </div>
        </section>

        <a
            v-if="peutCommander"
            href="/purchases/orders/create"
            class="min-h-16 rounded-[22px] px-5 py-4 text-center text-[20px] font-extrabold lg:hidden"
            :style="{
                backgroundColor: 'var(--s-bleu)',
                color: 'var(--s-sur-bleu)',
            }"
        >
            Commander
        </a>
    </main>
</template>
