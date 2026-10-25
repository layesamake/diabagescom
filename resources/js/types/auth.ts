export type User = {
    id: string;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
};

export type Auth = {
    user: User;
};

export type ContextOption = {
    id: string;
    name: string;
};

export type WarehouseOption = ContextOption & {
    code: string;
};

/** Contexte entreprise/dépôt résolu par le serveur (null hors espace entreprise). */
export type AppContext = {
    tenant: ContextOption;
    warehouse: WarehouseOption | null;
    role: { code: string; name: string };
    ui_mode: 'simple' | 'expert';
    simple_mode_available: boolean;
    tenants: ContextOption[];
    warehouses: WarehouseOption[];
    permissions: string[];
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};

export type SubscriptionStatus =
    | 'trialing'
    | 'active'
    | 'read_only'
    | 'suspended'
    | 'cancelled';

/** État d'abonnement de l'entreprise active (affichage ; le serveur décide). */
export type SubscriptionSummary = {
    status: SubscriptionStatus;
    plan: { code: string; name: string } | null;
    trial_ends_at: string | null;
    cancel_at: string | null;
    read_only_ends_at: string | null;
    trial_days_left: number | null;
    can_write: boolean;
    features: { accounting: boolean; online_store: boolean };
};

export type PlanOption = {
    code: string;
    name: string;
    warehouse_limit: number | null;
    included_users: number;
    accounting_enabled: boolean;
    price_monthly?: number | null;
    price_yearly?: number | null;
};
