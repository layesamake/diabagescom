<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    Download,
    FileSpreadsheet,
    HardDriveDownload,
    LoaderCircle,
    ShieldCheck,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import {
    download as backupDownload,
    index as backupIndex,
    store as backupStore,
} from '@/routes/backup';
import { create as restoreCreate } from '@/routes/restore';

type ExportRow = {
    id: string;
    status: 'pending' | 'ready' | 'failed' | 'expired';
    size: number | null;
    rows: number | null;
    author: string;
    created_at: string;
    expires_at: string | null;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('backup.page_title'), href: backupIndex() }],
    },
});

const props = defineProps<{ exports: ExportRow[]; keepDays: number }>();

const submitting = ref(false);
const pending = computed(() =>
    props.exports.some((e) => e.status === 'pending'),
);

// Tant qu'une sauvegarde est en préparation, la liste se met à jour seule.
const { start, stop } = usePoll(
    3000,
    { only: ['exports'] },
    { autoStart: false },
);
watch(pending, (value) => (value ? start() : stop()), { immediate: true });

function create(): void {
    router.post(
        backupStore().url,
        {},
        {
            preserveScroll: true,
            onStart: () => (submitting.value = true),
            onFinish: () => (submitting.value = false),
        },
    );
}

const dateTime = new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
const date = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' });

function size(bytes: number | null): string {
    if (bytes === null) {
        return '';
    }

    return bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} Ko`
        : `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} Mo`;
}

const badge: Record<ExportRow['status'], string> = {
    pending: 'bg-info-soft text-info-soft-foreground',
    ready: 'bg-success-soft text-success-soft-foreground',
    failed: 'bg-danger-soft text-danger-soft-foreground',
    expired: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <Head :title="t('backup.page_title')" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('backup.heading')"
            :description="t('backup.description')"
        />

        <section class="space-y-3 rounded-xl border bg-card p-5">
            <h3 class="font-semibold">{{ t('backup.what_title') }}</h3>
            <ul class="space-y-2 text-sm">
                <li class="flex gap-3">
                    <FileSpreadsheet
                        class="mt-0.5 size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />{{ t('backup.what_readable') }}
                </li>
                <li class="flex gap-3">
                    <HardDriveDownload
                        class="mt-0.5 size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />{{ t('backup.what_full') }}
                </li>
                <li class="flex gap-3">
                    <ShieldCheck
                        class="mt-0.5 size-4 shrink-0 text-warning"
                        aria-hidden="true"
                    />{{ t('backup.what_private') }}
                </li>
            </ul>
            <Button
                class="h-11"
                :disabled="submitting || pending"
                @click="create"
            >
                <LoaderCircle
                    v-if="submitting || pending"
                    class="size-4 animate-spin"
                />
                <HardDriveDownload v-else class="size-4" />
                {{ t('backup.create') }}
            </Button>
            <p class="text-sm text-muted-foreground">
                {{ t('backup.keep_note', { days: keepDays }) }}
            </p>
            <Link
                :href="restoreCreate()"
                class="inline-flex min-h-11 items-center text-sm font-medium text-primary underline-offset-4 hover:underline"
                >{{ t('restore.from_backup_page') }}</Link
            >
        </section>

        <section class="space-y-3">
            <h3 class="font-semibold">{{ t('backup.list_title') }}</h3>
            <p
                v-if="exports.length === 0"
                class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground"
            >
                {{ t('backup.empty') }}
            </p>
            <ol v-else class="divide-y rounded-lg border">
                <li
                    v-for="row in exports"
                    :key="row.id"
                    class="flex flex-wrap items-center gap-3 p-4"
                >
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="font-medium">
                            {{ dateTime.format(new Date(row.created_at)) }}
                            <span
                                class="ml-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="badge[row.status]"
                            >
                                <LoaderCircle
                                    v-if="row.status === 'pending'"
                                    class="size-3 animate-spin"
                                />
                                <CircleCheck
                                    v-else-if="row.status === 'ready'"
                                    class="size-3"
                                />
                                <CircleAlert
                                    v-else-if="row.status === 'failed'"
                                    class="size-3"
                                />
                                {{ t(`backup.status.${row.status}`) }}
                            </span>
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ t('backup.by', { name: row.author }) }}
                            <template v-if="row.status === 'ready'">
                                ·
                                {{ t('backup.rows', { count: row.rows ?? 0 }) }}
                                · {{ size(row.size) }}
                            </template>
                        </p>
                        <p
                            v-if="row.status === 'ready' && row.expires_at"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                t('backup.expires', {
                                    date: date.format(new Date(row.expires_at)),
                                })
                            }}
                        </p>
                        <p
                            v-if="row.status === 'failed'"
                            class="text-sm text-danger"
                        >
                            {{ t('backup.retry') }}
                        </p>
                    </div>
                    <Button
                        v-if="row.status === 'ready'"
                        as-child
                        variant="outline"
                        class="h-11"
                        ><a :href="backupDownload(row.id).url"
                            ><Download class="size-4" />{{
                                t('backup.download')
                            }}</a
                        ></Button
                    >
                </li>
            </ol>
        </section>
    </div>
</template>
