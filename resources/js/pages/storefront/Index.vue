<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import {
    index as storefrontIndex,
    products as storefrontProducts,
    orders as storefrontOrders,
    update,
} from '@/routes/storefront';

type Storefront = {
    id: string;
    slug: string;
    enabled: boolean;
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
    publicUrl: string;
    productsCount: number;
    ordersCount: number;
    canManage: boolean;
}>();
const booleans = (data: Record<string, unknown>) => ({
    ...data,
    enabled: data.enabled ? 1 : 0,
    accepts_orders: data.accepts_orders ? 1 : 0,
    delivery_enabled: data.delivery_enabled ? 1 : 0,
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('storefront.title'), href: storefrontIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="t('storefront.title')" />
    <div class="space-y-6 p-4 md:p-6">
        <Heading
            :title="t('storefront.title')"
            :description="t('storefront.description')"
        />
        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-lg border p-4">
                <p class="text-sm text-muted-foreground">
                    {{
                        t('storefront.products_count', { count: productsCount })
                    }}
                </p>
                <Link
                    :href="storefrontProducts()"
                    class="mt-3 inline-flex min-h-11 items-center underline"
                    >{{ t('storefront.manage_products') }}</Link
                >
            </div>
            <div class="rounded-lg border p-4">
                <p class="text-sm text-muted-foreground">
                    {{ t('storefront.orders_count', { count: ordersCount }) }}
                </p>
                <Link
                    :href="storefrontOrders()"
                    class="mt-3 inline-flex min-h-11 items-center underline"
                    >{{ t('storefront.manage_orders') }}</Link
                >
            </div>
        </div>
        <Form
            v-if="canManage"
            v-bind="update.form()"
            :transform="booleans"
            v-slot="{ errors, processing }"
            class="grid gap-5 rounded-lg border p-5 md:grid-cols-2"
        >
            <div class="md:col-span-2">
                <h2 class="text-lg font-semibold">
                    {{ t('storefront.settings') }}
                </h2>
            </div>
            <div class="grid gap-2">
                <Label for="slug">{{ t('storefront.slug') }}</Label
                ><Input
                    id="slug"
                    name="slug"
                    :default-value="storefront.slug"
                    required
                /><InputError :message="errors.slug" />
            </div>
            <div class="grid gap-2">
                <Label for="tagline">{{ t('storefront.tagline') }}</Label
                ><Input
                    id="tagline"
                    name="tagline"
                    :default-value="storefront.tagline ?? ''"
                /><InputError :message="errors.tagline" />
            </div>
            <div class="grid gap-2 md:col-span-2">
                <Label for="description">{{
                    t('storefront.description_label')
                }}</Label
                ><textarea
                    id="description"
                    name="description"
                    :value="storefront.description ?? ''"
                    class="min-h-28 rounded-md border bg-transparent p-3 text-sm"
                /><InputError :message="errors.description" />
            </div>
            <div class="grid gap-2">
                <Label for="phone">{{ t('storefront.phone') }}</Label
                ><Input
                    id="phone"
                    name="phone"
                    :default-value="storefront.phone ?? ''"
                />
            </div>
            <div class="grid gap-2">
                <Label for="email">{{ t('storefront.email') }}</Label
                ><Input
                    id="email"
                    name="email"
                    type="email"
                    :default-value="storefront.email ?? ''"
                />
            </div>
            <div class="grid gap-2">
                <Label for="whatsapp">{{ t('storefront.whatsapp') }}</Label
                ><Input
                    id="whatsapp"
                    name="whatsapp"
                    :default-value="storefront.whatsapp ?? ''"
                />
            </div>
            <div class="grid gap-2">
                <Label for="address">{{ t('storefront.address') }}</Label
                ><Input
                    id="address"
                    name="address"
                    :default-value="storefront.address ?? ''"
                />
            </div>
            <div class="grid gap-2 md:col-span-2">
                <Label for="opening_hours">{{
                    t('storefront.opening_hours')
                }}</Label
                ><Input
                    id="opening_hours"
                    name="opening_hours"
                    :default-value="storefront.opening_hours ?? ''"
                />
            </div>
            <div class="flex flex-col gap-3 md:col-span-2">
                <label class="flex min-h-11 items-center gap-3 text-sm"
                    ><input
                        type="checkbox"
                        name="enabled"
                        value="1"
                        :checked="storefront.enabled"
                        class="size-5"
                    />{{ t('storefront.enabled') }}</label
                >
                <label class="flex min-h-11 items-center gap-3 text-sm"
                    ><input
                        type="checkbox"
                        name="accepts_orders"
                        value="1"
                        :checked="storefront.accepts_orders"
                        class="size-5"
                    />{{ t('storefront.accepts_orders') }}</label
                >
                <label class="flex min-h-11 items-center gap-3 text-sm"
                    ><input
                        type="checkbox"
                        name="delivery_enabled"
                        value="1"
                        :checked="storefront.delivery_enabled"
                        class="size-5"
                    />{{ t('storefront.delivery_enabled') }}</label
                >
            </div>
            <div class="flex flex-wrap gap-3 md:col-span-2">
                <Button type="submit" class="h-11" :disabled="processing">{{
                    t('storefront.save')
                }}</Button
                ><Button
                    v-if="storefront.enabled"
                    as-child
                    type="button"
                    variant="outline"
                    class="h-11"
                    ><a :href="publicUrl" target="_blank" rel="noreferrer">{{
                        t('storefront.public_link')
                    }}</a></Button
                >
            </div>
        </Form>
    </div>
</template>
