/**
 * Primitives de contexte de synchronisation (audit 2026-10-15, « Contamination
 * IndexedDB lors d'un changement de tenant »).
 *
 * Isolées ici, sans dépendance, pour être testées sans IndexedDB ni réseau
 * (voir tests/js/sync-context.test.ts). `sync.ts` les utilise pour qu'une
 * synchronisation lancée pour le compte/entreprise A ne puisse jamais écrire
 * la réponse du serveur dans la base IndexedDB active de B : chaque push/pull
 * capture un contexte immuable au démarrage et n'écrit que sur `context.db`,
 * jamais sur une variable mutable globale qui aurait pu changer de cible.
 */
export type ScopeKey = {
    userId: string | number;
    tenantId: string;
    warehouseId: string | null;
};

/** Même compte et même entreprise : la base IndexedDB active ne doit pas changer. */
export function isSameBase(previous: ScopeKey | null, next: ScopeKey): boolean {
    return (
        previous !== null &&
        previous.userId === next.userId &&
        previous.tenantId === next.tenantId
    );
}

export type SyncContext<Db> = {
    readonly generation: number;
    readonly db: Db;
    readonly scope: ScopeKey;
    readonly controller: AbortController;
    /** Jeton d'appareil au moment où le contexte a été capturé. */
    readonly deviceKey: string | null;
    /** Opération en cours sur ce contexte ; `start()`/`clear()` l'attendent avant de fermer la base. */
    inFlight: Promise<void>;
};

/**
 * Registre du contexte de synchronisation actif. `start()` abandonne (abort)
 * et renvoie l'ancien contexte sans fermer sa base : l'appelant doit laisser
 * son opération en cours se terminer (elle se requeue toute seule sur son
 * propre contexte, jamais sur le nouveau) avant de fermer la connexion.
 */
export class SyncContextRegistry<Db> {
    private active: SyncContext<Db> | null = null;
    private generationCounter = 0;

    current(): SyncContext<Db> | null {
        return this.active;
    }

    start(
        db: Db,
        scope: ScopeKey,
        deviceKey: string | null = null,
    ): { context: SyncContext<Db>; previous: SyncContext<Db> | null } {
        const previous = this.active;
        previous?.controller.abort();

        const context: SyncContext<Db> = {
            generation: ++this.generationCounter,
            db,
            scope,
            deviceKey,
            controller: new AbortController(),
            inFlight: Promise.resolve(),
        };
        this.active = context;

        return { context, previous };
    }

    /** Un contexte capturé en début d'opération reste-t-il le contexte actif ? */
    isCurrent(context: SyncContext<Db>): boolean {
        return this.active === context;
    }

    /** Déconnexion explicite : abandonne le contexte actif et le renvoie pour purge. */
    clear(): SyncContext<Db> | null {
        const previous = this.active;
        previous?.controller.abort();
        this.active = null;

        return previous;
    }
}
