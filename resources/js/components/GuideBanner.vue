<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight, Lightbulb, X } from '@lucide/vue';
import { computed, reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { useAppContext } from '@/composables/useAppContext';
import { useGuide } from '@/composables/useGuide';
import { t } from '@/i18n';
import { index as guideIndex } from '@/routes/guide';
import type { GuideStep } from '@/types';

/**
 * Bandeau discret en haut des pages liées à une étape du guide :
 *  - sur la page d'une étape à faire : « Étape 3 sur 8 » avec la suite ;
 *  - sur l'écran de vente : rappel doux (jamais bloquant) d'une étape à faire
 *    avant de vendre.
 * Masquable pour la session en cours ; le serveur ne retient rien ici.
 */
const page = usePage();
const { context } = useAppContext();
const { steps, total, stepText, mark } = useGuide();

const hidden = reactive<Record<string, boolean>>({});

const storageKey = (key: string): string =>
    `diaba.guide_banner.${context.value?.tenant.id ?? ''}.${key}`;

function isHidden(key: string): boolean {
    if (hidden[key] !== undefined) {
        return hidden[key];
    }

    try {
        hidden[key] = sessionStorage.getItem(storageKey(key)) === '1';
    } catch {
        hidden[key] = false;
    }

    return hidden[key];
}

function hide(key: string): void {
    hidden[key] = true;

    try {
        sessionStorage.setItem(storageKey(key), '1');
    } catch {
        // Stockage indisponible : masqué jusqu'au prochain chargement.
    }
}

const shown = computed<{ kind: 'own' | 'remind'; step: GuideStep } | null>(
    () => {
        const component = page.component;

        if (component === 'Dashboard' || component === 'guide/Index') {
            return null;
        }

        const todo = steps.value.filter(
            (s) => s.status === 'todo' && !isHidden(s.key),
        );
        const own = todo.find((s) => s.pages.includes(component));

        if (own) {
            return { kind: 'own', step: own };
        }

        const remind = todo.find((s) => s.remind_on.includes(component));

        return remind ? { kind: 'remind', step: remind } : null;
    },
);

const position = computed(() =>
    shown.value
        ? steps.value.findIndex((s) => s.key === shown.value?.step.key) + 1
        : 0,
);

// Étape suivante à faire, autre que celle affichée.
const following = computed(() =>
    steps.value.find(
        (s) => s.status === 'todo' && s.key !== shown.value?.step.key,
    ),
);
</script>

<template>
    <aside
        v-if="shown"
        class="mx-4 mt-3 flex flex-wrap items-center gap-3 rounded-lg border px-4 py-3 md:mx-4"
        :class="
            shown.kind === 'own'
                ? 'border-primary/40 bg-primary/5'
                : 'border-warning-border bg-warning-soft text-warning-soft-foreground'
        "
        role="note"
        :aria-label="t('guide.nav')"
    >
        <Lightbulb class="size-5 shrink-0 text-primary" aria-hidden="true" />

        <div class="min-w-0 flex-1 text-sm">
            <template v-if="shown.kind === 'own'">
                <p class="font-medium">
                    {{ t('guide.step_of', { n: position, total }) }} ·
                    {{ stepText(shown.step.key, 'title') }}
                </p>
                <p class="text-muted-foreground">
                    {{ stepText(shown.step.key, 'hint') }}
                </p>
            </template>
            <template v-else>
                <p class="font-medium">{{ t('guide.reminder_title') }}</p>
                <p>
                    {{
                        t('guide.reminder_text', {
                            title: stepText(shown.step.key, 'title'),
                            hint: stepText(shown.step.key, 'hint'),
                        })
                    }}
                </p>
            </template>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                v-if="shown.kind === 'remind'"
                as-child
                size="sm"
                class="min-h-11 md:min-h-9"
            >
                <Link :href="shown.step.href">
                    {{ t('guide.go') }}
                    <ChevronRight class="size-4" aria-hidden="true" />
                </Link>
            </Button>
            <Button
                v-if="shown.kind === 'own' && !shown.step.detectable"
                size="sm"
                class="min-h-11 md:min-h-9"
                type="button"
                @click="mark(shown.step.key, 'done')"
            >
                {{ t('guide.mark_done') }}
            </Button>
            <Button
                v-if="shown.kind === 'own' && following"
                as-child
                size="sm"
                variant="outline"
                class="min-h-11 md:min-h-9"
            >
                <Link :href="following.href">
                    {{ t('guide.banner_next') }}
                    <ChevronRight class="size-4" aria-hidden="true" />
                </Link>
            </Button>
            <Button
                as-child
                size="sm"
                variant="ghost"
                class="min-h-11 md:min-h-9"
            >
                <Link :href="guideIndex()">{{ t('guide.see_all') }}</Link>
            </Button>
            <Button
                size="icon"
                variant="ghost"
                class="size-11 md:size-9"
                type="button"
                :aria-label="t('guide.banner_hide')"
                :title="t('guide.banner_hide')"
                @click="hide(shown.step.key)"
            >
                <X class="size-4" />
            </Button>
        </div>
    </aside>
</template>
