<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { store as order } from '@/routes/storefront/orders';

type Product = {
    id: string;
    name: string;
    description: string | null;
    category: string | null;
    price: number;
    unit: string;
    image_url: string | null;
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
    accepts_orders: boolean;
    delivery_enabled: boolean;
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
onMounted(() => {
    wasDark = document.documentElement.classList.contains('dark');
    document.documentElement.classList.remove('dark');
});
onBeforeUnmount(() => {
    if (wasDark) {
        document.documentElement.classList.add('dark');
    }
});
const cart = reactive<Record<string, number>>({});
const customer = reactive({
    customer_name: '',
    customer_phone: '',
    customer_email: '',
    delivery_address: '',
    fulfillment: 'pickup',
    notes: '',
});
const cartLines = computed(() =>
    props.products.data
        .filter((product) => (cart[product.id] ?? 0) > 0)
        .map((product) => ({ product, quantity: cart[product.id] })),
);
const total = computed(() =>
    cartLines.value.reduce(
        (sum, line) => sum + line.product.price * line.quantity,
        0,
    ),
);
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
const submitOrder = () =>
    router.post(order(props.storefront.slug), {
        ...customer,
        items: cartLines.value.map((line) => ({
            product_id: line.product.id,
            quantity: line.quantity,
        })),
    });
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
        <header class="border-b bg-white">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-6"
            >
                <div>
                    <p class="text-2xl font-bold">{{ storefront.name }}</p>
                    <p v-if="storefront.tagline" class="text-muted-foreground">
                        {{ storefront.tagline }}
                    </p>
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
        <div
            class="mx-auto grid max-w-6xl gap-8 px-4 py-8 lg:grid-cols-[1fr_360px]"
        >
            <section>
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
                    class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
                >
                    <article
                        v-for="product in products.data"
                        :key="product.id"
                        class="overflow-hidden rounded-lg border bg-white"
                    >
                        <img
                            v-if="product.image_url"
                            :src="product.image_url"
                            :alt="product.name"
                            class="h-40 w-full object-cover"
                        />
                        <div class="space-y-3 p-4">
                            <p class="font-semibold">{{ product.name }}</p>
                            <p
                                v-if="product.description"
                                class="text-sm text-muted-foreground"
                            >
                                {{ product.description }}
                            </p>
                            <p class="font-medium">
                                {{ format(product.price) }} XOF /
                                {{ product.unit }}
                            </p>
                            <Button
                                class="h-10 w-full"
                                @click="
                                    cart[product.id] =
                                        (cart[product.id] ?? 0) + 1
                                "
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
            <aside
                v-if="storefront.accepts_orders"
                class="h-fit rounded-lg border bg-white p-5"
            >
                <h2 class="text-lg font-semibold">
                    {{ t('storefront.public.cart') }}
                </h2>
                <div v-if="cartLines.length" class="mt-4 space-y-4">
                    <div
                        v-for="line in cartLines"
                        :key="line.product.id"
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span
                            >{{ line.product.name }} × {{ line.quantity }}</span
                        ><button
                            type="button"
                            class="underline"
                            @click="
                                cart[line.product.id] = Math.max(
                                    0,
                                    line.quantity - 1,
                                )
                            "
                        >
                            {{ t('common.cancel') }}
                        </button>
                    </div>
                    <p class="border-t pt-3 font-semibold">
                        {{ t('storefront.total') }} : {{ format(total) }} XOF
                    </p>
                    <form class="mt-4 space-y-3" @submit.prevent="submitOrder">
                        <div>
                            <Label for="customer_name">{{
                                t('storefront.public.customer_name')
                            }}</Label
                            ><Input
                                id="customer_name"
                                v-model="customer.customer_name"
                                required
                            />
                        </div>
                        <div>
                            <Label for="customer_phone">{{
                                t('storefront.public.customer_phone')
                            }}</Label
                            ><Input
                                id="customer_phone"
                                v-model="customer.customer_phone"
                                required
                            />
                        </div>
                        <div>
                            <Label for="customer_email">{{
                                t('storefront.public.customer_email')
                            }}</Label
                            ><Input
                                id="customer_email"
                                v-model="customer.customer_email"
                                type="email"
                            />
                        </div>
                        <select
                            v-model="customer.fulfillment"
                            class="h-10 w-full rounded-md border bg-transparent px-3 text-sm"
                        >
                            <option value="pickup">
                                {{ t('storefront.public.pickup') }}
                            </option>
                            <option
                                v-if="storefront.delivery_enabled"
                                value="delivery"
                            >
                                {{ t('storefront.public.delivery') }}
                            </option></select
                        ><Input
                            v-if="customer.fulfillment === 'delivery'"
                            v-model="customer.delivery_address"
                            :placeholder="
                                t('storefront.public.delivery_address')
                            "
                            required
                        /><textarea
                            v-model="customer.notes"
                            :placeholder="t('storefront.public.notes')"
                            class="min-h-20 w-full rounded-md border bg-transparent p-3 text-sm"
                        /><Button type="submit" class="h-11 w-full">{{
                            t('storefront.public.submit')
                        }}</Button>
                    </form>
                </div>
                <p v-else class="mt-4 text-sm text-muted-foreground">
                    {{ t('storefront.public.cart_empty') }}
                </p>
            </aside>
        </div>
    </main>
</template>
