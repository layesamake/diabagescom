/*
 * Règles pures (sans IndexedDB) pour ne jamais perdre une vente hors ligne :
 * - une commande mise en file ne peut pas être écrasée par une autre portant le même
 *   identifiant (audit 2026-10-15, « collision locale de command_id ») ;
 * - à la déconnexion, toute commande non envoyée OU à traiter (refusée, en conflit,
 *   à vérifier) est signalée, pas seulement celles encore en file.
 */

/** Sérialisation stable (clés triées) pour comparer deux contenus de vente. */
export function canonicalPayload(value: unknown): string {
    if (Array.isArray(value)) {
        return `[${value.map(canonicalPayload).join(',')}]`;
    }

    if (value !== null && typeof value === 'object') {
        const entries = Object.entries(value as Record<string, unknown>)
            .filter(([, v]) => v !== undefined)
            .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
            .map(([k, v]) => `${JSON.stringify(k)}:${canonicalPayload(v)}`);

        return `{${entries.join(',')}}`;
    }

    return JSON.stringify(value) ?? 'null';
}

export type EnqueueDecision = 'create' | 'reuse' | 'conflict';

/**
 * Faut-il créer la commande, réutiliser celle déjà en file (même contenu : double clic
 * ou double appel), ou refuser (même identifiant, contenu différent) ?
 */
export function decideEnqueue(
    existing: { payload: unknown } | undefined,
    payload: unknown,
): EnqueueDecision {
    if (existing === undefined) {
        return 'create';
    }

    return canonicalPayload(existing.payload) === canonicalPayload(payload)
        ? 'reuse'
        : 'conflict';
}

/** Nombre de commandes qui disparaîtraient de l'appareil à la déconnexion. */
export function unsentCommandCount(counts: {
    pending: number;
    attention: number;
}): number {
    return counts.pending + counts.attention;
}
