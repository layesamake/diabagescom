<script setup lang="ts">
/**
 * Commander (mode simple) : on choisit le fournisseur, puis les articles et les
 * quantités. Le prix d'achat est proposé (coût moyen du dépôt) et modifiable.
 * « Commander » crée la commande et la confirme : elle apparaît « en route ».
 */
import { Head, router, usePage } from '@inertiajs/vue3';
import { Check, Search, Truck } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Avatar from '@/components/simple/Avatar.vue';
import Compteur from '@/components/simple/Compteur.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { formatFranc } from '@/lib/money';
import { orderTotal, type OrderLine } from '@/lib/purchaseOrder';

type Fournisseur = { id: string; name: string };
type Article = {
    id: string;
    name: string;
    unit: string;
    image_url: string | null;
    stock: string;
    cost: number;
};

const props = defineProps<{
    warehouse: { id: string; code: string; name: string } | null;
    suppliers: Fournisseur[];
    products: Article[];
}>();

const page = usePage();
const fournisseur = ref<string | null>(
    props.suppliers.length === 1 ? props.suppliers[0].id : null,
);
const quantites = reactive<Record<string, number>>({});
const couts = reactive<Record<string, number | string>>(
    Object.fromEntries(props.products.map((p) => [p.id, p.cost])),
);
const recherche = ref('');
const traitement = ref(false);
// Une seule clé par écran : un double appui ne crée jamais deux commandes.
const cle = crypto.randomUUID();

const articlesAffiches = computed(() => {
    const terme = recherche.value.trim().toLocaleLowerCase('fr-FR');

    return terme === ''
        ? props.products
        : props.products.filter((p) =>
              p.name.toLocaleLowerCase('fr-FR').includes(terme),
          );
});

const lignes = computed<OrderLine[]>(() =>
    props.products
        .filter((p) => (quantites[p.id] ?? 0) > 0)
        .map((p) => ({
            productId: p.id,
            quantity: quantites[p.id],
            unitCost: Number(couts[p.id]) || 0,
        })),
);
const total = computed(() => orderTotal(lignes.value));
const prixManquant = computed(() => lignes.value.some((l) => l.unitCost <= 0));
const peutCommander = computed(
    () =>
        !traitement.value &&
        props.warehouse !== null &&
        fournisseur.value !== null &&
        lignes.value.length > 0 &&
        !prixManquant.value,
);
const erreur = computed(() => {
    const erreurs = page.props.errors as Record<string, string>;

    return Object.values(erreurs)[0];
});

function commander(): void {
    if (!peutCommander.value || !props.warehouse || !fournisseur.value) {
        return;
    }

    router.post(
        '/simple/achats',
        {
            idempotency_key: cle,
            warehouse_id: props.warehouse.id,
            supplier_id: fournisseur.value,
            lines: lignes.value.map((l) => ({
                product_id: l.productId,
                quantity: String(l.quantity),
                unit_cost: l.unitCost,
            })),
        },
        {
            preserveScroll: true,
            onStart: () => (traitement.value = true),
            onFinish: () => (traitement.value = false),
        },
    );
}
</script>

