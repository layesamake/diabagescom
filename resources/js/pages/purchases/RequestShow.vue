<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    Pencil,
    Send,
    ShoppingBasket,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SearchSelect from '@/components/SearchSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { show as orderShow } from '@/routes/purchases/orders';
import {
    approve as approveRoute,
    cancel as cancelRoute,
    convert as convertRoute,
    edit as requestEdit,
    index as requestsIndex,
    reject as rejectRoute,
    submit as submitRoute,
} from '@/routes/purchases/requests';
import { confirmAction } from '@/composables/useConfirm';

type Status =
    | 'draft'
    | 'submitted'
    | 'approved'
    | 'rejected'
    | 'ordered'
    | 'cancelled';

const props = defineProps<{
    purchaseRequest: {
        id: string;
        number: string | null;
        status: Status;
        warehouse: { name: string };
        author: string;
        needed_on: string | null;
        notes: string | null;
        decision_reason: string | null;
        cancel_reason: string | null;
        created_at: string | null;
        order: { id: string; number: string | null } | null;
        lines: {
            id: string;
            sku: string;
            label: string;
            unit: string;
            quantity: string;
            note: string | null;
        }[];
    };
    suppliers: { id: string; code: string; name: string }[];
    can: {
        edit: boolean;
        submit: boolean;
        decide: boolean;
        convert: boolean;
        cancel: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('purchases.requests.title'), href: requestsIndex() },
        ],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const r = computed(() => props.purchaseRequest);
const processing = ref(false);
const options = {
    preserveScroll: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};
const reason = ref('');
const showReject = ref(false);
const showCancel = ref(false);
const supplierId = ref('');
const supplierOptions = computed(() =>
    props.suppliers.map((s) => ({ value: s.id, label: s.name, hint: s.code })),
);

async function submit(): Promise<void> {
    if (
        await confirmAction({
            title: t('purchases.requests.submit_hint'),
            confirmLabel: t('purchases.requests.submit'),
            tone: 'primary',
        })
    ) {
        router.post(submitRoute(r.value.id).url, {}, options);
    }
}

async function approve(): Promise<void> {
    if (
        await confirmAction({
            title: t('purchases.requests.approve_hint'),
            confirmLabel: t('purchases.requests.approve'),
            tone: 'primary',
        })
    ) {
        router.post(approveRoute(r.value.id).url, {}, options);
    }
}

function reject(): void {
    router.post(rejectRoute(r.value.id).url, { reason: reason.value }, options);
}

function cancel(): void {
    router.post(
        cancelRoute(r.value.id).url,
        { reason: reason.value || null },
        options,
    );
}

function convert(): void {
    router.post(
        convertRoute(r.value.id).url,
        { supplier_id: supplierId.value },
        options,
    );
}
</script>

