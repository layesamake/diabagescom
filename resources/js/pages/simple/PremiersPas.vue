<script setup lang="ts">
/**
 * Parcours de démarrage du mode simple.
 *
 * L'écran ne conserve aucun état métier local : les étapes, leur ordre, leur
 * permission et leur progression sont ceux du StartGuide partagé par Inertia.
 * Ainsi une action réalisée depuis l'interface experte est aussitôt visible ici.
 */
import { Head, Link } from '@inertiajs/vue3';
import {
    Boxes,
    Check,
    ChevronRight,
    CircleUserRound,
    Mic,
    Package,
    Plus,
    ShoppingCart,
    Truck,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import BarreEtapes from '@/components/simple/BarreEtapes.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { useGuide } from '@/composables/useGuide';
import type { GuideStep } from '@/types';

type EtatProgression = 'faite' | 'passee' | 'en-cours' | 'a-faire';

const { steps, total, next, stepText } = useGuide();
const afficherToutes = ref(false);
const enCours = computed(() => next.value);
const faites = computed(
    () => steps.value.filter((etape) => etape.status !== 'todo').length,
);
const etatsProgression = computed<EtatProgression[]>(() =>
    steps.value.map((etape) => {
        if (etape.status === 'done') return 'faite';
        if (etape.status === 'skipped') return 'passee';
        return etape.key === enCours.value?.key ? 'en-cours' : 'a-faire';
    }),
);
const positionCourante = computed(() => {
    const position = steps.value.findIndex(
        (etape) => etape.key === enCours.value?.key,
    );

    return position >= 0 ? position + 1 : total.value;
});
const visibles = computed(() =>
    afficherToutes.value ? steps.value : steps.value.slice(0, 6),
);
const restantes = computed(() =>
    Math.max(total.value - visibles.value.length, 0),
);

function estFaite(etape: GuideStep): boolean {
    return etape.status === 'done';
}

function estPassee(etape: GuideStep): boolean {
    return etape.status === 'skipped';
}

function estEnCours(etape: GuideStep): boolean {
    return etape.key === enCours.value?.key;
}

function iconeEtape(key: string) {
    return (
        {
            company: CircleUserRound,
            voice: Mic,
            products: Package,
            stock: Boxes,
            cash: Wallet,
            sale: ShoppingCart,
            customer: CircleUserRound,
            team: CircleUserRound,
            backup: Package,
            supplier: Truck,
            order: Truck,
            receipt: Package,
            count: Boxes,
            accounting: Package,
            entry: Package,
            reports: Package,
        }[key] ?? CircleUserRound
    );
}
</script>

<template>
    <Head title="Premiers pas" />

    <EnTeteEcran
        class="lg:hidden"
        titre="Premiers pas"
        :etape="positionCourante"
        :etape-total="total"
    />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <BarreEtapes
            :total="total"
            :faites="faites"
            :en-cours="enCours !== null"
            :etats="etatsProgression"
            aria-label="Progression du parcours"
        />

        <ul v-if="total > 0" class="flex flex-col gap-2">
            <li
                v-for="etape in visibles"
                :key="etape.key"
                class="flex items-center gap-3 rounded-2xl border bg-card px-4 py-3"
                :style="
                    estEnCours(etape)
                        ? {
                              borderColor: 'var(--s-orange)',
                              backgroundColor: 'var(--s-surface)',
                          }
                        : undefined
                "
            >
                <span
                    v-if="estFaite(etape)"
                    class="grid size-9 shrink-0 place-items-center rounded-full"
                    :style="{
                        backgroundColor: 'var(--s-vert-clair)',
                        color: 'var(--s-sur-vert-clair)',
                    }"
                    aria-hidden="true"
                >
                    <Check :size="18" :stroke-width="3" />
                </span>
                <span
                    v-else-if="estPassee(etape)"
                    class="grid size-9 shrink-0 place-items-center rounded-full text-base font-extrabold"
                    :style="{
                        backgroundColor: 'var(--s-vert-clair)',
                        color: 'var(--s-sur-vert-clair)',
                    }"
                    aria-label="Étape passée"
                >
                    —
                </span>
                <span
                    v-else-if="estEnCours(etape)"
                    class="grid size-9 shrink-0 place-items-center rounded-full text-base font-extrabold"
                    :style="{
                        backgroundColor: 'var(--s-orange-clair)',
                        color: 'var(--s-sur-orange-clair)',
                    }"
                >
                    {{ steps.indexOf(etape) + 1 }}
                </span>
                <span
                    v-else
                    class="grid size-9 shrink-0 place-items-center rounded-full text-[15px] font-semibold"
                    :style="{
                        backgroundColor: 'var(--s-bordure)',
                        color: 'var(--s-texte-doux)',
                    }"
                >
                    {{ steps.indexOf(etape) + 1 }}
                </span>

                <component
                    :is="iconeEtape(etape.key)"
                    :size="22"
                    :stroke-width="2"
                    class="shrink-0"
                    :style="{
                        color:
                            !estFaite(etape) &&
                            !estPassee(etape) &&
                            !estEnCours(etape)
                                ? 'var(--s-texte-doux)'
                                : 'var(--s-texte)',
                    }"
                    aria-hidden="true"
                />

                <span
                    class="min-w-0 flex-1 truncate text-base font-medium"
                    :class="{
                        'text-muted-foreground':
                            !estFaite(etape) &&
                            !estPassee(etape) &&
                            !estEnCours(etape),
                    }"
                >
                    {{ stepText(etape.key, 'title') }}
                </span>

                <span
                    v-if="estEnCours(etape)"
                    class="rounded-full px-2 py-0.5 text-[15px] font-semibold"
                    :style="{
                        backgroundColor: 'var(--s-orange-clair)',
                        color: 'var(--s-sur-orange-clair)',
                    }"
                >
                    En cours
                </span>
                <span
                    v-else-if="estPassee(etape)"
                    class="rounded-full px-2 py-0.5 text-[15px] font-semibold"
                    :style="{
                        backgroundColor: 'var(--s-vert-clair)',
                        color: 'var(--s-sur-vert-clair)',
                    }"
                >
                    Passée
                </span>
            </li>
        </ul>

        <div
            v-if="enCours"
            class="flex flex-col gap-4 rounded-2xl border p-4"
            :style="{
                borderColor: 'var(--s-orange)',
                backgroundColor: 'var(--s-surface)',
            }"
        >
            <div class="flex items-start gap-3">
                <span
                    class="grid size-12 shrink-0 place-items-center rounded-full"
                    :style="{
                        backgroundColor: 'var(--s-orange-clair)',
                        color: 'var(--s-sur-orange-clair)',
                    }"
                    aria-hidden="true"
                >
                    <component :is="iconeEtape(enCours.key)" :size="22" />
                </span>
                <div class="flex min-w-0 flex-col gap-1">
                    <span
                        class="text-[15px] font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Étape {{ positionCourante }} sur {{ total }}
                    </span>
                    <strong class="text-lg">{{
                        stepText(enCours.key, 'title')
                    }}</strong>
                    <span class="text-[15px] text-muted-foreground">
                        {{ stepText(enCours.key, 'hint') }}
                    </span>
                </div>
            </div>
            <Link
                :href="enCours.href"
                class="inline-flex min-h-12 items-center justify-center gap-2 rounded-full px-5 py-3 text-base font-extrabold"
                :style="{
                    backgroundColor: 'var(--s-vert)',
                    color: 'var(--s-sur-vert)',
                }"
            >
                Commencer
                <ChevronRight :size="20" :stroke-width="2.5" />
            </Link>
        </div>

        <p
            v-else
            class="rounded-2xl border p-4 text-center text-[15px] font-semibold"
            :style="{
                borderColor: 'var(--s-vert)',
                backgroundColor: 'var(--s-vert-clair)',
                color: 'var(--s-sur-vert-clair)',
            }"
        >
            Toutes les étapes de votre parcours sont terminées.
        </p>

        <button
            v-if="restantes > 0"
            type="button"
            class="inline-flex min-h-12 items-center justify-center gap-2 self-center rounded-full border border-dashed px-4 py-2 text-[15px] font-semibold"
            :style="{
                borderColor: 'var(--s-bordure)',
                color: 'var(--s-texte-doux)',
            }"
            @click="afficherToutes = true"
        >
            <Plus :size="16" :stroke-width="2.5" />
            + {{ restantes }} autres étapes
        </button>
    </div>
</template>
