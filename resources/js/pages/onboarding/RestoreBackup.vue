<script setup lang="ts">
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CircleAlert,
    CircleCheck,
    LoaderCircle,
    ShieldCheck,
    Upload,
} from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { tenant as switchTenant } from '@/routes/context';
import { create as onboardingCreate } from '@/routes/onboarding';
import { store as restoreStore } from '@/routes/restore';

type RestoreRow = {
    id: string;
    status: 'pending' | 'done' | 'failed';
    file_name: string;
    source_name: string | null;
    tenant: { id: string; display_name: string } | null;
    rows: number | null;
    error: string | null;
    created_at: string;
};

defineOptions({
    layout: {
        title: t('restore.title'),
        description: t('restore.description'),
    },
});

const props = defineProps<{ restores: RestoreRow[]; maxMb: number }>();

const form = useForm<{ backup: File | null }>({ backup: null });

function submit(): void {
    form.post(restoreStore().url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

// Tant qu'une restauration est en cours, la liste se met à jour seule.
const pending = computed(() =>
    props.restores.some((r) => r.status === 'pending'),
);
const { start, stop } = usePoll(
    3000,
    { only: ['restores'] },
    { autoStart: false },
);
watch(pending, (value) => (value ? start() : stop()), { immediate: true });

function open(tenantId: string): void {
    router.post(switchTenant().url, { tenant_id: tenantId });
}

const dateTime = new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
const badge: Record<RestoreRow['status'], string> = {
    pending: 'bg-info-soft text-info-soft-foreground',
    done: 'bg-success-soft text-success-soft-foreground',
    failed: 'bg-danger-soft text-danger-soft-foreground',
};
</script>

<template>
    <Head :title="t('restore.page_title')" />

    <div class="flex flex-col gap-8">
        <section class="space-y-2 rounded-xl border bg-card p-5 text-sm">
            <h2 class="flex items-center gap-2 font-semibold">
                <ShieldCheck class="size-4 text-success" aria-hidden="true" />
                {{ t('restore.how_title') }}
            </h2>
            <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                <li>{{ t('restore.how_new') }}</li>
                <li>{{ t('restore.how_safe') }}</li>
                <li>{{ t('restore.how_members') }}</li>
                <li>{{ t('restore.how_check') }}</li>
            </ul>
        </section>

        <form class="grid gap-3" @submit.prevent="submit">
            <Label for="backup">{{ t('restore.file', { max: maxMb }) }}</Label>
            <input
                id="backup"
                type="file"
                accept=".zip,application/zip"
                required
                class="block min-h-11 w-full rounded-md border border-input bg-transparent text-sm file:mr-3 file:min-h-11 file:border-0 file:bg-secondary file:px-4 file:font-medium file:text-secondary-foreground"
                @change="
                    form.backup =
                        ($event.target as HTMLInputElement).files?.[0] ?? null
                "
            />
            <InputError :message="form.errors.backup" />
            <p v-if="form.progress" class="text-sm text-muted-foreground">
                {{
                    t('restore.uploading', {
                        percent: form.progress.percentage ?? 0,
                    })
                }}
            </p>
            <Button
                type="submit"
                class="h-11 w-full"
                :disabled="form.processing || !form.backup || pending"
            >
                <LoaderCircle
                    v-if="form.processing"
                    class="size-4 animate-spin"
                />
                <Upload v-else class="size-4" />
                {{ t('restore.submit') }}
            </Button>
        </form>

        <section v-if="restores.length" class="space-y-3">
            <h2 class="font-semibold">{{ t('restore.list_title') }}</h2>
            <ol class="divide-y rounded-lg border">
                <li
                    v-for="row in restores"
                    :key="row.id"
                    class="flex flex-wrap items-center gap-3 p-4"
                >
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="font-medium">
                            {{
                                row.source_name
                                    ? t('restore.from', {
                                          name: row.source_name,
                                      })
                                    : row.file_name
                            }}
                            <span
                                class="ml-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="badge[row.status]"
                            >
                                <LoaderCircle
                                    v-if="row.status === 'pending'"
                                    class="size-3 animate-spin"
                                />
                                <CircleCheck
                                    v-else-if="row.status === 'done'"
                                    class="size-3"
                                />
                                <CircleAlert v-else class="size-3" />
                                {{ t(`restore.status.${row.status}`) }}
                            </span>
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ dateTime.format(new Date(row.created_at)) }}
                            <template v-if="row.status === 'done'">
                                ·
                                {{
                                    t('restore.rows', { count: row.rows ?? 0 })
                                }}
                            </template>
                        </p>
                        <p v-if="row.error" class="text-sm text-danger">
                            {{ row.error }}
                        </p>
                    </div>
                    <Button
                        v-if="row.status === 'done' && row.tenant"
                        class="h-11"
                        @click="open(row.tenant.id)"
                        >{{ t('restore.open') }}</Button
                    >
                </li>
            </ol>
        </section>

        <Link
            :href="onboardingCreate({ query: { new: 1 } })"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('restore.back')
            }}
        </Link>
    </div>
</template>
