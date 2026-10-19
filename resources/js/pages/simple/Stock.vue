<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { AlertTriangle, Boxes, Check, PackageX } from '@lucide/vue';
import { computed, ref } from 'vue';
import CompteursFiltres, {
    type CaseFiltre,
} from '@/components/simple/CompteursFiltres.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import SimpleLayout from '@/layouts/SimpleLayout.vue';

type Statut = 'enough' | 'low' | 'empty';

type Produit = {
    id: string;
    name: string;
    sku: string;
    unit: string;
    quantity: string;
    quantity_display: string;
    status: Statut;
};

const props = defineProps<{
    warehouse: { id: string; code: string; name: string };
    products: Produit[];
    counters: Record<Statut, number>;
}>();

const cases: CaseFiltre[] = [
    {
        cle: 'enough',
        libelle: 'Assez',
        valeur: String(props.counters.enough),
        couleur: 'vert',
    },
    {
        cle: 'low',
        libelle: 'Bientôt fini',
        valeur: String(props.counters.low),
        couleur: 'orange',
    },
    {
        cle: 'empty',
        libelle: 'Presque fini',
        valeur: String(props.counters.empty),
        couleur: 'rouge',
    },
];

const filtre = ref<Statut | null>(null);
const produitsVisibles = computed(() =>
    filtre.value === null
        ? props.products
        : props.products.filter((product) => product.status === filtre.value),
);

const details: Record<
    Statut,
    { label: string; icon: typeof Check; color: string }
> = {
    enough: { label: 'Assez', icon: Check, color: 'var(--s-vert-fonce)' },
    low: {
        label: 'Bientôt fini',
        icon: AlertTriangle,
        color: 'var(--s-orange)',
    },
    empty: { label: 'Presque fini', icon: PackageX, color: 'var(--s-rouge)' },
};
</script>

<template>
    <Head title="Stock" />

    <SimpleLayout actif="stock">
        <EnTeteEcran class="lg:hidden" titre="Stock" :retour-visible="false" />

        <div class="grid max-w-[960px] gap-5 p-4 pb-28 lg:p-0 lg:pb-0">
            <header>
                <h1
                    class="text-[28px] leading-tight font-extrabold lg:text-[32px]"
                >
                    Stock
                </h1>
                <p
                    class="mt-1 text-[16px] font-semibold"
                    :style="{ color: 'var(--s-texte-doux)' }"
                >
                    {{ warehouse.name }} · {{ warehouse.code }}
                </p>
            </header>

            <CompteursFiltres
                :cases="cases"
                :actif="filtre"
                @filtrer="(cle) => (filtre = cle as Statut | null)"
            />

            <section
                class="overflow-hidden rounded-[24px]"
                :style="{ border: '1px solid var(--s-bordure)' }"
            >
                <div
                    v-for="product in produitsVisibles"
                    :key="product.id"
                    class="flex min-h-20 items-center gap-4 border-b px-4 py-3 last:border-b-0"
                    :style="{ borderColor: 'var(--s-bordure)' }"
                >
                    <span
                        class="grid size-12 shrink-0 place-items-center rounded-[16px]"
                        :style="{ backgroundColor: 'var(--s-fond)' }"
                    >
                        <Boxes
                            :size="24"
                            :style="{ color: details[product.status].color }"
                            aria-hidden="true"
                        />
                    </span>
                    <span class="min-w-0 flex-1">
                        <strong class="block truncate text-[17px]">{{
                            product.name
                        }}</strong>
                        <span
                            class="text-sm font-semibold"
                            :style="{ color: 'var(--s-texte-doux)' }"
                            >{{ product.sku }}</span
                        >
                    </span>
                    <span class="text-right">
                        <strong class="block text-[19px]"
                            >{{ product.quantity_display }}
                            {{ product.unit }}</strong
                        >
                        <span
                            class="text-sm font-bold"
                            :style="{ color: details[product.status].color }"
                            >{{ details[product.status].label }}</span
                        >
                    </span>
                </div>

                <div
                    v-if="produitsVisibles.length === 0"
                    class="grid min-h-52 place-items-center p-6 text-center"
                >
                    <div>
                        <Boxes
                            class="mx-auto mb-3 size-12"
                            :style="{ color: 'var(--s-texte-doux)' }"
                            aria-hidden="true"
                        />
                        <p class="text-[17px] font-bold">
                            Aucun article en stock
                        </p>
                        <p
                            class="mt-1 text-sm"
                            :style="{ color: 'var(--s-texte-doux)' }"
                        >
                            Ajoutez un article puis son arrivage pour le voir
                            ici.
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </SimpleLayout>
</template>
