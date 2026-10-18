<script setup lang="ts">
/**
 * Page de comparaison du lot 1 : chaque composant du mode simple est affiché
 * à côté de la maquette de référence correspondante, pour validation visuelle.
 *
 * Outil interne réservé à l'administrateur ; il ne fait pas partie du mode
 * simple livré aux vendeurs.
 */
import { Head } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Boxes,
    Coins,
    Package,
    ShoppingCart,
    Users,
    Wallet,
} from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import Avatar from '@/components/simple/Avatar.vue';
import BarreEtapes from '@/components/simple/BarreEtapes.vue';
import BoutonHautParleur from '@/components/simple/BoutonHautParleur.vue';
import CarteArticle from '@/components/simple/CarteArticle.vue';
import CarteMontant from '@/components/simple/CarteMontant.vue';
import Compteur from '@/components/simple/Compteur.vue';
import CompteursFiltres from '@/components/simple/CompteursFiltres.vue';
import EcranResultat from '@/components/simple/EcranResultat.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import FeuilleBas from '@/components/simple/FeuilleBas.vue';
import LigneAlerte from '@/components/simple/LigneAlerte.vue';
import LigneArticle from '@/components/simple/LigneArticle.vue';
import TuileAction from '@/components/simple/TuileAction.vue';
import { index as settings } from '@/routes/settings';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Paramètres', href: settings() }] },
});

const quantite = ref(3);
const billets = ref(2);
const filtre = ref<string | null>('presque');
const feuilleOuverte = ref(false);

/** Jetons de couleur du cahier des charges, pour contrôle à l'œil. */
const jetons = [
    ['--s-fond', '#F1F4F3', 'Fond des écrans'],
    ['--s-surface', '#FFFFFF', 'Cartes, listes'],
    ['--s-texte', '#10201C', 'Texte principal'],
    ['--s-texte-doux', '#4A5A56', 'Texte secondaire'],
    ['--s-bordure', '#D5DEDB', 'Bordures'],
    ['--s-marque', '#0A3F37', 'En-têtes'],
    ['--s-marque-2', '#0F5247', 'Boutons d’en-tête'],
    ['--s-vert', '#34B89C', 'Vendre, Valider'],
    ['--s-vert-fonce', '#1E9C80', 'Étapes faites'],
    ['--s-vert-clair', '#E3F6F0', 'Bouton plus'],
    ['--s-orange', '#F2A93B', 'Caisse, crédit'],
    ['--s-orange-clair', '#FFF1D6', 'Fonds crédit'],
    ['--s-bleu', '#2563C9', 'Stock, téléphone'],
    ['--s-bleu-clair', '#DCE8FB', 'Fonds téléphone'],
    ['--s-violet', '#6D3FB8', 'Clients'],
    ['--s-violet-clair', '#EDE4FA', 'Étiquette Chef'],
    ['--s-rouge', '#C8372D', 'Alertes, retirer'],
    ['--s-rouge-clair', '#FCE3DE', 'Bouton moins'],
] as const;

/** Composant → maquette qui sert de référence. */
const composants = [
    {
        cle: 'entete',
        nom: 'EnTeteEcran',
        maquette: '02a-vendre-panier-vide.png',
    },
    {
        cle: 'hautparleur',
        nom: 'BoutonHautParleur',
        maquette: '05a-messages-audio.png',
    },
    { cle: 'tuile', nom: 'TuileAction', maquette: '01-accueil.png' },
    { cle: 'montant', nom: 'CarteMontant', maquette: '01-accueil.png' },
    { cle: 'compteur', nom: 'Compteur', maquette: '06b-caisse-comptage.png' },
    {
        cle: 'article',
        nom: 'CarteArticle',
        maquette: '02b-vendre-panier-rempli.png',
    },
    { cle: 'ligne', nom: 'LigneArticle', maquette: '07a-stock.png' },
    { cle: 'alerte', nom: 'LigneAlerte', maquette: '01-accueil.png' },
    { cle: 'filtres', nom: 'CompteursFiltres', maquette: '07a-stock.png' },
    {
        cle: 'feuille',
        nom: 'FeuilleBas',
        maquette: '02c-vendre-choix-paiement.png',
    },
    {
        cle: 'resultat',
        nom: 'EcranResultat',
        maquette: '02d-vendre-vente-faite.png',
    },
    { cle: 'etapes', nom: 'BarreEtapes', maquette: '03-premiers-pas.png' },
    { cle: 'avatar', nom: 'Avatar', maquette: '08a-clients.png' },
    { cle: 'nav', nom: 'BarreNavBas', maquette: '01-accueil.png' },
] as const;

function maquetteUrl(fichier: string): string {
    const identifiant = fichier.replace(/\.png$/, '');

    return `/simple/maquettes/${encodeURIComponent(identifiant)}`;
}
</script>

