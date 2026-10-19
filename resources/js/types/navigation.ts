import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /** Petit compteur affiché à droite (ex. progression du guide). */
    badge?: string;
    /** Repère de la visite guidée (attribut data-tour). */
    tour?: string;
    /** Lien hors de DIABA, ouvert dans un nouvel onglet. */
    external?: boolean;
    /** Texte explicatif affiché au survol dans le menu réduit. */
    tooltip?: string;
};
