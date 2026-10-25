<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check, ChevronRight, Phone, Plus, UsersRound } from '@lucide/vue';
import Avatar from '@/components/simple/Avatar.vue';
import EnTeteEcran from '@/components/simple/EnTeteEcran.vue';

type Client = {
    id: string;
    name: string;
    phone: string | null;
    due: number;
};

type ClientSansDette = { id: string; name: string; phone: string | null };

defineProps<{
    total_due: number;
    customers: Client[];
    /** Clients qui ne doivent rien : sans eux, un client créé n'apparaîtrait pas. */
    settled: ClientSansDette[];
    can: { create: boolean };
}>();

function money(amount: number): string {
    return `${new Intl.NumberFormat('fr-FR').format(amount)} F`;
}
</script>

<template>
    <Head title="Clients" />

    <EnTeteEcran class="lg:hidden" titre="Clients" :retour-visible="false" />

    <div class="grid max-w-[960px] gap-5 p-4 pb-28 lg:p-0 lg:pb-0">
        <header>
            <h1
                class="sr-only text-[28px] leading-tight font-extrabold lg:not-sr-only lg:text-[32px]"
            >
                Clients
            </h1>
            <p
                class="mt-1 text-[16px] font-semibold"
                :style="{ color: 'var(--s-texte-doux)' }"
            >
                Ce que les clients doivent encore payer
            </p>
        </header>

        <section
            class="rounded-[24px] p-5"
            :style="{
                backgroundColor: 'var(--s-marque)',
                color: 'var(--s-sur-marque)',
            }"
        >
            <p class="text-[17px] font-bold">Total dû</p>
            <strong
                class="mt-1 block text-[36px] leading-tight font-extrabold"
                >{{ money(total_due) }}</strong
            >
        </section>

        <section
            class="overflow-hidden rounded-[24px]"
            :style="{ border: '1px solid var(--s-bordure)' }"
        >
            <div
                v-for="customer in customers"
                :key="customer.id"
                class="flex min-h-20 items-center gap-4 border-b px-4 py-3 last:border-b-0"
                :style="{ borderColor: 'var(--s-bordure)' }"
            >
                <a
                    :href="`/simple/clients/${customer.id}`"
                    class="flex min-w-0 flex-1 items-center gap-4 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-4"
                    :style="{ outlineColor: 'var(--s-marque)' }"
                >
                    <span
                        class="grid size-12 shrink-0 place-items-center rounded-full bg-[var(--s-orange-clair)] text-[17px] font-extrabold text-[var(--s-sur-orange-clair)]"
                    >
                        {{ customer.name.slice(0, 1).toUpperCase() }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <strong class="block truncate text-[17px]">{{
                            customer.name
                        }}</strong>
                        <span
                            v-if="customer.phone"
                            class="text-[15px] font-semibold"
                            :style="{ color: 'var(--s-texte-doux)' }"
                            >{{ customer.phone }}</span
                        >
                    </span>
                    <span class="text-right">
                        <strong
                            class="block text-[19px] text-[var(--s-rouge)]"
                            >{{ money(customer.due) }}</strong
                        >
                        <span
                            class="text-[15px] font-bold"
                            :style="{ color: 'var(--s-texte-doux)' }"
                            >à récupérer</span
                        >
                    </span>
                    <ChevronRight
                        class="size-5 shrink-0"
                        :style="{ color: 'var(--s-texte-doux)' }"
                        aria-hidden="true"
                    />
                </a>
                <a
                    v-if="customer.phone"
                    :href="`tel:${customer.phone}`"
                    class="inline-flex size-12 shrink-0 items-center justify-center rounded-full"
                    :style="{ color: 'var(--s-marque)' }"
                    :aria-label="`Appeler ${customer.name}`"
                >
                    <Phone :size="22" :stroke-width="2" aria-hidden="true" />
                </a>
            </div>

            <div
                v-if="customers.length === 0"
                class="grid min-h-52 place-items-center p-6 text-center"
            >
                <div>
                    <UsersRound
                        class="mx-auto mb-3 size-12"
                        :style="{ color: 'var(--s-texte-doux)' }"
                        aria-hidden="true"
                    />
                    <p class="text-[17px] font-bold">Aucune dette client</p>
                    <p
                        class="mt-1 text-[15px]"
                        :style="{ color: 'var(--s-texte-doux)' }"
                    >
                        Les ventes à crédit apparaîtront ici.
                    </p>
                </div>
            </div>
        </section>

        <section v-if="settled.length > 0" aria-labelledby="clients-sans-dette">
            <h2 id="clients-sans-dette" class="mb-2 text-[20px] font-extrabold">
                Ne doivent rien
            </h2>
            <ul
                class="overflow-hidden rounded-[24px]"
                :style="{ border: '1px solid var(--s-bordure)' }"
            >
                <li
                    v-for="client in settled"
                    :key="client.id"
                    class="flex min-h-20 items-center gap-4 border-b px-4 py-3 last:border-b-0"
                    :style="{ borderColor: 'var(--s-bordure)' }"
                >
                    <Avatar :nom="client.name" :taille="48" />
                    <span class="min-w-0 flex-1">
                        <strong class="block truncate text-[17px]">{{
                            client.name
                        }}</strong>
                        <span
                            class="inline-flex items-center gap-1 text-[15px] font-bold"
                            :style="{ color: 'var(--s-vert-fonce)' }"
                        >
                            <Check
                                :size="18"
                                :stroke-width="3"
                                aria-hidden="true"
                            />
                            ne doit rien
                        </span>
                    </span>
                    <a
                        v-if="client.phone"
                        :href="`tel:${client.phone}`"
                        class="inline-flex size-12 shrink-0 items-center justify-center rounded-full"
                        :style="{
                            backgroundColor: 'var(--s-vert-clair)',
                            color: 'var(--s-sur-vert-clair)',
                        }"
                        :aria-label="`Appeler ${client.name}`"
                    >
                        <Phone
                            :size="22"
                            :stroke-width="2"
                            aria-hidden="true"
                        />
                    </a>
                </li>
            </ul>
        </section>

        <a
            v-if="can.create"
            href="/simple/clients/nouveau"
            class="inline-flex h-16 items-center justify-center gap-3 rounded-[24px] text-[20px] font-extrabold"
            :style="{
                backgroundColor: 'var(--s-violet)',
                color: 'var(--s-sur-violet)',
            }"
        >
            <Plus :size="26" :stroke-width="3" aria-hidden="true" />
            Nouveau client
        </a>
    </div>
</template>
