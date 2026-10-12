<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Minus, Plus, SlidersHorizontal, Trash2, Wallet, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CustomerPicker, {
    type PickedCustomer,
} from '@/components/CustomerPicker.vue';
import InputError from '@/components/InputError.vue';
import ProductPicker, {
    type PickedProduct,
} from '@/components/ProductPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import {
    formatQuantity,
    formatXof,
    lineAmount,
    parseAmount,
    parseQuantity,
} from '@/lib/money';
import { enqueueSale, syncState, type SalePayload } from '@/offline/sync';
import { open as openRegister } from '@/routes/cash';
import { create as posCreate, store as posStore } from '@/routes/pos';
import { index as salesIndex } from '@/routes/sales';
import { confirmAction } from '@/composables/useConfirm';

type Method = 'cash' | 'wave' | 'orange_money' | 'card' | 'transfer' | 'cheque';
type CartLine = {
    product: PickedProduct;
    quantity: string;
    unitPrice: number;
    discount: string;
    options: boolean;
};
type Tender = { method: Method; amount: string; reference: string };

const props = defineProps<{
    warehouses: { id: string; code: string; name: string }[];
    warehouse: { id: string; code: string; name: string } | null;
    sessions: { id: string; register: string; opened_by: string }[];
    registers: { id: string; name: string }[];
    methods: Method[];
    frequent: PickedProduct[];
    can: {
        price_override: boolean;
        discount: boolean;
        negative_stock: boolean;
        open_cash: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('sales.title'), href: salesIndex() },
            { title: t('pos.title'), href: posCreate() },
        ],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const sessionId = ref(props.sessions[0]?.id ?? '');
const cart = ref<CartLine[]>([]);
const customer = ref<PickedCustomer | null>(null);
const tenders = ref<Tender[]>([{ method: 'cash', amount: '', reference: '' }]);
const onCredit = ref(false);
const negativeReason = ref('');
const processing = ref(false);
const openingAmount = ref('');
// Une clé par vente : un double appui ou un renvoi après coupure ne crée qu'une vente.
const saleKey = ref(crypto.randomUUID());
const provisional = ref<{ total: number; change: number; at: string } | null>(
    null,
);

const quickMethods: Method[] = ['cash', 'wave', 'orange_money', 'card'];

/**
 * Montants reçus en espèces les plus probables : le montant exact, puis les
 * billets ou sommes rondes juste au-dessus (monnaie à rendre calculée).
 */
const cashSuggestions = computed(() => {
    const due = total.value;

    if (due <= 0) {
        return [];
    }

    const rounded = [500, 1000, 2000, 5000, 10000]
        .map((unit) => Math.ceil(due / unit) * unit)
        .filter((amount) => amount > due);

    return [due, ...new Set(rounded)].slice(0, 5);
});

const showCashSuggestions = computed(
    () =>
        !onCredit.value &&
        tenders.value.length === 1 &&
        tenders.value[0].method === 'cash' &&
        cart.value.length > 0,
);

function receive(amount: number): void {
    tenders.value[0].amount = String(amount);
}

function addProduct(product: PickedProduct): void {
    const existing = cart.value.find((l) => l.product.id === product.id);

    if (existing) {
        step(existing, 1);

        return;
    }

    cart.value.unshift({
        product,
        quantity: '1',
        unitPrice: product.sale_price ?? 0,
        discount: '',
        options: false,
    });
}

function step(line: CartLine, delta: number): void {
    const milli = parseQuantity(line.quantity) ?? 0n;
    const next = milli + BigInt(delta) * 1000n;

    if (next <= 0n) {
        cart.value.splice(cart.value.indexOf(line), 1);

        return;
    }

    line.quantity = formatQuantity(next);
}

function lineTotal(line: CartLine): number {
    return Math.max(
        0,
        lineAmount(line.quantity, line.unitPrice) - parseAmount(line.discount),
    );
}

const total = computed(() =>
    cart.value.reduce((sum, line) => sum + lineTotal(line), 0),
);
const itemCount = computed(() => cart.value.length);
const invalidQuantity = computed(() =>
    cart.value.some((l) => {
        const q = parseQuantity(l.quantity);

        return (
            q === null || q <= 0n || (!l.product.decimals && q % 1000n !== 0n)
        );
    }),
);

/**
 * Répartition du paiement : chaque mode couvre au plus le reste dû.
 * Les espèces peuvent dépasser (monnaie rendue) ; les autres modes non.
 */
