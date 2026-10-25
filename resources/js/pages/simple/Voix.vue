<script setup lang="ts">
/**
 * Messages audio du mode simple (lot 4) — maquettes 05a, 05b et 05c.
 *
 * L'écran est réservé à l'administrateur : c'est lui qui choisit la langue de
 * la voix puis enregistre chaque phrase à sa façon. L'enregistrement utilise
 * l'API MediaRecorder du navigateur (webm/opus) ; chaque morceau est envoyé dès
 * l'arrêt. Le haut-parleur « play » n'apparaît qu'à côté des phrases déjà
 * enregistrées.
 *
 * Écran volontairement sans défilement horizontal sur 360 px : les onglets de
 * groupes défilent seuls, tout le reste est empilé.
 */
import { Head, router } from '@inertiajs/vue3';
import { Check, Mic, Play, Square, Volume2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';

type Segment = { index: number; clip_id: string | null };
type Phrase = {
    code: string;
    text: string;
    tokens: string[];
    segments: Segment[];
    disponible: boolean;
};
type Groupe = {
    name: string;
    core: boolean;
    restants: number;
    total: number;
    phrases: Phrase[];
};
type Langue = { code: string; name: string };

const props = defineProps<{
    language: string | null;
    language_name: string | null;
    voices_available: boolean;
    groups: Groupe[];
    progress: { done: number; total: number; complete: boolean };
    languages: Langue[];
    role: string;
    acces_administrateur: boolean;
}>();

const feuilleLangueOuverte = ref(false);
const groupeActif = ref<string>(
    props.groups.find((g) => g.core)?.name ?? props.groups[0]?.name ?? '',
);
const enregistrementActif = ref<string | null>(null);

let mediaRecorder: MediaRecorder | null = null;
let chunks: Blob[] = [];

function choisirLangue(code: string): void {
    feuilleLangueOuverte.value = false;
    router.put('/simple/voix/langue', { language: code });
}

function cleDeSegment(code: string, index: number): string {
    return `${code}-${index}`;
}

function enregistrer(code: string, index: number): void {
    if (enregistrementActif.value !== null) {
        return;
    }

    void demarrer(code, index).catch(() => {
        enregistrementActif.value = null;
    });
}

async function demarrer(code: string, index: number): Promise<void> {
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

function arreter(): void {
    if (mediaRecorder?.state === 'recording') {
        mediaRecorder.stop();
    }
}

function reecouter(clipId: string): void {
    new Audio(`/simple/voix/clips/${clipId}`).play().catch(() => {});
}

const phrasesDuGroupe = computed(
    () => props.groups.find((g) => g.name === groupeActif.value)?.phrases ?? [],
);

const pourcentage = computed(() =>
    props.progress.total === 0
        ? 0
        : Math.round((props.progress.done / props.progress.total) * 100),
);
</script>

<template>
    <Head title="Messages audio" />

    <EnTeteEcran
        titre="Messages audio"
        :sous-titre="role"
        :sous-titre-verrouille="acces_administrateur"
        :retour-visible="true"
    />

    <div class="grid gap-4 p-4 pb-2">
        <!-- Langue de la voix : lecture seule + bouton Changer (maquette 05a) -->
        <div
            class="flex items-center gap-3 rounded-[20px] p-4"
            :style="{
                backgroundColor: 'var(--s-marque)',
                color: 'var(--s-sur-marque)',
            }"
        >
            <Volume2 :size="26" :stroke-width="2" aria-hidden="true" />
            <div class="min-w-0 flex-1">
                <p class="text-[15px] opacity-90">Langue de la voix</p>
                <p class="truncate text-[22px] leading-tight font-extrabold">
                    {{ language_name ?? 'À choisir' }}
                </p>
            </div>
            <button
                type="button"
                class="min-h-12 shrink-0 rounded-full px-5 text-[15px] font-extrabold"
                :style="{
                    backgroundColor: 'var(--s-vert-clair)',
                    color: 'var(--s-vert-fonce)',
                }"
                @click="feuilleLangueOuverte = true"
            >
                Changer
            </button>
        </div>

        <!-- Compteur d'enregistrements + barre de progression -->
        <div
            class="rounded-[20px] p-4"
            :style="{
                backgroundColor: 'var(--s-marque)',
                color: 'var(--s-sur-marque)',
            }"
        >
            <div class="flex items-baseline justify-between gap-2">
                <span class="text-[15px] font-semibold">Enregistrés</span>
                <span class="text-[15px] font-bold tabular-nums">
                    {{ progress.done }} sur {{ progress.total }}
                </span>
            </div>
            <div
                class="mt-2 h-2.5 w-full overflow-hidden rounded-full"
                :style="{ backgroundColor: 'var(--s-marque-2)' }"
                role="progressbar"
                :aria-valuenow="progress.done"
                :aria-valuemin="0"
                :aria-valuemax="progress.total"
                :aria-label="`${progress.done} phrases enregistrées sur ${progress.total}`"
            >
                <div
                    class="h-full rounded-full"
                    :style="{
                        width: `${pourcentage}%`,
                        backgroundColor: 'var(--s-vert-clair)',
                    }"
                />
            </div>
        </div>

        <!-- Onglets des groupes, défilement horizontal isolé -->
        <nav
            class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1"
            aria-label="Groupes de phrases"
        >
            <button
                v-for="groupe in groups"
                :key="groupe.name"
                type="button"
                class="flex min-h-12 shrink-0 items-center gap-2 rounded-full px-5 text-[15px] font-extrabold whitespace-nowrap"
                :style="
                    groupe.name === groupeActif
                        ? {
                              backgroundColor: 'var(--s-marque)',
                              color: 'var(--s-sur-marque)',
                          }
                        : {
                              backgroundColor: 'var(--s-surface)',
                              color: 'var(--s-texte)',
                              border: '1px solid var(--s-bordure)',
                          }
                "
                :aria-current="groupe.name === groupeActif ? 'page' : undefined"
                @click="groupeActif = groupe.name"
            >
                {{ groupe.name }}
                <span
                    v-if="groupe.restants > 0"
                    class="rounded-full px-2 text-[15px] font-extrabold tabular-nums"
                    :style="{
                        backgroundColor: 'var(--s-rouge)',
                        color: '#FFFFFF',
                    }"
                >
                    {{ groupe.restants }}
                </span>
            </button>
        </nav>

        <!-- Bandeau d'aide -->
        <p
            class="rounded-[16px] p-4 text-[15px]"
            :style="{
                backgroundColor: 'var(--s-surface)',
                color: 'var(--s-texte-doux)',
                border: '1px solid var(--s-bordure)',
            }"
        >
            Dites chaque phrase en {{ language_name ?? 'votre langue' }}, à
            votre façon. Dans l'application, le haut-parleur n'apparaît qu'à
            côté des phrases enregistrées.
        </p>

        <div class="grid gap-3">
            <article
                v-for="phrase in phrasesDuGroupe"
                :key="phrase.code"
                class="grid gap-3 rounded-[20px] p-4"
                :style="{
                    backgroundColor:
                        enregistrementActif &&
                        enregistrementActif.startsWith(`${phrase.code}-`)
                            ? 'var(--s-rouge-clair)'
                            : 'var(--s-surface)',
                    border:
                        enregistrementActif &&
                        enregistrementActif.startsWith(`${phrase.code}-`)
                            ? '2px solid var(--s-rouge)'
                            : '1px solid var(--s-bordure)',
                }"
            >
                <div class="flex items-start gap-3">
                    <Check
                        v-if="phrase.disponible"
                        :size="24"
                        :stroke-width="2"
                        class="mt-0.5 shrink-0"
                        :style="{ color: 'var(--s-vert)' }"
                        aria-label="Phrase enregistrée"
                    />
                    <span
                        v-else
                        class="mt-1 inline-block size-6 shrink-0 rounded-full"
                        :style="{ backgroundColor: 'var(--s-bordure)' }"
                        aria-hidden="true"
                    />
                    <p class="text-[17px] leading-snug font-semibold">
                        « {{ phrase.text }} »
                    </p>
                </div>

                <div
                    v-for="segment in phrase.segments"
                    :key="segment.index"
                    class="grid gap-2"
                >
                    <template
                        v-if="
                            enregistrementActif !==
                            cleDeSegment(phrase.code, segment.index)
                        "
                    >
                        <!-- Phrase déjà enregistrée : Refaire + haut-parleur -->
                        <div v-if="segment.clip_id" class="flex gap-2">
                            <button
                                type="button"
                                class="flex min-h-14 flex-1 items-center justify-center gap-2 rounded-[16px] px-3 text-[15px] font-extrabold"
                                :style="{
                                    backgroundColor: 'var(--s-fond)',
                                    color: 'var(--s-texte)',
                                }"
                                @click="enregistrer(phrase.code, segment.index)"
                            >
                                <Mic
                                    :size="18"
                                    :stroke-width="2"
                                    aria-hidden="true"
                                />
                                Refaire
                            </button>
                            <button
                                type="button"
                                class="flex min-h-14 w-14 items-center justify-center rounded-[16px]"
                                :style="{
                                    backgroundColor: 'var(--s-vert-clair)',
                                    color: 'var(--s-vert-fonce)',
                                }"
                                aria-label="Écouter la phrase"
                                @click="reecouter(segment.clip_id)"
                            >
                                <Play :size="20" :stroke-width="2" />
                            </button>
                        </div>

                        <!-- Phrase vide : grand bouton rouge pleine largeur -->
                        <button
                            v-else
                            type="button"
                            class="flex min-h-14 w-full items-center justify-center gap-2 rounded-[16px] px-3 text-[16px] font-extrabold"
                            :style="{
                                backgroundColor: 'var(--s-rouge)',
                                color: '#FFFFFF',
                            }"
                            @click="enregistrer(phrase.code, segment.index)"
                        >
                            <Mic
                                :size="18"
                                :stroke-width="2"
                                aria-hidden="true"
                            />
                            Enregistrer
                        </button>
                    </template>

                    <!-- Maquette 05b : enregistrement en cours -->
                    <div v-else class="flex items-center gap-3">
                        <p
                            class="min-w-0 flex-1 text-[15px] font-extrabold"
                            :style="{ color: 'var(--s-rouge)' }"
                        >
                            <Mic
                                :size="16"
                                :stroke-width="2"
                                class="inline"
                                aria-hidden="true"
                            />
                            J'écoute… parlez maintenant
                        </p>
                        <button
                            type="button"
                            class="flex min-h-14 shrink-0 items-center gap-2 rounded-[16px] px-5 text-[15px] font-extrabold"
                            :style="{
                                backgroundColor: 'var(--s-rouge)',
                                color: '#FFFFFF',
                            }"
                            @click="arreter"
                        >
                            <Square
                                :size="16"
                                :stroke-width="2"
                                aria-hidden="true"
                            />
                            Fini
                        </button>
                    </div>
                </div>
            </article>
        </div>
    </div>

    <!-- Pied d'écran : sortie sans tout bloquer -->
    <div class="sticky bottom-0 p-4 pt-2">
        <button
            type="button"
            class="min-h-14 w-full rounded-[20px] px-4 text-[16px] font-extrabold"
            :style="{
                backgroundColor: 'var(--s-vert)',
                color: 'var(--s-sur-marque)',
            }"
            @click="router.visit('/simple')"
        >
            Terminer pour l'instant
        </button>
    </div>

    <!-- Maquette 05c : feuille de choix de la langue -->
    <div
        v-if="feuilleLangueOuverte"
        class="fixed inset-0 z-50 flex items-end"
        :style="{ backgroundColor: 'rgba(0,0,0,0.45)' }"
        role="dialog"
        aria-modal="true"
        aria-labelledby="titre-langue"
        @click.self="feuilleLangueOuverte = false"
    >
        <div
            class="w-full rounded-t-[28px] p-5"
            :style="{
                backgroundColor: 'var(--s-surface)',
                color: 'var(--s-texte)',
            }"
        >
            <div class="flex items-start justify-between gap-3">
                <h2
                    id="titre-langue"
                    class="text-[22px] leading-tight font-extrabold"
                >
                    Quelle langue pour la voix ?
                </h2>
                <button
                    type="button"
                    class="inline-flex size-12 shrink-0 items-center justify-center rounded-full"
                    :style="{
                        backgroundColor: 'var(--s-fond)',
                        color: 'var(--s-texte)',
                    }"
                    aria-label="Fermer"
                    @click="feuilleLangueOuverte = false"
                >
                    <X :size="22" :stroke-width="2" />
                </button>
            </div>

            <p
                class="mt-1 text-[15px]"
                :style="{ color: 'var(--s-texte-doux)' }"
            >
                Une seule langue pour toute l'entreprise. Les textes à l'écran
                restent en français.
            </p>

            <div class="mt-4 grid grid-cols-2 gap-2">
                <button
                    v-for="langue in languages"
                    :key="langue.code"
                    type="button"
                    class="min-h-14 rounded-[16px] px-3 text-[15px] font-extrabold"
                    :style="
                        langue.code === language
                            ? {
                                  backgroundColor: 'var(--s-marque)',
                                  color: 'var(--s-sur-marque)',
                              }
                            : {
                                  backgroundColor: 'var(--s-surface)',
                                  color: 'var(--s-texte)',
                                  border: '1px solid var(--s-bordure)',
                              }
                    "
                    :aria-pressed="langue.code === language"
                    @click="choisirLangue(langue.code)"
                >
                    {{ langue.name }}
                </button>
            </div>

            <button
                type="button"
                class="mt-4 min-h-14 w-full rounded-[16px] px-4 text-[15px] font-extrabold"
                :style="{
                    backgroundColor: 'var(--s-surface)',
                    color: 'var(--s-texte)',
                    border: '1px solid var(--s-bordure)',
                }"
                @click="feuilleLangueOuverte = false"
            >
                Fermer
            </button>
        </div>
    </div>
</template>
