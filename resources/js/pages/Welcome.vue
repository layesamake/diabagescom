<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    Menu,
    MessageCircle,
    Package,
    ShoppingCart,
    Speaker,
    Wallet,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { dashboard, login, register } from '@/routes';
import { privacy, terms } from '@/routes/legal';

type Plan = {
    code: string;
    name: string;
    warehouse_limit: number | null;
    included_users: number;
    accounting_enabled: boolean;
    online_store_enabled: boolean;
    price_monthly: number | null;
    price_yearly: number | null;
};

type Addon = {
    code: string;
    label: string;
    price_monthly: number;
    price_yearly: number | null;
};

const props = defineProps<{
    plans: Plan[];
    addons: Addon[];
    recommended: string | null;
    trialDays: number;
    contact: { whatsapp: string | null; email: string | null };
}>();

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));
const mobileMenuOpen = ref(false);
const whatsappNumber = ref('');
const formMessage = ref('');
const yearly = ref(false);

const whatsappUrl = computed(() =>
    props.contact.whatsapp
        ? `https://wa.me/${props.contact.whatsapp}?text=${encodeURIComponent('Bonjour, je souhaite en savoir plus sur DIABA GESCOM.')}`
        : null,
);

const ctaHref = computed(() => (signedIn.value ? dashboard() : register()));
const annualAvailable = computed(() =>
    props.plans.some((plan) => plan.price_yearly !== null),
);

const industries = [
    'Alimentation',
    'Quincaillerie',
    'Ferme et élevage',
    'Cosmétiques',
    'Pièces détachées',
    'Grossistes',
];

const problems = [
    [
        'Le cahier de ventes',
        'Chaque vente enregistrée, retrouvée en une seconde.',
    ],
    [
        'L’argent qui manque en caisse',
        'On compte les billets, l’écran dit s’il manque quelque chose.',
    ],
    ['La rupture de stock', 'L’article devient rouge avant d’être fini.'],
    [
        'Le crédit oublié',
        'Vous voyez qui vous doit combien, et l’appelez en un geste.',
    ],
];

const features = [
    ['Vente et scan', 'Caméra du téléphone ou douchette.'],
    ['Caisse', 'Ouverture, dépenses, fermeture billet par billet.'],
    ['Stock sur plusieurs dépôts', 'Boutique, magasin, entrepôt, transferts.'],
    ['Achats et fournisseurs', 'Commandes, réceptions, dettes à payer.'],
    ['Factures et reçus PDF', 'À votre logo, envoyés par WhatsApp.'],
    ['Boutique en ligne', 'Vos clients commandent depuis leur téléphone.'],
    ['Comptabilité SYSCOHADA', 'Écritures faites toutes seules.'],
    ['Vendeurs et gérants', 'Chacun voit seulement ce qui le concerne.'],
];

const faq = [
    [
        'Faut-il internet tout le temps ?',
        'Non. Vous vendez et ouvrez la caisse sans connexion. Tout se synchronise quand le réseau revient.',
    ],
    [
        'Mes vendeurs ne lisent pas bien. Ça marche quand même ?',
        'Oui. Tout se fait avec des photos, des couleurs et des gros boutons, et la voix explique chaque écran dans votre langue.',
    ],
    [
        'Sur quels appareils ?',
        'Ordinateur, tablette et téléphone, dans le navigateur. Rien à installer.',
    ],
    [
        'L’essai est-il vraiment sans engagement ?',
        'Oui. Vous avez toutes les fonctions pendant l’essai, sans carte bancaire.',
    ],
    [
        'Que deviennent mes données si j’arrête ?',
        'Elles restent à vous : export complet à tout moment, jamais vendues.',
    ],
];

function formatF(amount: number | null): string {
    return amount === null
        ? 'Sur demande'
        : `${new Intl.NumberFormat('fr-FR').format(amount)} F`;
}

function planPrice(plan: Plan): number | null {
    return yearly.value && plan.price_yearly !== null
        ? plan.price_yearly
        : plan.price_monthly;
}

