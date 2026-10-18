<script setup lang="ts">
/**
 * Écran Vendre du mode simple : un appui ajoute un article, le panier reste
 * visible et le paiement reprend le même endpoint idempotent que le POS expert.
 */
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Banknote,
    CreditCard,
    Smartphone,
    ShoppingCart,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CustomerPicker, {
    type PickedCustomer,
} from '@/components/CustomerPicker.vue';
import CarteArticle from '@/components/simple/CarteArticle.vue';
import Compteur from '@/components/simple/Compteur.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import EcranResultat from '@/components/simple/EcranResultat.vue';
import { formatFranc } from '@/lib/money';

type Method = 'cash' | 'wave' | 'orange_money';
type Product = {
    id: string;
    name: string;
    sale_price: number;
    available: string | null;
    image_url: string | null;
};
type CartLine = { product: Product; quantity: number };
type SaleFlash = {
    id: string;
    number: string;
    total: number;
    amount_paid: number;
    ticket_url: string;
};

type Props = {
    warehouses: { id: string; code: string; name: string }[];
    warehouse: { id: string; code: string; name: string } | null;
    sessions: { id: string; register: string; opened_by: string }[];
    registers: { id: string; name: string }[];
    methods: string[];
    frequent: Product[];
    can: {
        price_override: boolean;
        discount: boolean;
        negative_stock: boolean;
        open_cash: boolean;
    };
};

const props = defineProps<Props>();
const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const saleResult = computed(
    () =>
        (page.flash as Record<string, unknown> | undefined)?.sale as
            | SaleFlash
            | undefined,
);
const etat = ref<'catalogue' | 'resultat'>(
    saleResult.value ? 'resultat' : 'catalogue',
);
const panier = ref<CartLine[]>([]);
const paiement = ref<Method | 'credit'>('cash');
const client = ref<PickedCustomer | null>(null);
const montantRecu = ref(0);
const traitement = ref(false);
const cleVente = ref('');

const total = computed(() =>
    panier.value.reduce(
        (somme, ligne) => somme + ligne.product.sale_price * ligne.quantity,
        0,
    ),
);
const monnaie = computed(() =>
    paiement.value === 'cash'
        ? Math.max(0, montantRecu.value - total.value)
        : 0,
);
const peutValider = computed(
    () =>
        !traitement.value &&
        props.warehouse !== null &&
        props.sessions.length > 0 &&
        panier.value.length > 0 &&
        (paiement.value !== 'credit' || client.value !== null) &&
        (paiement.value !== 'cash' || montantRecu.value >= total.value),
);

function quantite(productId: string): number {
    return (
        panier.value.find((ligne) => ligne.product.id === productId)
            ?.quantity ?? 0
    );
}

function ajouterArticle(product: Product): void {
    const ligne = panier.value.find((item) => item.product.id === product.id);
    if (ligne) {
        ligne.quantity++;
    } else {
        panier.value.push({ product, quantity: 1 });
    }
    if (paiement.value === 'cash') {
        montantRecu.value = total.value;
    }
}

function changerQuantite(ligne: CartLine, quantity: number): void {
    if (quantity <= 0) {
        panier.value.splice(panier.value.indexOf(ligne), 1);
    } else {
        ligne.quantity = quantity;
    }
    if (paiement.value === 'cash') {
        montantRecu.value = total.value;
    }
}

function choisirPaiement(method: Method | 'credit'): void {
    paiement.value = method;
    montantRecu.value = method === 'cash' ? total.value : 0;
}

function validerVente(): void {
    if (!peutValider.value || !props.warehouse) {
        return;
    }
    cleVente.value ||= crypto.randomUUID();
    const tenders =
        paiement.value === 'credit'
            ? []
            : [
                  {
                      method: paiement.value,
                      amount: total.value,
                      reference: null,
                  },
              ];

    router.post(
        '/simple/vendre',
        {
            idempotency_key: cleVente.value,
            warehouse_id: props.warehouse.id,
            cash_session_id: props.sessions[0].id,
            customer_id: client.value?.id ?? null,
            lines: panier.value.map((ligne) => ({
                product_id: ligne.product.id,
                quantity: String(ligne.quantity),
            })),
            tenders,
        },
        {
            preserveScroll: true,
            onStart: () => (traitement.value = true),
            onFinish: () => (traitement.value = false),
        },
    );
}

function nouvelleVente(): void {
    panier.value = [];
    paiement.value = 'cash';
    montantRecu.value = 0;
    client.value = null;
    cleVente.value = '';
    etat.value = 'catalogue';
}

function ouvrirTicket(): void {
    if (saleResult.value?.ticket_url) {
        window.open(saleResult.value.ticket_url, '_blank', 'noopener');
    }
}
</script>

