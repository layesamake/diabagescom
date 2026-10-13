<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PhotoField from '@/components/PhotoField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import {
    destroy as imageDestroy,
    store as imageStore,
} from '@/routes/company/images';
import { edit, update } from '@/routes/company';

const props = defineProps<{
    company: { legal_name: string; display_name: string };
    profile: {
        vat_regime: string;
        maps_url: string | null;
        images: {
            logo: string | null;
            stamp: string | null;
            cover: string | null;
        };
    };
    regimes: string[];
    can: { manage: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('company.page_title'), href: edit() }],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const vatRegime = ref(props.profile.vat_regime);
const mapsUrl = ref(props.profile.maps_url ?? '');
const processing = ref(false);

function save(): void {
    router.put(
        update().url,
        { vat_regime: vatRegime.value, maps_url: mapsUrl.value },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}

const images = [
    {
        slot: 'logo',
        label: 'company.logo',
        hint: 'company.logo_hint',
        fit: 'contain',
    },
    {
        slot: 'stamp',
        label: 'company.stamp',
        hint: 'company.stamp_hint',
        fit: 'contain',
    },
    {
        slot: 'cover',
        label: 'company.cover',
        hint: 'company.cover_hint',
        fit: 'cover',
    },
] as const;
</script>

<template>
    <Head :title="t('company.page_title')" />

    <div class="space-y-8">
        <Heading
            variant="small"
            :title="company.display_name"
            :description="company.legal_name"
        />
        <p v-if="!can.manage" class="text-sm text-muted-foreground">
            {{ t('company.read_only') }}
        </p>

        <section class="space-y-6">
            <h2 class="text-lg font-semibold">{{ t('company.identity') }}</h2>
            <div v-for="item in images" :key="item.slot" class="space-y-2">
                <p class="text-sm font-medium">{{ t(item.label) }}</p>
                <p class="text-xs text-muted-foreground">{{ t(item.hint) }}</p>
                <PhotoField
                    :image-url="profile.images[item.slot]"
                    :alt="t(item.label)"
                    :upload-url="imageStore(item.slot).url"
                    :remove-url="imageDestroy(item.slot).url"
                    :can-edit="can.manage"
                    :input-id="`company-${item.slot}`"
                    :fit="item.fit"
                />
            </div>
        </section>

        <form class="space-y-6 border-t pt-6" @submit.prevent="save">
            <fieldset class="space-y-2" :disabled="!can.manage">
                <legend class="text-lg font-semibold">
                    {{ t('company.tax_title') }}
                </legend>
                <p class="text-xs text-muted-foreground">
                    {{ t('company.tax_hint') }}
                </p>
                <label
                    v-for="regime in regimes"
                    :key="regime"
                    class="flex min-h-11 items-center gap-3 text-sm"
                >
                    <input
                        v-model="vatRegime"
                        type="radio"
                        name="vat_regime"
                        :value="regime"
                        class="size-5"
                    />
                    {{ t(`company.regimes.${regime}` as TranslationKey) }}
                </label>
                <InputError :message="errors.vat_regime" />
            </fieldset>

            <fieldset class="space-y-2" :disabled="!can.manage">
                <legend class="text-lg font-semibold">
                    {{ t('company.location_title') }}
                </legend>
                <Label for="maps_url">{{ t('company.maps') }}</Label>
                <Input
                    id="maps_url"
                    v-model="mapsUrl"
                    type="url"
                    inputmode="url"
                    maxlength="500"
                    placeholder="https://maps.app.goo.gl/…"
                    class="h-11"
                />
                <p class="text-xs text-muted-foreground">
                    {{ t('company.maps_hint') }}
                </p>
                <a
                    v-if="profile.maps_url"
                    :href="profile.maps_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-block text-sm underline underline-offset-4"
                    >{{ t('company.maps_open') }}</a
                >
                <InputError :message="errors.maps_url" />
            </fieldset>

            <Button
                v-if="can.manage"
                type="submit"
                class="h-11"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                {{ t('company.save') }}
            </Button>
        </form>
    </div>
</template>
