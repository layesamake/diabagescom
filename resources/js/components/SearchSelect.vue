<script setup lang="ts">
import { ChevronDown, Search, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { highlight, useCombobox } from '@/composables/useCombobox';
import { t } from '@/i18n';

export type SearchOption = { value: string; label: string; hint?: string };

/**
 * Liste déroulante avec recherche (fournisseurs…) : un clic ouvre toute la
 * liste, deux lettres la filtrent et présélectionnent la première réponse,
 * Entrée valide. Remplace un <select> quand la liste peut être longue.
 */
const props = defineProps<{
    inputId: string;
    options: SearchOption[];
    placeholder?: string;
    disabled?: boolean;
}>();
const model = defineModel<string>({ default: '' });

const query = ref('');
const input = ref<HTMLInputElement | null>(null);
const selected = computed(() =>
    props.options.find((o) => o.value === model.value),
);

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return needle.length < 2
        ? props.options
        : props.options.filter((o) =>
              `${o.label} ${o.hint ?? ''}`
                  .toLowerCase()
                  .normalize('NFD')
                  .replace(/\p{M}/gu, '')
                  .includes(needle.normalize('NFD').replace(/\p{M}/gu, '')),
          );
});

const { open, active, move, chooseActive, close } = useCombobox(
    filtered,
    choose,
);

function choose(option: SearchOption): void {
    model.value = option.value;
    query.value = '';
    close();
}

// Deux lettres tapées : la première réponse est présélectionnée (Entrée la choisit).
watch(query, (value) => {
    open.value = true;
    active.value = value.trim().length >= 2 && filtered.value.length ? 0 : -1;
});

async function clear(): Promise<void> {
    model.value = '';
    await nextTick();
    input.value?.focus();
    open.value = true;
}

function onBlur(): void {
    // Laisse le temps au clic sur un choix d'être pris en compte.
    setTimeout(() => {
        close();
        query.value = '';
    }, 150);
}
</script>

<template>
    <div class="relative">
        <Search
            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
        />
        <input
            :id="inputId"
            ref="input"
            v-model="query"
            type="text"
            role="combobox"
            autocomplete="off"
            :disabled="disabled"
            :aria-expanded="open"
            :aria-controls="`${inputId}-list`"
            :aria-activedescendant="
                active >= 0 ? `${inputId}-opt-${active}` : undefined
            "
            :placeholder="selected ? selected.label : placeholder"
            class="flex h-11 w-full rounded-md border border-input bg-transparent pr-16 pl-9 text-base shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60 md:text-sm"
            :class="{ 'placeholder:text-foreground': selected && !open }"
            @focus="open = true"
            @click="open = true"
            @blur="onBlur"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="chooseActive()"
            @keydown.escape="close()"
        />
        <button
            v-if="selected && !disabled"
            type="button"
            class="absolute top-1/2 right-8 flex size-7 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:text-foreground"
            :aria-label="t('combobox.clear')"
            @click="clear"
        >
            <X class="size-4" />
        </button>
        <ChevronDown
            class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
        />
        <ul
            v-if="open && !disabled"
            :id="`${inputId}-list`"
            role="listbox"
            class="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-md border bg-popover py-1 text-popover-foreground shadow-md"
        >
            <li
                v-if="filtered.length === 0"
                class="px-3 py-2 text-sm text-muted-foreground"
            >
                {{ t('combobox.none') }}
            </li>
            <li
                v-for="(option, index) in filtered"
                :id="`${inputId}-opt-${index}`"
                :key="option.value"
                role="option"
                :aria-selected="option.value === model"
                :data-combobox-active="index === active"
                class="flex min-h-11 cursor-pointer items-center gap-3 px-3 text-sm"
                :class="[
                    index === active ? 'bg-accent' : 'hover:bg-accent/60',
                    option.value === model ? 'font-semibold' : '',
                ]"
                @mousedown.prevent="choose(option)"
                @mouseenter="active = index"
            >
                <span class="min-w-0 flex-1 truncate">
                    <template
                        v-for="(part, i) in highlight(option.label, query)"
                        :key="i"
                    >
                        <mark
                            v-if="part.match"
                            class="rounded-sm bg-primary/25 text-inherit"
                            >{{ part.text }}</mark
                        >
                        <template v-else>{{ part.text }}</template>
                    </template>
                </span>
                <span
                    v-if="option.hint"
                    class="text-xs text-muted-foreground"
                    >{{ option.hint }}</span
                >
            </li>
        </ul>
    </div>
</template>