function planPeriod(plan: Plan): 'an' | 'mois' {
    return yearly.value && plan.price_yearly !== null ? 'an' : 'mois';
}

function addonPrice(addon: Addon): number {
    return yearly.value && addon.price_yearly !== null
        ? addon.price_yearly
        : addon.price_monthly;
}

function addonPeriod(addon: Addon): 'an' | 'mois' {
    return yearly.value && addon.price_yearly !== null ? 'an' : 'mois';
}

function formatWhatsappNumber(number: string | null): string {
    if (!number) {
        return '';
    }

    return number.startsWith('221') && number.length === 12
        ? `+221 ${number.slice(3, 5)} ${number.slice(5, 8)} ${number.slice(8, 10)} ${number.slice(10)}`
        : `+${number}`;
}

function planDescription(plan: Plan): string {
    const warehouses =
        plan.warehouse_limit === null
            ? 'dépôts illimités'
            : `${plan.warehouse_limit} dépôt${plan.warehouse_limit > 1 ? 's' : ''}`;
    return `${plan.included_users} utilisateur${plan.included_users > 1 ? 's' : ''} · ${warehouses}`;
}

function sendWhatsAppLead(): void {
    if (!props.contact.whatsapp) {
        formMessage.value = 'Le contact WhatsApp sera bientôt disponible.';
        return;
    }

    const number = whatsappNumber.value.trim();
    if (!number) {
        formMessage.value = 'Indiquez votre numéro WhatsApp.';
        return;
    }

    const message = `Bonjour, je souhaite commencer l’essai gratuit DIABA GESCOM. Mon numéro WhatsApp : ${number}`;
    window.open(
        `https://wa.me/${props.contact.whatsapp}?text=${encodeURIComponent(message)}`,
        '_blank',
        'noopener,noreferrer',
    );
    formMessage.value =
        'WhatsApp est ouvert : envoyez le message pour démarrer votre essai.';
}
</script>

