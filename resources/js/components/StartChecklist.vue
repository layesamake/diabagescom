<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, CircleCheck, Circle, Rocket, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useAppContext } from '@/composables/useAppContext';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import { index as backupIndex } from '@/routes/backup';
import { index as cashIndex } from '@/routes/cash';
import { index as membersIndex } from '@/routes/members';
import { create as posCreate } from '@/routes/pos';
import { create as productCreate } from '@/routes/products';
import { index as stockIndex } from '@/routes/stock';

export type ChecklistStep = { key: string; done: boolean };

/**
 * Premiers pas d'une nouvelle entreprise : six étapes qui se cochent seules
 * dès qu'elles sont faites. Masquable (préférence propre à cet appareil).
 */
const props = defineProps<{ steps: ChecklistStep[] }>();

const links: Record<string, ReturnType<typeof stockIndex>> = {
    product: productCreate(),
    stock: stockIndex(),
    cash: cashIndex(),
    sale: posCreate(),
    team: membersIndex(),
    backup: backupIndex(),
};

const { context } = useAppContext();
const storageKey = computed(
    () => `diaba.checklist_hidden.${context.value?.tenant.id ?? ''}`,
);

function readHidden(): boolean {
    try {
        return localStorage.getItem(storageKey.value) === '1';
    } catch {
        return false;
    }
}

const hidden = ref(readHidden());

function hide(): void {
    hidden.value = true;

    try {
        localStorage.setItem(storageKey.value, '1');
    } catch {
        // Stockage indisponible : masqué pour cette visite seulement.
    }
}

const doneCount = computed(() => props.steps.filter((s) => s.done).length);
const next = computed(() => props.steps.find((s) => !s.done)?.key);
const label = (key: string, part: 'title' | 'hint') =>
    t(`checklist.steps.${key}.${part}` as TranslationKey);
</script>

<template>
    <section
        v-if="!hidden"
        class="space-y-4 rounded-xl border border-primary/40 bg-primary/5 p-5"
        aria-labelledby="checklist-title"
    >
        <div class="flex items-start gap-3">
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground"
            >
                <Rocket class="size-5" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 id="checklist-title" class="text-lg font-semibold">
                    {{ t('checklist.title') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        t('checklist.progress', {
                            done: doneCount,
                            total: steps.length,
                        })
                    }}
                </p>
            </div>
            <Button
                variant="ghost"
                size="icon"
                class="size-11"
                :aria-label="t('checklist.hide')"
                :title="t('checklist.hide')"
                @click="hide"
                ><X class="size-4"
            /></Button>
        </div>

        <div
            class="h-2 overflow-hidden rounded-full bg-muted"
            role="progressbar"
            :aria-valuenow="doneCount"
            aria-valuemin="0"
            :aria-valuemax="steps.length"
        >
            <div
                class="h-full rounded-full bg-primary transition-all"
                :style="{ width: `${(doneCount / steps.length) * 100}%` }"
            />
        </div>

        <ol class="grid gap-2 sm:grid-cols-2">
            <li v-for="(step, index) in steps" :key="step.key">
                <Link
                    :href="links[step.key]"
                    class="flex min-h-16 items-center gap-3 rounded-lg border bg-card p-3 transition-colors hover:bg-accent"
                    :class="{
                        'border-primary ring-1 ring-primary/40':
                            step.key === next,
                        'opacity-70': step.done,
                    }"
                >
                    <CircleCheck
                        v-if="step.done"
                        class="size-6 shrink-0 text-success"
                        aria-hidden="true"
                    />
                    <span
                        v-else
                        class="relative flex size-6 shrink-0 items-center justify-center text-xs font-semibold text-muted-foreground"
                        aria-hidden="true"
                        ><Circle class="absolute size-6" />{{ index + 1 }}</span
                    >
                    <span class="min-w-0 flex-1">
                        <span
                            class="block font-medium"
                            :class="{ 'line-through': step.done }"
                            >{{ label(step.key, 'title') }}</span
                        >
                        <span class="block text-sm text-muted-foreground">{{
                            label(step.key, 'hint')
                        }}</span>
                    </span>
                    <span class="sr-only">{{
                        step.done ? t('checklist.done') : t('checklist.todo')
                    }}</span>
                    <ChevronRight
                        v-if="!step.done"
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                </Link>
            </li>
        </ol>
    </section>
</template>
