<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, PackageCheck, Truck } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { index as stockIndex } from '@/routes/stock';
import {
    cancel as cancelRoute,
    index as transfersIndex,
    receive as receiveRoute,
    ship as shipRoute,
} from '@/routes/transfers';
import { confirmAction } from '@/composables/useConfirm';

type Line = {
    product_id: string;
    sku: string;
    name: string;
    unit: string;
    quantity: string;
    quantity_raw: string;
    quantity_received: string | null;
};

const props = defineProps<{
    transfer: {
        id: string;
        number: string | null;
        status: 'draft' | 'in_transit' | 'received' | 'cancelled';
        source: { name: string; code: string };
        destination: { name: string; code: string };
        notes: string | null;
        receipt_note: string | null;
        shipped_at: string | null;
        received_at: string | null;
        lines: Line[];
    };
    can: { ship: boolean; receive: boolean; cancel: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('stock.title'), href: stockIndex() },
            { title: t('stock.transfers'), href: transfersIndex() },
        ],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const processing = ref(false);
// Une clé par action : un double clic ou un renvoi n'expédie / ne reçoit qu'une fois.
const shipKey = crypto.randomUUID();
const receiveKey = crypto.randomUUID();
const received = reactive<Record<string, string>>({});
const note = ref('');

const options = {
    preserveScroll: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};

async function ship(): Promise<void> {
    if (
        await confirmAction({
            title: t('transfers.ship_confirm', {
                source: props.transfer.source.name,
            }),
            confirmLabel: t('confirm.ship'),
        })
    ) {
        router.post(
            shipRoute(props.transfer.id).url,
            { idempotency_key: shipKey },
            options,
        );
    }
}

function receive(): void {
    router.post(
        receiveRoute(props.transfer.id).url,
        { idempotency_key: receiveKey, received, note: note.value || null },
        options,
    );
}

function cancel(): void {
    router.post(cancelRoute(props.transfer.id).url, {}, options);
}
</script>

<template>
    <Head :title="transfer.number ?? t('transfers.draft_label')" />

    <div class="mx-auto max-w-3xl space-y-6 p-4 md:p-6">
        <Link
            :href="transfersIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('transfers.back')
            }}
        </Link>

        <Heading
            :title="
                transfer.number
                    ? t('transfers.title_show', { number: transfer.number })
                    : t('transfers.draft_label')
            "
            :description="t(`transfers.status.${transfer.status}`)"
        />

        <p class="flex flex-wrap items-center gap-2 text-lg">
            {{ transfer.source.name }}
            <ArrowRight class="size-5" aria-hidden="true" />
            {{ transfer.destination.name }}
        </p>
        <p v-if="transfer.shipped_at" class="text-sm text-muted-foreground">
            {{
                t('transfers.shipped', {
                    date: formatDate(transfer.shipped_at),
                })
            }}
        </p>
        <p v-if="transfer.received_at" class="text-sm text-muted-foreground">
            {{
                t('transfers.received_on', {
                    date: formatDate(transfer.received_at),
                })
            }}
        </p>
        <p v-if="transfer.notes" class="text-sm">{{ transfer.notes }}</p>
        <p v-if="transfer.receipt_note" class="text-sm">
            « {{ transfer.receipt_note }} »
        </p>

        <ul class="divide-y rounded-lg border">
            <li
                v-for="line in transfer.lines"
                :key="line.product_id"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 p-3"
            >
                <span class="min-w-40 flex-1"
                    ><span class="font-medium">{{ line.name }}</span
                    ><span class="block text-sm text-muted-foreground">{{
                        line.sku
                    }}</span></span
                >
                <span class="text-sm tabular-nums"
                    >{{ t('transfers.shipped_qty') }} : {{ line.quantity }}
                    {{ line.unit }}</span
                >
                <span
                    v-if="line.quantity_received !== null"
                    class="text-sm tabular-nums"
                    >{{ t('transfers.received_qty') }} :
                    {{ line.quantity_received }}</span
                >
                <div v-if="can.receive" class="grid gap-1">
                    <Label :for="`rcv-${line.product_id}`" class="text-xs">{{
                        t('transfers.received_qty')
                    }}</Label>
                    <Input
                        :id="`rcv-${line.product_id}`"
                        v-model="received[line.product_id]"
                        :placeholder="line.quantity"
                        inputmode="decimal"
                        class="h-11 w-28 tabular-nums"
                    />
                </div>
            </li>
        </ul>

        <InputError :message="errors.business ?? errors.received" />

        <section v-if="can.receive" class="space-y-3 rounded-lg border p-4">
            <p class="text-sm text-muted-foreground">
                {{ t('transfers.receive_hint') }}
            </p>
            <div class="grid gap-1">
                <Label for="note">{{ t('transfers.receipt_note') }}</Label>
                <Input id="note" v-model="note" maxlength="500" class="h-11" />
            </div>
            <Button class="h-11" :disabled="processing" @click="receive"
                ><PackageCheck class="size-4" />{{
                    t('transfers.confirm_receipt')
                }}</Button
            >
        </section>

        <div class="flex flex-wrap gap-2">
            <Button
                v-if="can.ship"
                class="h-11"
                :disabled="processing"
                @click="ship"
                ><Truck class="size-4" />{{ t('transfers.ship') }}</Button
            >
            <Button
                v-if="can.cancel"
                variant="outline"
                class="h-11"
                :disabled="processing"
                @click="cancel"
                >{{ t('transfers.cancel') }}</Button
            >
        </div>
    </div>
</template>
