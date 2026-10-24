<script setup lang="ts">
/**
 * Accueil du mode simple aligné sur la maquette 01.
 *
 * Ordre visuel sur mobile :
 * 1. bandeau entreprise (vert foncé) — assumé par le shell / EnTeteEcran ;
 * 2. date du jour + « Bonjour {prénom} » + badge « Essai : N jours » quand
 *    l'entreprise est en période d'essai ;
 * 3. carte « Aujourd'hui » (chiffres du jour) ;
 * 4. quatre tuiles d'action en grille 2 × 2 (sell, cash, stock, customers) ;
 * 5. section « À regarder » : stock presque fini + crédit à récupérer ;
 * 6. section « Cette semaine » : mini-graphique en barres des 7 derniers
 *    jours, du plus ancien au plus récent.
 *
 * Les montants suivent toujours le format « 45 000 F » du cahier des
 * charges : nombre entier, espace fine, jamais « XOF ».
 */
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    Clock,
    ListChecks,
    PackageX,
    Settings2,
    ShoppingCart,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import CarteMontant from '@/components/simple/CarteMontant.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import LigneAlerte from '@/components/simple/LigneAlerte.vue';
import TuileAction from '@/components/simple/TuileAction.vue';
import { useAppContext } from '@/composables/useAppContext';
import { formatFranc } from '@/lib/money';
import {
    cash as simpleCash,
    customers as simpleCustomers,
    demo as simpleDemo,
    mode as simpleMode,
    sell as simpleSell,
    stock as simpleStock,
} from '@/routes/simple';

type Jour = { day: string; weekday: number; total: number; count: number };
type CreditAlert = { amount: number; customers: number };
type Tile = 'sell' | 'cash' | 'stock' | 'customers';

const props = defineProps<{
    figures: { today: Jour; yesterday: Jour };
    week: Jour[];
    tiles: Tile[];
    alerts: {
        low_stock?: number;
        credit_to_recover?: CreditAlert | null;
    };
}>();

const page = usePage();
const auth = page.props.auth as {
    user?: { name?: string; prenom?: string };
} | null;
const subscription = page.props.subscription as {
    trial_days_left: number | null;
    status: string;
} | null;

const { context } = useAppContext();

const prenom = computed<string>(() => {
    const name = auth?.user?.name?.trim() ?? '';
    const first = name.split(/\s+/)[0] ?? '';
    return first || 'vous';
});

const salutation = computed<string>(() => {
    const heure = new Date().getHours();
    return heure >= 18 || heure < 5 ? 'Bonsoir' : 'Bonjour';
});

