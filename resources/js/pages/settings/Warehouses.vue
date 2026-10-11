<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import { index as warehousesIndex, store } from '@/routes/warehouses';

type WarehouseRow = {
    id: string;
    code: string;
    name: string;
    address: string | null;
    status: 'active' | 'archived';
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('warehouses.page_title'), href: warehousesIndex() },
        ],
    },
});

const props = defineProps<{
    warehouses: WarehouseRow[];
    usage: number;
    limit: number | null;
    canManage: boolean;
}>();

const page = usePage();

// Affichage seulement : la limite et la lecture seule sont appliquées par le serveur.
const limitReached = computed(
    () => props.limit !== null && props.usage >= props.limit,
);
const canWrite = computed(() => page.props.subscription?.can_write ?? false);
</script>

<template>
    <Head :title="t('warehouses.page_title')" />

    <div class="space-y-8">
        <Heading
            variant="small"
            :title="t('warehouses.heading')"
            :description="t('warehouses.description')"
        />

        <p class="text-sm font-medium" data-test="warehouse-usage">
            {{
                limit === null
                    ? t('warehouses.usage_unlimited', { usage })
                    : t('warehouses.usage', { usage, limit })
            }}
        </p>

        <ul v-if="warehouses.length" class="divide-y rounded-lg border">
            <li
                v-for="warehouse in warehouses"
                :key="warehouse.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 p-4"
            >
                <span class="font-medium">{{ warehouse.name }}</span>
                <span
                    class="rounded bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground"
                    >{{ warehouse.code }}</span
                >
                <span class="ml-auto text-sm text-muted-foreground">{{
                    t(`warehouses.status.${warehouse.status}`)
                }}</span>
                <span
                    v-if="warehouse.address"
                    class="w-full text-sm text-muted-foreground"
                    >{{ warehouse.address }}</span
                >
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">
            {{ t('warehouses.empty') }}
        </p>

        <section v-if="canManage && canWrite" class="space-y-4">
            <h3 class="font-medium">{{ t('warehouses.add') }}</h3>

            <p
                v-if="limitReached"
                class="rounded-lg border border-warning-border bg-warning-soft p-4 text-sm text-warning-soft-foreground"
            >
                {{ t('warehouses.limit_reached') }}
            </p>

            <Form
                v-else
                v-bind="store.form()"
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-4 sm:grid-cols-[2fr_1fr]">
                    <div class="grid gap-2">
                        <Label for="name">{{ t('warehouses.name') }}</Label>
                        <Input id="name" name="name" required class="h-11" />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="code">{{ t('warehouses.code') }}</Label>
                        <Input
                            id="code"
                            name="code"
                            required
                            maxlength="16"
                            autocapitalize="characters"
                            class="h-11 uppercase"
                        />
                        <InputError :message="errors.code" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="address">{{ t('warehouses.address') }}</Label>
                    <Input id="address" name="address" class="h-11" />
                    <InputError :message="errors.address" />
                </div>
                <Button
                    type="submit"
                    class="h-11 w-full sm:w-fit"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    {{ t('warehouses.create') }}
                </Button>
            </Form>
        </section>
    </div>
</template>
