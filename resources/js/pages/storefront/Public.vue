<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
} from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatXof } from '@/lib/money';
import { store as order } from '@/routes/storefront/orders';

type Product = {
    id: string;
    name: string;
    description: string | null;
    category: string | null;
    price: number;
    unit: string;
    image_url: string | null;
    max_quantity: number | null;
};
type Storefront = {
    name: string;
    slug: string;
    tagline: string | null;
    description: string | null;
    phone: string | null;
    email: string | null;
    whatsapp: string | null;
    address: string | null;
    opening_hours: string | null;
    logo_url: string | null;
    cover_url: string | null;
    maps_url: string | null;
    accepts_orders: boolean;
    delivery_enabled: boolean;
};
type OrderPayload = {
    idempotency_key: string;
    website: string;
    customer_name: string;
    customer_phone: string;
    customer_email: string;
    delivery_address: string;
    fulfillment: string;
    notes: string;
    items: { product_id: string; quantity: number }[];
};

const props = defineProps<{
    storefront: Storefront;
    products: { data: Product[] };
    categories: { id: string; name: string }[];
    filters: { q: string; category: string };
}>();
const search = ref(props.filters.q);

// La vitrine est toujours claire, même si l'interface de l'entreprise est en thème sombre.
let wasDark = false;

// --- Fiche article et commande -------------------------------------------
const dialog = ref<HTMLDialogElement | null>(null);
const selected = ref<Product | null>(null);
const quantity = ref(1);
const submitting = ref(false);
const errors = ref<Record<string, string>>({});
// Piège à robots : champ caché, toujours vide pour une vraie personne.
const website = ref('');
// Clé unique par commande : un double clic ou un renvoi ne crée qu'une commande.
const idempotencyKey = ref(crypto.randomUUID());
const customer = reactive({
    customer_name: '',
    customer_phone: '',
    customer_email: '',
    delivery_address: '',
    fulfillment: 'pickup',
    notes: '',
});
const maxQuantity = computed(() => selected.value?.max_quantity ?? 999999);
const total = computed(() =>
    selected.value ? selected.value.price * quantity.value : 0,
);
const clampQuantity = () => {
    const value = Number(quantity.value);
    quantity.value = Number.isFinite(value)
        ? Math.min(Math.max(value, 1), maxQuantity.value)
        : 1;
};
const openProduct = async (product: Product) => {
    selected.value = product;
    quantity.value = 1;
    errors.value = {};
    idempotencyKey.value = crypto.randomUUID();
    await nextTick();
    dialog.value?.showModal();
};
const closeProduct = () => {
    dialog.value?.close();
    selected.value = null;
};

// --- Hors connexion ----------------------------------------------------------
const STORAGE_KEY = 'diaba.storefront.pending';
const online = ref(true);
const pending = ref<{ slug: string; payload: OrderPayload }[]>([]);
const notice = ref('');
const readQueue = (): { slug: string; payload: OrderPayload }[] => {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');
    } catch {
        return [];
    }
};
const writeQueue = (queue: { slug: string; payload: OrderPayload }[]) => {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(queue));
    } catch {
        // Stockage indisponible : la commande ne peut pas être gardée hors ligne.
    }
    pending.value = queue.filter((e) => e.slug === props.storefront.slug);
};
const flushQueue = () => {
    const next = readQueue().find((e) => e.slug === props.storefront.slug);

    if (!next || !navigator.onLine || submitting.value) {
        return;
    }

    submitting.value = true;
    router.post(order(next.slug), next.payload, {
        onSuccess: () => {
            writeQueue(
                readQueue().filter(
                    (e) =>
                        e.payload.idempotency_key !==
                        next.payload.idempotency_key,
                ),
            );
        },
        onError: (bag) => {
            // Refusée par le serveur (stock, article retiré…) : inutile de réessayer.
            writeQueue(
                readQueue().filter(
                    (e) =>
                        e.payload.idempotency_key !==
                        next.payload.idempotency_key,
                ),
            );
            notice.value = Object.values(bag).join(' ');
        },
        onFinish: () => {
            submitting.value = false;
        },
    });
};
const setOnline = () => {
    online.value = navigator.onLine;

    if (online.value) {
        flushQueue();
    }
};

