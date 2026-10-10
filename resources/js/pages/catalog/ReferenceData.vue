<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { settings } from '@/routes/catalog';
import PhotoField from '@/components/PhotoField.vue';
import * as categoryRoutes from '@/routes/catalog/categories';
import * as categoryImage from '@/routes/catalog/categories/image';
import * as taxRoutes from '@/routes/catalog/tax_rates';
import * as unitRoutes from '@/routes/catalog/units';
import { index as productsIndex } from '@/routes/products';

type Category = {
    id: string;
    name: string;
    parent_id: string | null;
    image_url: string | null;
};
type Unit = {
    id: string;
    code: string;
    name: string;
    decimals_allowed: boolean;
    active: boolean;
};
type Tax = {
    id: string;
    name: string;
    rate_basis_points: number;
    is_default: boolean;
    active: boolean;
};

const props = defineProps<{
    categories: Category[];
    units: Unit[];
    taxRates: Tax[];
    can: { catalog: boolean; taxes: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('catalog.title'), href: productsIndex() },
            { title: t('reference.title'), href: settings() },
        ],
    },
});

const editingCategory = ref<Category | null>(null);
const editingUnit = ref<Unit | null>(null);
const editingTax = ref<Tax | null>(null);

const parentName = (id: string | null) =>
    props.categories.find((c) => c.id === id)?.name;
const percent = (bp: number) => (bp / 100).toLocaleString('fr-FR');
const rateInput = (bp: number) => (bp / 100).toString().replace('.', ',');
const selectClass =
    'flex h-11 w-full rounded-md border border-input bg-transparent px-3 text-sm';
</script>

