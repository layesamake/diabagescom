<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CircleHelp } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';

/**
 * Bouton « ? » : explique la page en cours en quelques phrases simples.
 * Affiché seulement pour les pages qui ont une aide.
 */
const page = usePage();

const help = computed(() => {
    const key = `help.${page.component}` as TranslationKey;
    const text = t(key);

    return text === key ? null : text;
});
</script>

<template>
    <Dialog v-if="help">
        <DialogTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="size-11 shrink-0 text-muted-foreground hover:text-foreground md:size-9"
                data-tour="help"
                :aria-label="t('help.button')"
                :title="t('help.button')"
            >
                <CircleHelp class="size-5" />
            </Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <DialogHeader class="gap-3 text-left">
                <span
                    class="flex size-11 items-center justify-center rounded-full bg-primary/10 text-primary"
                >
                    <CircleHelp class="size-5" aria-hidden="true" />
                </span>
                <DialogTitle>{{ t('help.title') }}</DialogTitle>
                <DialogDescription
                    class="text-base leading-relaxed whitespace-pre-line text-foreground"
                    >{{ help }}</DialogDescription
                >
            </DialogHeader>
        </DialogContent>
    </Dialog>
</template>