<template>
    <Head title="Vendre" />

    <template v-if="etat === 'resultat' && saleResult">
        <EcranResultat
            etat="reussi"
            titre="Vente faite"
            :montant="saleResult.total"
            :detail="
                saleResult.total > saleResult.amount_paid
                    ? `Reste à payer : ${formatFranc(saleResult.total - saleResult.amount_paid)}`
                    : `Vente ${saleResult.number}`
            "
            action-principale="Nouvelle vente"
            action-secondaire="Ticket"
            @principale="nouvelleVente"
            @secondaire="ouvrirTicket"
        />
    </template>

    <template v-else>
        <EnTeteEcran
            class="lg:hidden"
            titre="Vendre"
            :icone="ShoppingCart"
            :retour-visible="false"
        />

        <div
            class="grid gap-5 p-4 pb-28 lg:grid-cols-[minmax(0,1fr)_400px] lg:items-start lg:p-0 lg:pb-0"
        >
            <p
                v-if="sessions.length === 0"
                class="rounded-[24px] p-4 text-center text-[17px] font-semibold"
                :style="{
                    backgroundColor: 'var(--s-orange-clair)',
                    color: 'var(--s-sur-orange-clair)',
                }"
            >
                La caisse est fermée. Ouvrez-la d'abord.
            </p>

            <template v-else>
                <section class="lg:col-start-1">
                    <h2 class="mb-3 text-[20px] font-extrabold">Articles</h2>
                    <p
                        v-if="frequent.length === 0"
                        class="text-center text-[17px] font-semibold"
                    >
                        Aucun article actif.
                    </p>
                    <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <CarteArticle
                            v-for="article in frequent"
                            :key="article.id"
                            :nom="article.name"
                            :prix="article.sale_price"
                            :photo-url="article.image_url"
                            :quantite="quantite(article.id)"
                            @ajouter="ajouterArticle(article)"
                        />
                    </div>
                </section>

                <section
                    v-if="panier.length > 0"
                    class="grid gap-3 rounded-[24px] p-4 lg:sticky lg:top-6 lg:col-start-2 lg:row-span-2"
                    :style="{ backgroundColor: 'var(--s-surface)' }"
                >
                    <h2 class="text-[20px] font-extrabold">Panier</h2>
                    <div
                        v-for="ligne in panier"
                        :key="ligne.product.id"
                        class="flex items-center gap-3 border-b border-black/10 py-3 last:border-0"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[17px] font-bold">
                                {{ ligne.product.name }}
                            </p>
                            <p class="font-semibold">
                                {{
                                    formatFranc(
                                        ligne.product.sale_price *
                                            ligne.quantity,
                                    )
                                }}
                            </p>
                        </div>
                        <Compteur
                            :valeur="ligne.quantity"
                            :min="0"
                            @update:valeur="changerQuantite(ligne, $event)"
                        />
                        <button
                            type="button"
                            class="inline-flex size-11 items-center justify-center rounded-full"
                            aria-label="Retirer l'article"
                            @click="changerQuantite(ligne, 0)"
                        >
                            <Trash2 :size="22" />
                        </button>
                    </div>
                    <p class="text-right text-[26px] font-extrabold">
                        Total : {{ formatFranc(total) }}
                    </p>
                </section>

                <section
                    v-if="panier.length > 0"
                    class="grid gap-3 lg:col-start-2"
                >
                    <h2 class="text-[20px] font-extrabold">
                        Comment le client paie ?
                    </h2>
                    <div class="grid grid-cols-2 gap-3">
                        <button
                            type="button"
                            class="min-h-20 rounded-[20px] p-3 text-[17px] font-extrabold"
                            :class="
                                paiement === 'cash'
                                    ? 'ring-4 ring-emerald-500'
                                    : ''
                            "
                            @click="choisirPaiement('cash')"
                        >
                            <Banknote class="mx-auto mb-1" />Espèces
                        </button>
                        <button
                            type="button"
                            class="min-h-20 rounded-[20px] p-3 text-[17px] font-extrabold"
                            :class="
                                paiement === 'wave'
                                    ? 'ring-4 ring-emerald-500'
                                    : ''
                            "
                            @click="choisirPaiement('wave')"
                        >
                            <Smartphone class="mx-auto mb-1" />Wave
                        </button>
                        <button
                            type="button"
                            class="min-h-20 rounded-[20px] p-3 text-[17px] font-extrabold"
                            :class="
                                paiement === 'orange_money'
                                    ? 'ring-4 ring-emerald-500'
                                    : ''
                            "
                            @click="choisirPaiement('orange_money')"
                        >
                            <Smartphone class="mx-auto mb-1" />Orange Money
                        </button>
                        <button
                            type="button"
                            class="min-h-20 rounded-[20px] p-3 text-[17px] font-extrabold"
                            :class="
                                paiement === 'credit'
                                    ? 'ring-4 ring-emerald-500'
                                    : ''
                            "
                            @click="choisirPaiement('credit')"
                        >
                            <CreditCard class="mx-auto mb-1" />Crédit
                        </button>
                    </div>

                    <label
                        v-if="paiement === 'cash'"
                        class="grid gap-1 text-[17px] font-bold"
                    >
                        Montant reçu
                        <input
                            v-model.number="montantRecu"
                            type="number"
                            min="0"
                            inputmode="numeric"
                            class="h-16 rounded-[20px] border px-4 text-[24px] font-extrabold"
                        />
                    </label>
                    <p
                        v-if="monnaie > 0"
                        class="text-center text-[20px] font-extrabold"
                    >
                        Monnaie : {{ formatFranc(monnaie) }}
                    </p>
                    <div v-if="paiement === 'credit'" class="grid gap-2">
                        <label
                            for="simple-sale-customer"
                            class="text-[17px] font-bold"
                            >Client obligatoire</label
                        >
                        <CustomerPicker
                            v-model="client"
                            input-id="simple-sale-customer"
                        />
                    </div>
                    <p
                        v-if="errors.business || errors.lines || errors.tenders"
                        class="rounded-[16px] p-3 font-bold"
                        :style="{
                            backgroundColor: 'var(--s-rouge-clair)',
                            color: 'var(--s-sur-rouge-clair)',
                        }"
                    >
                        {{ errors.business ?? errors.lines ?? errors.tenders }}
                    </p>
                    <button
                        type="button"
                        class="h-16 rounded-[24px] text-[21px] font-extrabold disabled:opacity-40"
                        :style="{
                            backgroundColor: 'var(--s-vert)',
                            color: 'var(--s-sur-vert)',
                        }"
                        :disabled="!peutValider"
                        @click="validerVente"
                    >
                        Valider {{ formatFranc(total) }}
                    </button>
                </section>
            </template>
        </div>
    </template>
</template>
