<script setup lang="ts">
import {
    ArrowLeft,
    ArrowRight,
    Banknote,
    CircleAlert,
    CircleCheck,
    Coins,
    Lock,
    Minus,
    Plus,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatXof, parseAmount } from '@/lib/money';

/**
 * Fermeture de caisse guidée, en trois étapes :
 * 1. compter l'argent (billets et pièces, ou total direct) sans voir le
 *    montant attendu, pour un comptage honnête ;
 * 2. comparer avec le montant attendu, expliquer l'écart éventuel ;
 * 3. fermer — le détail du comptage accompagne la fermeture.
 */
const props = defineProps<{
    expected: number;
    byMethod: Record<string, number>;
    processing: boolean;
    error?: string;
}>();
const emit = defineEmits<{ submit: [counted: number, note: string | null] }>();

const NOTES = [10000, 5000, 2000, 1000, 500];
const COINS = [500, 250, 200, 100, 50, 25, 10, 5];

const step = ref<1 | 2 | 3>(1);
const mode = ref<'detail' | 'total'>('detail');
// Clés distinctes : le billet et la pièce de 500 F se comptent séparément.
const counts = reactive<Record<string, number>>({});
const direct = ref('');
const reason = ref('');

const key = (kind: 'note' | 'coin', value: number) => `${kind}-${value}`;

function bump(k: string, delta: number): void {
    counts[k] = Math.max(0, (counts[k] ?? 0) + delta);
}

function setCount(k: string, value: string): void {
    const n = Number.parseInt(value.replace(/\D/g, ''), 10);
    counts[k] = Number.isFinite(n) ? n : 0;
}

const detailTotal = computed(() =>
    Object.entries(counts).reduce(
        (sum, [k, n]) => sum + Number(k.split('-')[1]) * n,
        0,
    ),
);
const counted = computed(() =>
    mode.value === 'detail' ? detailTotal.value : parseAmount(direct.value),
);
const hasCount = computed(() =>
    mode.value === 'detail'
        ? Object.values(counts).some((n) => n > 0)
        : direct.value.trim() !== '',
);
const difference = computed(() => counted.value - props.expected);
const needsReason = computed(() => difference.value !== 0);

const suggestions = [
    'cash.wizard.reason_change',
    'cash.wizard.reason_expense',
    'cash.wizard.reason_note',
] as const;

/** Détail du comptage joint à la fermeture (traçabilité). */
const breakdown = computed(() => {
    if (mode.value !== 'detail') {
        return '';
    }

    const parts = [
        ...NOTES.map((v) => key('note', v)),
        ...COINS.map((v) => key('coin', v)),
    ]
        .filter((k) => (counts[k] ?? 0) > 0)
        .map((k) => `${counts[k]} × ${formatXof(Number(k.split('-')[1]))}`);

    return parts.length
        ? `${t('cash.wizard.breakdown')} : ${parts.join(', ')}`
        : '';
});

function submit(): void {
    const note = [reason.value.trim(), breakdown.value]
        .filter(Boolean)
        .join('\n')
        .slice(0, 500);
    emit('submit', counted.value, note || null);
}

const nonCash = computed(() =>
    Object.entries(props.byMethod).filter(
        ([method, amount]) => method !== 'cash' && amount !== 0,
    ),
);
</script>

