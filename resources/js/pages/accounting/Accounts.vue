<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t, type TranslationKey } from '@/i18n';
import { index as accountingIndex } from '@/routes/accounting';
import {
    index as accountsIndex,
    store as accountStore,
    update as accountUpdate,
} from '@/routes/accounting/accounts';
import { update as mappingUpdate } from '@/routes/accounting/mappings';

type Account = {
    id: string;
    code: string;
    name: string;
    class: number;
    party_type: 'customer' | 'supplier' | null;
    reconcilable: boolean;
    active: boolean;
};

const props = defineProps<{
    accounts: Account[];
    mappings: { key: string; code: string }[];
    can: { settings: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('accounting.title'), href: accountingIndex() },
            { title: t('accounting.accounts'), href: accountsIndex() },
        ],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const filter = ref('');
const showNew = ref(false);
const form = reactive({
    code: '',
    name: '',
    party_type: '',
    reconcilable: false,
});
const mappingValues = reactive<Record<string, string>>(
    Object.fromEntries(props.mappings.map((m) => [m.key, m.code])),
);
const visible = computed(() => {
    const needle = filter.value.trim().toLowerCase();

    return needle === ''
        ? props.accounts
        : props.accounts.filter(
              (a) =>
                  a.code.startsWith(needle) ||
                  a.name.toLowerCase().includes(needle),
          );
});
const options = { preserveScroll: true };

function create(): void {
    router.post(
        accountStore().url,
        { ...form, party_type: form.party_type || null },
        {
            ...options,
            onSuccess: () => {
                showNew.value = false;
                Object.assign(form, {
                    code: '',
                    name: '',
                    party_type: '',
                    reconcilable: false,
                });
            },
        },
    );
}

function toggle(account: Account): void {
    router.put(
        accountUpdate(account.id).url,
        { name: account.name, active: !account.active },
        options,
    );
}

function rename(account: Account, name: string): void {
    if (name.trim() && name !== account.name) {
        router.put(
            accountUpdate(account.id).url,
            { name, active: account.active },
            options,
        );
    }
}

function saveMapping(key: string): void {
    router.post(
        mappingUpdate().url,
        { key, code: mappingValues[key] },
        options,
    );
}
</script>

<template>
    <Head :title="t('accounting.accounts')" />

    <div class="mx-auto max-w-5xl space-y-8 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading :title="t('accounting.accounts')" />
            <Button
                v-if="can.settings"
                variant="outline"
                class="h-11"
                @click="showNew = !showNew"
                ><Plus class="size-4" />{{
                    t('accounting.new_account')
                }}</Button
            >
        </div>

        <section
            v-if="showNew"
            class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[8rem_1fr_10rem_auto_auto] sm:items-end"
        >
            <div class="grid gap-1">
                <Label for="code">{{ t('accounting.code') }}</Label>
                <Input
                    id="code"
                    v-model="form.code"
                    maxlength="20"
                    class="h-11 tabular-nums"
                />
            </div>
            <div class="grid gap-1">
                <Label for="name">{{ t('accounting.name') }}</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    maxlength="160"
                    class="h-11"
                />
            </div>
            <div class="grid gap-1">
                <Label for="party">{{ t('accounting.party') }}</Label>
                <select
                    id="party"
                    v-model="form.party_type"
                    class="h-11 rounded-md border border-input bg-transparent px-2 text-sm"
                >
                    <option value="">—</option>
                    <option value="customer">
                        {{ t('accounting.party_types.customer') }}
                    </option>
                    <option value="supplier">
                        {{ t('accounting.party_types.supplier') }}
                    </option>
                </select>
            </div>
            <label class="inline-flex min-h-11 items-center gap-2 text-sm"
                ><input
                    v-model="form.reconcilable"
                    type="checkbox"
                    class="size-5"
                />{{ t('accounting.reconcilable') }}</label
            >
            <Button class="h-11" @click="create">{{
                t('accounting.create_account')
            }}</Button>
            <InputError
                :message="errors.code ?? errors.name ?? errors.business"
                class="sm:col-span-5"
            />
        </section>

        <section class="space-y-3">
            <Input
                v-model="filter"
                type="search"
                class="h-11 max-w-xs"
                :placeholder="t('accounting.search')"
                :aria-label="t('accounting.search')"
            />
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="account in visible"
                    :key="account.id"
                    class="flex flex-wrap items-center gap-3 p-2 text-sm"
                    :class="account.active ? '' : 'opacity-60'"
                >
                    <span class="w-16 font-medium tabular-nums">{{
                        account.code
                    }}</span>
                    <Input
                        v-if="can.settings"
                        :model-value="account.name"
                        class="h-10 min-w-48 flex-1"
                        :aria-label="t('accounting.name')"
                        @change="
                            rename(
                                account,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <span v-else class="flex-1">{{ account.name }}</span>
                    <span
                        v-if="account.party_type"
                        class="rounded bg-muted px-2 py-0.5 text-xs"
                        >{{
                            t(`accounting.party_types.${account.party_type}`)
                        }}</span
                    >
                    <span
                        v-if="account.reconcilable"
                        class="rounded bg-muted px-2 py-0.5 text-xs"
                        >{{ t('accounting.reconcilable') }}</span
                    >
                    <span v-if="!account.active" class="text-xs">{{
                        t('accounting.inactive')
                    }}</span>
                    <Button
                        v-if="can.settings"
                        variant="ghost"
                        size="sm"
                        class="h-10"
                        @click="toggle(account)"
                        >{{
                            account.active
                                ? t('accounting.deactivate')
                                : t('accounting.activate')
                        }}</Button
                    >
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <h2 class="font-semibold">{{ t('accounting.mappings') }}</h2>
            <p class="text-sm text-muted-foreground">
                {{ t('accounting.mappings_hint') }}
            </p>
            <datalist id="account-codes">
                <option
                    v-for="a in accounts.filter((x) => x.active)"
                    :key="a.code"
                    :value="a.code"
                >
                    {{ a.name }}
                </option>
            </datalist>
            <ul class="grid gap-2 sm:grid-cols-2">
                <li
                    v-for="mapping in mappings"
                    :key="mapping.key"
                    class="flex items-center gap-2 rounded-md border p-2 text-sm"
                >
                    <span class="min-w-0 flex-1">{{
                        t(
                            `accounting.mapping_keys.${mapping.key}` as TranslationKey,
                        )
                    }}</span>
                    <Input
                        v-model="mappingValues[mapping.key]"
                        list="account-codes"
                        class="h-10 w-24 tabular-nums"
                        :disabled="!can.settings"
                        :aria-label="
                            t(
                                `accounting.mapping_keys.${mapping.key}` as TranslationKey,
                            )
                        "
                        @change="saveMapping(mapping.key)"
                    />
                </li>
            </ul>
        </section>
    </div>
</template>
