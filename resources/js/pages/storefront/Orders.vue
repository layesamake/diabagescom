<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import {
    index as storefrontIndex,
    orders as storefrontOrders,
} from '@/routes/storefront';
import { update as updateOrder } from '@/routes/storefront/orders';

type Order = {
    id: string;
    number: string;
    status: string;
    customer_name: string;
    customer_phone: string;
    total: number;
    items_count: number;
    created_at: string;
};
const props = defineProps<{
    orders: {
        data: Order[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    statuses: string[];
    filters: { status: string };
}>();
const statusLabel = (status: string) =>
    t(`storefront.order_${status}` as never);
defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('storefront.title'), href: storefrontIndex() },
            { title: t('storefront.orders'), href: storefrontOrders() },
        ],
    },
});
</script>

<template>
    <Head :title="t('storefront.orders')" />
    <div class="space-y-6 p-4 md:p-6">
        <Heading
            :title="t('storefront.orders')"
            :description="t('storefront.description')"
        />
        <div
            v-if="orders.data.length === 0"
            class="rounded-lg border p-6 text-muted-foreground"
        >
            {{ t('storefront.empty_orders') }}
        </div>
        <div v-else class="divide-y rounded-lg border">
            <div
                v-for="order in orders.data"
                :key="order.id"
                class="grid gap-3 p-4 md:grid-cols-[1fr_auto_auto] md:items-center"
            >
                <div>
                    <p class="font-semibold">
                        {{ order.number }} · {{ order.customer_name }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ order.customer_phone }} ·
                        {{ order.items_count }} article(s) ·
                        {{ order.total }} XOF
                    </p>
                </div>
                <span class="text-sm font-medium">{{
                    statusLabel(order.status)
                }}</span>
                <Form
                    v-bind="updateOrder.form(order.id)"
                    v-slot="{ processing }"
                    class="flex items-center gap-2"
                    ><select
                        name="status"
                        :value="order.status"
                        class="h-10 rounded-md border bg-transparent px-3 text-sm"
                    >
                        <option
                            v-for="status in statuses"
                            :key="status"
                            :value="status"
                        >
                            {{ statusLabel(status) }}
                        </option></select
                    ><Button
                        type="submit"
                        variant="outline"
                        class="h-10"
                        :disabled="processing"
                        >{{ t('storefront.update_status') }}</Button
                    ></Form
                >
            </div>
        </div>
    </div>
</template>
