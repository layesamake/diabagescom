<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, FileText, Undo2 } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof } from '@/lib/money';
import {
    create as invoiceCreate,
    show as invoiceShow,
} from '@/routes/purchases/invoices';
import {
    index as ordersIndex,
    show as orderShow,
} from '@/routes/purchases/orders';
import { store as returnStore } from '@/routes/purchases/receipts/returns';

const props = defineProps<{
    receipt: {
        id: string;
        number: string;
        supplier: { name: string };
        warehouse: { name: string };
        order: { id: string; number: string | null } | null;
        supplier_reference: string | null;
        note: string | null;
        total_excl_tax: number;
        received_at: string;
        author: string;
        invoice: { id: string; number: string | null; status: string } | null;
        lines: {
            id: string;
            sku: string;
            label: string;
            unit: string;
            quantity: string;
            returned: string;
            returnable: string;
            returnable_raw: string;
            unit_cost: number;
            total: number;
        }[];
        returns: {
            id: string;
            number: string;
            reason: string;
            total: number;
            created_at: string;
            author: string;
        }[];
    };
    can: { return: boolean; invoice: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('purchases.orders'), href: ordersIndex() }],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const r = computed(() => props.receipt);
const processing = ref(false);
const showReturn = ref(false);
const returnKey = crypto.randomUUID();
const quantities = reactive<Record<string, string>>({});
const reason = ref('');

function submitReturn(): void {
    router.post(
        returnStore(r.value.id).url,
        { idempotency_key: returnKey, reason: reason.value, quantities },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <Head :title="r.number" />

    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
        <Link
            :href="r.order ? orderShow(r.order.id) : ordersIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('purchases.back_orders')
            }}
        </Link>

        <Heading
            :title="t('purchases.receipt_title', { number: r.number })"
            :description="`${r.supplier.name} · ${r.warehouse.name} · ${formatDate(r.received_at)} · ${r.author}`"
        />

        <p class="flex flex-wrap gap-x-4 text-sm">
            <Link
                v-if="r.order"
                :href="orderShow(r.order.id)"
                class="underline underline-offset-4"
                >{{
                    t('purchases.order', { number: r.order.number ?? '' })
                }}</Link
            >
            <span v-if="r.supplier_reference"
                >{{ t('purchases.delivery_note') }} :
                {{ r.supplier_reference }}</span
            >
            <Link
                v-if="r.invoice"
                :href="invoiceShow(r.invoice.id)"
                class="underline underline-offset-4"
                >{{
                    t('purchases.invoice', {
                        number: r.invoice.number ?? t('purchases.status.draft'),
                    })
                }}</Link
            >
        </p>
        <p v-if="r.note" class="text-sm">« {{ r.note }} »</p>

        <ul class="divide-y rounded-lg border">
            <li
                v-for="line in r.lines"
                :key="line.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 p-3"
            >
                <span class="min-w-40 flex-1"
                    ><span class="font-medium">{{ line.label }}</span
                    ><span class="block text-xs text-muted-foreground"
                        >{{ line.sku }} ·
                        {{ formatXof(line.unit_cost) }} HT</span
                    ></span
                >
                <span class="text-sm tabular-nums"
                    >{{ line.quantity }} {{ line.unit }}</span
                >
                <span
                    v-if="line.returned !== '0'"
                    class="text-sm text-muted-foreground"
                    >↩ {{ line.returned }}</span
                >
                <span class="min-w-28 text-right font-semibold tabular-nums">{{
                    formatXof(line.total)
                }}</span>
                <div
                    v-if="showReturn && line.returnable_raw !== '0.000'"
                    class="grid gap-1"
                >
                    <Label :for="`ret-${line.id}`" class="text-xs">{{
                        t('purchases.return_quantity')
                    }}</Label>
                    <Input
                        :id="`ret-${line.id}`"
                        v-model="quantities[line.id]"
                        inputmode="decimal"
                        class="h-11 w-28 tabular-nums"
                        :placeholder="
                            t('purchases.returnable', {
                                quantity: line.returnable,
                            })
                        "
                    />
                </div>
            </li>
        </ul>
        <p class="text-right font-semibold tabular-nums">
            {{ t('money.total_excl_tax') }} : {{ formatXof(r.total_excl_tax) }}
        </p>

        <div class="flex flex-wrap gap-2">
            <Button v-if="can.invoice" as-child class="h-11"
                ><Link :href="invoiceCreate({ query: { receipt: r.id } })"
                    ><FileText class="size-4" />{{
                        t('purchases.create_invoice')
                    }}</Link
                ></Button
            >
            <Button
                v-if="can.return"
                variant="outline"
                class="h-11"
                @click="showReturn = !showReturn"
                ><Undo2 class="size-4" />{{
                    t('purchases.return_title')
                }}</Button
            >
        </div>

        <section v-if="showReturn" class="space-y-3 rounded-lg border p-4">
            <p class="text-sm text-muted-foreground">
                {{ t('purchases.return_hint') }}
            </p>
            <div class="grid max-w-md gap-1">
                <Label for="reason">{{ t('purchases.reason') }}</Label>
                <Input
                    id="reason"
                    v-model="reason"
                    maxlength="500"
                    class="h-11"
                />
            </div>
            <InputError :message="errors.reason ?? errors.business" />
            <Button class="h-11" :disabled="processing" @click="submitReturn">{{
                t('purchases.return_submit')
            }}</Button>
        </section>

        <section v-if="r.returns.length" class="space-y-2">
            <h2 class="font-semibold">{{ t('purchases.returns') }}</h2>
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="ret in r.returns"
                    :key="ret.id"
                    class="flex flex-wrap items-center gap-3 p-3 text-sm"
                >
                    <span class="font-medium">{{ ret.number }}</span>
                    <span class="flex-1">{{ ret.reason }}</span>
                    <span class="text-muted-foreground"
                        >{{ formatDate(ret.created_at) }} ·
                        {{ ret.author }}</span
                    >
                    <span class="tabular-nums">{{ formatXof(ret.total) }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
