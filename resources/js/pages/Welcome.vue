<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    BookOpenCheck,
    Boxes,
    Check,
    CircleCheck,
    CloudOff,
    CloudUpload,
    FileText,
    Lock,
    Mail,
    MessageCircle,
    NotebookPen,
    PackageX,
    Receipt,
    ScanBarcode,
    ShieldCheck,
    ShoppingCart,
    Smartphone,
    Truck,
    Users,
    Wallet,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import { formatXof } from '@/lib/money';
import { dashboard, login, register } from '@/routes';
import { privacy, terms } from '@/routes/legal';

type Plan = {
    code: string;
    name: string;
    warehouse_limit: number | null;
    included_users: number;
    accounting_enabled: boolean;
    price_monthly: number | null;
    price_yearly: number | null;
};

const props = defineProps<{
    plans: Plan[];
    addons: {
        code: string;
        label: string;
        price_monthly: number;
        price_yearly: number | null;
    }[];
    recommended: string | null;
    trialDays: number;
    contact: { whatsapp: string | null; email: string | null };
    testimonials: { quote: string; name: string; business?: string }[];
}>();

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));
const yearly = ref(false);

const whatsappUrl = computed(() =>
    props.contact.whatsapp
        ? `https://wa.me/${props.contact.whatsapp}?text=${encodeURIComponent('Bonjour, je souhaite en savoir plus sur DIABA GESCOM.')}`
        : null,
);
const contactUrl = computed(
    () =>
        whatsappUrl.value ??
        (props.contact.email ? `mailto:${props.contact.email}` : null),
);

const problems = [
    {
        icon: NotebookPen,
        before: 'Le cahier de ventes',
        after: 'Chaque vente est enregistrée, retrouvée en une seconde.',
    },
    {
        icon: Wallet,
        before: 'L’écart de caisse inexpliqué',
        after: 'Ouverture, mouvements et comptage guidé : vous savez où est chaque franc.',
    },
    {
        icon: PackageX,
        before: 'La rupture de stock',
        after: 'Le stock suit chaque vente et chaque achat, avec alerte avant d’être à court.',
    },
    {
        icon: FileText,
        before: 'La facture faite à la main',
        after: 'Devis, facture et reçu PDF prêts en un clic, envoyés par WhatsApp.',
    },
];

const features = [
    {
        icon: ScanBarcode,
        title: 'Vente comptoir et scan',
        text: 'Scannez avec la caméra du téléphone ou une douchette, encaissez en espèces, Wave ou Orange Money.',
    },
    {
        icon: Wallet,
        title: 'Caisse',
        text: 'Fond de caisse, dépenses, entrées et fermeture avec comptage billet par billet.',
    },
    {
        icon: Boxes,
        title: 'Stock multi-dépôts',
        text: 'Boutique, magasin, entrepôt : transferts, inventaires et valeur du stock.',
    },
    {
        icon: Truck,
        title: 'Achats et fournisseurs',
        text: 'Bons de commande, réceptions, factures fournisseurs et dettes à payer.',
    },
    {
        icon: Receipt,
        title: 'Devis, factures, reçus',
        text: 'Documents PDF à votre nom, numérotés automatiquement, imprimables sur ticket.',
    },
    {
        icon: MessageCircle,
        title: 'Partage WhatsApp',
        text: 'Envoyez une facture ou un devis à votre client en un message.',
    },
    {
        icon: CloudOff,
        title: 'Hors connexion',
        text: 'Coupure internet ? Continuez à vendre : tout se synchronise au retour du réseau.',
    },
    {
        icon: BookOpenCheck,
        title: 'Comptabilité SYSCOHADA',
        text: 'Écritures générées par vos ventes et achats, journaux, balance et rapprochement bancaire.',
    },
    {
        icon: Users,
        title: 'Collaborateurs',
        text: 'Vendeurs, caissiers, gérants : chacun voit seulement ce qui le concerne.',
    },
    {
        icon: CloudUpload,
        title: 'Sauvegarde Google Drive',
        text: 'Une copie de vos données dans votre propre Google Drive, en plus de nos sauvegardes.',
    },
];

const steps = [
    {
        title: 'Créez votre compte',
        text: 'Votre nom, votre e-mail et le nom de votre commerce. Moins de 2 minutes.',
    },
    {
        title: 'Ajoutez vos articles',
        text: 'Un par un, en scannant les codes-barres, ou en important votre liste Excel (CSV).',
    },
    {
        title: 'Vendez',
        text: 'Ouvrez la caisse et encaissez : stock, reçus et chiffres suivent tout seuls.',
    },
];

