export type GuideStatus = 'todo' | 'done' | 'skipped';

export type GuidePhase = 'before' | 'goal' | 'after';

export type GuideStep = {
    key: string;
    phase: GuidePhase;
    status: GuideStatus;
    /** Cochée toute seule parce que la donnée existe (un article, une vente…). */
    auto: boolean;
    /** Le serveur sait détecter cette étape ; sinon c'est l'utilisateur qui la coche. */
    detectable: boolean;
    href: string;
    /** Pages (composants Inertia) où le bandeau « étape en cours » s'affiche. */
    pages: string[];
    /** Pages où un rappel doux s'affiche tant que l'étape n'est pas faite. */
    remind_on: string[];
};

/** Guide de démarrage de l'utilisateur dans l'entreprise courante. */
export type GuideSummary = {
    track: string;
    steps: GuideStep[];
    next: string | null;
    dismissed: boolean;
    tour_seen: boolean;
};
