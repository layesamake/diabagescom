<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Phone, ReceiptText } from '@lucide/vue';
import { computed, ref } from 'vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { formatFranc } from '@/lib/money';

type Session = {
    id: string;
    register: string;
    opened_by: string;
};

type Invoice = {
    id: string;
    number: string | null;
    balance: number;
    sessions: Session[];
};

type Props = {
    customer: { id: string; name: string; phone: string | null };
    total_due: number;
    invoices: Invoice[];
    methods: string[];
};

const props = defineProps<Props>();
const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const selectedId = ref(props.invoices[0]?.id ?? '');
const selectedInvoice = computed(() =>
    props.invoices.find((invoice) => invoice.id === selectedId.value)!,
);
const form = useForm({
    idempotency_key: crypto.randomUUID(),
    cash_session_id: props.invoices[0]?.sessions[0]?.id ?? '',
    method: 'cash',
    amount: props.invoices[0]?.balance ?? 0,
});
const cashNeedsSession = computed(
    () => form.method === 'cash' && !form.cash_session_id,
);

function selectInvoice(invoice: Invoice): void {
    selectedId.value = invoice.id;
    form.amount = invoice.balance;
    form.cash_session_id = invoice.sessions[0]?.id ?? '';
    form.clearErrors();
}

function pay(): void {
    if (!selectedInvoice.value || cashNeedsSession.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        cash_session_id:
            data.method === 'cash' ? data.cash_session_id || null : null,
    })).post(
        `/simple/clients/${props.customer.id}/factures/${selectedInvoice.value.id}/paiement`,
        {
            preserveScroll: true,
            onSuccess: () => {
                form.idempotency_key = crypto.randomUUID();
            },
        },
    );
}
</script>

<template>
    <Head :title="customer.name" />

    <EnTeteEcran class="lg:hidden" :titre="customer.name" />

    <div class="grid max-w-[960px] gap-5 p-4 pb-28 lg:p-0 lg:pb-0">
        <header class="flex items-start justify-between gap-4">
            <div>
                <h1 class="sr-only text-[32px] font-extrabold lg:not-sr-only">
                    {{ customer.name }}
                </h1>
                <p
                    class="mt-1 text-[16px] font-semibold"
                    :style="{ color: 'var(--s-texte-doux)' }"
                >
                    Factures restant à payer
                </p>
            </div>
            <a
                v-if="customer.phone"
                :href="`tel:${customer.phone}`"
                class="inline-flex h-12 items-center gap-2 rounded-full px-4 font-bold"
                :style="{
                    backgroundColor: 'var(--s-orange-clair)',
                    color: 'var(--s-sur-orange-clair)',
                }"
            >
                <Phone class="size-5" aria-hidden="true" />
                Appeler
            </a>
        </header>

        <section
            class="rounded-[24px] p-5"
            :style="{
                backgroundColor: 'var(--s-marque)',
                color: 'var(--s-sur-marque)',
            }"
        >
            <p class="text-[17px] font-bold">Total dû</p>
            <strong class="mt-1 block text-[36px] leading-tight font-extrabold">
                {{ formatFranc(total_due) }}
            </strong>
        </section>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
            <section
                class="grid content-start gap-3"
                aria-label="Factures dues"
            >
                <button
                    v-for="invoice in invoices"
                    :key="invoice.id"
                    type="button"
                    class="flex min-h-20 items-center gap-3 rounded-[22px] border p-4 text-left"
                    :style="{
                        borderColor:
                            selectedId === invoice.id
                                ? 'var(--s-marque)'
                                : 'var(--s-bordure)',
                        backgroundColor:
                            selectedId === invoice.id
                                ? 'var(--s-orange-clair)'
                                : 'transparent',
                    }"
                    @click="selectInvoice(invoice)"
                >
                    <ReceiptText class="size-7 shrink-0" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <strong class="block truncate text-[17px]">
                            {{ invoice.number ?? 'Facture' }}
                        </strong>
                        <span
                            class="text-sm font-semibold"
                            :style="{ color: 'var(--s-texte-doux)' }"
                        >
                            Reste à payer
                        </span>
                    </span>
                    <strong class="text-[19px] text-[var(--s-rouge)]">
                        {{ formatFranc(invoice.balance) }}
                    </strong>
                </button>
            </section>

            <form
                class="grid content-start gap-4 rounded-[24px] border p-5 lg:sticky lg:top-6"
                :style="{ borderColor: 'var(--s-bordure)' }"
                @submit.prevent="pay"
            >
                <h2 class="text-[22px] font-extrabold">
                    Enregistrer un paiement
                </h2>

                <label class="grid gap-2 font-bold">
                    Montant
                    <input
                        v-model.number="form.amount"
                        type="number"
                        min="1"
                        :max="selectedInvoice.balance"
                        inputmode="numeric"
                        class="h-16 rounded-[20px] border px-4 text-center text-[24px] font-extrabold"
                    />
                    <span
                        v-if="form.errors.amount"
                        class="text-sm text-red-700"
                    >
                        {{ form.errors.amount }}
                    </span>
                </label>

                <label class="grid gap-2 font-bold">
                    Mode de paiement
                    <select
                        v-model="form.method"
                        class="h-14 rounded-[18px] border px-4 text-[17px]"
                    >
                        <option
                            v-for="method in methods"
                            :key="method"
                            :value="method"
                        >
                            {{
                                {
                                    cash: 'Espèces',
                                    wave: 'Wave',
                                    orange_money: 'Orange Money',
                                    card: 'Carte',
                                    transfer: 'Virement',
                                    cheque: 'Chèque',
                                }[method] ?? method
                            }}
                        </option>
                    </select>
                </label>

                <label
                    v-if="form.method === 'cash'"
                    class="grid gap-2 font-bold"
                >
                    Caisse ouverte
                    <select
                        v-model="form.cash_session_id"
                        class="h-14 rounded-[18px] border px-4 text-[17px]"
                    >
                        <option value="">Choisir une caisse</option>
                        <option
                            v-for="session in selectedInvoice.sessions"
                            :key="session.id"
                            :value="session.id"
                        >
                            {{ session.register }} — {{ session.opened_by }}
                        </option>
                    </select>
                    <span v-if="cashNeedsSession" class="text-sm text-red-700">
                        Ouvrez ou choisissez une caisse pour encaisser des
                        espèces.
                    </span>
                </label>

                <span
                    v-if="errors.business"
                    class="text-sm font-bold text-red-700"
                >
                    {{ errors.business }}
                </span>

                <button
                    type="submit"
                    class="h-16 rounded-[22px] text-[19px] font-extrabold disabled:opacity-50"
                    :style="{
                        backgroundColor: 'var(--s-marque)',
                        color: 'var(--s-sur-marque)',
                    }"
                    :disabled="form.processing || cashNeedsSession"
                >
                    {{
                        form.processing
                            ? 'Encaissement…'
                            : 'Valider le paiement'
                    }}
                </button>
            </form>
        </div>
    </div>
</template>
