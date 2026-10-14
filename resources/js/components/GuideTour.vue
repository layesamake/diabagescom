<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import { useAppContext } from '@/composables/useAppContext';
import { useGuide } from '@/composables/useGuide';
import { useGuideTour } from '@/composables/useGuideTour';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';

/**
 * Visite guidée : met en lumière, une à une, les zones clés de l'écran
 * (menu, entreprise et dépôt, aide, guide) avec une courte explication.
 * Les zones absentes ou invisibles (rôle sans accès, petit écran où le menu
 * est replié) sont sautées. Se lance seule à la première visite de l'accueil,
 * puis à la demande depuis le guide.
 */
type TourStep = { id: string; target?: string };

const SEQUENCE: TourStep[] = [
    { id: 'welcome' },
    { id: 'context', target: 'context' },
    { id: 'search', target: 'search' },
    { id: 'sell', target: 'nav-sell' },
    { id: 'buy', target: 'nav-buy' },
    { id: 'stock', target: 'nav-stock' },
    { id: 'manage', target: 'nav-manage' },
    { id: 'help', target: 'help' },
    { id: 'guide', target: 'nav-guide' },
    { id: 'done' },
];

const page = usePage();
const { context } = useAppContext();
const { guide, setState } = useGuide();
const { active, stop, startOnce } = useGuideTour();

const steps = ref<TourStep[]>(SEQUENCE);
const index = ref(0);
const rect = ref<DOMRect | null>(null);
const card = ref<HTMLElement | null>(null);
const nextButton = ref<{ $el: HTMLElement } | null>(null);
const cardStyle = ref<Record<string, string>>({});

const current = computed(() => steps.value[index.value]);
const isFirst = computed(() => index.value === 0);
const isLast = computed(() => index.value === steps.value.length - 1);

function targetOf(step: TourStep): HTMLElement | null {
    return step.target
        ? document.querySelector<HTMLElement>(`[data-tour="${step.target}"]`)
        : null;
}

function visible(element: HTMLElement | null): element is HTMLElement {
    if (!element) {
        return false;
    }

    const box = element.getBoundingClientRect();

    return box.width > 0 && box.height > 0;
}

const title = computed(() =>
    t(`guide.tour.${current.value.id}_title` as TranslationKey),
);
const text = computed(() =>
    t(`guide.tour.${current.value.id}_text` as TranslationKey),
);

function place(): void {
    const element = current.value ? targetOf(current.value) : null;
    const box = visible(element) ? element.getBoundingClientRect() : null;
    rect.value = box;

    const width = Math.min(360, window.innerWidth - 32);
    const height = card.value?.offsetHeight ?? 200;
    let left = (window.innerWidth - width) / 2;
    let top = (window.innerHeight - height) / 2;

    if (box) {
        const roomRight = window.innerWidth - box.right - 16;

        if (roomRight >= width && box.left < window.innerWidth / 2) {
            // Menu latéral : l'explication s'affiche à sa droite.
            left = box.right + 16;
            top = box.top;
        } else {
            left = box.left;
            top = box.bottom + 12;

            if (top + height > window.innerHeight - 16) {
                top = Math.max(16, box.top - height - 12);
            }
        }
    }

    left = Math.min(Math.max(16, left), window.innerWidth - width - 16);
    top = Math.min(Math.max(16, top), window.innerHeight - height - 16);
    cardStyle.value = {
        width: `${width}px`,
        left: `${left}px`,
        top: `${top}px`,
    };
}

async function show(): Promise<void> {
    await nextTick();
    const element = current.value ? targetOf(current.value) : null;

    if (visible(element)) {
        element.scrollIntoView({ block: 'nearest' });
    }

    place();
    await nextTick();
    place();
    nextButton.value?.$el?.focus();
}

function begin(): void {
    // Seules les zones réellement visibles pour cet utilisateur sont montrées.
    steps.value = SEQUENCE.filter(
        (step) => !step.target || visible(targetOf(step)),
    );
    index.value = 0;
    void show();
}

function close(): void {
    stop();
    rect.value = null;

    if (guide.value && !guide.value.tour_seen) {
        setState({ tour_seen: true });
    }
}

function go(delta: number): void {
    const target = index.value + delta;

    if (target < 0) {
        return;
    }

    if (target >= steps.value.length) {
        close();

        return;
    }

    index.value = target;
    void show();
}

function onKey(event: KeyboardEvent): void {
    if (!active.value) {
        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        close();
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        go(1);
    } else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        go(-1);
    }
}

watch(active, (on) => {
    if (on) {
        begin();
    }
});

onMounted(() => {
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    window.addEventListener('keydown', onKey);

    // Première visite de l'accueil : la visite démarre d'elle-même, une fois.
    if (
        page.component === 'Dashboard' &&
        context.value &&
        guide.value &&
        !guide.value.tour_seen
    ) {
        window.setTimeout(() => {
            if (!guide.value?.tour_seen) {
                startOnce();
            }
        }, 800);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', place);
    window.removeEventListener('scroll', place, true);
    window.removeEventListener('keydown', onKey);
});
</script>

<template>
    <div
        v-if="active && current"
        class="fixed inset-0 z-[100]"
        role="dialog"
        aria-modal="true"
        :aria-label="t('guide.tour.label')"
    >
        <!-- Zone mise en lumière : l'ombre géante assombrit tout le reste. -->
        <div
            v-if="rect"
            class="pointer-events-none fixed rounded-lg ring-2 ring-primary transition-all duration-200"
            :style="{
                left: `${rect.left - 6}px`,
                top: `${rect.top - 6}px`,
                width: `${rect.width + 12}px`,
                height: `${rect.height + 12}px`,
                boxShadow: '0 0 0 9999px rgba(0, 0, 0, 0.6)',
            }"
        />
        <div v-else class="fixed inset-0 bg-black/60" @click="close" />

        <div
            ref="card"
            class="fixed space-y-3 rounded-xl border bg-card p-4 text-card-foreground shadow-xl"
            :style="cardStyle"
        >
            <div class="flex items-start gap-2">
                <div class="min-w-0 flex-1 space-y-1">
                    <p class="text-xs font-medium text-muted-foreground">
                        {{
                            t('guide.tour.step_of', {
                                n: index + 1,
                                total: steps.length,
                            })
                        }}
                    </p>
                    <h2 class="text-base font-semibold">{{ title }}</h2>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    class="-mt-1 -mr-1 size-9"
                    type="button"
                    :aria-label="t('guide.tour.skip')"
                    :title="t('guide.tour.skip')"
                    @click="close"
                >
                    <X class="size-4" />
                </Button>
            </div>

            <p class="text-sm leading-relaxed">{{ text }}</p>

            <div class="flex items-center justify-between gap-2 pt-1">
                <Button
                    variant="ghost"
                    class="min-h-11"
                    type="button"
                    @click="close"
                >
                    {{ t('guide.tour.skip') }}
                </Button>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="!isFirst"
                        variant="outline"
                        class="min-h-11"
                        type="button"
                        @click="go(-1)"
                    >
                        {{ t('guide.tour.previous') }}
                    </Button>
                    <Button
                        ref="nextButton"
                        class="min-h-11"
                        type="button"
                        @click="go(1)"
                    >
                        {{
                            isLast
                                ? t('guide.tour.finish')
                                : t('guide.tour.next')
                        }}
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
