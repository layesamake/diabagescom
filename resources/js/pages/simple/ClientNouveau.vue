<script setup lang="ts">
/**
 * Nouveau client (mode simple) : un nom et, si on l'a, un numéro de téléphone.
 * Le client est créé par le même service que le mode expert : il apparaît
 * ensuite dans la liste des clients, sans dette.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { UsersRound } from '@lucide/vue';
import { computed } from 'vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';

const form = useForm({ name: '', phone: '' });
const peutEnregistrer = computed(
    () => !form.processing && form.name.trim().length >= 2,
);

function enregistrer(): void {
    if (peutEnregistrer.value) {
        form.post('/simple/clients', { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Nouveau client" />

    <EnTeteEcran
        class="lg:hidden"
        titre="Nouveau client"
        :icone="UsersRound"
        :retour-visible="true"
        @retour="router.visit('/simple/clients')"
    />

    <form
        class="grid max-w-[560px] gap-4 p-4 pb-28 lg:p-0 lg:pb-0"
        novalidate
        @submit.prevent="enregistrer"
    >
        <h1 class="sr-only lg:not-sr-only lg:text-[32px] lg:font-extrabold">
            Nouveau client
        </h1>

        <label for="client-nom" class="text-[17px] font-semibold">
            Nom du client
        </label>
        <input
            id="client-nom"
            v-model="form.name"
            type="text"
            autocomplete="name"
            maxlength="160"
            class="h-16 rounded-[20px] border px-4 text-[20px] font-semibold"
            :style="{
                borderColor: 'var(--s-bordure)',
                backgroundColor: 'var(--s-surface)',
                color: 'var(--s-texte)',
            }"
            :aria-invalid="form.errors.name ? 'true' : undefined"
            :aria-describedby="
                form.errors.name ? 'client-nom-erreur' : undefined
            "
        />
        <p
            v-if="form.errors.name"
            id="client-nom-erreur"
            role="alert"
            class="font-bold text-red-700"
        >
            {{ form.errors.name }}
        </p>

        <label for="client-telephone" class="text-[17px] font-semibold">
            Téléphone (facultatif)
        </label>
        <input
            id="client-telephone"
            v-model="form.phone"
            type="tel"
            inputmode="tel"
            autocomplete="tel"
            maxlength="32"
            class="h-16 rounded-[20px] border px-4 text-[20px] font-semibold"
            :style="{
                borderColor: 'var(--s-bordure)',
                backgroundColor: 'var(--s-surface)',
                color: 'var(--s-texte)',
            }"
            :aria-invalid="form.errors.phone ? 'true' : undefined"
            :aria-describedby="
                form.errors.phone ? 'client-telephone-erreur' : undefined
            "
        />
        <p
            v-if="form.errors.phone"
            id="client-telephone-erreur"
            role="alert"
            class="font-bold text-red-700"
        >
            {{ form.errors.phone }}
        </p>

        <button
            type="submit"
            class="h-16 rounded-[24px] text-[20px] font-extrabold disabled:opacity-40"
            :style="{
                backgroundColor: 'var(--s-violet)',
                color: 'var(--s-sur-violet)',
            }"
            :disabled="!peutEnregistrer"
        >
            Enregistrer
        </button>
    </form>
</template>
