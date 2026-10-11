<script setup lang="ts">
import { UserRound, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
const open = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

async function runSearch(): Promise<void> {
    const response = navigator.onLine
        ? await fetch(
              customersRoute({ query: { q: query.value.trim() } }).url,
              {
                  headers: {
                      Accept: 'application/json',
                      'X-Requested-With': 'XMLHttpRequest',
                  },
              },
          ).catch(() => null)
        : null;
    // Hors connexion : clients synchronisés sur l'appareil.
    results.value = response?.ok
        ? ((await response.json()) as { customers: PickedCustomer[] }).customers
        : await searchCustomersOffline(query.value);
    open.value = true;
}

watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(runSearch, 250);
});

function choose(customer: PickedCustomer): void {
    model.value = customer;
    query.value = '';
    open.value = false;
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
            class="h-11 pl-10"
            role="combobox"
            :aria-expanded="open"
            :aria-controls="`${props.inputId}-list`"
            :placeholder="t('sales.customer_search')"
            @focus="runSearch"
            @keydown.escape="open = false"
        />
        <ul
            v-if="open"
            :id="`${props.inputId}-list`"
            role="listbox"
            class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border bg-popover shadow-md"
        >
            <li
                v-if="results.length === 0"
                class="p-3 text-sm text-muted-foreground"
            >
                {{ t('sales.customer_none') }}
            </li>
            <li
                v-for="customer in results"
                :key="customer.id"
                role="option"
                :aria-selected="false"
            >
                <button
                    type="button"
                    class="flex min-h-11 w-full items-center gap-3 px-3 text-left text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                    @click="choose(customer)"
                >
                    <span class="min-w-0 flex-1 truncate">{{
                        customer.name
                    }}</span>
                    <span class="text-xs text-muted-foreground"
                        >{{ customer.code
                        }}<template v-if="customer.phone">
                            · {{ customer.phone }}</template
                        ></span
                    >
                </button>
            </li>
        </ul>
    </div>
</template>
