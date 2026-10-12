<script setup lang="ts">
import { ChevronDown, UserRound, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { highlight, useCombobox } from '@/composables/useCombobox';
import { t } from '@/i18n';
import { formatXof } from '@/lib/money';
import { searchCustomersOffline } from '@/offline/sync';
import { customers as customersRoute } from '@/routes/sales';

export type PickedCustomer = {
    id: string;
    code: string;
    name: string;
    phone?: string | null;
    credit_limit?: number | null;
    outstanding?: number;
};

/**
 * Choix facultatif d'un client (recherche par nom, code ou téléphone),
 * avec rappel du plafond de crédit et du montant déjà dû.
 */
const props = defineProps<{ inputId: string }>();
const model = defineModel<PickedCustomer | null>({ default: null });

const query = ref('');
const results = ref<PickedCustomer[]>([]);
const { open, active, move, chooseActive, close } = useCombobox(
    results,
    (customer) => choose(customer),
);
let timer: ReturnType<typeof setTimeout> | undefined;

async function runSearch(): Promise<void> {
    const typed = query.value.trim();
    // Moins de deux lettres : toute la liste à parcourir (ordre alphabétique).
    const q = typed.length < 2 ? '' : typed;
    const response = navigator.onLine
        ? await fetch(customersRoute({ query: { q } }).url, {
              headers: {
                  Accept: 'application/json',
                  'X-Requested-With': 'XMLHttpRequest',
              },
          }).catch(() => null)
        : null;
    // Hors connexion : clients synchronisés sur l'appareil.
    results.value = response?.ok
        ? ((await response.json()) as { customers: PickedCustomer[] }).customers
        : await searchCustomersOffline(q);
    open.value = true;
    // Deux lettres tapées : la première réponse est présélectionnée (Entrée la choisit).
    active.value = q !== '' && results.value.length > 0 ? 0 : -1;
}

watch(query, () => {
    clearTimeout(timer);
    active.value = -1;
    timer = setTimeout(runSearch, 250);
});

function choose(customer: PickedCustomer): void {
    model.value = customer;
    query.value = '';
    close();
}

function toggle(): void {
    if (open.value) {
        close();
    } else {
        void runSearch();
    }
}

function onBlur(): void {
    // Laisse le temps au clic sur un client d'être pris en compte.
    setTimeout(close, 150);
}
</script>

<template>
    <div
        v-if="model"
        class="flex min-h-11 items-center gap-3 rounded-md border px-3 py-2"
    >
        <UserRound class="size-5 text-muted-foreground" aria-hidden="true" />
        <span class="min-w-0 flex-1">
            <span class="block truncate font-medium">{{ model.name }}</span>
            <span class="block text-xs text-muted-foreground">
                {{ model.code }}
                <template v-if="model.credit_limit !== undefined">
                    ·
                    {{
                        model.credit_limit === null
                            ? t('sales.no_credit')
                            : t('sales.credit_info', {
                                  limit: formatXof(model.credit_limit),
                                  outstanding: formatXof(
                                      model.outstanding ?? 0,
                                  ),
                              })
                    }}
                </template>
            </span>
        </span>
        <Button
            type="button"
            variant="ghost"
            size="icon"
            class="size-11"
            :aria-label="t('sales.customer_clear')"
            @click="model = null"
            ><X class="size-4"
        /></Button>
    </div>
    <div v-else class="relative">
        <UserRound
            class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
        />
        <Input
            :id="props.inputId"
            v-model="query"
            type="search"
            autocomplete="off"
            class="h-11 pr-11 pl-10"
            role="combobox"
            :aria-expanded="open"
            :aria-controls="`${props.inputId}-list`"
            :aria-activedescendant="
                active >= 0 ? `${props.inputId}-opt-${active}` : undefined
            "
            :placeholder="t('sales.customer_search')"
            @focus="runSearch"
            @blur="onBlur"
            @keydown.down.prevent="open ? move(1) : runSearch()"
            @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="chooseActive()"
            @keydown.escape="close()"
        />
        <button
            type="button"
            tabindex="-1"
            class="absolute top-1/2 right-1 flex size-9 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
            :aria-label="t('combobox.browse')"
            @mousedown.prevent="toggle"
        >
            <ChevronDown
                class="size-4 transition-transform"
                :class="{ 'rotate-180': open }"
            />
        </button>
        <ul
            v-if="open"
            :id="`${props.inputId}-list`"
            role="listbox"
            class="absolute z-20 mt-1 max-h-80 w-full overflow-auto rounded-md border bg-popover py-1 text-popover-foreground shadow-md"
        >
            <li
                v-if="results.length === 0"
                class="p-3 text-sm text-muted-foreground"
            >
                {{ t('sales.customer_none') }}
            </li>
            <li
                v-for="(customer, index) in results"
                :id="`${props.inputId}-opt-${index}`"
                :key="customer.id"
                role="option"
                :aria-selected="index === active"
                :data-combobox-active="index === active"
                class="flex min-h-11 cursor-pointer items-center gap-3 px-3 text-sm"
                :class="index === active ? 'bg-accent' : 'hover:bg-accent/60'"
                @mousedown.prevent="choose(customer)"
                @mouseenter="active = index"
            >
                <span class="min-w-0 flex-1 truncate">
                    <template
                        v-for="(part, i) in highlight(customer.name, query)"
                        :key="i"
                    >
                        <mark
                            v-if="part.match"
                            class="rounded-sm bg-primary/25 font-semibold text-inherit"
                            >{{ part.text }}</mark
                        >
                        <template v-else>{{ part.text }}</template>
                    </template>
                </span>
                <span class="text-xs text-muted-foreground"
                    >{{ customer.code
                    }}<template v-if="customer.phone">
                        · {{ customer.phone }}</template
                    ></span
                >
            </li>
        </ul>
    </div>
</template>
