<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Lock, ShoppingCart } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof, parseAmount } from '@/lib/money';
import { close as closeRoute, index as cashIndex } from '@/routes/cash';
import { store as movementStore } from '@/routes/cash/movements';
import { create as posCreate } from '@/routes/pos';
import { show as salesShow } from '@/routes/sales';

type MovementType =
    | 'customer_payment'
    | 'customer_refund'
    | 'supplier_payment'
    | 'supplier_refund'
    | 'expense'
    | 'income';
type Method = 'cash' | 'wave' | 'orange_money' | 'card' | 'transfer' | 'cheque';

const props = defineProps<{
    session: {
        id: string;
        status: 'open' | 'closed';
        register: string;
        warehouse: { id: string; name: string };
        opening_amount: number;
        expected_amount: number | null;
        counted_amount: number | null;
        difference: number | null;
        opened_by: string;
        opened_at: string;
        closed_by: string | null;
        closed_at: string | null;
        closing_note: string | null;
        summary: {
            by_method: Partial<Record<Method, number>>;
            expenses: number;
            incomes: number;
            refunds: number;
            supplier_payments: number;
            drawer: number;
        };
        movements: {
            id: string;
            type: MovementType;
            amount: number;
            number: string | null;
            label: string | null;
            reason: string | null;
            occurred_at: string;
            author: string;
        }[];
        payments: {
            id: string;
            number: string;
            direction: 'in' | 'out';
            method: Method;
            amount: number;
            reference: string | null;
            document: { id: string; number: string | null } | null;
        }[];
    };
    can: { close: boolean; expense: boolean; income: boolean; sell: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('cash.title'), href: cashIndex() }],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const processing = ref(false);
const s = computed(() => props.session);
const isOpen = computed(() => s.value.status === 'open');

const movement = reactive({
    key: crypto.randomUUID(),
    type: (props.can.expense ? 'expense' : 'income') as 'expense' | 'income',
    amount: '',
    label: '',
    reason: '',
});

function addMovement(): void {
    router.post(
        movementStore(s.value.id).url,
        {
            idempotency_key: movement.key,
            type: movement.type,
            amount: parseAmount(movement.amount),
            label: movement.label,
            reason: movement.reason,
        },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => {
                movement.key = crypto.randomUUID();
                movement.amount = '';
                movement.label = '';
                movement.reason = '';
            },
        },
    );
}

const counted = ref('');
const note = ref('');
const difference = computed(() =>
    counted.value.trim() === ''
        ? null
        : parseAmount(counted.value) - s.value.summary.drawer,
);

function close(): void {
    if (window.confirm(t('cash.close_confirm'))) {
        router.post(
            closeRoute(s.value.id).url,
            {
                counted_amount: parseAmount(counted.value),
                note: note.value || null,
            },
            {
                preserveScroll: true,
                onStart: () => (processing.value = true),
                onFinish: () => (processing.value = false),
            },
        );
    }
}
</script>