<template>
    <Head title="DIABA GESCOM — la caisse qui parle votre langue" />

    <div class="min-h-screen bg-[#f1f4f3] font-sans text-[#10201c]">
        <header class="sticky top-0 z-40 border-b border-[#d5dedb] bg-white">
            <div
                class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3 md:px-6"
            >
                <Link
                    :href="'/'"
                    class="mr-auto flex items-center"
                    aria-label="DIABA GESCOM, accueil"
                >
                    <AppLogo />
                </Link>
                <nav
                    class="hidden items-center gap-6 text-sm font-bold md:flex"
                    aria-label="Sections"
                >
                    <a href="#comment" class="hover:text-[#1e9c80]"
                        >Comment ça marche</a
                    >
                    <a href="#prix" class="hover:text-[#1e9c80]">Prix</a>
                    <a href="#questions" class="hover:text-[#1e9c80]"
                        >Questions</a
                    >
                </nav>
                <Link
                    v-if="!signedIn"
                    :href="login()"
                    class="hidden text-sm font-bold text-[#0a3f37] md:block"
                    >Se connecter</Link
                >
                <Link
                    v-else
                    :href="dashboard()"
                    class="hidden text-sm font-bold text-[#0a3f37] md:block"
                    >Mon espace</Link
                >
                <Link
                    :href="ctaHref"
                    class="hidden min-h-12 items-center rounded-2xl bg-[#34b89c] px-5 text-sm font-extrabold text-[#04201a] md:flex"
                    >Essayer gratuitement</Link
                >
                <button
                    type="button"
                    class="grid size-12 place-items-center rounded-xl border-2 border-[#d5dedb] md:hidden"
                    :aria-expanded="mobileMenuOpen"
                    aria-label="Ouvrir le menu"
                    @click="mobileMenuOpen = !mobileMenuOpen"
                >
                    <X
                        v-if="mobileMenuOpen"
                        class="size-5"
                        aria-hidden="true"
                    />
                    <Menu v-else class="size-5" aria-hidden="true" />
                </button>
            </div>
            <nav
                v-if="mobileMenuOpen"
                class="border-t border-[#d5dedb] bg-white px-4 py-3 md:hidden"
                aria-label="Menu mobile"
            >
                <div class="mx-auto flex max-w-6xl flex-col font-bold">
                    <a
                        href="#comment"
                        class="rounded-xl px-3 py-3 hover:bg-[#f1f4f3]"
                        @click="mobileMenuOpen = false"
                        >Comment ça marche</a
                    >
                    <a
                        href="#prix"
                        class="rounded-xl px-3 py-3 hover:bg-[#f1f4f3]"
                        @click="mobileMenuOpen = false"
                        >Prix</a
                    >
                    <a
                        href="#questions"
                        class="rounded-xl px-3 py-3 hover:bg-[#f1f4f3]"
                        @click="mobileMenuOpen = false"
                        >Questions</a
                    >
                    <Link
                        :href="signedIn ? dashboard() : login()"
                        class="rounded-xl px-3 py-3 hover:bg-[#f1f4f3]"
                        @click="mobileMenuOpen = false"
                        >{{ signedIn ? 'Mon espace' : 'Se connecter' }}</Link
                    >
                </div>
            </nav>
        </header>

        <main>
            <section id="haut" class="overflow-hidden bg-[#0a3f37] text-white">
                <div
                    class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-12 sm:px-6 md:py-18 lg:grid-cols-[1.05fr_.95fr] lg:py-20"
                >
                    <div class="space-y-6">
                        <p
                            class="inline-flex rounded-full bg-[#0f5247] px-3 py-2 text-xs font-extrabold tracking-wider text-[#bfe6dc]"
                        >
                            POUR LES COMMERÇANTS DU SÉNÉGAL ET DE L’UEMOA
                        </p>
                        <h1
                            class="max-w-2xl text-4xl leading-[1.05] font-extrabold tracking-tight sm:text-5xl lg:text-6xl"
                        >
                            Vendez en 3 gestes.<br /><span
                                class="text-[#5be0b0]"
                                >Même sans savoir bien lire.</span
                            >
                        </h1>
                        <p
                            class="max-w-xl text-lg leading-8 text-[#d7eee7] sm:text-xl"
                        >
                            Diaba Gescom remplace le cahier : chaque vente,
                            chaque franc de la caisse et chaque article du stock
                            sont suivis. Des images, des couleurs et une voix
                            qui parle votre langue. Même sans internet.
                        </p>
                        <Link
                            :href="ctaHref"
                            class="inline-flex min-h-16 items-center gap-2 rounded-[18px] bg-[#34b89c] px-6 text-lg font-extrabold text-[#04201a] sm:text-xl"
                            >Essayer {{ trialDays }} jours gratuits
                            <ArrowRight class="size-5" aria-hidden="true"
                        /></Link>
                        <div
                            class="flex flex-wrap gap-x-5 gap-y-2 text-sm font-bold text-[#bfe6dc]"
                        >
                            <span class="inline-flex items-center gap-1.5"
                                ><Check class="size-4 text-[#5be0b0]" />Sans
                                engagement</span
                            >
                            <span class="inline-flex items-center gap-1.5"
                                ><Check class="size-4 text-[#5be0b0]" />Aucune
                                carte bancaire</span
                            >
                            <span class="inline-flex items-center gap-1.5"
                                ><Check class="size-4 text-[#5be0b0]" />Aide au
                                démarrage sur WhatsApp</span
                            >
                        </div>
                    </div>

                    <div class="relative mx-auto w-full max-w-md py-4">
                        <div
                            class="mx-auto w-64 rounded-[38px] bg-[#10201c] p-2.5 shadow-2xl sm:w-72"
                        >
                            <div
                                class="overflow-hidden rounded-[29px] bg-[#f1f4f3] text-[#10201c]"
                            >
                                <div
                                    class="flex items-center gap-2 bg-[#0a3f37] px-4 py-4 text-lg font-extrabold text-white"
                                >
                                    <ShoppingCart class="size-5" />Vendre
                                    <Speaker
                                        class="ml-auto size-5 text-[#5be0b0]"
                                    />
                                </div>
                                <div class="grid grid-cols-2 gap-2 p-3">
                                    <article
                                        v-for="(product, index) in [
                                            ['Riz 5 kg', '8 500 F', '#f6e7d3'],
                                            ['Huile 1 L', '1 500 F', '#fff1d6'],
                                            ['Sucre 1 kg', '750 F', '#e7eef9'],
                                            ['Lait 1 L', '800 F', '#e3f6f0'],
                                        ]"
                                        :key="product[0]"
                                        class="overflow-hidden rounded-xl border-3 bg-white"
                                        :class="
                                            index === 0 || index === 2
                                                ? 'border-[#1e9c80]'
                                                : 'border-white'
                                        "
                                    >
                                        <div
                                            class="h-15"
                                            :style="{ background: product[2] }"
                                        />
                                        <p class="p-2 text-xs font-bold">
                                            {{ product[0] }}<br /><strong
                                                class="text-sm text-[#0a3f37]"
                                                >{{ product[1] }}</strong
                                            >
                                        </p>
                                    </article>
                                </div>
                                <div
                                    class="flex items-center gap-2 border-t border-[#d5dedb] bg-white p-3"
                                >
                                    <span class="mr-auto text-xl font-extrabold"
                                        >17 750 F</span
                                    ><span
                                        class="rounded-xl bg-[#34b89c] px-3 py-3 text-sm font-extrabold text-[#04201a]"
                                        >Encaisser</span
                                    >
                                </div>
                            </div>
                        </div>
                        <div
                            class="absolute top-20 right-0 flex items-center gap-2 rounded-2xl bg-white p-3 text-sm font-extrabold text-[#10201c] shadow-lg"
                        >
                            <span
                                class="grid size-9 place-items-center rounded-full bg-[#f2a93b]"
                                ><Speaker class="size-5" /></span
                            ><span>La voix parle<br />wolof, pulaar…</span>
                        </div>
                        <div
                            class="absolute bottom-9 left-0 flex items-center gap-2 rounded-2xl bg-white p-3 text-sm font-extrabold text-[#10201c] shadow-lg"
                        >
                            <span
                                class="grid size-9 place-items-center rounded-full bg-[#5be0b0]"
                                ><Check class="size-5" /></span
                            ><span>Vente faite<br />17 750 F</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-b border-[#d5dedb] bg-white">
                <div
                    class="mx-auto flex max-w-6xl items-center gap-3 overflow-x-auto px-4 py-5 sm:px-6"
                >
                    <span class="shrink-0 font-bold text-[#4a5a56]"
                        >Fait pour :</span
                    >
                    <span
                        v-for="industry in industries"
                        :key="industry"
                        class="shrink-0 rounded-full bg-[#f1f4f3] px-4 py-2 text-sm font-bold"
                        >{{ industry }}</span
                    >
                </div>
            </section>

            <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-22">
                <div class="max-w-3xl">
                    <h2
                        class="text-3xl font-extrabold tracking-tight md:text-4xl"
                    >
                        Le soir, combien avez-vous vraiment gagné ?
                    </h2>
                    <p class="mt-3 text-lg leading-7 text-[#4a5a56]">
                        Avec un cahier, l’argent se perd sans qu’on sache où.
                        Diaba Gescom répond à ces 4 soucis du quotidien.
                    </p>
                </div>
                <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <article
                        v-for="([before, after], index) in problems"
                        :key="before"
                        class="rounded-3xl bg-white p-6 shadow-sm"
                    >
                        <span
                            class="mb-4 grid size-14 place-items-center rounded-full"
                            :class="
                                [
                                    'bg-[#e3f6f0] text-[#0a3f37]',
                                    'bg-[#fff1d6] text-[#6b4300]',
                                    'bg-[#dce8fb] text-[#173e80]',
                                    'bg-[#ede4fa] text-[#3f2270]',
                                ][index]
                            "
                            ><Wallet
                                v-if="index === 1"
                                class="size-7" /><Package
                                v-else-if="index === 2"
                                class="size-7" /><MessageCircle
                                v-else-if="index === 3"
                                class="size-7" /><ShoppingCart
                                v-else
                                class="size-7"
                        /></span>
                        <p
                            class="text-sm font-extrabold text-[#a0261e] line-through"
                        >
                            {{ before }}
                        </p>
                        <p class="mt-3 text-lg font-extrabold">{{ after }}</p>
                    </article>
                </div>
            </section>

            <section
                id="comment"
                class="mx-auto max-w-6xl scroll-mt-20 px-4 pb-18 sm:px-6 md:pb-22"
            >
                <p
                    class="text-sm font-extrabold tracking-wider text-[#1e9c80] uppercase"
                >
                    Comment ça marche
                </p>
                <h2
                    class="mt-3 text-3xl font-extrabold tracking-tight md:text-4xl"
                >
                    Une vente, c’est 3 gestes. Pas plus.
                </h2>
                <div class="mt-8 grid gap-5 md:grid-cols-3">
                    <article
                        v-for="(step, index) in [
                            [
                                'Touchez la photo de l’article',
                                'Ou scannez son code-barres. Touchez encore pour en ajouter un.',
                            ],
                            [
                                'Choisissez comment il paie',
                                'Espèces, téléphone ou crédit : chaque mode a sa couleur.',
                            ],
                            [
                                'C’est fait',
                                'Le stock baisse, la caisse monte, le ticket s’imprime. Tout seul.',
                            ],
                        ]"
                        :key="step[0]"
                        class="rounded-3xl bg-white p-7 shadow-sm"
                    >
                        <span
                            class="grid size-12 place-items-center rounded-full bg-[#0a3f37] text-xl font-extrabold text-white"
                            >{{ index + 1 }}</span
                        >
                        <div
                            class="mt-5 flex h-32 items-center justify-center rounded-2xl"
                            :class="
                                index === 0
                                    ? 'bg-[#e3f6f0]'
                                    : index === 1
                                      ? 'bg-[#f1f4f3]'
                                      : 'bg-[#0a3f37]'
                            "
                        >
                            <span
                                v-if="index === 0"
                                class="rounded-xl border-3 border-[#1e9c80] bg-white px-7 py-8 text-xl font-extrabold text-[#0a3f37]"
                                >+1</span
                            >
                            <span
                                v-else-if="index === 1"
                                class="rounded-xl bg-[#34b89c] px-6 py-3 font-extrabold text-[#04201a]"
                                >Espèces · Wave · Crédit</span
                            >
                            <Check
                                v-else
                                class="size-15 rounded-full bg-[#5be0b0] p-3 text-[#04201a]"
                            />
                        </div>
                        <h3 class="mt-5 text-xl font-extrabold">
                            {{ step[0] }}
                        </h3>
                        <p class="mt-2 leading-6 text-[#4a5a56]">
                            {{ step[1] }}
                        </p>
                    </article>
                </div>
            </section>

            <section class="bg-[#0a3f37] text-white">
                <div
                    class="mx-auto grid max-w-6xl gap-8 px-4 py-16 sm:px-6 md:py-22 lg:grid-cols-2"
                >
                    <div>
                        <p
                            class="text-sm font-extrabold tracking-wider text-[#5be0b0] uppercase"
                        >
                            Pensé pour tout le monde
                        </p>
                        <h2
                            class="mt-3 text-3xl font-extrabold tracking-tight md:text-4xl"
                        >
                            Vos vendeurs l’utilisent dès le premier jour.
                        </h2>
                        <p
                            class="mt-4 max-w-xl text-lg leading-7 text-[#d7eee7]"
                        >
                            Pas besoin de bien lire ni d’être à l’aise avec un
                            téléphone. Chaque action a sa couleur, son dessin et
                            sa voix.
                        </p>
                        <Link
                            :href="ctaHref"
                            class="mt-7 inline-flex min-h-14 items-center rounded-2xl bg-[#34b89c] px-6 text-lg font-extrabold text-[#04201a]"
                            >Essayer avec mes vendeurs</Link
                        >
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <article
                            v-for="item in [
                                [
                                    'Images et couleurs',
                                    'Vendre est vert, la caisse orange, le stock bleu. On ne se trompe pas.',
                                ],
                                [
                                    'Une voix dans votre langue',
                                    'Vous enregistrez les messages en wolof, pulaar, bambara…',
                                ],
                                [
                                    'Même sans internet',
                                    'Coupure ? Vous continuez à vendre. Tout se met à jour au retour du réseau.',
                                ],
                                [
                                    'Wave et Orange Money',
                                    'Espèces, téléphone, crédit : chaque paiement est rangé à sa place.',
                                ],
                            ]"
                            :key="item[0]"
                            class="rounded-3xl bg-[#0f5247] p-6"
                        >
                            <Speaker class="mb-4 size-9 text-[#5be0b0]" />
                            <h3 class="text-lg font-extrabold">
                                {{ item[0] }}
                            </h3>
                            <p class="mt-2 text-sm leading-6 text-[#d7eee7]">
                                {{ item[1] }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-22">
                <p
                    class="text-sm font-extrabold tracking-wider text-[#1e9c80] uppercase"
                >
                    Tout dans une seule application
                </p>
                <h2
                    class="mt-3 text-3xl font-extrabold tracking-tight md:text-4xl"
                >
                    Du comptoir au comptable, sans rien recopier.
                </h2>
                <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <article
                        v-for="(feature, index) in features"
                        :key="feature[0]"
                        class="flex gap-3 rounded-2xl bg-white p-5 shadow-sm"
                    >
                        <span
                            class="grid size-11 shrink-0 place-items-center rounded-full"
                            :class="
                                index % 4 === 0
                                    ? 'bg-[#e3f6f0] text-[#0a3f37]'
                                    : index % 4 === 1
                                      ? 'bg-[#fff1d6] text-[#6b4300]'
                                      : index % 4 === 2
                                        ? 'bg-[#dce8fb] text-[#173e80]'
                                        : 'bg-[#ede4fa] text-[#3f2270]'
                            "
                            ><Package class="size-5"
                        /></span>
                        <div>
                            <h3 class="font-extrabold">{{ feature[0] }}</h3>
                            <p class="mt-1 text-sm leading-5 text-[#4a5a56]">
                                {{ feature[1] }}
                            </p>
                        </div>
                    </article>
                </div>
            </section>

            <section id="prix" class="scroll-mt-20 bg-white">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-22">
                    <div class="mx-auto max-w-3xl text-center">
                        <p
                            class="text-sm font-extrabold tracking-wider text-[#1e9c80] uppercase"
                        >
                            Prix
                        </p>
                        <h2
                            class="mt-3 text-3xl font-extrabold tracking-tight md:text-4xl"
                        >
                            Moins cher qu’une erreur de caisse par mois.
                        </h2>
                        <p class="mt-3 text-lg text-[#4a5a56]">
                            {{ trialDays }} jours gratuits avec toutes les
                            fonctions. Paiement par Wave ou virement. Prix en
                            francs CFA.
                        </p>
                    </div>
                    <div class="mt-10 grid gap-5 md:grid-cols-3">
                        <article
                            v-for="plan in plans"
                            :key="plan.code"
                            class="relative flex flex-col rounded-3xl border-2 p-7"
                            :class="
                                plan.code === recommended
                                    ? 'border-[#34b89c] bg-[#f4fbf8]'
                                    : 'border-[#d5dedb]'
                            "
                        >
                            <span
                                v-if="plan.code === recommended"
                                class="absolute -top-3 left-7 rounded-full bg-[#34b89c] px-3 py-1 text-xs font-extrabold text-[#04201a]"
                                >Conseillé dès 2 boutiques</span
                            >
                            <h3 class="text-xl font-extrabold">
                                {{ plan.name }}
                            </h3>
                            <p class="mt-2 text-sm text-[#4a5a56]">
                                {{ planDescription(plan) }}
                            </p>
                            <p class="mt-5 text-4xl font-extrabold">
                                {{ formatF(planPrice(plan))
                                }}<span
                                    v-if="planPrice(plan) !== null"
                                    class="text-base font-normal text-[#4a5a56]"
                                >
                                    / {{ planPeriod(plan) }}</span
                                >
                            </p>
                            <ul class="my-6 flex-1 space-y-3 text-sm">
                                <li class="flex gap-2">
                                    <Check
                                        class="size-5 shrink-0 text-[#1e9c80]"
                                    />Ventes, caisse, stock et achats
                                </li>
                                <li class="flex gap-2">
                                    <Check
                                        class="size-5 shrink-0 text-[#1e9c80]"
                                    />Factures PDF et WhatsApp
                                </li>
                                <li class="flex gap-2">
                                    <Check
                                        class="size-5 shrink-0 text-[#1e9c80]"
                                    />Même sans connexion
                                </li>
                                <li
                                    v-if="plan.online_store_enabled"
                                    class="flex gap-2"
                                >
                                    <Check
                                        class="size-5 shrink-0 text-[#1e9c80]"
                                    />Boutique en ligne
                                </li>
                                <li
                                    v-if="plan.accounting_enabled"
                                    class="flex gap-2"
                                >
                                    <Check
                                        class="size-5 shrink-0 text-[#1e9c80]"
                                    />Comptabilité SYSCOHADA
                                </li>
                            </ul>
                            <Link
                                :href="ctaHref"
                                class="flex min-h-14 items-center justify-center rounded-2xl text-center font-extrabold"
                                :class="
                                    plan.code === recommended
                                        ? 'bg-[#34b89c] text-[#04201a]'
                                        : 'border-2 border-[#0a3f37] text-[#0a3f37]'
                                "
                                >Essayer gratuitement</Link
                            >
                        </article>
                    </div>
                    <div
                        v-if="annualAvailable"
                        class="mx-auto mt-8 flex w-fit rounded-xl border border-[#d5dedb] bg-[#f1f4f3] p-1"
                        role="radiogroup"
                        aria-label="Période de facturation"
                    >
                        <button
                            type="button"
                            class="min-h-10 rounded-lg px-4 text-sm font-bold"
                            :class="!yearly ? 'bg-[#0a3f37] text-white' : ''"
                            :aria-checked="!yearly"
                            role="radio"
                            @click="yearly = false"
                        >
                            Mensuel
                        </button>
                        <button
                            type="button"
                            class="min-h-10 rounded-lg px-4 text-sm font-bold"
                            :class="yearly ? 'bg-[#0a3f37] text-white' : ''"
                            :aria-checked="yearly"
                            role="radio"
                            @click="yearly = true"
                        >
                            Annuel
                        </button>
                    </div>
                    <p
                        v-if="addons.length"
                        class="mt-6 text-center text-sm text-[#4a5a56]"
                    >
                        Options :
                        <template
                            v-for="(addon, index) in addons"
                            :key="addon.code"
                        >
                            {{ index ? ' · ' : ' ' }}{{ addon.label }} :
                            {{ formatF(addonPrice(addon)) }} /
                            {{ addonPeriod(addon) }}
                        </template>
                    </p>
                </div>
            </section>

            <section
                id="questions"
                class="mx-auto max-w-3xl scroll-mt-20 px-4 py-16 sm:px-6 md:py-22"
            >
                <h2
                    class="text-center text-3xl font-extrabold tracking-tight md:text-4xl"
                >
                    Vos questions
                </h2>
                <div class="mt-8 space-y-3">
                    <details
                        v-for="([question, answer], index) in faq"
                        :key="question"
                        class="rounded-2xl bg-white p-5 shadow-sm"
                        :open="index === 0"
                    >
                        <summary class="cursor-pointer text-lg font-extrabold">
                            {{ question }}
                        </summary>
                        <p class="mt-3 leading-7 text-[#4a5a56]">
                            {{ answer }}
                        </p>
                    </details>
                </div>
            </section>

            <section id="essai" class="bg-[#0a3f37] text-white">
                <div
                    class="mx-auto max-w-4xl px-4 py-16 text-center sm:px-6 md:py-22"
                >
                    <h2
                        class="text-3xl font-extrabold tracking-tight md:text-5xl"
                    >
                        Ce soir, sachez exactement combien vous avez gagné.
                    </h2>
                    <p class="mt-4 text-lg text-[#d7eee7]">
                        Créez votre compte en 2 minutes. On vous aide à mettre
                        vos articles.
                    </p>
                    <form
                        v-if="contact.whatsapp"
                        class="mx-auto mt-7 flex max-w-2xl flex-col gap-3 sm:flex-row sm:items-end"
                        @submit.prevent="sendWhatsAppLead"
                    >
                        <label class="flex-1 text-left"
                            ><span
                                class="mb-2 block text-sm font-bold text-[#bfe6dc]"
                                >Votre numéro WhatsApp</span
                            ><input
                                v-model="whatsappNumber"
                                type="tel"
                                inputmode="tel"
                                autocomplete="tel"
                                placeholder="77 000 00 00"
                                class="min-h-16 w-full rounded-2xl px-5 text-lg text-[#10201c] outline-offset-2" /></label
                        ><button
                            type="submit"
                            class="min-h-16 rounded-2xl bg-[#34b89c] px-6 text-lg font-extrabold text-[#04201a]"
                        >
                            Commencer gratuitement
                        </button>
                    </form>
                    <a
                        v-else-if="contact.email"
                        :href="`mailto:${contact.email}`"
                        class="mx-auto mt-7 inline-flex min-h-16 items-center rounded-2xl bg-[#34b89c] px-6 text-lg font-extrabold text-[#04201a]"
                    >
                        Nous écrire par e-mail
                    </a>
                    <p v-else class="mt-7 text-[#d7eee7]">
                        Contactez-nous bientôt pour démarrer votre essai.
                    </p>
                    <p
                        v-if="formMessage"
                        class="mt-3 text-sm text-[#bfe6dc]"
                        aria-live="polite"
                    >
                        {{ formMessage }}
                    </p>
                    <p class="mt-4 text-sm font-bold text-[#bfe6dc]">
                        {{ trialDays }} jours gratuits · Sans engagement ·
                        Aucune carte bancaire
                    </p>
                    <a
                        v-if="whatsappUrl"
                        :href="whatsappUrl"
                        target="_blank"
                        rel="noopener"
                        class="mx-auto mt-6 inline-flex min-h-14 items-center gap-2 rounded-2xl border-2 border-[#5be0b0] px-5 font-bold"
                        ><MessageCircle class="size-5" />Une question ? WhatsApp
                        {{ formatWhatsappNumber(contact.whatsapp) }}</a
                    >
                </div>
            </section>
        </main>

        <footer class="bg-[#062a25] pb-22 text-[#bfe6dc] md:pb-0">
            <div
                class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-8 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6"
            >
                <p class="font-extrabold text-white">DIABA GESCOM</p>
                <p>
                    Vos données sauvegardées chaque jour, chiffrées, jamais
                    vendues.
                </p>
                <nav class="flex gap-4">
                    <Link :href="privacy()">Confidentialité</Link
                    ><Link :href="terms()">Conditions</Link>
                </nav>
            </div>
        </footer>

        <div
            class="fixed inset-x-0 bottom-0 z-40 flex gap-2 border-t border-[#d5dedb] bg-white p-3 shadow-2xl md:hidden"
        >
            <Link
                :href="ctaHref"
                class="flex min-h-14 flex-1 items-center justify-center rounded-2xl bg-[#34b89c] px-3 text-center font-extrabold text-[#04201a]"
                >Essayer gratuitement</Link
            ><a
                v-if="whatsappUrl"
                :href="whatsappUrl"
                target="_blank"
                rel="noopener"
                aria-label="Écrire sur WhatsApp"
                class="grid size-14 place-items-center rounded-2xl border-2 border-[#0a3f37] text-[#0a3f37]"
                ><MessageCircle class="size-6"
            /></a>
        </div>
    </div>
</template>
