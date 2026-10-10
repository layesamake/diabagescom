<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Pencil, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import { formatXof } from '@/lib/money';
import * as customers from '@/routes/customers';
import * as suppliers from '@/routes/suppliers';
import type { Paginated } from '@/types';

type Party = {
    id: string;
    code: string;
    name: string;
    phone: string | null;
    email: string | null;
    address_line: string | null;
    city: string | null;
    tax_number: string | null;
    payment_term_days: number;
    credit_limit?: number | null;
    notes: string | null;
    archived_at: string | null;
};

const props = defineProps<{
    type: 'customer' | 'supplier';
    parties: Paginated<Party>;
    filters: { q: string; status: string };
    canManage: boolean;
}>();

const routes = computed(() =>
    props.type === 'customer' ? customers : suppliers,
);
const title = computed(() =>
    props.type === 'customer' ? t('parties.customers') : t('parties.suppliers'),
);

defineOptions({ layout: { breadcrumbs: [] } });

const q = ref(props.filters.q);
const status = ref(props.filters.status);
// null : panneau fermé ; 'new' : création ; sinon la fiche modifiée.
const editing = ref<Party | 'new' | null>(null);

let timer: ReturnType<typeof setTimeout> | undefined;
function search(): void {
    router.get(
        routes.value.index().url,
        { q: q.value || undefined, status: status.value },
        { preserveState: true, replace: true },
    );
}
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(search, 350);
});
watch(status, search);

const current = computed(() =>
    editing.value && editing.value !== 'new' ? editing.value : null,
);
const formAction = computed(() =>
    current.value
        ? routes.value.update.form(current.value.id)
        : routes.value.store.form(),
);

