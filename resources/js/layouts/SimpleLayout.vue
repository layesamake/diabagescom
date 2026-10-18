<script setup lang="ts">
/**
 * Mise en page du mode simple : colonne de 430 px centrée (téléphone d'abord,
 * utilisable sur tablette et ordinateur), thème clair imposé, barre de
 * navigation en bas.
 *
 * La classe « mode-simple » porte toutes les variables de couleur : aucun
 * écran n'écrit de couleur en dur.
 */
import {
    Boxes,
    Home,
    MoreHorizontal,
    ReceiptText,
    ShoppingCart,
} from '@lucide/vue';
import { computed } from 'vue';
import BarreNavBas from '@/components/simple/BarreNavBas.vue';
import {
    home as simpleHome,
    sell as simpleSell,
    cash as simpleCash,
} from '@/routes/simple';

const props = withDefaults(defineProps<{ actif?: string }>(), {
    actif: 'accueil',
});

const accueil = computed(() => simpleHome().url);

const entrees = computed(() => [
    { cle: 'accueil', libelle: 'Accueil', icone: Home, href: accueil.value },
    {
        cle: 'ventes',
        libelle: 'Ventes',
        icone: ReceiptText,
        href: accueil.value,
    },
    {
        cle: 'vendre',
        libelle: 'Vendre',
        icone: ShoppingCart,
        href: simpleSell().url,
    },
    { cle: 'stock', libelle: 'Stock', icone: Boxes, href: accueil.value },
    {
        cle: 'plus',
        libelle: 'Plus',
        icone: MoreHorizontal,
        href: accueil.value,
    },
]);
</script>

<template>
    <div class="mode-simple min-h-dvh">
        <div
            class="mx-auto w-full max-w-[430px] pb-[calc(var(--s-barre-bas)+16px)]"
        >
            <slot />
        </div>

        <BarreNavBas :actif="props.actif" :entrees="entrees" />
    </div>
</template>
