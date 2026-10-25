<script setup lang="ts">
/**
 * Saisie d'un article dans le mode simple : les réglages techniques (SKU, taxe,
 * type) restent côté serveur afin que le vendeur ne renseigne que l'essentiel.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { Camera, ImagePlus, PackagePlus } from '@lucide/vue';
import { ref } from 'vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';
import { formatFranc } from '@/lib/money';

type Option = { id: string; name: string };
type Unit = Option & { code: string };

const props = defineProps<{
    categories: Option[];
    units: Unit[];
}>();

const form = useForm<{
    name: string;
    sale_price: number | null;
    unit_id: string;
    category_id: string;
    image: File | null;
}>({
    name: '',
    sale_price: null,
    unit_id: props.units.find((unit) => unit.code === 'U')?.id ?? '',
    category_id: '',
    image: null,
});
const imageInput = ref<HTMLInputElement | null>(null);
const photoPreview = ref<string | null>(null);

function choosePhoto(event: Event): void {
    const image = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.image = image;
    photoPreview.value = image ? URL.createObjectURL(image) : null;
}

function enregistrer(): void {
    form.post('/simple/articles', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.unit_id =
                props.units.find((unit) => unit.code === 'U')?.id ?? '';
            photoPreview.value = null;
            if (imageInput.value) imageInput.value.value = '';
        },
    });
}
</script>

<template>
    <Head title="Ajouter un article" />

    <EnTeteEcran
        class="lg:hidden"
        titre="Ajouter un article"
        :icone="PackagePlus"
        :retour-visible="false"
    />

    <form
        class="mx-auto grid max-w-[760px] gap-5 p-4 pb-28 lg:p-0 lg:pb-0"
        @submit.prevent="enregistrer"
    >
        <header>
            <h1
                class="sr-only text-[28px] leading-tight font-extrabold lg:not-sr-only lg:text-[32px]"
            >
                Ajouter un article
            </h1>
            <p
                class="mt-1 text-[16px] font-semibold"
                :style="{ color: 'var(--s-texte-doux)' }"
            >
                Indiquez seulement ce qui aide à vendre l’article.
            </p>
        </header>

        <section
            class="grid gap-4 rounded-[24px] border p-4"
            :style="{ borderColor: 'var(--s-bordure)' }"
        >
            <div class="grid gap-2">
                <label for="product-name" class="text-[17px] font-extrabold"
                    >Nom de l’article</label
                >
                <input
                    id="product-name"
                    v-model="form.name"
                    required
                    maxlength="160"
                    autocomplete="off"
                    class="h-16 rounded-[20px] border px-4 text-[20px] font-bold"
                    :style="{ borderColor: 'var(--s-bordure)' }"
                    placeholder="Ex. Huile d’arachide 1 L"
                />
                <p v-if="form.errors.name" class="font-bold text-red-700">
                    {{ form.errors.name }}
                </p>
            </div>

            <div class="grid gap-2">
                <label for="product-price" class="text-[17px] font-extrabold"
                    >Prix de vente</label
                >
                <input
                    id="product-price"
                    v-model.number="form.sale_price"
                    required
                    type="number"
                    min="0"
                    max="9999999999999"
                    inputmode="numeric"
                    class="h-16 rounded-[20px] border px-4 text-center text-[24px] font-extrabold tabular-nums"
                    :style="{ borderColor: 'var(--s-bordure)' }"
                    placeholder="0"
                />
                <p
                    v-if="form.sale_price !== null"
                    class="text-[15px] font-semibold"
                    :style="{ color: 'var(--s-texte-doux)' }"
                >
                    {{ formatFranc(form.sale_price) }}
                </p>
                <p v-if="form.errors.sale_price" class="font-bold text-red-700">
                    {{ form.errors.sale_price }}
                </p>
            </div>
        </section>

        <section
            class="grid gap-4 rounded-[24px] border p-4 sm:grid-cols-2"
            :style="{ borderColor: 'var(--s-bordure)' }"
        >
            <div class="grid gap-2">
                <label for="product-unit" class="text-[17px] font-extrabold"
                    >Vendu par</label
                >
                <select
                    id="product-unit"
                    v-model="form.unit_id"
                    required
                    class="h-14 rounded-[18px] border bg-white px-3 text-[17px] font-bold"
                    :style="{ borderColor: 'var(--s-bordure)' }"
                >
                    <option
                        v-for="unit in units"
                        :key="unit.id"
                        :value="unit.id"
                    >
                        {{ unit.name }} ({{ unit.code }})
                    </option>
                </select>
                <p v-if="form.errors.unit_id" class="font-bold text-red-700">
                    {{ form.errors.unit_id }}
                </p>
            </div>

            <div class="grid gap-2">
                <label for="product-category" class="text-[17px] font-extrabold"
                    >Famille</label
                >
                <select
                    id="product-category"
                    v-model="form.category_id"
                    class="h-14 rounded-[18px] border bg-white px-3 text-[17px] font-bold"
                    :style="{ borderColor: 'var(--s-bordure)' }"
                >
                    <option value="">Sans famille</option>
                    <option
                        v-for="category in categories"
                        :key="category.id"
                        :value="category.id"
                    >
                        {{ category.name }}
                    </option>
                </select>
                <p
                    v-if="form.errors.category_id"
                    class="font-bold text-red-700"
                >
                    {{ form.errors.category_id }}
                </p>
            </div>
        </section>

        <section
            class="grid gap-3 rounded-[24px] border p-4"
            :style="{ borderColor: 'var(--s-bordure)' }"
        >
            <p class="text-[17px] font-extrabold">Photo de l'article</p>
            <div class="flex items-center gap-4">
                <img
                    v-if="photoPreview"
                    :src="photoPreview"
                    alt="Aperçu de la photo de l'article"
                    class="size-24 rounded-[20px] object-cover"
                />
                <span
                    v-else
                    class="grid size-24 place-items-center rounded-[20px]"
                    :style="{
                        backgroundColor: 'var(--s-fond)',
                        color: 'var(--s-texte-doux)',
                    }"
                >
                    <ImagePlus :size="32" aria-hidden="true" />
                </span>
                <label
                    for="product-image"
                    class="inline-flex min-h-12 cursor-pointer items-center gap-2 rounded-[18px] px-4 text-[17px] font-extrabold"
                    :style="{
                        backgroundColor: 'var(--s-bleu-clair)',
                        color: 'var(--s-sur-bleu-clair)',
                    }"
                >
                    <Camera :size="22" aria-hidden="true" /> Prendre ou choisir
                    <input
                        id="product-image"
                        ref="imageInput"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        capture="environment"
                        class="sr-only"
                        @change="choosePhoto"
                    />
                </label>
            </div>
            <p
                class="text-[15px] font-semibold"
                :style="{ color: 'var(--s-texte-doux)' }"
            >
                Facultative, mais utile pour reconnaître l’article.
            </p>
            <p v-if="form.errors.image" class="font-bold text-red-700">
                {{ form.errors.image }}
            </p>
        </section>

        <button
            type="submit"
            class="min-h-16 rounded-[24px] text-[20px] font-extrabold disabled:opacity-50"
            :style="{
                backgroundColor: 'var(--s-vert)',
                color: 'var(--s-sur-vert)',
            }"
            :disabled="form.processing"
        >
            {{ form.processing ? 'Enregistrement…' : 'Enregistrer l’article' }}
        </button>
    </form>
</template>