onMounted(() => {
    wasDark = document.documentElement.classList.contains('dark');
    document.documentElement.classList.remove('dark');
    online.value = navigator.onLine;
    pending.value = readQueue().filter((e) => e.slug === props.storefront.slug);
    window.addEventListener('online', setOnline);
    window.addEventListener('offline', setOnline);
    flushQueue();
});
onBeforeUnmount(() => {
    if (wasDark) {
        document.documentElement.classList.add('dark');
    }

    window.removeEventListener('online', setOnline);
    window.removeEventListener('offline', setOnline);
});

const submitOrder = () => {
    if (!selected.value || submitting.value) {
        return;
    }

    clampQuantity();
    const payload: OrderPayload = {
        ...customer,
        idempotency_key: idempotencyKey.value,
        website: website.value,
        items: [{ product_id: selected.value.id, quantity: quantity.value }],
    };

    if (!navigator.onLine) {
        writeQueue([...readQueue(), { slug: props.storefront.slug, payload }]);
        notice.value = t('storefront.public.saved_offline');
        closeProduct();

        return;
    }

    submitting.value = true;
    errors.value = {};
    router.post(order(props.storefront.slug), payload, {
        onError: (bag) => (errors.value = bag),
        onFinish: () => (submitting.value = false),
    });
};

// --- Recherche et catégories ---------------------------------------------
const menu = computed(() => [
    { id: '', name: t('storefront.public.all_categories') },
    ...props.categories,
]);
const browse = (category: string) =>
    router.get(
        window.location.pathname,
        {
            ...(search.value ? { q: search.value } : {}),
            ...(category ? { category } : {}),
        },
        { preserveState: true },
    );
const submitSearch = () => browse(props.filters.category);
const whatsappUrl = computed(() =>
    props.storefront.whatsapp
        ? `https://wa.me/${props.storefront.whatsapp.replace(/\D/g, '')}`
        : null,
);
const format = (value: number) => new Intl.NumberFormat('fr-FR').format(value);
</script>

