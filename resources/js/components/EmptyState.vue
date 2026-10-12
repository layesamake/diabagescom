<script setup lang="ts">
import { Inbox } from '@lucide/vue';
import type { Component } from 'vue';

/**
 * État vide d'une liste : icône, message, et éventuellement une phrase
 * d'aide et une action (slot « action ») pour savoir quoi faire ensuite.
 */
withDefaults(defineProps<{ icon?: Component; hint?: string }>(), {
    icon: () => Inbox,
    hint: undefined,
});
</script>

<template>
    <div
        class="flex flex-col items-center gap-3 rounded-xl border border-dashed px-6 py-10 text-center"
        role="status"
    >
        <span
            class="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground"
        >
            <component :is="icon" class="size-6" aria-hidden="true" />
        </span>
        <p class="max-w-md font-medium">
            <slot />
        </p>
        <p v-if="hint" class="max-w-md text-sm text-muted-foreground">
            {{ hint }}
        </p>
        <div v-if="$slots.action" class="mt-1">
            <slot name="action" />
        </div>
    </div>
</template>