<template>
    <Head :title="s.register" />

    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
        <Link
            :href="cashIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{ t('cash.back') }}
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="
                    t('cash.session_title', {
                        register: s.register,
                        date: formatDate(s.opened_at),
                    })
                "
                :description="`${s.warehouse.name} · ${t('cash.opened_by', { name: s.opened_by, date: formatDate(s.opened_at) })}`"
            />
            <Button v-if="can.sell" as-child class="h-11"
                ><Link
                    :href="posCreate({ query: { warehouse: s.warehouse.id } })"
                    ><ShoppingCart class="size-4" />{{ t('cash.sell') }}</Link
                ></Button
            >
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border p-4">
                <p class="text-sm text-muted-foreground">
                    {{ t('cash.drawer') }}
                </p>
                <p class="text-2xl font-bold tabular-nums">
                    {{ formatXof(s.summary.drawer) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ t('cash.opening') }} : {{ formatXof(s.opening_amount) }}
                </p>
            </div>
            <div class="rounded-lg border p-4 sm:col-span-2">
                <p class="mb-2 text-sm text-muted-foreground">
                    {{ t('cash.by_method') }}
                </p>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <template
                        v-for="(amount, method) in s.summary.by_method"
                        :key="method"
                    >
                        <dt>{{ t(`methods.${method}`) }}</dt>
                        <dd class="text-right tabular-nums">
                            {{ formatXof(amount ?? 0) }}
                        </dd>
                    </template>
                    <dt>{{ t('cash.expenses') }}</dt>
                    <dd class="text-right tabular-nums">
                        − {{ formatXof(s.summary.expenses) }}
                    </dd>
                    <dt>{{ t('cash.incomes') }}</dt>
                    <dd class="text-right tabular-nums">
                        {{ formatXof(s.summary.incomes) }}
                    </dd>
                    <template v-if="s.summary.refunds">
                        <dt>{{ t('cash.refunds') }}</dt>
                        <dd class="text-right tabular-nums">
                            − {{ formatXof(s.summary.refunds) }}
                        </dd>
                    </template>
                    <template v-if="s.summary.supplier_payments">
                        <dt>{{ t('cash.supplier_payments') }}</dt>
                        <dd class="text-right tabular-nums">
                            − {{ formatXof(s.summary.supplier_payments) }}
                        </dd>
                    </template>
                </dl>
            </div>
        </div>

        <div v-if="!isOpen" class="space-y-1 rounded-lg border p-4 text-sm">
            <p>
                {{ t('cash.expected') }} :
                <strong class="tabular-nums">{{
                    formatXof(s.expected_amount ?? 0)
                }}</strong>
            </p>
            <p>
                {{ t('cash.counted') }} :
                <strong class="tabular-nums">{{
                    formatXof(s.counted_amount ?? 0)
                }}</strong>
            </p>
            <p :class="s.difference ? 'text-destructive' : ''">
                {{ t('cash.difference') }} :
                <strong class="tabular-nums">{{
                    formatXof(s.difference ?? 0)
                }}</strong>
            </p>
            <p v-if="s.closing_note">« {{ s.closing_note }} »</p>
            <p class="text-muted-foreground">
                {{ s.closed_by }} · {{ formatDate(s.closed_at) }}
            </p>
        </div>

        <section class="space-y-2">
            <h2 class="font-semibold">{{ t('cash.movements') }}</h2>
            <p
                v-if="s.movements.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('cash.no_movements') }}
            </p>
            <ul v-else class="divide-y rounded-lg border">
                <li
                    v-for="m in s.movements"
                    :key="m.id"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 p-3 text-sm"
                >
                    <span class="min-w-32 font-medium">{{
                        t(`cash.types.${m.type}`)
                    }}</span>
                    <span class="min-w-0 flex-1"
                        >{{ m.number ?? m.label
                        }}<template v-if="m.number && m.label">
                            · {{ m.label }}</template
                        ><span
                            v-if="m.reason"
                            class="block text-xs text-muted-foreground"
                            >{{ m.reason }}</span
                        ></span
                    >
                    <span class="text-muted-foreground">{{ m.author }}</span>
                    <span
                        class="min-w-24 text-right font-semibold tabular-nums"
                        :class="m.amount < 0 ? 'text-destructive' : ''"
                        >{{ formatXof(m.amount) }}</span
                    >
                </li>
            </ul>
        </section>

        <section v-if="s.payments.length" class="space-y-2">
            <h2 class="font-semibold">{{ t('cash.other_payments') }}</h2>
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="p in s.payments"
                    :key="p.id"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 p-3 text-sm"
                >
                    <span class="min-w-24">{{ t(`methods.${p.method}`) }}</span>
                    <span class="flex-1">
                        <Link
                            v-if="p.document"
                            :href="salesShow(p.document.id)"
                            class="underline underline-offset-4"
                            >{{ p.document.number }}</Link
                        ><template v-if="p.reference">
                            · {{ p.reference }}</template
                        >
                    </span>
                    <span class="font-semibold tabular-nums"
                        >{{ p.direction === 'out' ? '− ' : ''
                        }}{{ formatXof(p.amount) }}</span
                    >
                </li>
            </ul>
        </section>

        <section
            v-if="can.expense || can.income"
            class="space-y-3 rounded-lg border p-4"
        >
            <div class="flex flex-wrap gap-2" role="radiogroup">
                <Button
                    v-if="can.expense"
                    role="radio"
                    :aria-checked="movement.type === 'expense'"
                    :variant="
                        movement.type === 'expense' ? 'default' : 'outline'
                    "
                    class="h-11"
                    @click="movement.type = 'expense'"
                    >{{ t('cash.add_expense') }}</Button
                >
                <Button
                    v-if="can.income"
                    role="radio"
                    :aria-checked="movement.type === 'income'"
                    :variant="
                        movement.type === 'income' ? 'default' : 'outline'
                    "
                    class="h-11"
                    @click="movement.type = 'income'"
                    >{{ t('cash.add_income') }}</Button
                >
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="grid gap-1">
                    <Label for="mv-amount">{{ t('cash.amount') }}</Label>
                    <Input
                        id="mv-amount"
                        v-model="movement.amount"
                        inputmode="numeric"
                        class="h-11 tabular-nums"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="mv-label">{{ t('cash.label') }}</Label>
                    <Input
                        id="mv-label"
                        v-model="movement.label"
                        maxlength="200"
                        class="h-11"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="mv-reason">{{ t('cash.reason') }}</Label>
                    <Input
                        id="mv-reason"
                        v-model="movement.reason"
                        maxlength="500"
                        class="h-11"
                    />
                </div>
            </div>
            <InputError
                :message="
                    errors.amount ??
                    errors.label ??
                    errors.reason ??
                    errors.business
                "
            />
            <Button class="h-11" :disabled="processing" @click="addMovement">{{
                t('common.save')
            }}</Button>
        </section>

        <section v-if="can.close" class="space-y-3 rounded-lg border p-4">
            <h2 class="font-semibold">{{ t('cash.close_title') }}</h2>
            <p class="text-sm">
                {{ t('cash.expected') }} :
                <strong class="tabular-nums">{{
                    formatXof(s.summary.drawer)
                }}</strong>
            </p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1">
                    <Label for="counted">{{ t('cash.counted') }}</Label>
                    <Input
                        id="counted"
                        v-model="counted"
                        inputmode="numeric"
                        class="h-11 tabular-nums"
                    />
                    <p
                        v-if="difference !== null"
                        class="text-sm"
                        :class="
                            difference !== 0
                                ? 'text-destructive'
                                : 'text-success'
                        "
                    >
                        {{ t('cash.difference') }} : {{ formatXof(difference) }}
                    </p>
                </div>
                <div v-if="difference" class="grid gap-1">
                    <Label for="close-note">{{ t('cash.closing_note') }}</Label>
                    <Input
                        id="close-note"
                        v-model="note"
                        maxlength="500"
                        class="h-11"
                    />
                </div>
            </div>
            <InputError :message="errors.counted_amount ?? errors.business" />
            <Button
                variant="destructive"
                class="h-11"
                :disabled="processing || counted.trim() === ''"
                @click="close"
                ><Lock class="size-4" />{{ t('cash.close') }}</Button
            >
        </section>
    </div>
</template>