<template>
    <section class="space-y-5 rounded-xl border p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">{{ t('cash.close_title') }}</h2>
            <ol
                class="flex items-center gap-2 text-sm"
                :aria-label="t('cash.wizard.steps')"
            >
                <li
                    v-for="n in [1, 2, 3]"
                    :key="n"
                    class="flex items-center gap-2"
                >
                    <span
                        class="flex size-7 items-center justify-center rounded-full text-xs font-semibold"
                        :class="
                            n === step
                                ? 'bg-primary text-primary-foreground'
                                : n < step
                                  ? 'bg-success-soft text-success'
                                  : 'bg-muted text-muted-foreground'
                        "
                        :aria-current="n === step ? 'step' : undefined"
                        >{{ n }}</span
                    >
                    <span
                        class="hidden sm:inline"
                        :class="
                            n === step ? 'font-medium' : 'text-muted-foreground'
                        "
                        >{{
                            t(`cash.wizard.step${n}` as 'cash.wizard.step1')
                        }}</span
                    >
                </li>
            </ol>
        </div>

        <!-- Étape 1 : compter -->
        <template v-if="step === 1">
            <p class="text-muted-foreground">
                {{ t('cash.wizard.count_hint') }}
            </p>
            <div class="grid grid-cols-2 gap-2 sm:max-w-sm" role="radiogroup">
                <Button
                    type="button"
                    role="radio"
                    :aria-checked="mode === 'detail'"
                    :variant="mode === 'detail' ? 'default' : 'outline'"
                    class="h-11"
                    @click="mode = 'detail'"
                    >{{ t('cash.wizard.by_detail') }}</Button
                >
                <Button
                    type="button"
                    role="radio"
                    :aria-checked="mode === 'total'"
                    :variant="mode === 'total' ? 'default' : 'outline'"
                    class="h-11"
                    @click="mode = 'total'"
                    >{{ t('cash.wizard.by_total') }}</Button
                >
            </div>

            <div v-if="mode === 'detail'" class="grid gap-5 md:grid-cols-2">
                <fieldset
                    v-for="group in [
                        {
                            kind: 'note' as const,
                            values: NOTES,
                            icon: Banknote,
                        },
                        { kind: 'coin' as const, values: COINS, icon: Coins },
                    ]"
                    :key="group.kind"
                    class="space-y-2"
                >
                    <legend class="mb-2 flex items-center gap-2 font-medium">
                        <component
                            :is="group.icon"
                            class="size-4 text-primary"
                            aria-hidden="true"
                        />
                        {{
                            t(
                                group.kind === 'note'
                                    ? 'cash.wizard.notes'
                                    : 'cash.wizard.coins',
                            )
                        }}
                    </legend>
                    <div
                        v-for="value in group.values"
                        :key="value"
                        class="flex items-center gap-2"
                    >
                        <span class="w-20 shrink-0 font-medium tabular-nums">{{
                            formatXof(value).replace(' XOF', ' F')
                        }}</span>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            class="size-11 shrink-0"
                            :aria-label="
                                t('cash.wizard.less', {
                                    value: formatXof(value),
                                })
                            "
                            @click="bump(key(group.kind, value), -1)"
                            ><Minus class="size-4"
                        /></Button>
                        <Input
                            :model-value="
                                String(counts[key(group.kind, value)] ?? 0)
                            "
                            inputmode="numeric"
                            class="h-11 w-16 text-center tabular-nums"
                            :aria-label="
                                t('cash.wizard.count_of', {
                                    value: formatXof(value),
                                })
                            "
                            @update:model-value="
                                setCount(key(group.kind, value), String($event))
                            "
                            @focus="
                                ($event.target as HTMLInputElement).select()
                            "
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            class="size-11 shrink-0"
                            :aria-label="
                                t('cash.wizard.more', {
                                    value: formatXof(value),
                                })
                            "
                            @click="bump(key(group.kind, value), 1)"
                            ><Plus class="size-4"
                        /></Button>
                        <span
                            class="ml-auto text-sm text-muted-foreground tabular-nums"
                            >{{
                                formatXof(
                                    value *
                                        (counts[key(group.kind, value)] ?? 0),
                                )
                            }}</span
                        >
                    </div>
                </fieldset>
            </div>

            <div v-else class="grid gap-1 sm:max-w-xs">
                <Label for="counted-direct">{{ t('cash.counted') }}</Label>
                <Input
                    id="counted-direct"
                    v-amount
                    v-model="direct"
                    class="h-12 text-lg tabular-nums"
                />
            </div>

            <div
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-muted p-4"
            >
                <span class="text-muted-foreground">{{
                    t('cash.wizard.counted_total')
                }}</span>
                <span class="text-2xl font-bold tabular-nums">{{
                    formatXof(counted)
                }}</span>
            </div>
            <Button
                class="h-12 w-full text-base sm:w-auto"
                :disabled="!hasCount"
                @click="step = 2"
                >{{ t('cash.wizard.next') }}<ArrowRight class="size-4"
            /></Button>
        </template>

        <!-- Étape 2 : comparer -->
        <template v-else-if="step === 2">
            <dl class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg border p-4">
                    <dt class="text-sm text-muted-foreground">
                        {{ t('cash.wizard.counted_total') }}
                    </dt>
                    <dd class="text-2xl font-bold tabular-nums">
                        {{ formatXof(counted) }}
                    </dd>
                </div>
                <div class="rounded-lg border p-4">
                    <dt class="text-sm text-muted-foreground">
                        {{ t('cash.expected') }}
                    </dt>
                    <dd class="text-2xl font-bold tabular-nums">
                        {{ formatXof(expected) }}
                    </dd>
                </div>
            </dl>

            <p
                class="flex items-center gap-3 rounded-lg border p-4 text-lg font-semibold"
                :class="
                    difference === 0
                        ? 'border-success-border bg-success-soft text-success-soft-foreground'
                        : 'border-warning-border bg-warning-soft text-warning-soft-foreground'
                "
                role="status"
            >
                <CircleCheck
                    v-if="difference === 0"
                    class="size-6 shrink-0"
                    aria-hidden="true"
                />
                <CircleAlert
                    v-else
                    class="size-6 shrink-0"
                    aria-hidden="true"
                />
                {{
                    difference === 0
                        ? t('cash.wizard.balanced')
                        : difference < 0
                          ? t('cash.wizard.missing', {
                                amount: formatXof(-difference),
                            })
                          : t('cash.wizard.surplus', {
                                amount: formatXof(difference),
                            })
                }}
            </p>

            <div v-if="needsReason" class="grid gap-2">
                <Label for="close-reason">{{ t('cash.wizard.reason') }}</Label>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="s in suggestions"
                        :key="s"
                        type="button"
                        variant="secondary"
                        class="h-11"
                        @click="reason = t(s)"
                        >{{ t(s) }}</Button
                    >
                </div>
                <Input
                    id="close-reason"
                    v-model="reason"
                    maxlength="300"
                    class="h-11"
                />
            </div>

            <div class="flex flex-wrap gap-2">
                <Button variant="outline" class="h-12" @click="step = 1"
                    ><ArrowLeft class="size-4" />{{
                        t('cash.wizard.recount')
                    }}</Button
                >
                <Button
                    class="h-12 text-base"
                    :disabled="needsReason && reason.trim().length < 3"
                    @click="step = 3"
                    >{{ t('cash.wizard.next') }}<ArrowRight class="size-4"
                /></Button>
            </div>
        </template>

        <!-- Étape 3 : fermer -->
        <template v-else>
            <ul class="divide-y rounded-lg border">
                <li class="flex justify-between gap-3 p-3">
                    <span>{{ t('cash.wizard.cash_counted') }}</span>
                    <span class="font-semibold tabular-nums">{{
                        formatXof(counted)
                    }}</span>
                </li>
                <li
                    v-if="difference !== 0"
                    class="flex justify-between gap-3 p-3"
                >
                    <span>{{ t('cash.difference') }}</span>
                    <span class="font-semibold text-warning tabular-nums">{{
                        formatXof(difference)
                    }}</span>
                </li>
                <li
                    v-for="[method, amount] in nonCash"
                    :key="method"
                    class="flex justify-between gap-3 p-3 text-muted-foreground"
                >
                    <span>{{ t(`methods.${method}` as 'methods.cash') }}</span>
                    <span class="tabular-nums">{{ formatXof(amount) }}</span>
                </li>
            </ul>
            <p class="text-sm text-muted-foreground">
                {{ t('cash.wizard.final_hint') }}
            </p>
            <InputError :message="error" />
            <div class="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    class="h-12"
                    :disabled="processing"
                    @click="step = 2"
                    ><ArrowLeft class="size-4" />{{
                        t('cash.wizard.back')
                    }}</Button
                >
                <Button
                    variant="destructive"
                    class="h-12 text-base"
                    :disabled="processing"
                    @click="submit"
                    ><Lock class="size-4" />{{ t('cash.close') }}</Button
                >
            </div>
        </template>
    </section>
</template>
