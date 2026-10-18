<script setup lang="ts">
/**
 * Messages audio du mode simple (lot 4) : langue de la voix + enregistrement
 * phrase par phrase. Réservé à l'administrateur.
 *
 * L'enregistrement utilise l'API MediaRecorder du navigateur (webm/opus) ;
 * chaque morceau est envoyé dès l'arrêt de l'enregistrement. Un haut-parleur
 * « Réécouter » n'apparaît que si le morceau existe déjà.
 */
import { Head, router } from '@inertiajs/vue3';
import { Mic, Square, Volume2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import BarreEtapes from '@/components/simple/BarreEtapes.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';

type Segment = { index: number; clip_id: string | null };
type Phrase = {
    code: string;
    text: string;
    tokens: string[];
    segments: Segment[];
    disponible: boolean;
};
type Groupe = { name: string; core: boolean; phrases: Phrase[] };

const props = defineProps<{
    language: string | null;
    groups: Groupe[];
    progress: { done: number; total: number; complete: boolean };
}>();

const LANGUES = [
    { code: 'wo', nom: 'Wolof' },
    { code: 'ff', nom: 'Pulaar' },
    { code: 'bm', nom: 'Bambara' },
    { code: 'srr', nom: 'Sérère' },
    { code: 'dyo', nom: 'Diola' },
    { code: 'snk', nom: 'Soninké' },
    { code: 'mnk', nom: 'Mandinka' },
];

const langueChoisie = ref(props.language ?? '');
const groupeOuvert = ref<string | null>(
    props.groups.find((g) => g.core)?.name ?? null,
);
const enregistrementActif = ref<string | null>(null);

let mediaRecorder: MediaRecorder | null = null;
let chunks: Blob[] = [];

function choisirLangue(): void {
    if (langueChoisie.value === '') {
        return;
    }
    router.put('/simple/voix/langue', { language: langueChoisie.value });
}

function cleDeSegment(code: string, index: number): string {
    return `${code}-${index}`;
}

async function demarrerEnregistrement(
    code: string,
    index: number,
): Promise<void> {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    chunks = [];
    mediaRecorder = new MediaRecorder(stream);
    mediaRecorder.ondataavailable = (event) => chunks.push(event.data);
    mediaRecorder.onstop = () => {
        stream.getTracks().forEach((track) => track.stop());
        const blob = new Blob(chunks, { type: 'audio/webm' });
        const form = new FormData();
        form.append('audio', blob, `${code}-${index}.webm`);
        router.post(`/simple/voix/${code}/${index}`, form, {
            forceFormData: true,
        });
        enregistrementActif.value = null;
    };
    mediaRecorder.start();
    enregistrementActif.value = cleDeSegment(code, index);

    // Au plus 5 secondes par morceau, comme demandé au cahier des charges.
    setTimeout(() => {
        if (mediaRecorder?.state === 'recording') {
            mediaRecorder.stop();
        }
    }, 5000);
}

function arreterEnregistrement(): void {
    if (mediaRecorder?.state === 'recording') {
        mediaRecorder.stop();
    }
}

function reecouter(clipId: string): void {
    new Audio(`/simple/voix/clips/${clipId}`).play().catch(() => {});
}

const groupeActif = computed(
    () => props.groups.find((g) => g.name === groupeOuvert.value) ?? null,
);
</script>

<template>
    <Head title="Messages audio" />

    <EnTeteEcran titre="Messages audio" :retour-visible="true" />

    <div class="grid gap-4 p-4">
        <div
            class="grid gap-3 rounded-[24px] p-4"
            :style="{
                backgroundColor: 'var(--s-marque)',
                color: 'var(--s-sur-marque)',
            }"
        >
            <label class="grid gap-1 text-[15px] font-semibold">
                Langue de la voix
                <select
                    v-model="langueChoisie"
                    class="min-h-12 rounded-[16px] px-3 text-[17px] text-[var(--s-texte)]"
                    @change="choisirLangue"
                >
                    <option value="" disabled>Choisissez une langue</option>
                    <option v-for="l in LANGUES" :key="l.code" :value="l.code">
                        {{ l.nom }}
                    </option>
                    <option value="autre">Autre</option>
                </select>
            </label>

            <BarreEtapes
                v-if="language"
                :total="progress.total"
                :faites="progress.done"
                libelle="Enregistrements de base (Communs, Chiffres, Vendre, Caisse)"
            />
        </div>

        <p
            v-if="!language"
            class="text-[15px]"
            :style="{ color: 'var(--s-texte-doux)' }"
        >
            Choisissez d'abord une langue pour commencer à enregistrer.
        </p>

        <div v-else class="grid gap-2">
            <button
                v-for="groupe in groups"
                :key="groupe.name"
                type="button"
                class="flex min-h-14 items-center justify-between rounded-[20px] px-4 text-[17px] font-semibold"
                :style="{
                    backgroundColor: 'var(--s-surface)',
                    border: '1px solid var(--s-bordure)',
                }"
                @click="
                    groupeOuvert =
                        groupeOuvert === groupe.name ? null : groupe.name
                "
            >
                <span>{{ groupe.name }}</span>
                <span
                    class="text-[14px]"
                    :style="{ color: 'var(--s-texte-doux)' }"
                >
                    {{ groupe.phrases.filter((p) => p.disponible).length }} /
                    {{ groupe.phrases.length }}
                </span>
            </button>

            <div
                v-if="groupeActif"
                class="grid gap-3 rounded-[20px] p-3"
                :style="{ backgroundColor: 'var(--s-surface)' }"
            >
                <div
                    v-for="phrase in groupeActif.phrases"
                    :key="phrase.code"
                    class="grid gap-2 rounded-[16px] p-3"
                    :style="{ border: '1px solid var(--s-bordure)' }"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[17px]">{{ phrase.text }}</span>
                        <Volume2
                            v-if="phrase.disponible"
                            :size="20"
                            :stroke-width="2"
                            :style="{ color: 'var(--s-vert-fonce)' }"
                            aria-hidden="true"
                        />
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <div
                            v-for="segment in phrase.segments"
                            :key="segment.index"
                            class="flex items-center gap-2"
                        >
                            <button
                                v-if="
                                    enregistrementActif !==
                                    cleDeSegment(phrase.code, segment.index)
                                "
                                type="button"
                                class="flex min-h-12 items-center gap-2 rounded-[16px] px-3 text-[15px] font-semibold"
                                :style="{
                                    backgroundColor: 'var(--s-rouge-clair)',
                                    color: 'var(--s-rouge)',
                                }"
                                @click="
                                    demarrerEnregistrement(
                                        phrase.code,
                                        segment.index,
                                    )
                                "
                            >
                                <Mic
                                    :size="18"
                                    :stroke-width="2"
                                    aria-hidden="true"
                                />
                                {{
                                    segment.clip_id ? 'Refaire' : 'Enregistrer'
                                }}
                            </button>
                            <button
                                v-else
                                type="button"
                                class="flex min-h-12 items-center gap-2 rounded-[16px] px-3 text-[15px] font-semibold"
                                :style="{
                                    backgroundColor: 'var(--s-rouge)',
                                    color: '#FFFFFF',
                                }"
                                @click="arreterEnregistrement"
                            >
                                <Square
                                    :size="18"
                                    :stroke-width="2"
                                    aria-hidden="true"
                                />
                                Arrêter
                            </button>

                            <button
                                v-if="segment.clip_id"
                                type="button"
                                class="flex min-h-12 items-center justify-center rounded-full"
                                :style="{
                                    width: '44px',
                                    height: '44px',
                                    backgroundColor: 'var(--s-marque-2)',
                                    color: 'var(--s-sur-marque)',
                                }"
                                aria-label="Réécouter"
                                @click="reecouter(segment.clip_id)"
                            >
                                <Volume2 :size="20" :stroke-width="2" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