function toggleArchive(party: Party): void {
    router.post(
        routes.value.archive(party.id).url,
        { archived: party.archived_at === null },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="title" />

    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="title"
                :description="
                    type === 'customer'
                        ? t('parties.customers_description')
                        : t('parties.suppliers_description')
                "
            />
            <Button v-if="canManage" class="h-11" @click="editing = 'new'">
                <Plus class="size-4" />{{
                    type === 'customer'
                        ? t('parties.add_customer')
                        : t('parties.add_supplier')
                }}
            </Button>
        </div>

        <section
            v-if="editing"
            class="rounded-lg border p-4"
            aria-live="polite"
        >
            <Form
                :key="current?.id ?? 'new'"
                v-bind="formAction"
                :options="{ preserveScroll: true }"
                @success="editing = null"
                v-slot="{ errors, processing }"
                class="grid gap-4 sm:grid-cols-2"
            >
                <div class="grid gap-2">
                    <Label for="p-name">{{ t('parties.name') }}</Label>
                    <Input
                        id="p-name"
                        name="name"
                        required
                        maxlength="160"
                        class="h-11"
                        :default-value="current?.name"
                        v-focus
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="p-code">{{ t('parties.code') }}</Label>
                    <Input
                        id="p-code"
                        name="code"
                        maxlength="20"
                        autocapitalize="characters"
                        class="h-11"
                        :default-value="current?.code"
                        aria-describedby="p-code-hint"
                    />
                    <p id="p-code-hint" class="text-xs text-muted-foreground">
                        {{ t('parties.code_hint') }}
                    </p>
                    <InputError :message="errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="p-phone">{{ t('parties.phone') }}</Label>
                    <Input
                        id="p-phone"
                        name="phone"
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel"
                        class="h-11"
                        :default-value="current?.phone ?? ''"
                    />
                    <InputError :message="errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="p-email">{{ t('parties.email') }}</Label>
                    <Input
                        id="p-email"
                        name="email"
                        type="email"
                        class="h-11"
                        :default-value="current?.email ?? ''"
                    />
                    <InputError :message="errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="p-address">{{ t('parties.address') }}</Label>
                    <Input
                        id="p-address"
                        name="address_line"
                        class="h-11"
                        :default-value="current?.address_line ?? ''"
                    />
                    <InputError :message="errors.address_line" />
                </div>
                <div class="grid gap-2">
                    <Label for="p-city">{{ t('parties.city') }}</Label>
                    <Input
                        id="p-city"
                        name="city"
                        class="h-11"
                        :default-value="current?.city ?? ''"
                    />
                    <InputError :message="errors.city" />
                </div>
                <div class="grid gap-2">
                    <Label for="p-tax">{{ t('parties.tax_number') }}</Label>
                    <Input
                        id="p-tax"
                        name="tax_number"
                        class="h-11"
                        :default-value="current?.tax_number ?? ''"
                    />
                    <InputError :message="errors.tax_number" />
                </div>
                <div class="grid gap-2">
                    <Label for="p-terms">{{ t('parties.terms') }}</Label>
                    <Input
                        id="p-terms"
                        name="payment_term_days"
                        type="number"
                        inputmode="numeric"
                        min="0"
                        max="365"
                        class="h-11"
                        :default-value="current?.payment_term_days ?? 0"
                    />
                    <InputError :message="errors.payment_term_days" />
                </div>
                <div v-if="type === 'customer'" class="grid gap-2">
                    <Label for="p-credit">{{
                        t('parties.credit_limit')
                    }}</Label>
                    <Input
                        id="p-credit"
                        name="credit_limit"
                        inputmode="numeric"
                        pattern="[0-9 ]*"
                        class="h-11 tabular-nums"
                        :default-value="current?.credit_limit ?? ''"
                        aria-describedby="p-credit-hint"
                    />
                    <p id="p-credit-hint" class="text-xs text-muted-foreground">
                        {{ t('parties.credit_hint') }}
                    </p>
                    <InputError :message="errors.credit_limit" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="p-notes">{{ t('parties.notes') }}</Label>
                    <textarea
                        id="p-notes"
                        name="notes"
                        rows="2"
                        maxlength="2000"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base md:text-sm"
                        >{{ current?.notes ?? '' }}</textarea>
                    <InputError :message="errors.notes" />
                </div>
                <div class="flex gap-2 sm:col-span-2">
                    <Button type="submit" class="h-11" :disabled="processing"
                        ><Spinner v-if="processing" />{{
                            t('parties.save')
                        }}</Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        class="h-11"
                        @click="editing = null"
                        >{{ t('parties.cancel') }}</Button
                    >
                </div>
            </Form>
        </section>

        <form
            class="grid gap-3 sm:grid-cols-[1fr_auto]"
            role="search"
            @submit.prevent="search"
        >
            <Label for="q" class="sr-only">{{ t('parties.search') }}</Label>
            <Input
                id="q"
                v-model="q"
                type="search"
                class="h-11"
                :placeholder="t('parties.search')"
            />
            <select
                v-model="status"
                class="flex h-11 rounded-md border border-input bg-transparent px-3 text-sm"
                :aria-label="t('catalog.status_active')"
            >
                <option value="active">{{ t('catalog.status_active') }}</option>
                <option value="archived">
                    {{ t('catalog.status_archived') }}
                </option>
                <option value="all">{{ t('catalog.status_all') }}</option>
            </select>
        </form>

        <p
            v-if="parties.data.length === 0"
            class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground"
        >
            {{ t('parties.empty') }}
        </p>

        <ul v-else class="divide-y rounded-lg border">
            <li
                v-for="party in parties.data"
                :key="party.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 p-3"
            >
                <span class="grid min-w-0 flex-1 gap-0.5">
                    <span class="truncate font-medium">{{ party.name }}</span>
                    <span class="truncate text-sm text-muted-foreground">
                        {{ party.code }}
                        <template v-if="party.phone">
                            · {{ party.phone }}</template
                        >
                        <template v-if="party.city">
                            · {{ party.city }}</template
                        >
                    </span>
                </span>
                <span class="text-sm text-muted-foreground">
                    <template v-if="party.archived_at">{{
                        t('parties.archived_badge')
                    }}</template>
                    <template v-else-if="type === 'customer'">
                        {{
                            party.credit_limit != null
                                ? formatXof(party.credit_limit)
                                : t('parties.no_credit')
                        }}
                        ·
                        {{
                            t('parties.terms_days', {
                                days: party.payment_term_days,
                            })
                        }}
                    </template>
                    <template v-else>{{
                        t('parties.terms_days', {
                            days: party.payment_term_days,
                        })
                    }}</template>
                </span>
                <span v-if="canManage" class="flex gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-11"
                        :aria-label="`${t('parties.edit')} ${party.name}`"
                        @click="editing = party"
                    >
                        <Pencil class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-11"
                        :aria-label="`${party.archived_at ? t('parties.restore') : t('parties.archive')} ${party.name}`"
                        @click="toggleArchive(party)"
                    >
                        <component
                            :is="party.archived_at ? ArchiveRestore : Archive"
                            class="size-4"
                        />
                    </Button>
                </span>
            </li>
        </ul>

        <nav
            v-if="parties.prev_page_url || parties.next_page_url"
            class="flex justify-between"
        >
            <Link
                v-if="parties.prev_page_url"
                :href="parties.prev_page_url"
                preserve-state
                class="inline-flex min-h-11 items-center underline underline-offset-4"
                >{{ t('common.previous') }}</Link
            >
            <span v-else />
            <Link
                v-if="parties.next_page_url"
                :href="parties.next_page_url"
                preserve-state
                class="inline-flex min-h-11 items-center underline underline-offset-4"
                >{{ t('common.next') }}</Link
            >
        </nav>
    </div>
</template>
