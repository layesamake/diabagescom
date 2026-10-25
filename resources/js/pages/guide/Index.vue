<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ChevronRight,
    CircleCheck,
    CircleDashed,
    Compass,
    Rocket,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useGuide } from '@/composables/useGuide';
import { useGuideTour } from '@/composables/useGuideTour';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import { index as guideIndex } from '@/routes/guide';
import type { GuidePhase, GuideStep } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('guide.nav'), href: guideIndex() }],
    },
});

const {
    steps,
    total,
    handled,
    complete,
    next,
    track,
    stepText,
    phaseTitle,
    mark,
} = useGuide();
const tour = useGuideTour();

const phases: GuidePhase[] = ['before', 'goal', 'after'];

const groups = computed(() =>
    phases
        .map((phase) => ({
            phase,
            steps: steps.value.filter((s) => s.phase === phase),
        }))
        .filter((group) => group.steps.length > 0),
);

const title = computed(() =>
    t(`guide.tracks.${track.value}.title` as TranslationKey),
);
const intro = computed(() =>
    t(`guide.tracks.${track.value}.intro` as TranslationKey),
);

const statusLabel = (step: GuideStep): string =>
    t(`guide.status_${step.status}` as TranslationKey);
</script>

<template>
    <Head :title="t('guide.page_title')" />

    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-8">
        <header class="flex flex-wrap items-start gap-4">
            <span
                class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground"
            >
                <Rocket class="size-6" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1 space-y-1">
                <h1 class="text-2xl font-semibold tracking-tight md:text-3xl">
                    {{ title }}
                </h1>
                <p class="text-muted-foreground">{{ intro }}</p>
            </div>
            <Button
                variant="outline"
                class="min-h-11"
                type="button"
                @click="tour.start()"
            >
                <Compass class="size-4" aria-hidden="true" />
                {{ t('guide.replay_tour') }}
            </Button>
        </header>

        <section class="space-y-2" aria-labelledby="guide-progress">
            <p id="guide-progress" class="text-sm font-medium">
                {{ t('guide.progress', { done: handled, total }) }}
            </p>
            <div
                class="h-2 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                :aria-valuenow="handled"
                aria-valuemin="0"
                :aria-valuemax="total"
            >
                <div
                    class="h-full rounded-full bg-primary transition-all"
                    :style="{
                        width: `${total ? (handled / total) * 100 : 0}%`,
                    }"
                />
            </div>
        </section>

        <section
            v-if="complete"
            class="rounded-xl border border-success-border bg-success-soft p-4 text-success-soft-foreground"
        >
            <h2 class="font-semibold">{{ t('guide.complete_title') }}</h2>
            <p class="text-sm">
                {{ t('guide.complete_text') }}
            </p>
        </section>

        <section
            v-for="group in groups"
            :key="group.phase"
            class="space-y-3"
            :aria-labelledby="`phase-${group.phase}`"
        >
            <h2
                :id="`phase-${group.phase}`"
                class="text-lg font-semibold tracking-tight"
            >
                {{ phaseTitle(group.phase) }}
            </h2>

            <ol class="space-y-2">
                <li
                    v-for="step in group.steps"
                    :key="step.key"
                    class="flex flex-col gap-3 rounded-lg border bg-card p-4 sm:flex-row sm:items-center"
                    :class="{
                        'border-primary ring-1 ring-primary/40':
                            step.key === next?.key,
                        'opacity-75': step.status !== 'todo',
                    }"
                >
                    <div class="flex min-w-0 flex-1 items-start gap-3">
                        <CircleCheck
                            v-if="step.status === 'done'"
                            class="mt-0.5 size-6 shrink-0 text-success"
                            aria-hidden="true"
                        />
                        <CircleDashed
                            v-else
                            class="mt-0.5 size-6 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 space-y-0.5">
                            <p
                                class="font-medium"
                                :class="{
                                    'line-through': step.status === 'done',
                                }"
                            >
                                {{ stepText(step.key, 'title') }}
                                <span class="sr-only">
                                    — {{ statusLabel(step) }}</span
                                >
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ stepText(step.key, 'hint') }}
                            </p>
                            <p
                                v-if="step.auto"
                                class="text-xs text-muted-foreground"
                            >
                                {{ t('guide.auto_done') }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2 sm:justify-end"
                    >
                        <Button
                            as-child
                            :variant="
                                step.status === 'todo' ? 'default' : 'outline'
                            "
                            class="min-h-11"
                        >
                            <Link :href="step.href">
                                {{ t('guide.go') }}
                                <ChevronRight
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </Link>
                        </Button>
                        <Button
                            v-if="step.status === 'todo' && !step.detectable"
                            variant="ghost"
                            class="min-h-11"
                            type="button"
                            @click="mark(step.key, 'done')"
                        >
                            {{ t('guide.mark_done') }}
                        </Button>
                        <Button
                            v-if="step.status === 'todo'"
                            variant="ghost"
                            class="min-h-11"
                            type="button"
                            @click="mark(step.key, 'skipped')"
                        >
                            {{ t('guide.skip') }}
                        </Button>
                        <Button
                            v-if="!step.auto && step.status !== 'todo'"
                            variant="ghost"
                            class="min-h-11"
                            type="button"
                            @click="mark(step.key, 'todo')"
                        >
                            {{ t('guide.reopen') }}
                        </Button>
                    </div>
                </li>
            </ol>
        </section>
    </div>
</template>