const plan = computed(() => {
    let remaining = total.value;
    let change = 0;
    let overpaid = false;
    const applied: {
        method: Method;
        amount: number;
        reference: string | null;
    }[] = [];

    if (!onCredit.value) {
        for (const tender of tenders.value) {
            const entered =
                tenders.value.length === 1 && tender.amount.trim() === ''
                    ? total.value
                    : parseAmount(tender.amount);
            const used = Math.min(entered, remaining);

            if (tender.method === 'cash') {
                change += entered - used;
            } else if (entered > used) {
                overpaid = true;
            }

            remaining -= used;

            if (used > 0) {
                applied.push({
                    method: tender.method,
                    amount: used,
                    reference: tender.reference.trim() || null,
                });
            }
        }
    }

    return { applied, remaining, change, overpaid };
});

const blocked = computed(
    () =>
        processing.value ||
        cart.value.length === 0 ||
        invalidQuantity.value ||
        !sessionId.value ||
        plan.value.overpaid ||
        (plan.value.remaining > 0 && !customer.value),
);

const needsNegativeReason = computed(
    () =>
        props.can.negative_stock &&
        (errors.value.business ?? '').startsWith('Stock insuffisant'),
);

watch(
    () => props.sessions,
    (sessions) => {
        if (!sessions.some((s) => s.id === sessionId.value)) {
            sessionId.value = sessions[0]?.id ?? '';
        }
    },
);

function selectMethod(method: Method): void {
    onCredit.value = false;
    tenders.value[0].method = method;
}

function changeWarehouse(id: string): void {
    router.get(posCreate({ query: { warehouse: id } }).url);
}

function open(registerId: string): void {
    router.post(
        openRegister(registerId).url,
        { opening_amount: parseAmount(openingAmount.value), to_pos: true },
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}

async function clearCart(): Promise<void> {
    if (
        await confirmAction({
            title: t('pos.clear_confirm'),
            confirmLabel: t('confirm.clear'),
            tone: 'danger',
        })
    ) {
        cart.value = [];
        customer.value = null;
        tenders.value = [{ method: 'cash', amount: '', reference: '' }];
        onCredit.value = false;
    }
}

function payload(): SalePayload | null {
    if (!props.warehouse) {
        return null;
    }

    return {
        warehouse_id: props.warehouse.id,
        cash_session_id: sessionId.value,
        customer_id: customer.value?.id ?? null,
        negative_stock_reason: negativeReason.value.trim() || null,
        lines: cart.value.map((l) => ({
            product_id: l.product.id,
            quantity: l.quantity,
            unit_price: l.unitPrice,
            discount: parseAmount(l.discount),
        })),
        tenders: plan.value.applied,
    };
}

/**
 * Hors connexion (ou réseau coupé pendant l'envoi) : la vente part en file
 * locale avec la même clé. Si le serveur l'avait déjà reçue, la synchronisation
 * renverra simplement son résultat : jamais de doublon.
 */
async function saveOffline(data: SalePayload): Promise<void> {
    await enqueueSale(
        data,
        {
            total: total.value,
            labels: cart.value.map((l) => `${l.quantity} × ${l.product.name}`),
            customer: customer.value?.name ?? null,
        },
        saleKey.value,
    );
    provisional.value = {
        total: total.value,
        change: plan.value.change,
        at: new Date().toISOString(),
    };
    cart.value = [];
    customer.value = null;
    tenders.value = [{ method: 'cash', amount: '', reference: '' }];
    onCredit.value = false;
    negativeReason.value = '';
    saleKey.value = crypto.randomUUID();
}

function checkout(): void {
    const data = payload();

    if (blocked.value || !data) {
        return;
    }

    if (!syncState.online || !navigator.onLine) {
        void saveOffline(data);

        return;
    }

    router.post(
        posStore().url,
        { idempotency_key: saleKey.value, ...data },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onNetworkError: () => {
                void saveOffline(data);

                return false;
            },
        },
    );
}
</script>

