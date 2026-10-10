<script setup lang="ts">
/**
 * Icône seule DIABA GESCOM (docs/15). Décorative par défaut (alt vide) :
 * le nom de l'application est toujours affiché ou annoncé à côté.
 */
withDefaults(defineProps<{ alt?: string }>(), { alt: '' });
</script>

<template>
    <img
        src="/brand/diaba-icon.svg"
        :alt="alt"
        width="34"
        height="32"
        draggable="false"
    />
</template>
