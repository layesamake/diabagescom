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
