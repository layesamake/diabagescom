<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PhotoField from '@/components/PhotoField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import { confirmAction } from '@/composables/useConfirm';
import { update as profileUpdate } from '@/routes/platform/tenants/profile';
import {
    destroy as imageDestroy,
    store as imageStore,
} from '@/routes/platform/tenants/profile/images';

/**
 * Support : fiche de l'entreprise cliente. Toute modification exige un motif
 * (tracé dans le journal de l'entreprise). Le cachet/signature n'est jamais
 * affiché ici : on voit seulement s'il existe, et on peut le retirer.
 */
const props = defineProps<{
    tenantId: string;
    profile: {
        vat_regime: string;
        maps_url: string | null;
        logo_url: string | null;
        cover_url: string | null;
        has_stamp: boolean;
        regimes: string[];
    };
}>();

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const vatRegime = ref(props.profile.vat_regime);
const mapsUrl = ref(props.profile.maps_url ?? '');
const reason = ref('');
const processing = ref(false);
const reasonOk = computed(() => reason.value.trim().length >= 5);
const extra = computed(() => ({ reason: reason.value.trim() }));

function save(): void {
    router.put(
        profileUpdate(props.tenantId).url,
        {
            vat_regime: vatRegime.value,
            maps_url: mapsUrl.value,
            reason: reason.value.trim(),
        },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}

async function removeStamp(): Promise<void> {
    if (
        await confirmAction({
            title: t('platform.profile.stamp_remove_confirm'),
            confirmLabel: t('confirm.remove'),
            tone: 'danger',
        })
    ) {
        router.delete(
            imageDestroy({ tenant: props.tenantId, slot: 'stamp' }).url,
            { preserveScroll: true, data: extra.value },
        );
    }
}
</script>

<template>
    <section class="space-y-4 rounded-xl border p-4">
        <div>
            <h2 class="text-lg font-semibold">
                {{ t('platform.profile.title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ t('platform.profile.hint') }}
            </p>
        </div>

        <div class="grid gap-1">
            <Label for="profile-reason">{{
                t('platform.profile.reason')
            }}</Label>
            <Input
                id="profile-reason"
                v-model="reason"
                maxlength="500"
                class="h-11"
            />
            <InputError :message="errors.reason" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div class="space-y-2">
                <p class="text-sm font-medium">{{ t('company.logo') }}</p>
                <PhotoField
                    :image-url="profile.logo_url"
                    :alt="t('company.logo')"
                    :upload-url="
                        imageStore({ tenant: tenantId, slot: 'logo' }).url
                    "
                    :remove-url="
                        imageDestroy({ tenant: tenantId, slot: 'logo' }).url
                    "
                    :can-edit="reasonOk"
                    :extra-data="extra"
                    input-id="support-logo"
                    fit="contain"
                    size="sm"
                />
            </div>
            <div class="space-y-2">
                <p class="text-sm font-medium">{{ t('company.cover') }}</p>
                <PhotoField
                    :image-url="profile.cover_url"
                    :alt="t('company.cover')"
                    :upload-url="
                        imageStore({ tenant: tenantId, slot: 'cover' }).url
                    "
                    :remove-url="
                        imageDestroy({ tenant: tenantId, slot: 'cover' }).url
                    "
                    :can-edit="reasonOk"
                    :extra-data="extra"
                    input-id="support-cover"
                    size="sm"
                />
            </div>
        </div>
        <p v-if="!reasonOk" class="text-xs text-muted-foreground">
            {{ t('platform.profile.reason_needed') }}
        </p>

        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="font-medium">{{ t('company.stamp') }} :</span>
            <span>{{
                profile.has_stamp
                    ? t('platform.profile.stamp_present')
                    : t('platform.profile.stamp_absent')
            }}</span>
            <Button
                v-if="profile.has_stamp"
                type="button"
                variant="ghost"
                class="h-11 text-destructive"
                :disabled="!reasonOk"
                @click="removeStamp"
                >{{ t('photo.remove') }}</Button
            >
        </div>

        <form class="space-y-4" @submit.prevent="save">
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">
                    {{ t('company.tax_title') }}
                </legend>
                <label
                    v-for="regime in profile.regimes"
                    :key="regime"
                    class="flex min-h-11 items-center gap-3 text-sm"
                >
                    <input
                        v-model="vatRegime"
                        type="radio"
                        name="support_vat_regime"
                        :value="regime"
                        class="size-5"
                    />
                    {{ t(`company.regimes.${regime}` as TranslationKey) }}
                </label>
                <InputError :message="errors.vat_regime" />
            </fieldset>

            <div class="grid gap-1">
                <Label for="support-maps">{{ t('company.maps') }}</Label>
                <Input
                    id="support-maps"
                    v-model="mapsUrl"
                    type="url"
                    maxlength="500"
                    placeholder="https://maps.app.goo.gl/…"
                    class="h-11"
                />
                <InputError :message="errors.maps_url" />
            </div>

            <Button
                type="submit"
                class="h-11"
                :disabled="processing || !reasonOk"
            >
                {{ t('company.save') }}
            </Button>
        </form>
    </section>
</template>
