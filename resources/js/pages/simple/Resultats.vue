<script setup lang="ts">
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { BarChart3 } from '@lucide/vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { useAudio } from '@/composables/useAudio';
import { formatFranc } from '@/lib/money';

type PeriodKey = 'week' | 'month' | 'year';

const audio = useAudio();
const props = defineProps<{
    period: { key: PeriodKey; from: string; to: string };
    summary: { sold: number; spent: number | null; remaining: number | null };
    comparison: Record<string, unknown>;
    by_method: { method: string; total: number }[];
    by_day: { day: string; total: number; sales: number }[];
    top_products: { label: string; quantity: string; total: number }[];
    can: { margin: boolean };
    audio_code: string;
}>();

const periods: { key: PeriodKey; label: string; title: string }[] = [
    { key: 'week', label: 'Semaine', title: 'Cette semaine' },
    { key: 'month', label: 'Mois', title: 'Ce mois-ci' },
    { key: 'year', label: 'Année', title: 'Cette année' },
];

const title = computed(
    () =>
        periods.find((option) => option.key === props.period.key)?.title ??
        'Mes résultats',
);

function choosePeriod(key: PeriodKey): void {
    router.get('/simple/resultats', { period: key });
}

function methodLabel(method: string): string {
    return (
        {
            cash: 'Espèces',
            mobile_money: 'Mobile money',
            card: 'Carte',
            transfer: 'Virement',
        }[method] ?? method
    );
}
</script>

<template>
    <Head title="Mes résultats" />

    <EnTeteEcran
        titre="Mes résultats"
        :icone="BarChart3"
        :code-audio="audio_code"
        :audio-disponible="
            period.key === 'month' && audio.disponible(audio_code)
        "
        @jouer="(code) => audio.jouer(code, { montant: summary.sold })"
        @retour="router.visit('/simple')"
    />

    <main class="grid gap-4 p-4 pb-28 lg:max-w-3xl lg:p-8">
        <div
            class="grid grid-cols-3 gap-2"
            role="group"
            aria-label="Choisir la période"
        >
            <button
                v-for="option in periods"
                :key="option.key"
                type="button"
                class="min-h-12 rounded-xl border px-2 py-3 text-[15px] font-extrabold"
                :class="
                    option.key === period.key
                        ? 'bg-[var(--s-vert-fonce)] text-white'
                        : 'bg-white'
                "
                :style="{ borderColor: 'var(--s-bordure)' }"
                :aria-pressed="option.key === period.key"
                @click="choosePeriod(option.key)"
            >
                {{ option.label }}
            </button>
        </div>

        <p class="text-[18px] font-extrabold">{{ title }}</p>
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
            v-if="by_method.length > 0"
            class="rounded-[22px] border p-4"
            :style="{ borderColor: 'var(--s-bordure)' }"
        >
            <h2 class="text-[20px] font-extrabold">Où est l'argent ?</h2>
            <dl class="mt-3 grid gap-2">
                <div
                    v-for="entry in by_method"
                    :key="entry.method"
                    class="flex items-center justify-between gap-3 text-[17px] font-semibold"
                >
                    <dt>{{ methodLabel(entry.method) }}</dt>
                    <dd class="tabular-nums">{{ formatFranc(entry.total) }}</dd>
                </div>
            </dl>
        </section>

        <section
            v-if="by_day.length > 0"
            class="rounded-[22px] border p-4"
            :style="{ borderColor: 'var(--s-bordure)' }"
        >
            <h2 class="text-[20px] font-extrabold">Vendu par jour</h2>
            <dl class="mt-3 grid gap-2">
                <div
                    v-for="entry in by_day"
                    :key="entry.day"
                    class="flex items-center justify-between gap-3 text-[17px] font-semibold"
                >
                    <dt>{{ entry.day }}</dt>
                    <dd class="tabular-nums">{{ formatFranc(entry.total) }}</dd>
                </div>
            </dl>
        </section>

        <section
            v-if="top_products.length > 0"
            class="rounded-[22px] border p-4"
            :style="{ borderColor: 'var(--s-bordure)' }"
        >
            <h2 class="text-[20px] font-extrabold">Meilleures ventes</h2>
            <dl class="mt-3 grid gap-2">
                <div
                    v-for="product in top_products"
                    :key="`${product.label}-${product.quantity}`"
                    class="flex items-center justify-between gap-3 text-[17px] font-semibold"
                >
                    <dt>
                        {{ product.label }}
                        <span
                            class="text-[15px] font-medium text-[var(--s-texte-doux)]"
                        >
                            × {{ product.quantity }}
                        </span>
                    </dt>
                    <dd class="tabular-nums">
                        {{ formatFranc(product.total) }}
                    </dd>
                </div>
            </dl>
        </section>
    </main>
</template>
