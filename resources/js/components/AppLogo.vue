<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div class="flex size-8 shrink-0 items-center justify-center">
        <AppLogoIcon class="h-8 w-auto" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span
            class="truncate leading-tight font-extrabold tracking-wide text-[var(--color-brand-navy)] dark:text-white"
            >DIABA
            <span
                class="text-[var(--color-brand-teal)] dark:text-[var(--color-brand-teal-light)]"
                >GESCOM</span
            ></span
        >
    </div>
</template>