const dateCourante = computed<string>(() =>
    new Date().toLocaleDateString('fr-FR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }),
);

const badgeEssai = computed<string | null>(() => {
    if (
        subscription?.status === 'trialing' &&
        subscription.trial_days_left !== null
    ) {
        return `Essai : ${subscription.trial_days_left} jours`;
    }
    return null;
});

const tendance = computed<'hausse' | 'baisse' | null>(() => {
    if (props.figures.today.total === props.figures.yesterday.total) {
        return null;
    }
    return props.figures.today.total > props.figures.yesterday.total
        ? 'hausse'
        : 'baisse';
});

const maximumSemaine = computed<number>(() =>
    props.week.reduce((max, jour) => (jour.total > max ? jour.total : max), 0),
);

const libellesJours = ['Di', 'Lu', 'Ma', 'Me', 'Je', 'Ve', 'Sa'];

const tuiles = computed(() =>
    (
        [
            {
                cle: 'sell' as const,
                libelle: 'Vendre',
                icone: ShoppingCart,
                couleur: 'vert' as const,
                href: simpleSell().url,
            },
            {
                cle: 'cash' as const,
                libelle: 'Caisse',
                icone: Wallet,
                couleur: 'orange' as const,
                href: simpleCash().url,
            },
            {
                cle: 'stock' as const,
                libelle: 'Stock',
                icone: Boxes,
                couleur: 'bleu' as const,
                href: simpleStock().url,
            },
            {
                cle: 'customers' as const,
                libelle: 'Clients',
                icone: Users,
                couleur: 'violet' as const,
                href: simpleCustomers().url,
            },
        ] satisfies {
            cle: Tile;
            libelle: string;
            icone: typeof ShoppingCart;
            couleur: 'vert' | 'orange' | 'bleu' | 'violet';
            href: string;
        }[]
    ).filter((tuile) => props.tiles.includes(tuile.cle)),
);

function ouvrir(href: string): void {
    router.get(href);
}

function versModeExpert(): void {
    router.post(simpleMode().url, { mode: 'expert' });
}
</script>

<template>
    <Head title="Accueil" />

    <EnTeteEcran class="lg:hidden" titre="Accueil" :retour-visible="false" />

    <div
        class="grid max-w-[1080px] gap-5 p-4 pb-28 lg:grid-cols-2 lg:p-0 lg:pb-0"
    >
        <h1
            class="sr-only lg:not-sr-only lg:col-span-2 lg:text-[32px] lg:font-extrabold"
        >
            Accueil
        </h1>

        <section class="lg:col-span-2">
            <p class="text-[15px] text-[var(--s-texte-doux)]">
                {{ dateCourante }}
            </p>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <p class="text-[26px] leading-tight font-extrabold">
                    {{ salutation }} {{ prenom }}
                </p>
                <span
                    v-if="badgeEssai"
                    class="inline-flex items-center rounded-full px-3 py-1 text-[15px] font-semibold"
                    :style="{
                        backgroundColor: 'var(--s-orange-clair)',
                        color: 'var(--s-sur-orange-clair)',
                    }"
                >
                    {{ badgeEssai }}
                </span>
            </div>
        </section>

        <CarteMontant
            libelle="Aujourd'hui"
            :montant="figures.today.total"
            :pastilles="[`${figures.today.count} ventes`]"
            :tendance="tendance"
        />

        <div class="grid grid-cols-2 gap-3">
            <TuileAction
                v-for="tuile in tuiles"
                :key="tuile.cle"
                :libelle="tuile.libelle"
                :icone="tuile.icone"
                :couleur="tuile.couleur"
                @appui="ouvrir(tuile.href)"
            />
        </div>

        <Link
            href="/simple/guide"
            class="flex min-h-14 items-center justify-center gap-2 rounded-[24px] text-[17px] font-bold lg:col-span-2"
            :style="{
                backgroundColor: 'var(--s-orange-clair)',
                color: 'var(--s-sur-orange-clair)',
            }"
        >
            <ListChecks :size="22" :stroke-width="2" aria-hidden="true" />
            Premiers pas
        </Link>

        <section
            v-if="alerts.low_stock || alerts.credit_to_recover"
            class="overflow-hidden rounded-[24px] lg:col-span-2"
            :style="{ border: '1px solid var(--s-bordure)' }"
        >
            <LigneAlerte
                v-if="alerts.low_stock"
                :icone="PackageX"
                couleur="rouge"
                :valeur="String(alerts.low_stock)"
                texte="articles presque finis"
            />
            <LigneAlerte
                v-if="alerts.credit_to_recover"
                :icone="Clock"
                couleur="orange"
                :valeur="formatFranc(alerts.credit_to_recover.amount)"
                :texte="`à récupérer · ${alerts.credit_to_recover.customers} client${alerts.credit_to_recover.customers > 1 ? 's' : ''}`"
            />
        </section>

        <section
            v-if="week.length > 0"
            class="rounded-[24px] p-4 lg:col-span-2"
            :style="{
                backgroundColor: 'var(--s-surface)',
                border: '1px solid var(--s-bordure)',
            }"
        >
            <h2 class="text-[20px] font-extrabold">Cette semaine</h2>
            <div class="mt-3 flex items-end justify-between gap-2">
                <div
                    v-for="(jour, index) in week"
                    :key="jour.day"
                    class="flex flex-1 flex-col items-center gap-1"
                >
                    <div
                        class="w-full rounded-t-md"
                        :style="{
                            height: `${maximumSemaine > 0 ? Math.max(8, Math.round((jour.total / maximumSemaine) * 96)) : 8}px`,
                            backgroundColor:
                                index === week.length - 1
                                    ? 'var(--s-vert-fonce)'
                                    : 'var(--s-vert-clair)',
                        }"
                        :aria-label="`${libellesJours[jour.weekday % 7]} : ${formatFranc(jour.total)}`"
                    ></div>
                    <span
                        class="text-[13px] font-semibold"
                        :style="{
                            color:
                                index === week.length - 1
                                    ? 'var(--s-vert-fonce)'
                                    : 'var(--s-texte-doux)',
                        }"
                    >
                        {{
                            index === week.length - 1
                                ? 'Auj.'
                                : libellesJours[jour.weekday % 7]
                        }}
                    </span>
                </div>
            </div>
        </section>

        <Link
            v-if="context?.role.code === 'admin'"
            :href="simpleDemo()"
            class="flex min-h-14 items-center justify-center gap-2 rounded-[24px] text-[17px] font-bold"
            :style="{
                backgroundColor: 'var(--s-surface)',
                color: 'var(--s-texte)',
                border: '1px solid var(--s-bordure)',
            }"
        >
            <Settings2 :size="22" :stroke-width="2" aria-hidden="true" />
            Voir les composants
        </Link>

        <button
            v-if="context?.role.code === 'admin'"
            type="button"
            class="min-h-14 rounded-[24px] text-[17px] font-bold"
            :style="{
                backgroundColor: 'var(--s-surface)',
                color: 'var(--s-texte-doux)',
                border: '1px solid var(--s-bordure)',
            }"
            @click="versModeExpert"
        >
            Revenir au mode expert
        </button>
    </div>
</template>