const trust = [
    {
        icon: CloudUpload,
        title: 'Sauvegardé chaque jour',
        text: 'Copies chiffrées quotidiennes, conservées hors du serveur.',
    },
    {
        icon: Lock,
        title: 'Accès sécurisés',
        text: 'Connexion chiffrée, double authentification, journal de toutes les actions sensibles.',
    },
    {
        icon: ShieldCheck,
        title: 'Vos données restent à vous',
        text: 'Jamais vendues ni utilisées pour de la publicité. Export complet à tout moment.',
    },
    {
        icon: Smartphone,
        title: 'Paiement simple',
        text: 'Abonnement réglé par Wave, Orange Money ou virement.',
    },
];

const faq = computed(() => [
    {
        q: 'Faut-il une connexion internet permanente ?',
        a: 'Non. La vente comptoir continue sans réseau et tout se synchronise automatiquement dès que la connexion revient.',
    },
    {
        q: 'L’essai gratuit est-il vraiment sans engagement ?',
        a: `Oui. Vous profitez de toutes les fonctions pendant ${props.trialDays} jours, sans carte bancaire. Vous choisissez ensuite votre formule, ou vous arrêtez.`,
    },
    {
        q: 'Sur quels appareils fonctionne DIABA GESCOM ?',
        a: 'Sur téléphone, tablette et ordinateur, dans le navigateur. Vous pouvez aussi l’installer comme une application, sans passer par un magasin d’applications.',
    },
    {
        q: 'J’ai plusieurs boutiques ou un entrepôt, est-ce possible ?',
        a: 'Oui. Chaque dépôt a son propre stock et sa caisse ; vous suivez tout depuis un seul compte. Le nombre de dépôts dépend de la formule.',
    },
    {
        q: 'Mon comptable peut-il utiliser les données ?',
        a: 'Avec les formules Compta, les écritures SYSCOHADA sont générées automatiquement ; journaux, grand livre et balance s’exportent pour votre comptable.',
    },
    {
        q: 'Que deviennent mes données si j’arrête ?',
        a: 'Vous pouvez exporter toutes vos données à tout moment. Elles vous appartiennent.',
    },
    {
        q: 'Puis-je changer de formule plus tard ?',
        a: 'Oui, à tout moment depuis vos paramètres, et ajouter des utilisateurs ou des dépôts selon vos besoins.',
    },
]);

function planPrice(plan: Plan): number | null {
    return yearly.value ? plan.price_yearly : plan.price_monthly;
}

function yearlySaving(plan: Plan): number | null {
    if (!plan.price_monthly || !plan.price_yearly) {
        return null;
    }

    const saving = Math.round(
        (1 - plan.price_yearly / (plan.price_monthly * 12)) * 100,
    );

    return saving > 0 ? saving : null;
}

function planFeatures(plan: Plan): { ok: boolean; text: string }[] {
    return [
        {
            ok: true,
            text: `${plan.included_users} utilisateur${plan.included_users > 1 ? 's' : ''} inclus`,
        },
        {
            ok: true,
            text:
                plan.warehouse_limit === null
                    ? 'Dépôts illimités'
                    : `${plan.warehouse_limit} dépôt${plan.warehouse_limit > 1 ? 's' : ''}`,
        },
        { ok: true, text: 'Ventes, caisse, stock et achats' },
        { ok: true, text: 'Factures PDF et partage WhatsApp' },
        { ok: true, text: 'Mode hors connexion' },
        { ok: plan.accounting_enabled, text: 'Comptabilité SYSCOHADA' },
    ];
}

const pricesKnown = computed(() =>
    props.plans.some((plan) => plan.price_monthly !== null),
);
// Bascule mensuel/annuel seulement si au moins un prix annuel est fixé.
const yearlyKnown = computed(() =>
    props.plans.some((plan) => plan.price_yearly !== null),
);
</script>

