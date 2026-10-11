<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Wallet } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pager from '@/components/Pager.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { formatXof, parseAmount } from '@/lib/money';
import { index as cashIndex, open as openRoute } from '@/routes/cash';
import { store as registerStore } from '@/routes/cash/registers';
import { show as sessionShow } from '@/routes/cash/sessions';
import type { Paginated } from '@/types';

type Session = {
    id: string;
    status: 'open' | 'closed';
    register: string;
    opening_amount: number;
    difference: number | null;
    opened_by: string;
    opened_at: string;
    closed_at: string | null;
};

defineProps<{
    registers: {
        id: string;
        name: string;
        warehouse: { id: string; name: string };
        session: {
            id: string;
            opened_by: string;
            opened_at: string;
            mine: boolean;
        } | null;
    }[];
    sessions: Paginated<Session>;
    warehouses: { id: string; code: string; name: string }[];
    can: { open_close: boolean; manage: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('cash.title'), href: cashIndex() }],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const processing = ref(false);
const opening = reactive<Record<string, string>>({});
const openFor = ref<string | null>(null);
const newRegister = reactive({ warehouse_id: '', name: '', show: false });

const options = {
    preserveScroll: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};

function open(registerId: string): void {
    router.post(
        openRoute(registerId).url,
        { opening_amount: parseAmount(opening[registerId]) },
        options,
    );
}

function createRegister(): void {
    router.post(
        registerStore().url,
        { warehouse_id: newRegister.warehouse_id, name: newRegister.name },
        {
            ...options,
            onSuccess: () => {
                newRegister.show = false;
                newRegister.name = '';
            },
        },
    );
}
</script>

<template>
    <Head :title="t('cash.title')" />

    <div class="space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="t('cash.title')"
                :description="t('cash.description')"
            />
            <Button
                v-if="can.manage"
                variant="outline"
                class="h-11"
                @click="newRegister.show = !newRegister.show"
                ><Plus class="size-4" />{{ t('cash.new_register') }}</Button
            >
        </div>

        <section
            v-if="newRegister.show"
            class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
        >
            <div class="grid gap-1">
                <Label for="reg-warehouse">{{ t('sales.warehouse') }}</Label>
                <select
                    id="reg-warehouse"
                    v-model="newRegister.warehouse_id"
                    class="h-11 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">
                        {{ w.name }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <Label for="reg-name">{{ t('cash.register_name') }}</Label>
                <Input
                    id="reg-name"
                    v-model="newRegister.name"
                    maxlength="80"
                    class="h-11"
                />
            </div>
            <Button
                class="h-11"
                :disabled="processing"
                @click="createRegister"
                >{{ t('cash.create_register') }}</Button
            >
            <InputError
                :message="errors.name ?? errors.business"
                class="sm:col-span-3"
            />
        </section>

        <section class="space-y-3">
            <h2 class="font-semibold">{{ t('cash.registers') }}</h2>
            <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <li
                    v-for="register in registers"
                    :key="register.id"
                    class="space-y-3 rounded-lg border p-4"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-medium">{{ register.name }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ register.warehouse.name }}
                            </p>
                        </div>
                        <span
                            class="rounded px-2 py-0.5 text-xs font-medium"
                            :class="
                                register.session
                                    ? 'bg-success-soft text-success-soft-foreground'
                                    : 'bg-muted text-muted-foreground'
                            "
                            >{{
                                register.session
                                    ? t('cash.open')
                                    : t('cash.closed')
                            }}</span
                        >
                    </div>
                    <template v-if="register.session">
                        <p class="text-sm">
                            {{
                                t('cash.opened_by', {
                                    name: register.session.opened_by,
                                    date: formatDate(
                                        register.session.opened_at,
                                    ),
                                })
                            }}
                        </p>
                        <Button as-child variant="outline" class="h-11 w-full"
                            ><Link :href="sessionShow(register.session.id)">{{
                                t('cash.view_session')
                            }}</Link></Button
                        >
                    </template>
                    <template v-else-if="can.open_close">
                        <div v-if="openFor === register.id" class="space-y-2">
                            <Label :for="`open-${register.id}`">{{
                                t('cash.opening_amount')
                            }}</Label>
                            <Input
                                :id="`open-${register.id}`"
                                v-model="opening[register.id]"
                                inputmode="numeric"
                                class="h-11 tabular-nums"
                            />
                            <Button
                                class="h-11 w-full"
                                :disabled="processing"
                                @click="open(register.id)"
                                ><Wallet class="size-4" />{{
                                    t('cash.open_action')
                                }}</Button
                            >
                        </div>
                        <Button
                            v-else
                            class="h-11 w-full"
                            @click="openFor = register.id"
                            ><Wallet class="size-4" />{{
                                t('cash.open_action')
                            }}</Button
                        >
                    </template>
                </li>
            </ul>
            <InputError
                :message="
                    errors.opening_amount ??
                    (newRegister.show ? undefined : errors.business)
                "
            />
        </section>

        <section class="space-y-3">
            <h2 class="font-semibold">{{ t('cash.history') }}</h2>
            <p
                v-if="sessions.data.length === 0"
                class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground"
            >
                {{ t('cash.no_sessions') }}
            </p>
            <ul v-else class="divide-y rounded-lg border">
                <li v-for="s in sessions.data" :key="s.id">
                    <Link
                        :href="sessionShow(s.id)"
                        class="flex min-h-14 flex-wrap items-center gap-x-4 gap-y-1 p-3 text-sm hover:bg-accent"
                    >
                        <span class="min-w-32 font-medium">{{
                            s.register
                        }}</span>
                        <span class="flex-1"
                            >{{ formatDate(s.opened_at) }} ·
                            {{ s.opened_by }}</span
                        >
                        <span
                            v-if="s.difference !== null && s.difference !== 0"
                            class="text-destructive tabular-nums"
                            >{{ t('cash.difference') }}
                            {{ formatXof(s.difference) }}</span
                        >
                        <span
                            class="rounded px-2 py-0.5 text-xs font-medium"
                            :class="
                                s.status === 'open'
                                    ? 'bg-success-soft text-success-soft-foreground'
                                    : 'bg-muted'
                            "
                            >{{
                                s.status === 'open'
                                    ? t('cash.open')
                                    : t('cash.closed')
                            }}</span
                        >
                    </Link>
                </li>
            </ul>
            <Pager
                :prev="sessions.prev_page_url"
                :next="sessions.next_page_url"
            />
        </section>
    </div>
</template>
