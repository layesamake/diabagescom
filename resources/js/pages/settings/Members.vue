<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    Mail,
    Pencil,
    ShieldCheck,
    UserPlus,
    UserRoundX,
    UserRoundCheck,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { confirmAction } from '@/composables/useConfirm';
import { t } from '@/i18n';
import type { TranslationKey } from '@/i18n';
import {
    index as membersIndex,
    reactivate as reactivateRoute,
    resend as resendRoute,
    store as storeRoute,
    suspend as suspendRoute,
    update as updateRoute,
} from '@/routes/members';

type Member = {
    id: string;
    name: string;
    email: string;
    role: string;
    status: 'active' | 'suspended';
    pending: boolean;
    two_factor: boolean;
    warehouses: { id: string; name: string }[];
    is_me: boolean;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('team.page_title'), href: membersIndex() }],
    },
});

const props = defineProps<{
    members: Member[];
    roles: { code: string; name: string }[];
    warehouses: { id: string; name: string }[];
    seats: { used: number; limit: number | null };
    can: { invite: boolean; manage: boolean };
}>();

const full = computed(
    () => props.seats.limit !== null && props.seats.used >= props.seats.limit,
);
const roleName = (code: string) =>
    props.roles.find((r) => r.code === code)?.name ?? code;

// Un seul formulaire pour ajouter ou modifier un collaborateur.
const editing = ref<Member | null>(null);
const dialogOpen = ref(false);
const form = useForm<{
    name: string;
    email: string;
    role: string;
    warehouses: string[];
}>({
    name: '',
    email: '',
    role: 'cashier',
    warehouses: [],
});

function openInvite(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    // Un seul dépôt : coché d'office.
    form.warehouses =
        props.warehouses.length === 1 ? [props.warehouses[0].id] : [];
    dialogOpen.value = true;
}

function openEdit(member: Member): void {
    editing.value = member;
    form.clearErrors();
    form.name = member.name;
    form.email = member.email;
    form.role = member.role;
    form.warehouses = member.warehouses.map((w) => w.id);
    dialogOpen.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    if (editing.value) {
        form.transform((data) => ({
            role: data.role,
            warehouses: data.warehouses,
        })).put(updateRoute(editing.value.id).url, options);
    } else {
        form.transform((data) => data).post(storeRoute().url, options);
    }
}

async function suspend(member: Member): Promise<void> {
    if (
        await confirmAction({
            title: t('team.suspend_title', { name: member.name }),
            message: t('team.suspend_message'),
            confirmLabel: t('team.suspend_confirm'),
            tone: 'danger',
        })
    ) {
        router.post(suspendRoute(member.id).url, {}, { preserveScroll: true });
    }
}

function reactivate(member: Member): void {
    router.post(reactivateRoute(member.id).url, {}, { preserveScroll: true });
}

function resend(member: Member): void {
    router.post(resendRoute(member.id).url, {}, { preserveScroll: true });
}

const isAdminRole = computed(() => form.role === 'admin');
</script>

