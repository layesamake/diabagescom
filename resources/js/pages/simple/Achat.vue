<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { PackageCheck, Truck } from '@lucide/vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';

type Ligne = {
    id: string;
    product_id: string;
    name: string;
    unit: string;
    quantity: string;
    received: string;
    remaining: string;
};

defineProps<{
    order: {
        id: string;
        number: string | null;
        status: string;
        supplier: { id: string; name: string };
        lines: Ligne[];
    };
    can: { receive: boolean };
    audio_code: string;
}>();
</script>

<template>
    <Head title="Recevoir" />

    <EnTeteEcran
        titre="Recevoir"
        :icone="Truck"
        :code-audio="audio_code"
        :audio-disponible="true"
        @retour="router.visit('/simple/achats')"
    />

    <main class="grid gap-4 p-4 pb-28 lg:max-w-3xl lg:p-8">
        <section class="rounded-[22px] border p-4" :style="{ borderColor: 'var(--s-bordure)' }">
            <p class="text-[20px] font-extrabold">{{ order.supplier.name }}</p>
            <p v-if="order.number" class="mt-1 text-sm font-semibold" :style="{ color: 'var(--s-texte-doux)' }">
                {{ order.number }}
            </p>
        </section>

        <p class="text-[18px] font-extrabold">Comptez ce que vous avez reçu.</p>

        <section class="overflow-hidden rounded-[22px] border" :style="{ borderColor: 'var(--s-bordure)' }">
            <article v-for="line in order.lines" :key="line.id" class="flex items-center gap-3 border-b p-4 last:border-b-0" :style="{ borderColor: 'var(--s-bordure)' }">
                <PackageCheck class="size-10 shrink-0" :style="{ color: 'var(--s-bleu)' }" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="text-[17px] font-extrabold">{{ line.name }}</p>
                    <p class="text-[15px] font-semibold" :style="{ color: 'var(--s-texte-doux)' }">
                        Reste {{ line.remaining }} {{ line.unit }} sur {{ line.quantity }}
                    </p>
                </div>
            </article>
        </section>

        <p v-if="!can.receive" class="rounded-[18px] p-4 font-semibold" :style="{ backgroundColor: 'var(--s-orange-clair)', color: 'var(--s-sur-orange-clair)' }">
            Vous pouvez voir cette commande, mais seul un responsable autorisé peut recevoir les articles.
        </p>
    </main>
</template>
