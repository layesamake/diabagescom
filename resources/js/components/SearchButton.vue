<script setup lang="ts">
import { Search } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useAppContext } from '@/composables/useAppContext';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { t } from '@/i18n';

/** Bouton de l'en-tête qui ouvre la recherche globale (comme Ctrl+K). */
const palette = useCommandPalette();
const { context } = useAppContext();
const mac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform);
</script>

<template>
    <Button
        v-if="context"
        variant="outline"
        class="size-11 shrink-0 gap-2 px-0 text-muted-foreground md:h-9 md:w-auto md:px-3"
        type="button"
        data-tour="search"
        :aria-label="t('search.button')"
        :title="t('search.button')"
        @click="palette.show()"
    >
        <Search class="size-4" aria-hidden="true" />
        <span class="hidden md:inline">{{ t('search.button') }}</span>
        <kbd
            class="hidden rounded border bg-muted px-1.5 text-[11px] font-medium md:inline"
            >{{ mac ? '⌘K' : 'Ctrl K' }}</kbd
        >
    </Button>
</template>
