<script setup lang="ts">
import { Camera, CircleAlert, CircleCheck, X } from '@lucide/vue';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import {
    createDetector,
    openCamera,
    signalScan,
    type Detector,
} from '@/lib/barcodeScanner';

/**
 * Scan par la caméra (téléphone, tablette) : la caméra arrière reste ouverte
 * et chaque code lu est transmis ; un même code n'est repris qu'après une
 * courte pause, pour scanner plusieurs articles à la suite sans doublon.
 */
const open = defineModel<boolean>('open', { default: false });
const props = defineProps<{
    /** Dernier article ajouté, affiché en confirmation sous l'image. */
    feedback?: { ok: boolean; text: string } | null;
}>();
const emit = defineEmits<{ detected: [code: string] }>();

const video = ref<HTMLVideoElement | null>(null);
const state = ref<'starting' | 'ready' | 'denied' | 'error'>('starting');
let stream: MediaStream | null = null;
let detector: Detector | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;
let last = { code: '', at: 0 };

async function start(): Promise<void> {
    state.value = 'starting';

    try {
        [stream, detector] = await Promise.all([
            openCamera(),
            createDetector(),
        ]);
        await nextTick();

        if (!video.value || !open.value) {
            stop();

            return;
        }

        video.value.srcObject = stream;
        await video.value.play();
        state.value = 'ready';
        loop();
    } catch (error) {
        state.value =
            error instanceof DOMException && error.name === 'NotAllowedError'
                ? 'denied'
                : 'error';
        stop();
    }
}

function loop(): void {
    timer = setTimeout(async () => {
        if (!open.value || !detector || !video.value) {
            return;
        }

        try {
            const [found] = await detector.detect(video.value);
            const now = Date.now();

            if (
                found?.rawValue &&
                (found.rawValue !== last.code || now - last.at > 1500)
            ) {
                last = { code: found.rawValue, at: now };
                signalScan();
                emit('detected', found.rawValue);
            }
        } catch {
            // Image illisible (flou, reflet) : on réessaie à l'image suivante.
        }

        loop();
    }, 150);
}

function stop(): void {
    clearTimeout(timer);
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
}

watch(open, (value) => (value ? void start() : stop()));
onBeforeUnmount(stop);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex flex-col bg-black/95 text-white"
            role="dialog"
            aria-modal="true"
            :aria-label="t('scanner.title')"
        >
            <div class="flex items-center justify-between gap-3 p-4">
                <p class="flex items-center gap-2 font-medium">
                    <Camera class="size-5" aria-hidden="true" />
                    {{ t('scanner.title') }}
                </p>
                <Button variant="secondary" class="h-11" @click="open = false"
                    ><X class="size-4" />{{ t('scanner.done') }}</Button
                >
            </div>

            <div class="relative flex flex-1 items-center justify-center">
                <video
                    ref="video"
                    class="h-full max-h-[70vh] w-full object-cover"
                    playsinline
                    muted
                />
                <!-- Cadre de visée -->
                <div
                    v-if="state === 'ready'"
                    class="pointer-events-none absolute inset-x-8 top-1/2 h-40 -translate-y-1/2 rounded-xl border-4 border-primary/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)]"
                    aria-hidden="true"
                />
                <p
                    v-if="state === 'starting'"
                    class="absolute text-center text-sm text-white/80"
                >
                    {{ t('scanner.starting') }}
                </p>
                <div
                    v-if="state === 'denied' || state === 'error'"
                    class="absolute mx-6 max-w-sm space-y-2 rounded-xl bg-white/10 p-5 text-center"
                    role="alert"
                >
                    <CircleAlert class="mx-auto size-8" aria-hidden="true" />
                    <p class="font-medium">
                        {{
                            state === 'denied'
                                ? t('scanner.denied')
                                : t('scanner.error')
                        }}
                    </p>
                    <p class="text-sm text-white/80">
                        {{ t('scanner.fallback') }}
                    </p>
                </div>
            </div>

            <div class="min-h-24 space-y-1 p-4 text-center" aria-live="polite">
                <p
                    v-if="props.feedback"
                    class="inline-flex items-center gap-2 rounded-full px-4 py-2 font-medium"
                    :class="
                        props.feedback.ok
                            ? 'bg-success text-white'
                            : 'bg-danger text-white'
                    "
                >
                    <CircleCheck
                        v-if="props.feedback.ok"
                        class="size-5"
                        aria-hidden="true"
                    />
                    <CircleAlert v-else class="size-5" aria-hidden="true" />
                    {{ props.feedback.text }}
                </p>
                <p v-else class="text-sm text-white/80">
                    {{ t('scanner.hint') }}
                </p>
            </div>
        </div>
    </Teleport>
</template>