<template>
    <Head title="Ventes, stock et caisse pour votre commerce" />

    <div class="min-h-screen bg-background text-foreground">
        <!-- En-tête -->
        <header
            class="sticky top-0 z-30 border-b bg-background/90 backdrop-blur"
        >
            <div
                class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3"
            >
                <Link
                    :href="'/'"
                    class="flex items-center"
                    aria-label="DIABA GESCOM, accueil"
                    ><AppLogo
                /></Link>
                <nav
                    class="hidden items-center gap-6 text-sm text-muted-foreground md:flex"
                    aria-label="Sections"
                >
                    <a href="#fonctions" class="hover:text-foreground"
                        >Fonctions</a
                    >
                    <a href="#tarifs" class="hover:text-foreground">Tarifs</a>
                    <a href="#faq" class="hover:text-foreground">Questions</a>
                    <a href="#contact" class="hover:text-foreground">Contact</a>
                </nav>
                <div class="flex items-center gap-2">
                    <Button v-if="signedIn" as-child class="h-11">
                        <Link :href="dashboard()">Mon espace</Link>
                    </Button>
                    <template v-else>
                        <Button as-child variant="ghost" class="h-11">
                            <Link :href="login()">Se connecter</Link>
                        </Button>
                        <Button as-child class="hidden h-11 sm:inline-flex">
                            <Link :href="register()">Essai gratuit</Link>
                        </Button>
                    </template>
                </div>
            </div>
        </header>

        <main>
            <!-- Accroche -->
            <section class="overflow-hidden">
                <div
                    class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-12 md:py-20 lg:grid-cols-2"
                >
                    <div class="space-y-6">
                        <p
                            class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm text-muted-foreground"
                        >
                            <span
                                class="size-2 rounded-full bg-[var(--color-brand-teal-light)]"
                                aria-hidden="true"
                            />
                            Conçu pour les commerces du Sénégal
                        </p>
                        <h1
                            class="text-4xl font-bold tracking-tight text-balance md:text-5xl"
                        >
                            Gérez ventes, stock et caisse de votre boutique,
                            <span
                                class="text-[var(--color-brand-teal)] dark:text-[var(--color-brand-teal-light)]"
                                >même sans connexion</span
                            >.
                        </h1>
                        <p class="max-w-xl text-lg text-muted-foreground">
                            DIABA GESCOM remplace le cahier, la calculatrice et
                            les factures faites à la main. Sur votre téléphone
                            comme sur l’ordinateur, en francs CFA.
                        </p>
                        <div class="flex flex-col gap-3 sm:flex-row">
                            <Button as-child size="lg" class="h-12 text-base">
                                <Link
                                    :href="signedIn ? dashboard() : register()"
                                    >Essai gratuit de {{ trialDays }} jours
                                    <ArrowRight
                                        class="size-4"
                                        aria-hidden="true"
                                /></Link>
                            </Button>
                            <Button
                                as-child
                                size="lg"
                                variant="outline"
                                class="h-12 text-base"
                            >
                                <a href="#fonctions">Voir ce que ça fait</a>
                            </Button>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Sans carte bancaire · Sans installation · Annulable
                            à tout moment
                        </p>
                    </div>

                    <!-- Aperçu de l'application (dessiné en CSS : rien à télécharger) -->
                    <div
                        class="relative mx-auto w-full max-w-lg"
                        aria-hidden="true"
                    >
                        <div
                            class="hidden rounded-xl border bg-card p-3 shadow-xl sm:block"
                        >
                            <div class="mb-3 flex gap-1.5">
                                <span class="size-2.5 rounded-full bg-muted" />
                                <span class="size-2.5 rounded-full bg-muted" />
                                <span class="size-2.5 rounded-full bg-muted" />
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <div
                                    v-for="card in [
                                        ['Ventes du jour', 486500],
                                        ['En caisse', 312000],
                                        ['À encaisser', 95000],
                                    ]"
                                    :key="card[0]"
                                    class="rounded-lg border p-2"
                                >
                                    <p
                                        class="text-[10px] text-muted-foreground"
                                    >
                                        {{ card[0] }}
                                    </p>
                                    <p class="text-xs font-bold tabular-nums">
                                        {{ formatXof(card[1] as number) }}
                                    </p>
                                </div>
                            </div>
                            <div
                                class="mt-3 flex h-28 items-end gap-1.5 rounded-lg border p-2"
                            >
                                <span
                                    v-for="(h, i) in [
                                        40, 55, 35, 70, 60, 85, 50, 75, 90, 65,
                                        80, 95,
                                    ]"
                                    :key="i"
                                    class="flex-1 rounded-t bg-[var(--color-brand-navy)]/80 dark:bg-[var(--color-brand-teal-light)]/70"
                                    :style="{ height: `${h}%` }"
                                />
                            </div>
                        </div>
                        <div
                            class="mx-auto w-56 rounded-[2rem] border-4 border-foreground/80 bg-card p-3 shadow-2xl sm:absolute sm:-bottom-10 sm:-left-4 sm:mx-0"
                        >
                            <p class="mb-2 text-xs font-semibold">
                                Vente comptoir
                            </p>
                            <ul class="space-y-1.5 text-[11px]">
                                <li
                                    v-for="line in [
                                        ['Riz parfumé 5 kg', 2, 8500],
                                        ['Huile 1 L', 3, 1500],
                                        ['Sucre 1 kg', 1, 750],
                                    ]"
                                    :key="line[0]"
                                    class="flex justify-between gap-2 rounded border p-1.5"
                                >
                                    <span class="truncate"
                                        >{{ line[1] }} × {{ line[0] }}</span
                                    >
                                    <span class="tabular-nums">{{
                                        formatXof(
                                            (line[1] as number) *
                                                (line[2] as number),
                                        )
                                    }}</span>
                                </li>
                            </ul>
                            <div
                                class="mt-3 rounded-lg bg-primary p-2 text-center text-xs font-semibold text-primary-foreground"
                            >
                                Encaisser {{ formatXof(22250) }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Problèmes résolus -->
            <section class="border-y bg-muted/40">
                <div class="mx-auto max-w-6xl px-4 py-14">
                    <h2
                        class="mb-8 text-center text-2xl font-bold tracking-tight md:text-3xl"
                    >
                        Fini les soucis du quotidien
                    </h2>
                    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <li
                            v-for="p in problems"
                            :key="p.before"
                            class="rounded-xl border bg-card p-5"
                        >
                            <component
                                :is="p.icon"
                                class="mb-3 size-6 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <p class="font-semibold">
                                <s class="decoration-danger/70">{{
                                    p.before
                                }}</s>
                            </p>
                            <p class="mt-2 text-sm text-muted-foreground">
                                {{ p.after }}
                            </p>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Fonctions -->
            <section id="fonctions" class="scroll-mt-20">
                <div class="mx-auto max-w-6xl px-4 py-16">
                    <div class="mx-auto mb-10 max-w-2xl text-center">
                        <h2
                            class="text-2xl font-bold tracking-tight md:text-3xl"
                        >
                            Tout votre commerce dans une seule application
                        </h2>
                        <p class="mt-3 text-muted-foreground">
                            De la vente au comptoir jusqu’à la comptabilité,
                            sans ressaisie.
                        </p>
                    </div>
                    <ul
                        class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
                    >
                        <li
                            v-for="f in features"
                            :key="f.title"
                            class="rounded-xl border p-5"
                        >
                            <span
                                class="mb-3 inline-flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary"
                            >
                                <component
                                    :is="f.icon"
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <h3 class="font-semibold">{{ f.title }}</h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ f.text }}
                            </p>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Comment ça marche -->
            <section class="border-y bg-muted/40">
                <div class="mx-auto max-w-6xl px-4 py-16">
                    <h2
                        class="mb-10 text-center text-2xl font-bold tracking-tight md:text-3xl"
                    >
                        Prêt à vendre en 3 étapes
                    </h2>
                    <ol class="grid gap-6 md:grid-cols-3">
                        <li
                            v-for="(step, i) in steps"
                            :key="step.title"
                            class="flex gap-4"
                        >
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary text-lg font-bold text-primary-foreground"
                                >{{ i + 1 }}</span
                            >
                            <div>
                                <h3 class="font-semibold">{{ step.title }}</h3>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ step.text }}
                                </p>
                            </div>
                        </li>
                    </ol>
                    <div class="mt-10 text-center">
                        <Button as-child size="lg" class="h-12 text-base">
                            <Link :href="signedIn ? dashboard() : register()"
                                ><ShoppingCart
                                    class="size-4"
                                    aria-hidden="true"
                                />Commencer maintenant</Link
                            >
                        </Button>
                    </div>
                </div>
            </section>

            <!-- Tarifs -->
            <section id="tarifs" class="scroll-mt-20">
                <div class="mx-auto max-w-6xl px-4 py-16">
                    <div class="mx-auto mb-8 max-w-2xl text-center">
                        <h2
                            class="text-2xl font-bold tracking-tight md:text-3xl"
                        >
                            Des formules adaptées à votre taille
                        </h2>
                        <p class="mt-3 text-muted-foreground">
                            {{ trialDays }} jours d’essai gratuit avec toutes
                            les fonctions. Prix en francs CFA (XOF).
                        </p>
                    </div>

                    <div
                        v-if="yearlyKnown"
                        class="mb-8 flex justify-center"
                        role="radiogroup"
                        aria-label="Période de facturation"
                    >
                        <div class="inline-flex rounded-lg border p-1">
                            <button
                                type="button"
                                role="radio"
                                :aria-checked="!yearly"
                                class="min-h-10 rounded-md px-4 text-sm font-medium"
                                :class="
                                    !yearly
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground'
                                "
                                @click="yearly = false"
                            >
                                Mensuel
                            </button>
                            <button
                                type="button"
                                role="radio"
                                :aria-checked="yearly"
                                class="min-h-10 rounded-md px-4 text-sm font-medium"
                                :class="
                                    yearly
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground'
                                "
                                @click="yearly = true"
                            >
                                Annuel
                            </button>
                        </div>
                    </div>

                    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <li
                            v-for="plan in plans"
                            :key="plan.code"
                            class="relative flex flex-col rounded-xl border p-5"
                            :class="
                                plan.code === recommended
                                    ? 'border-primary shadow-lg ring-1 ring-primary'
                                    : ''
                            "
                        >
                            <p
                                v-if="plan.code === recommended"
                                class="absolute -top-3 left-5 rounded-full bg-primary px-3 py-0.5 text-xs font-semibold text-primary-foreground"
                            >
                                Le plus complet
                            </p>
                            <h3 class="text-lg font-semibold">
                                {{ plan.name }}
                            </h3>
                            <div class="mt-3 min-h-16">
                                <template v-if="planPrice(plan) !== null">
                                    <p class="text-3xl font-bold tabular-nums">
                                        {{ formatXof(planPrice(plan)!) }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ yearly ? 'par an' : 'par mois' }}
                                        <template
                                            v-if="yearly && yearlySaving(plan)"
                                        >
                                            · {{ yearlySaving(plan) }} %
                                            d’économie</template
                                        >
                                    </p>
                                </template>
                                <p v-else class="text-muted-foreground">
                                    Tarif bientôt disponible
                                </p>
                            </div>
                            <ul class="my-5 flex-1 space-y-2 text-sm">
                                <li
                                    v-for="item in planFeatures(plan)"
                                    :key="item.text"
                                    class="flex items-start gap-2"
                                    :class="
                                        item.ok ? '' : 'text-muted-foreground'
                                    "
                                >
                                    <Check
                                        v-if="item.ok"
                                        class="mt-0.5 size-4 shrink-0 text-success"
                                        aria-hidden="true"
                                    />
                                    <X
                                        v-else
                                        class="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span
                                        ><span v-if="!item.ok" class="sr-only"
                                            >Non inclus : </span
                                        >{{ item.text }}</span
                                    >
                                </li>
                            </ul>
                            <Button
                                as-child
                                class="h-11"
                                :variant="
                                    plan.code === recommended
                                        ? 'default'
                                        : 'outline'
                                "
                            >
                                <Link
                                    :href="signedIn ? dashboard() : register()"
                                    >Essayer gratuitement</Link
                                >
                            </Button>
                        </li>
                    </ul>

                    <p
                        v-if="addons.length"
                        class="mt-6 text-center text-sm text-muted-foreground"
                    >
                        Besoin de plus ?
                        <template v-for="(addon, i) in addons" :key="addon.code"
                            >{{ i ? ' · ' : '' }}{{ addon.label }} :
                            {{
                                formatXof(
                                    yearly && addon.price_yearly !== null
                                        ? addon.price_yearly
                                        : addon.price_monthly,
                                )
                            }}
                            /
                            {{
                                yearly && addon.price_yearly !== null
                                    ? 'an'
                                    : 'mois'
                            }}</template
                        >
                    </p>
                    <p
                        v-if="!pricesKnown && contactUrl"
                        class="mt-6 text-center text-sm"
                    >
                        <a
                            :href="contactUrl"
                            class="underline underline-offset-4"
                            target="_blank"
                            rel="noopener"
                            >Demandez-nous les tarifs</a
                        >
                    </p>
                </div>
            </section>

            <!-- Confiance -->
            <section class="border-y bg-muted/40">
                <div class="mx-auto max-w-6xl px-4 py-16">
                    <h2
                        class="mb-10 text-center text-2xl font-bold tracking-tight md:text-3xl"
                    >
                        Vos données sont entre de bonnes mains
                    </h2>
                    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <li
                            v-for="item in trust"
                            :key="item.title"
                            class="rounded-xl border bg-card p-5"
                        >
                            <component
                                :is="item.icon"
                                class="mb-3 size-6 text-success"
                                aria-hidden="true"
                            />
                            <h3 class="font-semibold">{{ item.title }}</h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ item.text }}
                            </p>
                        </li>
                    </ul>

                    <ul
                        v-if="testimonials.length"
                        class="mt-12 grid gap-4 md:grid-cols-3"
                    >
                        <li
                            v-for="item in testimonials"
                            :key="item.name"
                            class="rounded-xl border bg-card p-5"
                        >
                            <blockquote class="text-sm">
                                « {{ item.quote }} »
                            </blockquote>
                            <p class="mt-3 text-sm font-semibold">
                                {{ item.name
                                }}<span
                                    v-if="item.business"
                                    class="font-normal text-muted-foreground"
                                >
                                    · {{ item.business }}</span
                                >
                            </p>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Questions fréquentes -->
            <section id="faq" class="scroll-mt-20">
                <div class="mx-auto max-w-3xl px-4 py-16">
                    <h2
                        class="mb-8 text-center text-2xl font-bold tracking-tight md:text-3xl"
                    >
                        Questions fréquentes
                    </h2>
                    <div class="divide-y rounded-xl border">
                        <details
                            v-for="item in faq"
                            :key="item.q"
                            class="group p-4"
                        >
                            <summary
                                class="flex min-h-8 cursor-pointer list-none items-center justify-between gap-3 font-medium"
                            >
                                {{ item.q }}
                                <span
                                    class="text-xl text-muted-foreground transition group-open:rotate-45"
                                    aria-hidden="true"
                                    >+</span
                                >
                            </summary>
                            <p class="mt-2 text-sm text-muted-foreground">
                                {{ item.a }}
                            </p>
                        </details>
                    </div>
                </div>
            </section>

            <!-- Contact -->
            <section
                id="contact"
                class="scroll-mt-20 bg-[var(--color-brand-navy)] text-white"
            >
                <div class="mx-auto max-w-4xl px-4 py-16 text-center">
                    <h2 class="text-2xl font-bold tracking-tight md:text-3xl">
                        Une question ? Parlons-en.
                    </h2>
                    <p class="mx-auto mt-3 max-w-xl text-white/80">
                        Nous vous aidons à démarrer : import de vos articles,
                        réglages, formation de vos vendeurs.
                    </p>
                    <div
                        class="mt-8 flex flex-col justify-center gap-3 sm:flex-row"
                    >
                        <Button
                            v-if="whatsappUrl"
                            as-child
                            size="lg"
                            class="h-12 bg-[#1f9d55] text-base text-white hover:bg-[#1a8549]"
                        >
                            <a
                                :href="whatsappUrl"
                                target="_blank"
                                rel="noopener"
                                ><MessageCircle
                                    class="size-5"
                                    aria-hidden="true"
                                />Écrire sur WhatsApp</a
                            >
                        </Button>
                        <Button
                            v-if="contact.email"
                            as-child
                            size="lg"
                            variant="outline"
                            class="h-12 border-white/40 bg-transparent text-base text-white hover:bg-white/10 hover:text-white"
                        >
                            <a :href="`mailto:${contact.email}`"
                                ><Mail class="size-5" aria-hidden="true" />{{
                                    contact.email
                                }}</a
                            >
                        </Button>
                    </div>
                    <p
                        v-if="!signedIn"
                        class="mt-8 inline-flex items-center gap-2 text-sm text-white/80"
                    >
                        <CircleCheck class="size-4" aria-hidden="true" />
                        Ou essayez tout de suite :
                        <Link
                            :href="register()"
                            class="font-semibold text-white underline underline-offset-4"
                            >créer mon compte</Link
                        >
                    </p>
                </div>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
            >
                <p>© {{ new Date().getFullYear() }} DIABA GESCOM · Sénégal</p>
                <nav class="flex flex-wrap gap-4" aria-label="Informations">
                    <Link :href="privacy()" class="hover:text-foreground"
                        >Confidentialité</Link
                    >
                    <Link :href="terms()" class="hover:text-foreground"
                        >Conditions d’utilisation</Link
                    >
                    <Link
                        v-if="!signedIn"
                        :href="login()"
                        class="hover:text-foreground"
                        >Se connecter</Link
                    >
                </nav>
            </div>
        </footer>
    </div>
</template>
