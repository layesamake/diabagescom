<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import {
    index as storefrontIndex,
    products as storefrontProducts,
} from '@/routes/storefront';
import { update as updateProduct } from '@/routes/storefront/products';

type Product = {
    id: string;
    sku: string;
    name: string;
    category: string | null;
    sale_price: number;
    unit: string;
    visible: boolean;
    public_name: string | null;
    public_description: string | null;
    public_price: number | null;
};
const props = defineProps<{
    storefront: { slug: string };
    products: Product[];
}>();
const transform = (
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> => ({
    ...data,
    visible: data.visible ? 1 : 0,
    public_price: data.public_price === '' ? null : data.public_price,
});
defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('storefront.title'), href: storefrontIndex() },
            { title: t('storefront.products'), href: storefrontProducts() },
        ],
    },
});
</script>

<template>
    <Head :title="t('storefront.products')" />
    <div class="space-y-6 p-4 md:p-6">
        <Heading
            :title="t('storefront.products')"
            :description="t('storefront.description')"
        />
        <div
            v-if="products.length === 0"
            class="rounded-lg border p-6 text-muted-foreground"
        >
            {{ t('storefront.no_products') }}
        </div>
        <Form
            v-for="product in products"
            :key="product.id"
            v-bind="updateProduct.form(product.id)"
            :transform="transform"
            v-slot="{ processing }"
            class="grid gap-4 rounded-lg border p-4 md:grid-cols-2"
        >
            <div class="md:col-span-2">
                <p class="font-semibold">{{ product.name }}</p>
                <p class="text-sm text-muted-foreground">
                    {{ product.sku }} · {{ product.category ?? '—' }} ·
                    {{ product.sale_price }} XOF / {{ product.unit }}
                </p>
            </div>
            <div class="grid gap-2">
                <Label :for="`name-${product.id}`">{{
                    t('storefront.public_name')
                }}</Label
                ><Input
                    :id="`name-${product.id}`"
                    name="public_name"
                    :default-value="product.public_name ?? product.name"
                />
            </div>
            <div class="grid gap-2">
                <Label :for="`price-${product.id}`">{{
                    t('storefront.public_price')
                }}</Label
                ><Input
                    :id="`price-${product.id}`"
                    name="public_price"
                    type="number"
                    min="0"
                    :default-value="product.public_price ?? product.sale_price"
                />
            </div>
            <div class="grid gap-2 md:col-span-2">
                <Label :for="`desc-${product.id}`">{{
                    t('storefront.public_description')
                }}</Label
                ><textarea
                    :id="`desc-${product.id}`"
                    name="public_description"
                    :value="product.public_description ?? ''"
                    class="min-h-20 rounded-md border bg-transparent p-3 text-sm"
                />
            </div>
            <label class="flex min-h-11 items-center gap-3 text-sm"
                ><input
                    type="checkbox"
                    name="visible"
                    value="1"
                    :checked="product.visible"
                    class="size-5"
                />{{ t('storefront.visible') }}</label
            >
            <div class="flex justify-end">
                <Button type="submit" class="h-11" :disabled="processing">{{
                    t('storefront.save_product')
                }}</Button>
            </div>
        </Form>
    </div>
</template>