<template>
    <Head title="Commander" />

    <EnTeteEcran
        class="lg:hidden"
        titre="Commander"
        :icone="Truck"
        :retour-visible="true"
        @retour="router.visit('/simple/achats')"
    />

    <div class="grid max-w-[880px] gap-5 p-4 pb-40 lg:p-0 lg:pb-8">
        <h1 class="sr-only lg:not-sr-only lg:text-[32px] lg:font-extrabold">
            Commander
        </h1>

        <p
            v-if="!warehouse"
            class="rounded-[24px] p-4 text-center text-[17px] font-semibold"
            :style="{
                backgroundColor: 'var(--s-orange-clair)',
                color: 'var(--s-sur-orange-clair)',
            }"
        >
            Aucun dépôt n’est ouvert pour vous. Demandez à l’administrateur.
        </p>

        <template v-else>
            <section aria-labelledby="chez-qui">
                <h2 id="chez-qui" class="mb-3 text-[20px] font-extrabold">
                    Chez qui ?
                </h2>
                <p
                    v-if="suppliers.length === 0"
                    class="rounded-[20px] p-4 text-[17px] font-semibold"
                    :style="{
                        backgroundColor: 'var(--s-orange-clair)',
                        color: 'var(--s-sur-orange-clair)',
                    }"
                >
                    Aucun fournisseur. Ajoutez-en un depuis le mode expert
                    (Achats, puis Fournisseurs).
                </p>
                <ul v-else class="grid gap-3" role="radiogroup">
                    <li v-for="item in suppliers" :key="item.id">
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="fournisseur === item.id"
                            class="flex min-h-16 w-full items-center gap-4 rounded-[20px] border-2 px-4 text-left"
                            :style="{
                                backgroundColor: 'var(--s-surface)',
                                borderColor:
                                    fournisseur === item.id
                                        ? 'var(--s-bleu)'
                                        : 'var(--s-bordure)',
                            }"
                            @click="fournisseur = item.id"
                        >
                            <Avatar :nom="item.name" :taille="48" />
                            <strong
                                class="min-w-0 flex-1 truncate text-[19px]"
                                >{{ item.name }}</strong
                            >
                            <Check
                                v-if="fournisseur === item.id"
                                :size="28"
                                :stroke-width="3"
                                :style="{ color: 'var(--s-bleu)' }"
                                aria-hidden="true"
                            />
                        </button>
                    </li>
                </ul>
            </section>

            <section aria-labelledby="quels-articles">
                <h2 id="quels-articles" class="mb-3 text-[20px] font-extrabold">
                    Quels articles ?
                </h2>
                <label for="achat-recherche" class="relative mb-3 block">
                    <span
                        class="pointer-events-none absolute inset-y-0 left-4 flex items-center"
                        :style="{ color: 'var(--s-texte-doux)' }"
                        aria-hidden="true"
                    >
                        <Search :size="22" :stroke-width="2" />
                    </span>
                    <input
                        id="achat-recherche"
                        v-model="recherche"
                        type="search"
                        placeholder="Chercher un article"
                        aria-label="Chercher un article"
                        class="h-14 w-full rounded-[20px] border pr-4 pl-12 text-[17px] font-semibold"
                        :style="{
                            borderColor: 'var(--s-bordure)',
                            backgroundColor: 'var(--s-surface)',
                            color: 'var(--s-texte)',
                        }"
                    />
                </label>

                <p
                    v-if="articlesAffiches.length === 0"
                    class="text-center text-[17px] font-semibold"
                >
                    {{
                        recherche.trim() === ''
                            ? 'Aucun article à commander.'
                            : `Aucun article ne correspond à « ${recherche} ».`
                    }}
                </p>
                <ul v-else class="grid gap-3">
                    <li
                        v-for="article in articlesAffiches"
                        :key="article.id"
                        class="grid gap-3 rounded-[20px] p-3"
                        :style="{
                            backgroundColor: 'var(--s-surface)',
                            border:
                                (quantites[article.id] ?? 0) > 0
                                    ? '2px solid var(--s-bleu)'
                                    : '1px solid var(--s-bordure)',
                        }"
                    >
                        <div class="flex items-center gap-3">
                            <Avatar
                                :nom="article.name"
                                :photo-url="article.image_url"
                                :taille="56"
                                forme="carre"
                            />
                            <span class="min-w-0 flex-1">
                                <strong class="block truncate text-[17px]">{{
                                    article.name
                                }}</strong>
                                <span
                                    class="text-[15px] font-semibold"
                                    :style="{ color: 'var(--s-texte-doux)' }"
                                    >En stock : {{ article.stock }}
                                    {{ article.unit }}</span
                                >
                            </span>
                            <Compteur
                                :valeur="quantites[article.id] ?? 0"
                                :min="0"
                                :max="999999"
                                @update:valeur="
                                    (valeur) => (quantites[article.id] = valeur)
                                "
                            />
                        </div>

                        <label
                            v-if="(quantites[article.id] ?? 0) > 0"
                            class="flex items-center justify-between gap-3 text-[15px] font-semibold"
                        >
                            Prix d’achat (F) par {{ article.unit }}
                            <input
                                v-model.number="couts[article.id]"
                                type="number"
                                min="0"
                                inputmode="numeric"
                                class="h-12 w-36 rounded-[16px] border px-3 text-right text-[19px] font-extrabold tabular-nums"
                                :style="{
                                    borderColor: 'var(--s-bordure)',
                                    color: 'var(--s-texte)',
                                }"
                            />
                        </label>
                    </li>
                </ul>
            </section>

            <div
                class="sticky bottom-[calc(var(--s-barre-bas)+8px)] z-10 grid gap-2 rounded-[24px] p-3 lg:bottom-4"
                :style="{
                    backgroundColor: 'var(--s-surface)',
                    border: '1px solid var(--s-bordure)',
                }"
            >
                <p
                    v-if="erreur"
                    role="alert"
                    class="rounded-[16px] p-3 font-bold"
                    :style="{
                        backgroundColor: 'var(--s-rouge-clair)',
                        color: 'var(--s-sur-rouge-clair)',
                    }"
                >
                    {{ erreur }}
                </p>
                <p
                    v-else-if="prixManquant"
                    class="text-[15px] font-bold"
                    :style="{ color: 'var(--s-sur-orange-clair)' }"
                >
                    Indiquez le prix d’achat de chaque article.
                </p>
                <div class="flex items-baseline justify-between gap-3">
                    <span
                        class="text-[15px] font-semibold"
                        :style="{ color: 'var(--s-texte-doux)' }"
                        >Total hors taxe</span
                    >
                    <strong class="text-[26px] font-extrabold tabular-nums">{{
                        formatFranc(total)
                    }}</strong>
                </div>
                <button
                    type="button"
                    class="h-16 rounded-[24px] text-[20px] font-extrabold disabled:opacity-40"
                    :style="{
                        backgroundColor: 'var(--s-bleu)',
                        color: 'var(--s-sur-bleu)',
                    }"
                    :disabled="!peutCommander"
                    @click="commander"
                >
                    Commander
                </button>
            </div>
        </template>
    </div>
</template>
