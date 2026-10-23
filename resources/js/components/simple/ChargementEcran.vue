<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';

withDefaults(defineProps<{ lignes?: number }>(), {
    lignes: 3,
});
</script>

<template>
    <section
        class="grid content-start gap-5 bg-[var(--s-surface)] p-4 pt-10"
        role="status"
        aria-live="polite"
        aria-label="Chargement en cours"
    >
        <div class="flex items-center gap-3">
            <LoaderCircle
                class="size-6 text-[var(--s-marque)] motion-safe:animate-spin"
                :stroke-width="2"
                aria-hidden="true"
            />
            <p class="text-[17px] font-extrabold">Chargement…</p>
        </div>

        <div aria-hidden="true" class="grid gap-3">
            <div
                v-for="ligne in lignes"
                :key="ligne"
                class="h-20 rounded-[20px] opacity-60 motion-safe:animate-pulse"
                :style="{ backgroundColor: 'var(--s-bleu-clair)' }"
            />
        </div>
    </section>
</template>