<template>
    <Head :title="t('reference.title')" />

    <div class="mx-auto max-w-4xl space-y-10 p-4 md:p-6">
        <Link
            :href="productsIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('catalog.back')
            }}
        </Link>
        <Heading
            :title="t('reference.title')"
            :description="t('reference.description')"
        />

        <!-- Catégories -->
        <section class="space-y-4">
            <h2 class="text-lg font-semibold">
                {{ t('reference.categories') }}
            </h2>
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="c in categories"
                    :key="c.id"
                    class="flex flex-wrap items-center gap-3 px-3 py-3"
                >
                    <span class="min-w-40 flex-1 font-medium">
                        <span
                            v-if="c.parent_id"
                            class="font-normal text-muted-foreground"
                            >{{ parentName(c.parent_id) }} › </span
                        >{{ c.name }}
                    </span>
                    <PhotoField
                        :image-url="c.image_url"
                        :alt="c.name"
                        :upload-url="categoryImage.store(c.id).url"
                        :remove-url="categoryImage.destroy(c.id).url"
                        :can-edit="can.catalog"
                        :input-id="`category-${c.id}`"
                        size="sm"
                    />
                    <Button
                        v-if="can.catalog"
                        variant="ghost"
                        class="h-11"
                        @click="editingCategory = c"
                        >{{ t('reference.edit') }}</Button
                    >
                </li>
            </ul>
            <Form
                v-if="can.catalog"
                :key="editingCategory?.id ?? 'new-category'"
                v-bind="
                    categoryRoutes.save.form(
                        editingCategory ? { category: editingCategory.id } : {},
                    )
                "
                reset-on-success
                :options="{ preserveScroll: true }"
                @success="editingCategory = null"
                v-slot="{ errors, processing }"
                class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
            >
                <div class="grid gap-2">
                    <Label for="cat-name">{{ t('reference.name') }}</Label>
                    <Input
                        id="cat-name"
                        name="name"
                        required
                        maxlength="80"
                        class="h-11"
                        :default-value="editingCategory?.name"
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="cat-parent">{{ t('reference.parent') }}</Label>
                    <select
                        id="cat-parent"
                        name="parent_id"
                        :class="selectClass"
                    >
                        <option value="">{{ t('reference.none') }}</option>
                        <option
                            v-for="c in categories.filter(
                                (x) => x.id !== editingCategory?.id,
                            )"
                            :key="c.id"
                            :value="c.id"
                            :selected="editingCategory?.parent_id === c.id"
                        >
                            {{ c.name }}
                        </option>
                    </select>
                    <InputError :message="errors.parent_id" />
                </div>
                <div class="flex gap-2">
                    <Button type="submit" class="h-11" :disabled="processing">{{
                        editingCategory
                            ? t('reference.save')
                            : t('reference.add')
                    }}</Button>
                    <Button
                        v-if="editingCategory"
                        type="button"
                        variant="ghost"
                        class="h-11"
                        @click="editingCategory = null"
                        >{{ t('reference.cancel') }}</Button
                    >
                </div>
            </Form>
        </section>

        <!-- Unités -->
        <section class="space-y-4">
            <h2 class="text-lg font-semibold">{{ t('reference.units') }}</h2>
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="u in units"
                    :key="u.id"
                    class="flex min-h-11 items-center gap-3 px-3 py-2"
                >
                    <span class="w-14 font-mono text-sm">{{ u.code }}</span>
                    <span class="flex-1"
                        >{{ u.name
                        }}<span
                            v-if="u.decimals_allowed"
                            class="text-sm text-muted-foreground"
                        >
                            · {{ t('reference.decimals') }}</span
                        ></span
                    >
                    <Button
                        v-if="can.catalog"
                        variant="ghost"
                        class="h-11"
                        @click="editingUnit = u"
                        >{{ t('reference.edit') }}</Button
                    >
                </li>
            </ul>
            <Form
                v-if="can.catalog"
                :key="editingUnit?.id ?? 'new-unit'"
                v-bind="
                    unitRoutes.save.form(
                        editingUnit ? { unit: editingUnit.id } : {},
                    )
                "
                :transform="
                    (data) => ({
                        ...data,
                        decimals_allowed: data.decimals_allowed ? 1 : 0,
                    })
                "
                reset-on-success
                :options="{ preserveScroll: true }"
                @success="editingUnit = null"
                v-slot="{ errors, processing }"
                class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[8rem_1fr_auto] sm:items-end"
            >
                <div class="grid gap-2">
                    <Label for="unit-code">{{ t('reference.code') }}</Label>
                    <Input
                        id="unit-code"
                        name="code"
                        required
                        maxlength="12"
                        autocapitalize="characters"
                        class="h-11"
                        :default-value="editingUnit?.code"
                    />
                    <InputError :message="errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="unit-name">{{ t('reference.name') }}</Label>
                    <Input
                        id="unit-name"
                        name="name"
                        required
                        maxlength="40"
                        class="h-11"
                        :default-value="editingUnit?.name"
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="flex gap-2">
                    <Button type="submit" class="h-11" :disabled="processing">{{
                        editingUnit ? t('reference.save') : t('reference.add')
                    }}</Button>
                    <Button
                        v-if="editingUnit"
                        type="button"
                        variant="ghost"
                        class="h-11"
                        @click="editingUnit = null"
                        >{{ t('reference.cancel') }}</Button
                    >
                </div>
                <label
                    class="flex min-h-11 items-center gap-3 text-sm sm:col-span-3"
                >
                    <input
                        type="checkbox"
                        name="decimals_allowed"
                        value="1"
                        class="size-5"
                        :checked="editingUnit?.decimals_allowed"
                    />
                    {{ t('reference.decimals') }}
                </label>
            </Form>
        </section>

        <!-- Taxes -->
        <section class="space-y-4">
            <h2 class="text-lg font-semibold">{{ t('reference.taxes') }}</h2>
            <p class="text-sm text-muted-foreground">
                {{ t('reference.taxes_hint') }}
            </p>
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="tax in taxRates"
                    :key="tax.id"
                    class="flex min-h-11 items-center gap-3 px-3 py-2"
                >
                    <span class="flex-1">{{ tax.name }}</span>
                    <span class="tabular-nums"
                        >{{ percent(tax.rate_basis_points) }} %</span
                    >
                    <span class="text-sm text-muted-foreground">
                        <template v-if="tax.is_default">{{
                            t('reference.default')
                        }}</template>
                        <template v-else-if="!tax.active">{{
                            t('reference.inactive')
                        }}</template>
                    </span>
                    <Button
                        v-if="can.taxes"
                        variant="ghost"
                        class="h-11"
                        @click="editingTax = tax"
                        >{{ t('reference.edit') }}</Button
                    >
                </li>
            </ul>
            <Form
                v-if="can.taxes"
                :key="editingTax?.id ?? 'new-tax'"
                v-bind="
                    taxRoutes.save.form(
                        editingTax ? { taxRate: editingTax.id } : {},
                    )
                "
                :transform="
                    (data) => ({
                        ...data,
                        is_default: data.is_default ? 1 : 0,
                        active: data.active ? 1 : 0,
                    })
                "
                reset-on-success
                :options="{ preserveScroll: true }"
                @success="editingTax = null"
                v-slot="{ errors, processing }"
                class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_8rem_auto] sm:items-end"
            >
                <div class="grid gap-2">
                    <Label for="tax-name">{{ t('reference.name') }}</Label>
                    <Input
                        id="tax-name"
                        name="name"
                        required
                        maxlength="60"
                        class="h-11"
                        :default-value="editingTax?.name"
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="tax-rate">{{ t('reference.rate') }}</Label>
                    <Input
                        id="tax-rate"
                        name="rate_percent"
                        required
                        inputmode="decimal"
                        class="h-11"
                        :default-value="
                            editingTax
                                ? rateInput(editingTax.rate_basis_points)
                                : ''
                        "
                    />
                    <InputError :message="errors.rate_percent" />
                </div>
                <div class="flex gap-2">
                    <Button type="submit" class="h-11" :disabled="processing">{{
                        editingTax ? t('reference.save') : t('reference.add')
                    }}</Button>
                    <Button
                        v-if="editingTax"
                        type="button"
                        variant="ghost"
                        class="h-11"
                        @click="editingTax = null"
                        >{{ t('reference.cancel') }}</Button
                    >
                </div>
                <div class="flex flex-wrap gap-6 sm:col-span-3">
                    <label class="flex min-h-11 items-center gap-3 text-sm">
                        <input
                            type="checkbox"
                            name="is_default"
                            value="1"
                            class="size-5"
                            :checked="editingTax?.is_default"
                        />{{ t('reference.default') }}
                    </label>
                    <label class="flex min-h-11 items-center gap-3 text-sm">
                        <input
                            type="checkbox"
                            name="active"
                            value="1"
                            class="size-5"
                            :checked="editingTax?.active ?? true"
                        />{{ t('reference.active') }}
                    </label>
                </div>
            </Form>
        </section>
    </div>
</template>
