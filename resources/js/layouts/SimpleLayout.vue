<script setup lang="ts">
/**
 * Cadre adaptatif du mode simple : navigation tactile en bas sur mobile,
 * espace de travail ordinateur avec menu latéral dès 1024 px.
 *
 * L'apparence de la barre latérale suit les maquettes D1..D7 :
 * - fond vert foncé (--s-marque), texte blanc ;
 * - Vendre en première position, mis en valeur par une pillule vert clair
 *   tant qu'il n'est pas l'écran actif ;
 * - écran actif signalé par une pillule blanche ;
 * - icônes colorées par fonction (règle 2 du cahier des charges) ;
 * - pied de page « En ligne » + initiales + nom utilisateur.
 */
import {
    BarChart3,
    Boxes,
    Home,
    Menu,
    ReceiptText,
    ShoppingCart,
    Truck,
    UsersRound,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import BarreNavBas from '@/components/simple/BarreNavBas.vue';
import { useAppContext } from '@/composables/useAppContext';
import {
    home as simpleHome,
    sell as simpleSell,
    sales as simpleSales,
} from '@/routes/simple';

const props = withDefaults(defineProps<{ actif?: string }>(), {
    actif: 'accueil',
});
const { context, can } = useAppContext();
const page = usePage();
const auth = computed(
    () => page.props.auth as { user?: { name?: string; role?: string } } | null,
);
const accueil = computed(() => simpleHome().url);
const actif = computed(() => {
    if (props.actif !== 'accueil') return props.actif;
    return (
        (
            {
                'simple/Accueil': 'accueil',
                'simple/Vendre': 'vendre',
                'simple/Caisse': 'caisse',
                'simple/Ventes': 'ventes',
                'simple/Stock': 'stock',
                'simple/Clients': 'clients',
                'simple/Achats': 'achats',
                'simple/Resultats': 'resultats',
            } as Record<string, string>
        )[page.component] ?? 'accueil'
    );
});
const peutVoirStock = computed(() => can('inventory.view'));
const peutVoirClients = computed(() => can('customer.view'));

type EntreeNav = {
    cle: string;
    libelle: string;
    icone: typeof Home;
    href: string;
    couleur: string;
    primaire: boolean;
    visible: boolean;
    actif?: boolean;
};

const COULEUR_VERT = 'var(--s-vert)';
const COULEUR_BLEU = 'var(--s-bleu)';
const COULEUR_ORANGE = 'var(--s-orange)';
const COULEUR_VIOLET = 'var(--s-violet)';

const navigationOrdinateur = computed<EntreeNav[]>(() =>
    [
        {
            cle: 'vendre',
            libelle: 'Vendre',
            icone: ShoppingCart,
            href: simpleSell().url,
            couleur: COULEUR_VERT,
            primaire: true,
            visible: true,
        },
        {
            cle: 'accueil',
            libelle: 'Accueil',
            icone: Home,
            href: accueil.value,
            couleur: 'var(--s-sur-marque)',
            primaire: false,
            visible: true,
        },
        {
            cle: 'ventes',
            libelle: 'Mes ventes',
            icone: ReceiptText,
            href: simpleSales().url,
            couleur: COULEUR_BLEU,
            primaire: false,
            visible: true,
        },
        {
            cle: 'caisse',
            libelle: 'Caisse',
            icone: Wallet,
            href: '/simple/caisse',
            couleur: COULEUR_ORANGE,
            primaire: false,
            visible: true,
        },
        {
            cle: 'stock',
            libelle: 'Stock',
            icone: Boxes,
            href: '/simple/stock',
            couleur: COULEUR_BLEU,
            primaire: false,
            visible: peutVoirStock.value,
        },
        {
            cle: 'clients',
            libelle: 'Clients',
            icone: UsersRound,
            href: '/simple/clients',
            couleur: COULEUR_VIOLET,
            primaire: false,
            visible: peutVoirClients.value,
        },
        {
            cle: 'achats',
            libelle: 'Achats',
            icone: Truck,
            href: '/simple/achats',
            couleur: COULEUR_VERT,
            primaire: false,
            visible: true,
        },
        {
            cle: 'resultats',
            libelle: 'Mes résultats',
            icone: BarChart3,
            href: '/simple/resultats',
            couleur: COULEUR_VERT,
            primaire: false,
            visible: true,
        },
    ]
        .filter((entree): entree is EntreeNav => entree.visible)
        .map((entree) => ({
            ...entree,
            actif: actif.value === entree.cle,
        })),
);

const entrees = computed<EntreeNav[]>(() =>
    [
        {
            cle: 'accueil',
            libelle: 'Accueil',
            icone: Home,
            href: accueil.value,
            couleur: 'var(--s-sur-marque)',
            primaire: false,
            visible: true,
        },
        {
            cle: 'ventes',
            libelle: 'Ventes',
            icone: ReceiptText,
            href: simpleSales().url,
            couleur: COULEUR_BLEU,
            primaire: false,
            visible: true,
        },
        {
            cle: 'vendre',
            libelle: 'Vendre',
            icone: ShoppingCart,
            href: simpleSell().url,
            couleur: COULEUR_VERT,
            primaire: true,
            visible: true,
        },
        {
            cle: 'stock',
            libelle: 'Stock',
            icone: Boxes,
            href: '/simple/stock',
            couleur: COULEUR_BLEU,
            primaire: false,
            visible: peutVoirStock.value,
        },
        {
            cle: 'clients',
            libelle: 'Clients',
            icone: UsersRound,
            href: '/simple/clients',
            couleur: COULEUR_VIOLET,
            primaire: false,
            visible: peutVoirClients.value,
        },
        {
            cle: 'plus',
            libelle: 'Plus',
            icone: Menu,
            href: accueil.value,
            couleur: 'var(--s-sur-marque)',
            primaire: false,
            visible: !peutVoirClients.value,
        },
    ].filter((entree): entree is EntreeNav => entree.visible),
);

const nomUtilisateur = computed(
    () =>
        (auth.value?.user as { name?: string } | undefined)?.name ??
        'Utilisateur',
);
const roleUtilisateur = computed(
    () => (auth.value?.user as { role?: string } | undefined)?.role ?? 'Membre',
);
const initialesUtilisateur = computed(() => {
    const parts = nomUtilisateur.value.split(/\s+/u).filter(Boolean);
    return parts
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
});
</script>

<template>
    <div
        class="mode-simple min-h-dvh lg:grid lg:grid-cols-[248px_minmax(0,1fr)]"
    >
        <aside
            class="hidden min-h-dvh flex-col gap-6 border-r p-5 lg:flex"
            :style="{
                backgroundColor: 'var(--s-marque)',
                borderColor: 'var(--s-marque)',
                color: 'var(--s-sur-marque)',
            }"
        >
            <a :href="accueil" class="flex items-center gap-3">
                <span
                    class="grid size-11 shrink-0 place-items-center rounded-[14px] text-xl font-black"
                    :style="{
                        backgroundColor: 'var(--s-sur-marque)',
                        color: 'var(--s-marque)',
                    }"
                    >D</span
                >
                <span class="min-w-0">
                    <strong class="block truncate text-[15px] font-extrabold">{{
                        context?.tenant?.name ?? 'Mon entreprise'
                    }}</strong>
                    <span class="block text-[12px] font-semibold opacity-80"
                        >Mode simple</span
                    >
                </span>
            </a>
            <nav aria-label="Navigation principale" class="grid gap-1">
                <a
                    v-for="entree in navigationOrdinateur"
                    :key="entree.cle"
                    :href="entree.href"
                    :aria-current="entree.actif ? 'page' : undefined"
                    class="flex min-h-12 items-center gap-3 rounded-[14px] px-4 text-[16px] font-bold transition-colors"
                    :style="
                        entree.actif
                            ? {
                                  backgroundColor: 'var(--s-surface)',
                                  color: 'var(--s-texte)',
                              }
                            : entree.primaire
                              ? {
                                    backgroundColor: 'var(--s-vert-clair)',
                                    color: 'var(--s-sur-vert-clair)',
                                }
                              : {
                                    backgroundColor: 'transparent',
                                    color: 'var(--s-sur-marque)',
                                }
                    "
                >
                    <component
                        :is="entree.icone"
                        :size="22"
                        :stroke-width="2"
                        :style="{
                            color: entree.actif
                                ? 'var(--s-texte)'
                                : entree.couleur,
                        }"
                        aria-hidden="true"
                    />
                    <span>{{ entree.libelle }}</span>
                </a>
            </nav>
            <div class="mt-auto grid gap-3">
                <div class="flex items-center gap-2 text-[13px] font-semibold">
                    <span
                        class="inline-block size-2 rounded-full"
                        :style="{
                            backgroundColor: 'var(--s-vert-clair)',
                        }"
                        aria-hidden="true"
                    ></span>
                    En ligne
                </div>
                <div class="flex items-center gap-3">
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-full text-[14px] font-extrabold"
                        :style="{
                            backgroundColor: 'var(--s-orange-clair)',
                            color: 'var(--s-texte)',
                        }"
                        >{{ initialesUtilisateur || '?' }}</span
                    >
                    <span class="min-w-0">
                        <strong
                            class="block truncate text-[14px] font-extrabold"
                            >{{ nomUtilisateur }}</strong
                        >
                        <span
                            class="block text-[12px] font-semibold opacity-80"
                            >{{ roleUtilisateur }}</span
                        >
                    </span>
                </div>
            </div>
        </aside>

        <main class="min-w-0">
            <div
                class="mx-auto w-full max-w-[1440px] pb-[calc(var(--s-barre-bas)+16px)] lg:px-8 lg:py-8 lg:pb-10"
            >
                <slot />
            </div>
        </main>

        <BarreNavBas class="lg:hidden" :actif="actif" :entrees="entrees" />
    </div>
</template>