<template>
    <Head :title="r.number ?? t('purchases.requests.title')" />

    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
        <Link
            :href="requestsIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('purchases.requests.back')
            }}
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="
                    r.number
                        ? t('purchases.requests.number', { number: r.number })
                        : t('purchases.requests.draft_title')
                "
                :description="`${r.warehouse.name} · ${r.author} · ${formatDate(r.created_at)}`"
            />
            <span class="rounded bg-muted px-2 py-1 text-xs font-medium">{{
                t(`purchases.requests.status.${r.status}`)
            }}</span>
        </div>

        <p v-if="r.needed_on" class="text-sm">
            {{ t('purchases.requests.needed_on') }} :
            <strong>{{ formatDate(r.needed_on) }}</strong>
        </p>
        <p v-if="r.notes" class="text-sm whitespace-pre-line">{{ r.notes }}</p>
        <p v-if="r.decision_reason" class="rounded-lg border p-3 text-sm">
            <strong>{{ t('purchases.requests.decision_reason') }} :</strong>
            {{ r.decision_reason }}
        </p>
        <p v-if="r.cancel_reason" class="rounded-lg border p-3 text-sm">
            <strong>{{ t('purchases.cancel_reason') }} :</strong>
            {{ r.cancel_reason }}
        </p>
        <p v-if="r.order" class="text-sm">
            <Link
                :href="orderShow(r.order.id)"
                class="underline underline-offset-4"
                >{{
                    t('purchases.requests.see_order', {
                        number: r.order.number ?? t('purchases.status.draft'),
                    })
                }}</Link
            >
        </p>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full min-w-[28rem] text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="p-2">{{ t('purchases.label') }}</th>
                        <th class="p-2 text-right">
                            {{ t('purchases.quantity') }}
                        </th>
                        <th class="p-2">
                            {{ t('purchases.requests.line_note') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in r.lines" :key="line.id" class="border-t">
                        <td class="p-2">
                            <span class="font-medium">{{ line.label }}</span>
                            <span class="block text-muted-foreground">{{
                                line.sku
                            }}</span>
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ line.quantity }} {{ line.unit }}
                        </td>
                        <td class="p-2 text-muted-foreground">
                            {{ line.note }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <InputError
            :message="errors.business ?? errors.reason ?? errors.supplier_id"
        />

        <div class="flex flex-wrap gap-2">
            <Button v-if="can.edit" as-child variant="outline" class="h-11"
                ><Link :href="requestEdit(r.id)"
                    ><Pencil class="size-4" />{{ t('purchases.edit') }}</Link
                ></Button
            >
            <Button
                v-if="can.submit"
                class="h-11"
                :disabled="processing"
                @click="submit"
                ><Send class="size-4" />{{
                    t('purchases.requests.submit')
                }}</Button
            >
            <Button
                v-if="can.decide"
                class="h-11"
                :disabled="processing"
                @click="approve"
                ><CheckCircle2 class="size-4" />{{
                    t('purchases.requests.approve')
                }}</Button
            >
            <Button
                v-if="can.decide"
                variant="outline"
                class="h-11"
                @click="
                    showReject = !showReject;
                    showCancel = false;
                "
                ><XCircle class="size-4" />{{
                    t('purchases.requests.reject')
                }}</Button
            >
            <Button
                v-if="can.cancel"
                variant="ghost"
                class="h-11"
                @click="
                    showCancel = !showCancel;
                    showReject = false;
                "
                >{{ t('purchases.cancel') }}</Button
            >
        </div>

        <section
            v-if="showReject && can.decide"
            class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_auto] sm:items-end"
        >
            <div class="grid gap-1">
                <Label for="reject-reason">{{
                    t('purchases.requests.reject_reason')
                }}</Label>
                <Input
                    id="reject-reason"
                    v-model="reason"
                    maxlength="500"
                    class="h-11"
                />
            </div>
            <Button
                class="h-11"
                :disabled="processing || reason.trim().length < 3"
                @click="reject"
                >{{ t('purchases.requests.reject') }}</Button
            >
        </section>

        <section
            v-if="showCancel && can.cancel"
            class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_auto] sm:items-end"
        >
            <div class="grid gap-1">
                <Label for="cancel-reason">{{
                    t('purchases.cancel_reason')
                }}</Label>
                <Input
                    id="cancel-reason"
                    v-model="reason"
                    maxlength="500"
                    class="h-11"
                />
            </div>
            <Button class="h-11" :disabled="processing" @click="cancel">{{
                t('purchases.cancel')
            }}</Button>
        </section>

        <section
            v-if="can.convert"
            class="space-y-3 rounded-lg border p-4"
            aria-labelledby="convert-title"
        >
            <h2 id="convert-title" class="font-semibold">
                {{ t('purchases.requests.convert_title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ t('purchases.requests.convert_hint') }}
            </p>
            <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                <div class="grid gap-1">
                    <Label for="convert-supplier">{{
                        t('purchases.supplier')
                    }}</Label>
                    <SearchSelect
                        v-model="supplierId"
                        input-id="convert-supplier"
                        :options="supplierOptions"
                        :placeholder="t('purchases.choose_supplier')"
                    />
                </div>
                <Button
                    class="h-11"
                    :disabled="processing || !supplierId"
                    @click="convert"
                    ><ShoppingBasket class="size-4" />{{
                        t('purchases.requests.convert')
                    }}</Button
                >
            </div>
        </section>
    </div>
</template>
