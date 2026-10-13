<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Camera, ImagePlus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import { confirmAction } from '@/composables/useConfirm';

/**
 * Photo d'un article ou d'une catégorie : aperçu, envoi d'un fichier ou prise de
 * vue directe (smartphone), retrait. Le serveur ré-encode l'image (WebP, sans
 * métadonnées) et ne la sert que par lien signé.
 */
const props = defineProps<{
    imageUrl: string | null;
    alt: string;
    uploadUrl: string;
    removeUrl: string;
    canEdit: boolean;
    inputId: string;
    size?: 'sm' | 'lg';
    /** « contain » pour un logo ou un cachet (jamais rogné). */
    fit?: 'cover' | 'contain';
}>();

const preview = ref<string | null>(null);
const processing = ref(false);
const error = ref<string | undefined>();

// Seul le fichier choisi est envoyé (appareil photo ou galerie).
function upload(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    preview.value = URL.createObjectURL(file);
    error.value = undefined;

    router.post(
        props.uploadUrl,
        { image: file },
        {
            preserveScroll: true,
            forceFormData: true,
            onStart: () => (processing.value = true),
            onError: (errors) => (error.value = errors.image),
            onFinish: () => {
                processing.value = false;
                preview.value = null;
                input.value = '';
            },
        },
    );
}

async function remove(): Promise<void> {
    if (
        await confirmAction({
            title: t('photo.remove_confirm'),
            confirmLabel: t('confirm.remove'),
            tone: 'danger',
        })
    ) {
        router.delete(props.removeUrl, { preserveScroll: true });
    }
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <img
            v-if="preview || imageUrl"
            :src="preview ?? imageUrl ?? ''"
            :alt="alt"
            class="rounded-lg border"
            :class="[
                size === 'sm' ? 'size-14' : 'size-40',
                fit === 'contain'
                    ? 'bg-white object-contain p-1'
                    : 'object-cover',
            ]"
        />
        <span
            v-else
            :class="size === 'sm' ? 'size-14' : 'size-40'"
            class="flex items-center justify-center rounded-lg border border-dashed text-muted-foreground"
        >
            <ImagePlus class="size-5" aria-hidden="true" />
            <span class="sr-only">{{ t('photo.none') }}</span>
        </span>

        <div v-if="canEdit" class="grid gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <!-- Prise de vue directe : ouvre l'appareil photo sur smartphone. -->
                <label
                    :for="`${inputId}-camera`"
                    class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md border px-3 text-sm focus-within:ring-2 focus-within:ring-ring hover:bg-accent"
                >
                    <Camera class="size-4" aria-hidden="true" />{{
                        t('photo.take')
                    }}
                    <input
                        :id="`${inputId}-camera`"
                        type="file"
                        accept="image/*"
                        capture="environment"
                        class="sr-only"
                        :disabled="processing"
                        @change="upload"
                    />
                </label>
                <label
                    :for="`${inputId}-file`"
                    class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md border px-3 text-sm focus-within:ring-2 focus-within:ring-ring hover:bg-accent"
                >
                    <ImagePlus class="size-4" aria-hidden="true" />{{
                        t('photo.choose')
                    }}
                    <input
                        :id="`${inputId}-file`"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="sr-only"
                        :disabled="processing"
                        @change="upload"
                    />
                </label>
                <Button
                    v-if="imageUrl"
                    type="button"
                    variant="ghost"
                    class="h-11 text-destructive"
                    :disabled="processing"
                    @click="remove"
                >
                    <Trash2 class="size-4" />{{ t('photo.remove') }}
                </Button>
                <Spinner v-if="processing" />
            </div>
            <p class="text-xs text-muted-foreground">{{ t('photo.hint') }}</p>
            <InputError :message="error" />
        </div>
    </div>
</template>