<template>
    <Head :title="t('pos.title')" />

    <div
        class="mx-auto grid max-w-6xl gap-4 p-4 pb-32 md:p-6 lg:grid-cols-[1fr_24rem] lg:pb-6"
    >
        <section class="space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-semibold">{{ t('pos.title') }}</h1>
                <select
                    v-if="warehouses.length > 1"
                    :value="warehouse?.id"
                    class="h-11 rounded-md border border-input bg-transparent px-3 text-sm"
                    :aria-label="t('sales.warehouse')"
                    @change="
                        changeWarehouse(
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">
                        {{ w.name }}
                    </option>
                </select>
                <span v-else-if="warehouse" class="text-muted-foreground">{{
                    warehouse.name
                }}</span>
            </div>

            <!-- Caisse : obligatoire pour vendre -->
            <div
                v-if="sessions.length === 0"
                class="space-y-3 rounded-lg border border-warning-border bg-warning-soft p-4"
                role="alert"
            >
                <p class="font-medium">{{ t('pos.no_session_title') }}</p>
                <p class="text-sm">{{ t('pos.no_session') }}</p>
                <div
                    v-if="can.open_cash && registers.length"
                    class="flex flex-wrap items-end gap-2"
                >
                    <div class="grid gap-1">
                        <Label for="opening" class="text-xs">{{
                            t('cash.opening_amount')
                        }}</Label>
                        <Input
                            id="opening"
                            v-amount
                            v-model="openingAmount"
                            inputmode="numeric"
                            class="h-11 w-40 bg-background tabular-nums"
                        />
                    </div>
                    <Button
                        v-for="register in registers"
                        :key="register.id"
                        class="h-11"
                        :disabled="processing"
                        @click="open(register.id)"
                        ><Wallet class="size-4" />{{ t('pos.open_cash') }} ·
                        {{ register.name }}</Button
                    >
                </div>
            </div>
            <div v-else-if="sessions.length > 1" class="grid gap-1">
                <Label for="session">{{ t('sales.session') }}</Label>
                <select
                    id="session"
                    v-model="sessionId"
                    class="h-11 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option v-for="s in sessions" :key="s.id" :value="s.id">
                        {{ s.register }} — {{ s.opened_by }}
                    </option>
                </select>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                {{
                    t('pos.session', {
                        register: sessions[0].register,
                        name: sessions[0].opened_by,
                    })
                }}
            </p>

            <div
                v-if="provisional"
                class="space-y-2 rounded-lg border border-info-border bg-info-soft p-4"
                role="status"
            >
                <p class="flex items-center justify-between gap-2 font-medium">
                    {{ t('offline.provisional_title') }}
                    <span
                        class="rounded bg-background px-2 py-0.5 text-xs uppercase"
                        >{{ t('offline.provisional') }}</span
                    >
                </p>
                <p class="text-2xl font-bold tabular-nums">
                    {{ formatXof(provisional.total) }}
                </p>
                <p v-if="provisional.change > 0" class="text-sm font-medium">
                    {{
                        t('pos.change', {
                            amount: formatXof(provisional.change),
                        })
                    }}
                </p>
                <p class="text-sm">{{ t('offline.provisional_hint') }}</p>
                <Button
                    variant="outline"
                    class="h-11"
                    @click="provisional = null"
                    >{{ t('pos.new_sale') }}</Button
                >
            </div>

            <ProductPicker
                input-id="pos-picker"
                :warehouse-id="warehouse?.id"
                @select="addProduct"
            />

            <!-- Articles fréquents : ajout au panier d'une seule touche -->
            <section
                v-if="frequent.length"
                class="space-y-2"
                aria-labelledby="frequent-title"
            >
                <h2
                    id="frequent-title"
                    class="text-sm font-medium text-muted-foreground"
                >
                    {{ t('pos.frequent') }}
                </h2>
                <div
                    class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4"
                >
                    <button
                        v-for="product in frequent"
                        :key="product.id"
                        type="button"
                        class="flex min-h-20 flex-col justify-between rounded-lg border bg-card p-3 text-left transition-colors hover:border-primary/60 hover:bg-accent active:scale-[0.98]"
                        @click="addProduct(product)"
                    >
                        <span class="line-clamp-2 text-sm font-medium">{{
                            product.name
                        }}</span>
                        <span
                            class="mt-1 flex items-baseline justify-between gap-2"
                        >
                            <span
                                class="font-semibold text-primary tabular-nums"
                                >{{ formatXof(product.sale_price ?? 0) }}</span
                            >
                            <span
                                v-if="
                                    product.available !== null &&
                                    product.available !== undefined
                                "
                                class="text-xs text-muted-foreground"
                                >{{
                                    t('pos.tile_stock', {
                                        quantity: product.available,
                                    })
                                }}</span
                            >
                        </span>
                    </button>
                </div>
            </section>

            <div>
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">
                        {{ t('pos.cart') }}
                        <span
                            v-if="itemCount"
                            class="font-normal text-muted-foreground"
                            >· {{ t('pos.items', { count: itemCount }) }}</span
                        >
                    </h2>
                    <Button
                        v-if="cart.length"
                        variant="ghost"
                        class="h-11"
                        @click="clearCart"
                        ><Trash2 class="size-4" />{{ t('pos.clear') }}</Button
                    >
                </div>
                <p
                    v-if="cart.length === 0"
                    class="rounded-lg border border-dashed p-8 text-center text-muted-foreground"
                >
                    {{ t('pos.cart_empty') }}
                </p>
                <ul v-else class="divide-y rounded-lg border">
                    <li
                        v-for="(line, index) in cart"
                        :key="line.product.id"
                        class="space-y-2 p-3"
                    >
                        <div class="flex items-center gap-2">
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium">
                                    {{ line.product.name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ formatXof(line.unitPrice) }} /
                                    {{ line.product.unit }}
                                    <template
                                        v-if="
                                            line.product.available !== null &&
                                            line.product.available !== undefined
                                        "
                                        >·
                                        {{
                                            t('pos.available', {
                                                quantity:
                                                    line.product.available,
                                            })
                                        }}</template
                                    >
                                </p>
                            </div>
                            <span class="font-semibold tabular-nums">{{
                                formatXof(lineTotal(line))
                            }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                class="size-11"
                                :aria-label="
                                    t('pos.decrease', {
                                        name: line.product.name,
                                    })
                                "
                                @click="step(line, -1)"
                                ><Minus class="size-4"
                            /></Button>
                            <Input
                                v-model="line.quantity"
                                :inputmode="
                                    line.product.decimals
                                        ? 'decimal'
                                        : 'numeric'
                                "
                                class="h-11 w-20 text-center tabular-nums"
                                :aria-label="t('sales.quantity')"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                class="size-11"
                                :aria-label="
                                    t('pos.increase', {
                                        name: line.product.name,
                                    })
                                "
                                @click="step(line, 1)"
                                ><Plus class="size-4"
                            /></Button>
                            <Button
                                v-if="can.price_override || can.discount"
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-11"
                                :aria-label="t('pos.line_options')"
                                :aria-expanded="line.options"
                                @click="line.options = !line.options"
                                ><SlidersHorizontal class="size-4"
                            /></Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="ml-auto size-11"
                                :aria-label="
                                    t('sales.remove_line', {
                                        name: line.product.name,
                                    })
                                "
                                @click="cart.splice(index, 1)"
                                ><X class="size-4"
                            /></Button>
                        </div>
                        <div v-if="line.options" class="grid grid-cols-2 gap-2">
                            <div v-if="can.price_override" class="grid gap-1">
                                <Label
                                    :for="`price-${index}`"
                                    class="text-xs"
                                    >{{ t('sales.unit_price') }}</Label
                                >
                                <Input
                                    :id="`price-${index}`"
                                    :model-value="String(line.unitPrice)"
                                    inputmode="numeric"
                                    class="h-11 tabular-nums"
                                    @update:model-value="
                                        line.unitPrice = parseAmount(
                                            String($event),
                                        )
                                    "
                                />
                            </div>
                            <div v-if="can.discount" class="grid gap-1">
                                <Label
                                    :for="`discount-${index}`"
                                    class="text-xs"
                                    >{{ t('sales.discount') }}</Label
                                >
                                <Input
                                    :id="`discount-${index}`"
                                    v-amount
                                    v-model="line.discount"
                                    inputmode="numeric"
                                    class="h-11 tabular-nums"
                                />
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </section>

        <!-- Client et paiement -->
        <aside class="space-y-4">
            <div class="grid gap-1">
                <Label for="pos-customer"
                    >{{ t('sales.customer') }}
                    <span class="font-normal text-muted-foreground"
                        >({{ t('common.optional') }})</span
                    ></Label
                >
                <CustomerPicker v-model="customer" input-id="pos-customer" />
            </div>

            <fieldset class="space-y-3">
                <legend class="mb-2 font-semibold">
                    {{ t('pos.payment') }}
                </legend>
                <div class="grid grid-cols-3 gap-2" role="radiogroup">
                    <Button
                        v-for="method in quickMethods"
                        :key="method"
                        type="button"
                        role="radio"
                        :aria-checked="
                            !onCredit && tenders[0].method === method
                        "
                        :variant="
                            !onCredit && tenders[0].method === method
                                ? 'default'
                                : 'outline'
                        "
                        class="h-11"
                        @click="selectMethod(method)"
                        >{{ t(`methods.${method}`) }}</Button
                    >
                    <Button
                        type="button"
                        role="radio"
                        :aria-checked="onCredit"
                        :variant="onCredit ? 'default' : 'outline'"
                        class="col-span-2 h-11"
                        @click="onCredit = true"
                        >{{ t('pos.credit') }}</Button
                    >
                </div>

                <template v-if="!onCredit">
                    <div
                        v-for="(tender, index) in tenders"
                        :key="index"
                        class="grid gap-2 rounded-lg border p-3"
                    >
                        <div class="flex items-end gap-2">
                            <div class="grid flex-1 gap-1">
                                <Label :for="`tender-${index}`" class="text-xs"
                                    >{{
                                        tender.method === 'cash'
                                            ? t('pos.received')
                                            : t('pos.amount')
                                    }}
                                    · {{ t(`methods.${tender.method}`) }}</Label
                                >
                                <Input
                                    :id="`tender-${index}`"
                                    v-amount
                                    v-model="tender.amount"
                                    inputmode="numeric"
                                    :placeholder="
                                        tenders.length === 1
                                            ? String(total)
                                            : ''
                                    "
                                    class="h-11 tabular-nums"
                                />
                            </div>
                            <select
                                v-if="index > 0"
                                v-model="tender.method"
                                class="h-11 rounded-md border border-input bg-transparent px-2 text-sm"
                                :aria-label="t('pos.payment')"
                            >
                                <option
                                    v-for="m in methods"
                                    :key="m"
                                    :value="m"
                                >
                                    {{ t(`methods.${m}`) }}
                                </option>
                            </select>
                            <Button
                                v-if="index > 0"
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-11"
                                :aria-label="t('pos.remove_method')"
                                @click="tenders.splice(index, 1)"
                                ><X class="size-4"
                            /></Button>
                        </div>
                        <div
                            v-if="index === 0 && showCashSuggestions"
                            class="flex flex-wrap gap-2"
                            :aria-label="t('pos.quick_cash')"
                        >
                            <Button
                                v-for="(amount, i) in cashSuggestions"
                                :key="amount"
                                type="button"
                                variant="secondary"
                                class="h-11 flex-1 tabular-nums"
                                @click="receive(amount)"
                                >{{
                                    i === 0
                                        ? t('pos.exact_amount')
                                        : formatXof(amount)
                                }}</Button
                            >
                        </div>
                        <Input
                            v-if="tender.method !== 'cash'"
                            v-model="tender.reference"
                            maxlength="100"
                            class="h-11"
                            :placeholder="t('sales.reference')"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        class="h-11 w-full"
                        @click="
                            tenders.push({
                                method: 'wave',
                                amount: '',
                                reference: '',
                            })
                        "
                        ><Plus class="size-4" />{{
                            t('pos.add_method')
                        }}</Button
                    >
                </template>
            </fieldset>

            <div v-if="needsNegativeReason" class="grid gap-1">
                <Label for="negative">{{ t('sales.negative_reason') }}</Label>
                <Input
                    id="negative"
                    v-model="negativeReason"
                    maxlength="500"
                    class="h-11"
                />
                <p class="text-xs text-muted-foreground">
                    {{ t('sales.negative_hint') }}
                </p>
            </div>

            <InputError
                :message="
                    errors.business ??
                    errors.cash_session_id ??
                    errors.lines ??
                    errors.tenders
                "
            />

            <!-- Barre d'encaissement : toujours visible sur smartphone -->
            <div
                class="fixed inset-x-0 bottom-0 z-30 space-y-2 border-t bg-background p-4 shadow-lg lg:static lg:border-0 lg:p-0 lg:shadow-none"
            >
                <div class="flex items-baseline justify-between">
                    <span class="text-muted-foreground">{{
                        t('pos.total')
                    }}</span>
                    <span class="text-3xl font-bold tabular-nums lg:text-4xl">{{
                        formatXof(total)
                    }}</span>
                </div>
                <p
                    v-if="plan.change > 0"
                    class="text-right text-lg font-semibold text-success"
                >
                    {{ t('pos.change', { amount: formatXof(plan.change) }) }}
                </p>
                <p
                    v-if="plan.remaining > 0 && cart.length"
                    class="text-right text-sm"
                    :class="customer ? '' : 'text-destructive'"
                >
                    {{
                        customer
                            ? t('pos.on_credit', {
                                  amount: formatXof(plan.remaining),
                              })
                            : t('pos.credit_requires_customer')
                    }}
                </p>
                <Button
                    class="h-12 w-full text-base"
                    :disabled="blocked"
                    @click="checkout"
                >
                    <Spinner v-if="processing" />
                    {{
                        !syncState.online
                            ? t('offline.offline_sale', {
                                  amount: formatXof(total),
                              })
                            : plan.remaining > 0
                              ? t('pos.checkout_credit', {
                                    amount: formatXof(total),
                                })
                              : t('pos.checkout', { amount: formatXof(total) })
                    }}
                </Button>
            </div>
        </aside>
    </div>
</template>