<template>
    <Head title="Mode simple — composants" />

    <div class="space-y-8 p-4 md:p-6">
        <Heading
            title="Mode simple — composants du lot 1"
            description="Chaque composant à côté de la maquette de référence. Comparez, puis dites-moi ce qui doit changer."
        />

        <!-- Jetons de couleur -->
        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Couleurs et police</h2>
            <p class="text-sm text-muted-foreground">
                Valeurs déclarées une seule fois dans app.css. Police Atkinson
                Hyperlegible Next, déjà utilisée par Diaba.
            </p>
            <div
                class="mode-simple grid gap-2 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-3"
            >
                <div
                    v-for="[variable, hexa, usage] in jetons"
                    :key="variable"
                    class="flex items-center gap-3 rounded-[16px] p-2"
                    style="background-color: var(--s-surface)"
                >
                    <span
                        class="size-10 shrink-0 rounded-[12px]"
                        :style="{
                            backgroundColor: `var(${variable})`,
                            border: '1px solid var(--s-bordure)',
                        }"
                    />
                    <span class="min-w-0">
                        <span class="block font-mono text-xs">{{
                            variable
                        }}</span>
                        <span
                            class="block font-mono text-xs text-muted-foreground"
                        >
                            {{ hexa }}
                        </span>
                        <span class="block text-sm">{{ usage }}</span>
                    </span>
                </div>
            </div>
        </section>

        <!-- Composants, chacun face à sa maquette -->
        <section
            v-for="item in composants"
            :key="item.cle"
            class="space-y-3 border-t pt-6"
        >
            <h2 class="text-lg font-semibold">
                {{ item.nom }}
                <span class="text-sm font-normal text-muted-foreground">
                    — référence : {{ item.maquette }}
                </span>
            </h2>

            <div class="grid gap-4 lg:grid-cols-2">
                <!-- Composant réel -->
                <div class="space-y-2">
                    <p class="text-sm font-medium text-muted-foreground">
                        Composant codé
                    </p>
                    <div
                        class="mode-simple overflow-hidden rounded-[24px] border p-4"
                        style="background-color: var(--s-fond)"
                    >
                        <EnTeteEcran
                            v-if="item.cle === 'entete'"
                            titre="Vendre"
                            :icone="ShoppingCart"
                            code-audio="VEN-02"
                            :audio-disponible="true"
                            :hors-ligne="true"
                        />

                        <div
                            v-else-if="item.cle === 'hautparleur'"
                            class="flex gap-4"
                        >
                            <BoutonHautParleur
                                code="COM-01"
                                :disponible="true"
                            />
                            <BoutonHautParleur
                                code="COM-01"
                                :disponible="true"
                                taille="grand"
                            />
                            <p class="self-center text-[15px]">
                                Phrase non enregistrée : rien ne s’affiche →
                                <BoutonHautParleur
                                    code="VEN-99"
                                    :disponible="false"
                                />
                            </p>
                        </div>

                        <div
                            v-else-if="item.cle === 'tuile'"
                            class="grid grid-cols-2 gap-3"
                        >
                            <TuileAction
                                libelle="Vendre"
                                :icone="ShoppingCart"
                                couleur="vert"
                            />
                            <TuileAction
                                libelle="Caisse"
                                :icone="Wallet"
                                couleur="orange"
                            />
                            <TuileAction
                                libelle="Stock"
                                :icone="Boxes"
                                couleur="bleu"
                            />
                            <TuileAction
                                libelle="Clients"
                                :icone="Users"
                                couleur="violet"
                            />
                        </div>

                        <CarteMontant
                            v-else-if="item.cle === 'montant'"
                            libelle="Aujourd’hui"
                            :montant="127500"
                            :pastilles="['12 ventes']"
                            tendance="hausse"
                            code-audio="ACC-03"
                            :audio-disponible="true"
                        />

                        <div
                            v-else-if="item.cle === 'compteur'"
                            class="grid gap-4"
                        >
                            <Compteur v-model:valeur="quantite" unite="sacs" />
                            <Compteur
                                v-model:valeur="billets"
                                unite="billets de 5 000"
                            />
                        </div>

                        <div
                            v-else-if="item.cle === 'article'"
                            class="grid grid-cols-2 gap-3"
                        >
                            <CarteArticle
                                nom="Riz parfumé 25 kg"
                                :prix="17500"
                                :quantite="2"
                            />
                            <CarteArticle nom="Huile 5 L" :prix="6500" />
                        </div>

                        <div
                            v-else-if="item.cle === 'ligne'"
                            class="divide-y overflow-hidden rounded-[20px]"
                            style="border: 1px solid var(--s-bordure)"
                        >
                            <LigneArticle
                                nom="Riz parfumé 25 kg"
                                quantite="24"
                                unite="sacs"
                                niveau="assez"
                                :remplissage="80"
                            />
                            <LigneArticle
                                nom="Huile 5 L"
                                quantite="6"
                                unite="bidons"
                                niveau="bientot"
                                :remplissage="35"
                            />
                            <LigneArticle
                                nom="Sucre 1 kg"
                                quantite="2"
                                unite="paquets"
                                niveau="presque"
                                :remplissage="10"
                            />
                        </div>

                        <div
                            v-else-if="item.cle === 'alerte'"
                            class="divide-y overflow-hidden rounded-[20px]"
                            style="border: 1px solid var(--s-bordure)"
                        >
                            <LigneAlerte
                                :icone="AlertTriangle"
                                couleur="rouge"
                                valeur="3"
                                texte="articles presque finis"
                            />
                            <LigneAlerte
                                :icone="Coins"
                                couleur="orange"
                                valeur="45 000 F"
                                texte="à récupérer chez les clients"
                            />
                        </div>

                        <CompteursFiltres
                            v-else-if="item.cle === 'filtres'"
                            :actif="filtre"
                            :cases="[
                                {
                                    cle: 'assez',
                                    libelle: 'Il en reste',
                                    valeur: '18',
                                    couleur: 'vert-fonce',
                                },
                                {
                                    cle: 'bientot',
                                    libelle: 'Bientôt fini',
                                    valeur: '5',
                                    couleur: 'orange',
                                },
                                {
                                    cle: 'presque',
                                    libelle: 'Presque fini',
                                    valeur: '3',
                                    couleur: 'rouge',
                                },
                            ]"
                            @filtrer="filtre = $event"
                        />

                        <div v-else-if="item.cle === 'feuille'">
                            <button
                                type="button"
                                class="h-16 w-full rounded-[24px] text-[20px] font-extrabold"
                                style="
                                    background-color: var(--s-vert);
                                    color: var(--s-sur-vert);
                                "
                                @click="feuilleOuverte = true"
                            >
                                Ouvrir la feuille
                            </button>
                            <FeuilleBas
                                :ouverte="feuilleOuverte"
                                titre="Le client paie comment ?"
                                code-audio="VEN-07"
                                :audio-disponible="true"
                                @fermer="feuilleOuverte = false"
                            >
                                <button
                                    type="button"
                                    class="h-16 rounded-[24px] text-[20px] font-extrabold"
                                    style="
                                        background-color: var(--s-vert);
                                        color: var(--s-sur-vert);
                                    "
                                >
                                    Espèces
                                </button>
                                <button
                                    type="button"
                                    class="h-16 rounded-[24px] text-[20px] font-extrabold"
                                    style="
                                        background-color: var(--s-bleu);
                                        color: var(--s-sur-bleu);
                                    "
                                >
                                    Téléphone
                                </button>
                                <button
                                    type="button"
                                    class="h-16 rounded-[24px] text-[20px] font-extrabold"
                                    style="
                                        background-color: var(--s-orange);
                                        color: var(--s-sur-orange);
                                    "
                                >
                                    À crédit
                                </button>
                            </FeuilleBas>
                        </div>

                        <div
                            v-else-if="item.cle === 'resultat'"
                            class="overflow-hidden rounded-[20px]"
                            style="height: 520px"
                        >
                            <EcranResultat
                                etat="reussi"
                                titre="Vente faite"
                                :montant="24000"
                                detail="Rendez 1 000 F au client"
                                action-principale="Nouvelle vente"
                                action-secondaire="Ticket"
                                code-audio="VEN-15"
                                :audio-disponible="true"
                            />
                        </div>

                        <div
                            v-else-if="item.cle === 'etapes'"
                            class="grid gap-4"
                        >
                            <BarreEtapes
                                :total="11"
                                :faites="1"
                                :en-cours="true"
                                libelle="Premiers pas"
                            />
                            <BarreEtapes
                                :total="12"
                                :faites="12"
                                libelle="Messages audio"
                            />
                        </div>

                        <div
                            v-else-if="item.cle === 'avatar'"
                            class="flex items-end gap-4"
                        >
                            <Avatar nom="Awa Diallo" />
                            <Avatar nom="Moussa Fall" :taille="72" />
                            <Avatar
                                nom="Riz parfumé 25 kg"
                                :taille="72"
                                forme="carre"
                            />
                        </div>

                        <p v-else class="text-[15px]">
                            Barre du bas : visible en permanence sur tous les
                            écrans du mode simple. Ouvrez
                            <code>/simple</code> pour la voir en place.
                        </p>
                    </div>
                </div>

                <!-- Maquette de référence -->
                <div class="space-y-2">
                    <p class="text-sm font-medium text-muted-foreground">
                        Maquette de référence
                    </p>
                    <a
                        :href="maquetteUrl(item.maquette)"
                        target="_blank"
                        rel="noopener"
                        class="block overflow-hidden rounded-[24px] border"
                    >
                        <img
                            :src="maquetteUrl(item.maquette)"
                            :alt="`Maquette ${item.maquette}`"
                            class="w-full"
                            loading="lazy"
                        />
                    </a>
                </div>
            </div>
        </section>
    </div>
</template>
