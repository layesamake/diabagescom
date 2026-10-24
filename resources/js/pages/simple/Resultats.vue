<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { BarChart3 } from '@lucide/vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { formatFranc } from '@/lib/money';

defineProps<{
    period: { key: 'week' | 'month' | 'year'; from: string; to: string };
    summary: { sold: number; spent: number | null; remaining: number | null };
    comparison: Record<string, unknown>;
    by_method: { method: string; total: number }[];
    top_products: { label: string; quantity: string; total: number }[];
    can: { margin: boolean };
    audio_code: string;
}>();
</script>

<template>
    <Head title="Mes résultats" />

    <EnTeteEcran
        titre="Mes résultats"
        :icone="BarChart3"
        :code-audio="audio_code"
        :audio-disponible="true"
        @retour="router.visit('/simple')"
    />

    <main class="grid gap-4 p-4 pb-28 lg:max-w-3xl lg:p-8">
        <p class="text-[18px] font-extrabold">Ce mois-ci</p>
        <section class="grid gap-3">
            <article
                class="rounded-[22px] p-5"
                :style="{
                    backgroundColor: 'var(--s-vert-clair)',
                    color: 'var(--s-sur-vert-clair)',
                }"
            >
                <p class="text-[17px] font-extrabold">Vendu</p>
                <p class="mt-1 text-[30px] font-extrabold tabular-nums">
                    {{ formatFranc(summary.sold) }}
                </p>
            </article>
            <article
                v-if="can.margin && summary.spent !== null"
                class="rounded-[22px] p-5"
                :style="{
                    backgroundColor: 'var(--s-orange-clair)',
                    color: 'var(--s-sur-orange-clair)',
                }"
            >
                <p class="text-[17px] font-extrabold">Dépensé</p>
                <p class="mt-1 text-[30px] font-extrabold tabular-nums">
                    {{ formatFranc(summary.spent) }}
                </p>
            </article>
            <article
                v-if="can.margin && summary.remaining !== null"
                class="rounded-[22px] p-5"
                :style="{
                    backgroundColor: 'var(--s-bleu-clair)',
                    color: 'var(--s-sur-bleu-clair)',
                }"
            >
                <p class="text-[17px] font-extrabold">Il me reste</p>
                <p class="mt-1 text-[30px] font-extrabold tabular-nums">
                    {{ formatFranc(summary.remaining) }}
                </p>
            </article>
        </section>

        <section
            v-if="top_products.length > 0"
            class="rounded-[22px] border p-4"
            :style="{ borderColor: 'var(--s-bordure)' }"
        >
            <h2 class="text-[20px] font-extrabold">Meilleures ventes</h2>
            <p class="mt-2 text-[17px] font-semibold">
                {{ top_products[0].label }}
            </p>
        </section>
    </main>
</template>