<template>
    <Head :title="props.storefront.tagline || t('storefront.title')" />
    <main class="min-h-screen bg-slate-50 text-slate-900">
        <div
            v-if="!online"
            class="bg-amber-100 px-4 py-2 text-center text-sm text-amber-900"
            role="status"
        >
            {{ t('storefront.public.offline') }}
        </div>
        <div
            v-if="pending.length"
            class="bg-sky-100 px-4 py-2 text-center text-sm text-sky-900"
            role="status"
        >
            {{ t('storefront.public.pending', { count: pending.length }) }}
        </div>
        <p
            v-if="notice"
            class="bg-emerald-100 px-4 py-2 text-center text-sm text-emerald-900"
            role="status"
        >
            {{ notice }}
        </p>
        <img
            v-if="storefront.cover_url"
            :src="storefront.cover_url"
            :alt="t('storefront.public.cover_alt', { name: storefront.name })"
            class="h-40 w-full object-cover sm:h-56"
        />
        <header class="border-b bg-white">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-6"
            >
                <div class="flex items-center gap-4">
                    <img
                        v-if="storefront.logo_url"
                        :src="storefront.logo_url"
                        :alt="
                            t('storefront.public.logo_alt', {
                                name: storefront.name,
                            })
                        "
                        class="size-16 rounded-lg border bg-white object-contain p-1"
                    />
                    <div>
                        <p class="text-2xl font-bold">{{ storefront.name }}</p>
                        <p
                            v-if="storefront.tagline"
                            class="text-muted-foreground"
                        >
                            {{ storefront.tagline }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 text-sm">
                    <a
                        v-if="storefront.phone"
                        :href="`tel:${storefront.phone}`"
                        class="rounded-md border px-3 py-2"
                        >{{ t('storefront.public.call') }}</a
                    ><a
                        v-if="storefront.email"
                        :href="`mailto:${storefront.email}`"
                        class="rounded-md border px-3 py-2"
                        >{{ t('storefront.public.email') }}</a
                    ><a
                        v-if="storefront.maps_url"
                        :href="storefront.maps_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="rounded-md border px-3 py-2"
                        >{{ t('storefront.public.map') }}</a
                    ><a
                        v-if="whatsappUrl"
                        :href="whatsappUrl"
                        target="_blank"
                        rel="noreferrer"
                        class="rounded-md border px-3 py-2"
                        >{{ t('storefront.public.whatsapp') }}</a
                    >
                </div>
            </div>
        </header>
        <nav
            v-if="categories.length"
            :aria-label="t('storefront.public.categories')"
            class="border-b bg-white"
        >
            <ul
                class="mx-auto flex max-w-6xl gap-2 overflow-x-auto px-4 py-3 text-sm"
            >
                <li v-for="item in menu" :key="item.id">
                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center rounded-full border px-4 whitespace-nowrap"
                        :class="
                            filters.category === item.id
                                ? 'border-slate-900 bg-slate-900 text-white'
                                : 'bg-white text-slate-900 hover:bg-slate-100'
                        "
                        @click="browse(item.id)"
                    >
                        {{ item.name }}
                    </button>
                </li>
            </ul>
        </nav>
        <section class="mx-auto max-w-6xl px-4 py-8">
            <p
                v-if="storefront.description"
                class="mb-6 whitespace-pre-line text-muted-foreground"
            >
                {{ storefront.description }}
            </p>
            <form class="mb-6 flex gap-2" @submit.prevent="submitSearch">
                <Input
                    v-model="search"
                    :placeholder="t('storefront.public.search')"
                    class="h-11 border-slate-300 bg-white text-slate-900"
                /><Button type="submit" class="h-11">{{
                    t('common.continue')
                }}</Button>
            </form>
            <div
                v-if="products.data.length"
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <article
                    v-for="product in products.data"
                    :key="product.id"
                    class="flex flex-col overflow-hidden rounded-lg border bg-white"
                >
                    <button
                        type="button"
                        class="block text-left"
                        :aria-label="product.name"
                        @click="openProduct(product)"
                    >
                        <img
                            v-if="product.image_url"
                            :src="product.image_url"
                            :alt="product.name"
                            loading="lazy"
                            class="h-40 w-full object-cover"
                        />
                    </button>
                    <div class="flex flex-1 flex-col gap-3 p-4">
                        <p class="font-semibold">{{ product.name }}</p>
                        <p
                            v-if="product.description"
                            class="line-clamp-2 text-sm text-muted-foreground"
                        >
                            {{ product.description }}
                        </p>
                        <p class="font-medium">
                            {{ formatXof(product.price) }} / {{ product.unit }}
                        </p>
                        <p
                            v-if="product.max_quantity === 0"
                            class="text-sm font-medium text-red-700"
                        >
                            {{ t('storefront.public.out_of_stock') }}
                        </p>
                        <Button
                            v-if="storefront.accepts_orders"
                            class="mt-auto h-10 w-full"
                            :disabled="product.max_quantity === 0"
                            @click="openProduct(product)"
                            >{{ t('storefront.public.order') }}</Button
                        >
                    </div>
                </article>
            </div>
            <p
                v-else
                class="rounded-lg border bg-white p-6 text-muted-foreground"
            >
                {{ t('storefront.public.empty') }}
            </p>
        </section>

        <dialog
            ref="dialog"
            class="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-xl border bg-white p-0 text-slate-900 shadow-xl backdrop:bg-black/50"
            @close="selected = null"
        >
            <form
                v-if="selected"
                class="max-h-[90vh] space-y-4 overflow-y-auto p-5"
                @submit.prevent="submitOrder"
            >
                <div class="flex items-start justify-between gap-3">
                    <h2 class="text-xl font-semibold">{{ selected.name }}</h2>
                    <button
                        type="button"
                        class="inline-flex size-11 items-center justify-center rounded-md border"
                        :aria-label="t('common.cancel')"
                        @click="closeProduct"
                    >
                        ✕
                    </button>
                </div>
                <img
                    v-if="selected.image_url"
                    :src="selected.image_url"
                    :alt="selected.name"
                    class="h-52 w-full rounded-lg object-cover"
                />
                <p
                    v-if="selected.description"
                    class="text-sm whitespace-pre-line text-muted-foreground"
                >
                    {{ selected.description }}
                </p>
                <p class="text-lg font-semibold">
                    {{ formatXof(selected.price) }} / {{ selected.unit }}
                </p>

                <div class="grid gap-2">
                    <Label for="quantity">{{
                        t('storefront.public.quantity')
                    }}</Label>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex size-11 items-center justify-center rounded-md border text-xl"
                            :aria-label="t('storefront.public.less')"
                            @click="((quantity -= 1), clampQuantity())"
                        >
                            −
                        </button>
                        <Input
                            id="quantity"
                            v-model.number="quantity"
                            type="number"
                            min="1"
                            :max="selected.max_quantity ?? undefined"
                            inputmode="numeric"
                            class="h-11 w-24 border-slate-300 bg-white text-center text-slate-900"
                            @change="clampQuantity"
                        />
                        <button
                            type="button"
                            class="inline-flex size-11 items-center justify-center rounded-md border text-xl"
                            :aria-label="t('storefront.public.more')"
                            @click="((quantity += 1), clampQuantity())"
                        >
                            +
                        </button>
                        <span
                            v-if="selected.max_quantity !== null"
                            class="text-sm text-muted-foreground"
                            >{{
                                t('storefront.public.available', {
                                    count: selected.max_quantity,
                                })
                            }}</span
                        >
                    </div>
                </div>

                <input
                    v-model="website"
                    type="text"
                    name="website"
                    tabindex="-1"
                    autocomplete="off"
                    aria-hidden="true"
                    class="absolute -left-[9999px] h-0 w-0 opacity-0"
                />
                <div class="grid gap-2">
                    <Label for="customer_name">{{
                        t('storefront.public.customer_name')
                    }}</Label
                    ><Input
                        id="customer_name"
                        v-model="customer.customer_name"
                        autocomplete="name"
                        maxlength="160"
                        required
                        class="h-11 border-slate-300 bg-white text-slate-900"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="customer_phone">{{
                        t('storefront.public.customer_phone')
                    }}</Label
                    ><Input
                        id="customer_phone"
                        v-model="customer.customer_phone"
                        type="tel"
                        autocomplete="tel"
                        maxlength="32"
                        required
                        class="h-11 border-slate-300 bg-white text-slate-900"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="customer_email">{{
                        t('storefront.public.customer_email')
                    }}</Label
                    ><Input
                        id="customer_email"
                        v-model="customer.customer_email"
                        type="email"
                        autocomplete="email"
                        class="h-11 border-slate-300 bg-white text-slate-900"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="fulfillment">{{
                        t('storefront.public.fulfillment')
                    }}</Label>
                    <select
                        id="fulfillment"
                        v-model="customer.fulfillment"
                        class="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                    >
                        <option value="pickup">
                            {{ t('storefront.public.pickup') }}
                        </option>
                        <option
                            v-if="storefront.delivery_enabled"
                            value="delivery"
                        >
                            {{ t('storefront.public.delivery') }}
                        </option>
                    </select>
                </div>
                <div
                    v-if="customer.fulfillment === 'delivery'"
                    class="grid gap-2"
                >
                    <Label for="delivery_address">{{
                        t('storefront.public.delivery_address')
                    }}</Label
                    ><Input
                        id="delivery_address"
                        v-model="customer.delivery_address"
                        maxlength="300"
                        required
                        class="h-11 border-slate-300 bg-white text-slate-900"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="notes">{{ t('storefront.public.notes') }}</Label
                    ><textarea
                        id="notes"
                        v-model="customer.notes"
                        maxlength="2000"
                        class="min-h-20 w-full rounded-md border border-slate-300 bg-white p-3 text-sm"
                    />
                </div>

                <p
                    v-for="(message, field) in errors"
                    :key="field"
                    class="text-sm text-red-700"
                    role="alert"
                >
                    {{ message }}
                </p>
                <p class="border-t pt-3 text-lg font-semibold">
                    {{ t('storefront.total') }} : {{ formatXof(total) }}
                </p>
                <Button
                    type="submit"
                    class="h-12 w-full"
                    :disabled="submitting"
                    >{{ t('storefront.public.submit') }}</Button
                >
            </form>
        </dialog>
    </main>
</template>
