<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ChevronRight,
    CircleCheck,
    Circle,
    Compass,
    Rocket,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useGuide } from '@/composables/useGuide';
import { useGuideTour } from '@/composables/useGuideTour';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import { index as guideIndex } from '@/routes/guide';

/**
 * Carte de l'accueil : où en est l'utilisateur dans son guide et quelle est la
 * prochaine étape. Masquable (mémorisé par le serveur) ; le guide complet reste
 * accessible depuis le menu.
 */
const {
    guide,
    steps,
    total,
    handled,
    complete,
    next,
    track,
    stepText,
    mark,
    setState,
} = useGuide();
const tour = useGuideTour();

const visible = computed(
    () => guide.value !== null && !guide.value.dismissed && !complete.value,
);

// Prochaine étape puis les suivantes à faire, sans noyer l'accueil.
const upcoming = computed(() =>
    steps.value.filter((s) => s.status === 'todo').slice(0, 4),
);

const title = computed(() =>
    t(`guide.tracks.${track.value}.title` as TranslationKey),
);
</script>

<template>
    <section
        v-if="visible"
        class="space-y-4 rounded-xl border border-primary/40 bg-primary/5 p-5"
        aria-labelledby="guide-card-title"
    >
        <div class="flex items-start gap-3">
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground"
            >
                <Rocket class="size-5" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 id="guide-card-title" class="text-lg font-semibold">
                    {{ title }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        t('guide.progress', {
                            done: handled,
                            total,
                        })
                    }}
                </p>
            </div>
            <Button
                variant="ghost"
                size="icon"
                class="size-11"
                :aria-label="t('guide.hide_card')"
                :title="t('guide.hide_card')"
                @click="setState({ dismissed: true })"
                ><X class="size-4"
            /></Button>
        </div>

        <div
            class="h-2 overflow-hidden rounded-full bg-muted"
            role="progressbar"
            :aria-valuenow="handled"
            aria-valuemin="0"
            :aria-valuemax="total"
        >
            <div
                class="h-full rounded-full bg-primary transition-all"
                :style="{ width: `${total ? (handled / total) * 100 : 0}%` }"
            />
        </div>

        <ol class="grid gap-2 sm:grid-cols-2">
            <li v-for="step in upcoming" :key="step.key">
                <Link
                    :href="step.href"
                    class="flex min-h-16 items-center gap-3 rounded-lg border bg-card p-3 transition-colors hover:bg-accent"
                    :class="{
                        'border-primary ring-1 ring-primary/40':
                            step.key === next?.key,
                    }"
                >
                    <CircleCheck
                        v-if="step.status === 'done'"
                        class="size-6 shrink-0 text-success"
                        aria-hidden="true"
                    />
                    <Circle
                        v-else
                        class="size-6 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{
                            stepText(step.key, 'title')
                        }}</span>
                        <span class="block text-sm text-muted-foreground">{{
                            stepText(step.key, 'hint')
                        }}</span>
                    </span>
                    <ChevronRight
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                </Link>
            </li>
        </ol>

        <div class="flex flex-wrap items-center gap-2">
            <Button v-if="next" as-child class="min-h-11">
                <Link :href="next.href">{{
                    t('guide.continue', { title: stepText(next.key, 'title') })
                }}</Link>
            </Button>
            <Button as-child variant="outline" class="min-h-11">
                <Link :href="guideIndex()">{{ t('guide.see_all') }}</Link>
            </Button>
            <Button
                variant="ghost"
                class="min-h-11"
                type="button"
                @click="tour.start()"
            >
                <Compass class="size-4" aria-hidden="true" />
                {{ t('guide.start_tour') }}
            </Button>
            <!-- Étape non détectable par le serveur : l'utilisateur la coche lui-même. -->
            <Button
                v-if="next && !next.detectable"
                variant="ghost"
                class="min-h-11"
                type="button"
                @click="mark(next.key, 'done')"
            >
                {{ t('guide.mark_done') }}
            </Button>
        </div>
    </section>
</template>
