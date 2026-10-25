import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import { update as updateGuide } from '@/routes/guide';
import { update as updateStep } from '@/routes/guide/steps';
import type { GuideStatus, GuideStep, GuideSummary } from '@/types';

/**
 * Guide de démarrage de l'utilisateur : étapes adaptées à son rôle, calculées
 * par le serveur (prop partagée `guide`). Cocher, passer ou masquer passe par
 * le serveur pour que la progression suive l'utilisateur d'un appareil à l'autre.
 */
export function useGuide() {
    const page = usePage();

    const guide = computed<GuideSummary | null>(
        () => (page.props.guide as GuideSummary | null | undefined) ?? null,
    );
    const steps = computed<GuideStep[]>(() => guide.value?.steps ?? []);
    const total = computed(() => steps.value.length);
    const handled = computed(
        () => steps.value.filter((s) => s.status !== 'todo').length,
    );
    const complete = computed(
        () => total.value > 0 && handled.value === total.value,
    );
    const next = computed<GuideStep | null>(
        () => steps.value.find((s) => s.key === guide.value?.next) ?? null,
    );
    const track = computed(() => guide.value?.track ?? 'member');

    const stepText = (key: string, part: 'title' | 'hint'): string =>
        t(`guide.steps.${key}.${part}` as TranslationKey);

    /** Titre de phase du parcours de l'utilisateur, sinon le titre général. */
    function phaseTitle(phase: GuideStep['phase']): string {
        const specific = `guide.tracks.${track.value}.phases.${phase}`;
        const text = t(specific as TranslationKey);

        return text === specific
            ? t(`guide.phases.${phase}` as TranslationKey)
            : text;
    }

    function mark(key: string, status: GuideStatus): void {
        router.post(
            updateStep(key).url,
            { status },
            { preserveScroll: true, preserveState: true },
        );
    }

    function setState(values: {
        dismissed?: boolean;
        tour_seen?: boolean;
    }): void {
        router.put(updateGuide().url, values, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    return {
        guide,
        steps,
        total,
        handled,
        complete,
        next,
        track,
        stepText,
        phaseTitle,
        mark,
        setState,
    };
}
