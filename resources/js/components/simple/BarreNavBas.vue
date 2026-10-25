<script setup lang="ts">
/**
 * Barre de navigation du bas : 5 entrées, Vendre au centre surélevé,
 * 88 px de haut. Présente sur tous les écrans du mode simple.
 *
 * Au lot 1 les écrans n'existent pas encore : les entrées sans route pointent
 * vers l'accueil du mode simple plutôt que vers une page absente.
 */
import { Link } from '@inertiajs/vue3';
import { Home, Menu, ReceiptText, ShoppingCart } from '@lucide/vue';
import type { Component } from 'vue';

type Entree = {
    cle: string;
    libelle: string;
    icone: Component;
    href: string;
};

withDefaults(
    defineProps<{
        /** Clé de l'entrée active (accueil, ventes, vendre, stock, plus). */
        actif?: string;
        entrees: Entree[];
    }>(),
    { actif: 'accueil' },
);
</script>

<template>
    <nav
        class="fixed inset-x-0 bottom-0 z-40 flex w-full items-end justify-between px-2 pb-2"
        :style="{
            height: 'var(--s-barre-bas)',
            backgroundColor: 'var(--s-surface)',
            borderTop: '1px solid var(--s-bordure)',
        }"
        aria-label="Navigation principale"
    >
        <template v-for="entree in entrees" :key="entree.cle">
            <!-- Vendre : bouton central surélevé, vert, toujours atteignable. -->
            <Link
                v-if="entree.cle === 'vendre'"
                :href="entree.href"
                class="-mt-6 flex min-h-20 w-20 shrink-0 flex-col items-center justify-center gap-1 rounded-full"
                :style="{
                    backgroundColor: 'var(--s-vert)',
                    color: 'var(--s-sur-vert)',
                }"
                :aria-current="actif === entree.cle ? 'page' : undefined"
            >
                <component
                    :is="entree.icone"
                    :size="30"
                    :stroke-width="2.5"
                    aria-hidden="true"
                />
                <span class="text-[15px] font-extrabold">{{
                    entree.libelle
                }}</span>
            </Link>

            <Link
                v-else
                :href="entree.href"
                class="flex min-h-14 flex-1 flex-col items-center justify-center gap-1 rounded-[16px] py-1"
                :style="{
                    color:
                        actif === entree.cle
                            ? 'var(--s-vert)'
                            : 'var(--s-texte-doux)',
                }"
                :aria-current="actif === entree.cle ? 'page' : undefined"
            >
                <component
                    :is="entree.icone"
                    :size="26"
                    :stroke-width="actif === entree.cle ? 2.5 : 2"
                    aria-hidden="true"
                />
                <span
                    class="text-[15px]"
                    :class="
                        actif === entree.cle
                            ? 'font-extrabold'
                            : 'font-semibold'
                    "
                >
                    {{ entree.libelle }}
                </span>
            </Link>
        </template>
    </nav>
</template>