<template>
    <Head :title="t('team.page_title')" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="t('team.heading')"
                :description="t('team.description')"
            />
            <Button
                v-if="can.invite"
                class="h-11"
                :disabled="full"
                @click="openInvite"
                ><UserPlus class="size-4" />{{ t('team.invite') }}</Button
            >
        </div>

        <p
            class="flex items-center gap-2 text-sm"
            :class="full ? 'text-warning' : 'text-muted-foreground'"
        >
            <Users class="size-4" aria-hidden="true" />
            {{
                seats.limit === null
                    ? t('team.seats_unlimited', { used: seats.used })
                    : t('team.seats', { used: seats.used, limit: seats.limit })
            }}
            <template v-if="full"> · {{ t('team.seats_full') }}</template>
        </p>

        <EmptyState
            v-if="members.length <= 1"
            :icon="UserPlus"
            :hint="t('team.empty_hint')"
            >{{ t('team.empty') }}</EmptyState
        >

        <ul class="divide-y rounded-lg border">
            <li
                v-for="member in members"
                :key="member.id"
                class="flex flex-wrap items-center gap-4 p-4"
                :class="{ 'opacity-60': member.status === 'suspended' }"
            >
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 font-semibold text-primary"
                    aria-hidden="true"
                    >{{ member.name.slice(0, 1).toUpperCase() }}</span
                >
                <div class="min-w-0 flex-1 space-y-1">
                    <p class="font-medium">
                        {{ member.name }}
                        <span
                            v-if="member.is_me"
                            class="text-sm font-normal text-muted-foreground"
                            >({{ t('team.me') }})</span
                        >
                    </p>
                    <p class="truncate text-sm text-muted-foreground">
                        {{ member.email }}
                    </p>
                    <div class="flex flex-wrap gap-1.5 pt-1 text-xs">
                        <span
                            class="rounded-full bg-primary/10 px-2 py-0.5 font-medium text-primary"
                            >{{ roleName(member.role) }}</span
                        >
                        <span
                            v-if="member.status === 'suspended'"
                            class="rounded-full bg-danger-soft px-2 py-0.5 font-medium text-danger-soft-foreground"
                            >{{ t('team.status_suspended') }}</span
                        >
                        <span
                            v-else-if="member.pending"
                            class="rounded-full bg-warning-soft px-2 py-0.5 font-medium text-warning-soft-foreground"
                            >{{ t('team.status_pending') }}</span
                        >
                        <span
                            v-if="member.two_factor"
                            class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 font-medium text-success-soft-foreground"
                            ><ShieldCheck class="size-3" />{{
                                t('team.two_factor')
                            }}</span
                        >
                        <span
                            v-if="member.role === 'admin'"
                            class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-muted-foreground"
                            ><Building2 class="size-3" />{{
                                t('team.all_warehouses')
                            }}</span
                        >
                        <template v-else>
                            <span
                                v-for="w in member.warehouses"
                                :key="w.id"
                                class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-muted-foreground"
                                ><Building2 class="size-3" />{{ w.name }}</span
                            >
                        </template>
                    </div>
                </div>
                <div v-if="!member.is_me" class="flex flex-wrap gap-2">
                    <Button
                        v-if="
                            can.invite &&
                            member.pending &&
                            member.status === 'active'
                        "
                        variant="ghost"
                        class="h-11"
                        @click="resend(member)"
                        ><Mail class="size-4" />{{ t('team.resend') }}</Button
                    >
                    <template v-if="can.manage">
                        <Button
                            v-if="member.status === 'active'"
                            variant="outline"
                            class="h-11"
                            @click="openEdit(member)"
                            ><Pencil class="size-4" />{{
                                t('team.edit')
                            }}</Button
                        >
                        <Button
                            v-if="member.status === 'active'"
                            variant="ghost"
                            class="h-11 text-danger"
                            @click="suspend(member)"
                            ><UserRoundX class="size-4" />{{
                                t('team.suspend')
                            }}</Button
                        >
                        <Button
                            v-else
                            variant="outline"
                            class="h-11"
                            :disabled="full"
                            @click="reactivate(member)"
                            ><UserRoundCheck class="size-4" />{{
                                t('team.reactivate')
                            }}</Button
                        >
                    </template>
                </div>
            </li>
        </ul>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        editing
                            ? t('team.edit_title', { name: editing.name })
                            : t('team.invite_title')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        editing ? t('team.edit_hint') : t('team.invite_hint')
                    }}</DialogDescription>
                </DialogHeader>

                <form class="grid gap-5" @submit.prevent="submit">
                    <template v-if="!editing">
                        <div class="grid gap-2">
                            <Label for="member-name">{{
                                t('team.name')
                            }}</Label>
                            <Input
                                id="member-name"
                                v-model="form.name"
                                required
                                autocomplete="off"
                                class="h-11"
                            />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="member-email">{{
                                t('team.email')
                            }}</Label>
                            <Input
                                id="member-email"
                                v-model="form.email"
                                type="email"
                                required
                                autocomplete="off"
                                class="h-11"
                            />
                            <InputError :message="form.errors.email" />
                        </div>
                    </template>

                    <fieldset class="grid gap-2">
                        <legend class="mb-2 text-sm font-medium">
                            {{ t('team.role') }}
                        </legend>
                        <label
                            v-for="role in roles.filter(
                                (r) => r.code !== 'admin' || can.manage,
                            )"
                            :key="role.code"
                            class="flex cursor-pointer gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-primary/10 has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                        >
                            <input
                                v-model="form.role"
                                type="radio"
                                name="member-role"
                                :value="role.code"
                                class="mt-1 size-4 accent-primary"
                            />
                            <span>
                                <span class="block font-medium">{{
                                    role.name
                                }}</span>
                                <span
                                    class="block text-sm text-muted-foreground"
                                    >{{
                                        t(
                                            `team.roles.${role.code}` as TranslationKey,
                                        )
                                    }}</span
                                >
                            </span>
                        </label>
                        <InputError :message="form.errors.role" />
                    </fieldset>

                    <fieldset v-if="!isAdminRole" class="grid gap-2">
                        <legend class="mb-2 text-sm font-medium">
                            {{ t('team.warehouses') }}
                        </legend>
                        <label
                            v-for="w in warehouses"
                            :key="w.id"
                            class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border px-3"
                        >
                            <input
                                v-model="form.warehouses"
                                type="checkbox"
                                :value="w.id"
                                class="size-4 accent-primary"
                            />
                            {{ w.name }}
                        </label>
                        <InputError :message="form.errors.warehouses" />
                    </fieldset>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('team.admin_all_warehouses') }}
                    </p>

                    <InputError
                        :message="
                            (form.errors as Record<string, string>).member
                        "
                    />

                    <div class="flex flex-wrap justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="h-11"
                            @click="dialogOpen = false"
                            >{{ t('confirm.cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            class="h-11"
                            :disabled="form.processing"
                            >{{
                                editing ? t('team.save') : t('team.send_invite')
                            }}</Button
                        >
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
