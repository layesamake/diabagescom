<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Smartphone } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import {
    index as devicesIndex,
    revoke as revokeRoute,
} from '@/routes/sync/devices';
import { confirmAction } from '@/composables/useConfirm';
import EmptyState from '@/components/EmptyState.vue';

defineProps<{
    devices: {
        id: string;
        label: string;
        user: string;
        mine: boolean;
        last_seen_at: string | null;
        revoked_at: string | null;
        pending_review: number;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('offline.devices'), href: devicesIndex() }],
    },
});

async function revoke(id: string, name: string): Promise<void> {
    if (
        await confirmAction({
            title: t('offline.revoke_confirm', { name }),
            confirmLabel: t('confirm.revoke'),
            tone: 'danger',
        })
    ) {
        router.post(revokeRoute(id).url, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="t('offline.devices')" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('offline.devices')"
            :description="t('offline.devices_description')"
        />

        <EmptyState v-if="devices.length === 0">
            {{ t('offline.no_devices') }}
        </EmptyState>

        <ul v-else class="divide-y rounded-lg border">
            <li
                v-for="device in devices"
                :key="device.id"
                class="flex flex-wrap items-center gap-3 p-3"
            >
                <Smartphone
                    class="size-5 text-muted-foreground"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1 text-sm">
                    <p class="font-medium">{{ device.label }}</p>
                    <p class="text-muted-foreground">
                        {{ t('offline.device_user', { name: device.user }) }}
                        <template v-if="device.last_seen_at">
                            ·
                            {{
                                t('offline.device_seen', {
                                    date: formatDate(device.last_seen_at),
                                })
                            }}</template
                        >
                    </p>
                    <p v-if="device.revoked_at" class="text-destructive">
                        {{
                            t('offline.device_revoked', {
                                date: formatDate(device.revoked_at),
                            })
                        }}
                    </p>
                </div>
                <Button
                    v-if="!device.revoked_at"
                    variant="outline"
                    class="h-11"
                    @click="revoke(device.id, device.label)"
                    >{{ t('offline.revoke') }}</Button
                >
            </li>
        </ul>
    </div>
</template>
