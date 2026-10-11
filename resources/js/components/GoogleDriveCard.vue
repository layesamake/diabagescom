<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    CloudUpload,
    HardDrive,
    Unlink,
} from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import {
    connect as driveConnect,
    destroy as driveDestroy,
    send as driveSend,
    update as driveUpdate,
} from '@/routes/backup/google';

export type DriveState = {
    available: boolean;
    keep: number;
    connection: {
        email: string | null;
        frequency: 'daily' | 'weekly';
        last_run_at: string | null;
        last_status: 'ok' | 'failed' | null;
        last_error: string | null;
    } | null;
};

const props = defineProps<{ drive: DriveState; busy: boolean }>();

const confirming = ref(false);
const dateTime = new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function setFrequency(frequency: string): void {
    router.put(driveUpdate().url, { frequency }, { preserveScroll: true });
}

function sendNow(): void {
    router.post(driveSend().url, {}, { preserveScroll: true });
}

function disconnect(): void {
    router.delete(driveDestroy().url, {
        preserveScroll: true,
        onFinish: () => (confirming.value = false),
    });
}
</script>

<template>
    <section class="space-y-4 rounded-xl border bg-card p-5">
        <div class="flex items-start gap-3">
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
            >
                <HardDrive class="size-5" aria-hidden="true" />
            </span>
            <div class="space-y-1">
                <h3 class="font-semibold">{{ t('backup.drive.title') }}</h3>
                <p class="text-sm text-muted-foreground">
                    {{ t('backup.drive.description', { keep: drive.keep }) }}
                </p>
            </div>
        </div>

        <p
            v-if="!drive.available"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
        >
            {{ t('backup.drive.unavailable') }}
        </p>

        <template v-else-if="!drive.connection">
            <p class="text-sm text-muted-foreground">
                {{ t('backup.drive.privacy') }}
            </p>
            <!-- Lien classique : la page d'autorisation de Google s'ouvre dans l'onglet. -->
            <Button as-child class="h-11"
                ><a :href="driveConnect().url"
                    ><HardDrive class="size-4" />{{
                        t('backup.drive.connect')
                    }}</a
                ></Button
            >
        </template>

        <template v-else>
            <p class="flex items-center gap-2 text-sm">
                <CircleCheck class="size-4 text-success" aria-hidden="true" />
                {{
                    t('backup.drive.connected', {
                        email: drive.connection.email ?? 'Google',
                    })
                }}
            </p>

            <fieldset class="space-y-2">
                <legend class="mb-2 text-sm font-medium">
                    {{ t('backup.drive.frequency') }}
                </legend>
                <div class="grid grid-cols-2 gap-2 sm:max-w-sm">
                    <label
                        v-for="option in ['daily', 'weekly'] as const"
                        :key="option"
                        class="flex min-h-11 cursor-pointer items-center justify-center rounded-md border px-3 text-sm font-medium has-checked:border-primary has-checked:bg-primary/10 has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                    >
                        <input
                            type="radio"
                            name="drive-frequency"
                            class="sr-only"
                            :value="option"
                            :checked="drive.connection.frequency === option"
                            @change="setFrequency(option)"
                        />
                        {{ t(`backup.drive.${option}`) }}
                    </label>
                </div>
            </fieldset>

            <p
                v-if="drive.connection.last_run_at"
                class="flex items-center gap-2 text-sm"
                :class="
                    drive.connection.last_status === 'failed'
                        ? 'text-danger'
                        : 'text-muted-foreground'
                "
            >
                <CircleAlert
                    v-if="drive.connection.last_status === 'failed'"
                    class="size-4 shrink-0"
                    aria-hidden="true"
                />
                {{
                    drive.connection.last_status === 'failed'
                        ? drive.connection.last_error
                        : t('backup.drive.last_run', {
                              date: dateTime.format(
                                  new Date(drive.connection.last_run_at),
                              ),
                          })
                }}
            </p>

            <div class="flex flex-wrap gap-2">
                <Button class="h-11" :disabled="props.busy" @click="sendNow"
                    ><CloudUpload class="size-4" />{{
                        t('backup.drive.send_now')
                    }}</Button
                >
                <Button
                    v-if="!confirming"
                    variant="outline"
                    class="h-11"
                    @click="confirming = true"
                    ><Unlink class="size-4" />{{
                        t('backup.drive.disconnect')
                    }}</Button
                >
                <template v-else>
                    <Button
                        variant="destructive"
                        class="h-11"
                        @click="disconnect"
                        >{{ t('backup.drive.disconnect_confirm') }}</Button
                    >
                    <Button
                        variant="ghost"
                        class="h-11"
                        @click="confirming = false"
                        >{{ t('backup.drive.cancel') }}</Button
                    >
                </template>
            </div>
            <p v-if="confirming" class="text-sm text-muted-foreground">
                {{ t('backup.drive.disconnect_hint') }}
            </p>
        </template>
    </section>
</template>
