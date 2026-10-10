<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Building2, Check, ChevronsUpDown, Plus, Warehouse } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppContext } from '@/composables/useAppContext';
import { t } from '@/i18n';
import {
    tenant as switchTenant,
    warehouse as switchWarehouse,
} from '@/routes/context';
import { create as createCompany } from '@/routes/onboarding';

/**
 * Repères permanents (docs/07) : entreprise et dépôt actifs toujours visibles,
 * modifiables seulement parmi les choix autorisés renvoyés par le serveur.
 */
const { context } = useAppContext();

function selectTenant(id: string): void {
    if (id !== context.value?.tenant.id) {
        router.post(switchTenant().url, { tenant_id: id });
    }
}

function selectWarehouse(id: string): void {
    if (id !== context.value?.warehouse?.id) {
        router.post(
            switchWarehouse().url,
            { warehouse_id: id },
            { preserveScroll: true },
        );
    }
}

const triggerClass =
    'flex min-h-11 min-w-0 shrink items-center gap-2 rounded-md px-2 text-left text-sm hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
</script>

<template>
    <div
        v-if="context"
        class="flex min-w-0 flex-1 flex-nowrap items-center gap-1"
    >
        <DropdownMenu>
            <DropdownMenuTrigger
                :class="triggerClass"
                data-test="company-switcher"
            >
                <Building2
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="hidden text-muted-foreground xl:inline"
                    >{{ t('context.company') }} :</span
                >
                <span class="truncate font-medium">{{
                    context.tenant.name
                }}</span>
                <ChevronsUpDown
                    class="size-3.5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="sr-only">{{ t('context.switch_company') }}</span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="min-w-56">
                <DropdownMenuLabel>{{
                    t('context.switch_company')
                }}</DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="option in context.tenants"
                    :key="option.id"
                    class="min-h-11"
                    @select="selectTenant(option.id)"
                >
                    <Check
                        class="size-4"
                        :class="
                            option.id === context.tenant.id
                                ? 'opacity-100'
                                : 'opacity-0'
                        "
                        aria-hidden="true"
                    />
                    {{ option.name }}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem as-child class="min-h-11">
                    <Link :href="createCompany({ query: { new: 1 } })">
                        <Plus class="size-4" aria-hidden="true" />
                        {{ t('context.new_company') }}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <span class="hidden text-muted-foreground xl:inline" aria-hidden="true"
            >|</span
        >

        <DropdownMenu v-if="context.warehouses.length > 0">
            <DropdownMenuTrigger
                :class="triggerClass"
                data-test="warehouse-switcher"
            >
                <Warehouse
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="hidden text-muted-foreground xl:inline"
                    >{{ t('context.warehouse') }} :</span
                >
                <span class="truncate font-medium">{{
                    context.warehouse?.name
                }}</span>
                <ChevronsUpDown
                    class="size-3.5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="sr-only">{{ t('context.switch_warehouse') }}</span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="min-w-56">
                <DropdownMenuLabel>{{
                    t('context.switch_warehouse')
                }}</DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="option in context.warehouses"
                    :key="option.id"
                    class="min-h-11"
                    @select="selectWarehouse(option.id)"
                >
                    <Check
                        class="size-4"
                        :class="
                            option.id === context.warehouse?.id
                                ? 'opacity-100'
                                : 'opacity-0'
                        "
                        aria-hidden="true"
                    />
                    {{ option.name }}
                    <span class="ml-auto text-xs text-muted-foreground">{{
                        option.code
                    }}</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
        <span v-else class="px-2 text-sm text-muted-foreground">{{
            t('context.no_warehouse')
        }}</span>
    </div>
</template>
