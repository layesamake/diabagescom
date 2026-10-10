<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Archive,
    ArchiveRestore,
    Barcode,
    Trash2,
} from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import {
    archive as productArchive,
    index as productsIndex,
    store as productStore,
    update as productUpdate,
} from '@/routes/products';
import {
    destroy as barcodeDestroy,
    store as barcodeStore,
} from '@/routes/products/barcodes';
import PhotoField from '@/components/PhotoField.vue';
import {
    destroy as imageDestroy,
    store as imageStore,
} from '@/routes/products/image';
import type { Option, ProductDetail, TaxRateOption, UnitOption } from '@/types';

const props = defineProps<{
    product: ProductDetail | null;
    categories: Option[];
    units: UnitOption[];
    taxRates: TaxRateOption[];
    can: {
        create: boolean;
        update: boolean;
        archive: boolean;
        price_override: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('catalog.title'), href: productsIndex() }],
    },
});

const isEdit = computed(() => props.product !== null);
const canSave = computed(() =>
    isEdit.value ? props.can.update : props.can.create,
);
// À la création, le prix fait partie de la fiche ; ensuite, il faut l'autorisation dédiée.
const pricesLocked = computed(() => isEdit.value && !props.can.price_override);

const formAction = computed(() =>
    props.product ? productUpdate.form(props.product.id) : productStore.form(),
);

const selectClass =
    'flex h-11 w-full rounded-md border border-input bg-transparent px-3 text-base md:text-sm disabled:opacity-60';

function formatRate(bp: number): string {
    return `${(bp / 100).toLocaleString('fr-FR')} %`;
}

function removeBarcode(id: string, code: string): void {
    if (!props.product) {
        return;
    }

    if (window.confirm(t('catalog.remove_barcode', { code }))) {
        router.delete(
            barcodeDestroy({ product: props.product.id, barcode: id }).url,
            { preserveScroll: true },
        );
    }
}

function toggleArchive(): void {
    if (props.product) {
        router.post(
            productArchive(props.product.id).url,
            { archived: !props.product.archived },
            { preserveScroll: true },
        );
    }
}
</script>

<template>
    <Head
        :title="
            product
                ? t('catalog.edit_title', { sku: product.sku })
                : t('catalog.create_title')
        "
    />

    <div class="mx-auto max-w-3xl space-y-8 p-4 md:p-6">
        <Link
            :href="productsIndex()"
            class="inline-flex min-h-11 items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />{{
                t('catalog.back')
            }}
        </Link>

        <Heading
            :title="product ? product.name : t('catalog.create_title')"
            :description="product ? product.sku : t('catalog.description')"
        />

        <p
            v-if="product?.archived"
            role="status"
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-50"
        >
            {{ t('catalog.archived_notice') }}
        </p>

        <Form
            v-bind="formAction"
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing }"
            class="grid gap-5"
        >
            <input
                v-if="product"
                type="hidden"
                name="version"
                :value="product.version"
            />
            <InputError :message="errors.version" />

            <div class="grid gap-5 sm:grid-cols-[1fr_2fr]">
                <div class="grid gap-2">
                    <Label for="sku">{{ t('catalog.sku') }}</Label>
                    <Input
                        id="sku"
                        name="sku"
                        required
                        maxlength="40"
                        autocapitalize="characters"
                        class="h-11"
                        :default-value="product?.sku"
                        :disabled="!canSave"
                    />
                    <InputError :message="errors.sku" />
                </div>
                <div class="grid gap-2">
                    <Label for="name">{{ t('catalog.name') }}</Label>
                    <Input
                        id="name"
                        name="name"
                        required
                        maxlength="160"
                        class="h-11"
                        :default-value="product?.name"
                        :disabled="!canSave"
                        v-focus="!product"
                    />
                    <InputError :message="errors.name" />
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="type">{{ t('catalog.type') }}</Label>
                    <select
                        id="type"
                        name="type"
                        :class="selectClass"
                        :disabled="!canSave"
                    >
                        <option
                            v-for="type in [
                                'stockable',
                                'service',
                                'consumable',
                            ] as const"
                            :key="type"
                            :value="type"
                            :selected="(product?.type ?? 'stockable') === type"
                        >
                            {{ t(`catalog.types.${type}`) }}
                        </option>
                    </select>
                    <InputError :message="errors.type" />
                </div>
                <div class="grid gap-2">
                    <Label for="category_id">{{ t('catalog.category') }}</Label>
                    <select
                        id="category_id"
                        name="category_id"
                        :class="selectClass"
                        :disabled="!canSave"
                    >
                        <option value="">{{ t('catalog.no_category') }}</option>
                        <option
                            v-for="c in categories"
                            :key="c.id"
                            :value="c.id"
                            :selected="product?.category_id === c.id"
                        >
                            {{ c.name }}
                        </option>
                    </select>
                    <InputError :message="errors.category_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="unit_id">{{ t('catalog.unit') }}</Label>
                    <select
                        id="unit_id"
                        name="unit_id"
                        required
                        :class="selectClass"
                        :disabled="!canSave"
                    >
                        <option
                            v-for="u in units"
                            :key="u.id"
                            :value="u.id"
                            :selected="
                                product
                                    ? product.unit_id === u.id
                                    : u.code === 'U'
                            "
                        >
                            {{ u.name }} ({{ u.code }})
                        </option>
                    </select>
                    <InputError :message="errors.unit_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="tax_rate_id">{{ t('catalog.tax') }}</Label>
                    <select
                        id="tax_rate_id"
                        name="tax_rate_id"
                        required
                        :class="selectClass"
                        :disabled="!canSave"
                    >
                        <option
                            v-for="tax in taxRates"
                            :key="tax.id"
                            :value="tax.id"
                            :selected="
                                product
                                    ? product.tax_rate_id === tax.id
                                    : tax.is_default
                            "
                        >
                            {{ tax.name }} —
                            {{ formatRate(tax.rate_basis_points) }}
                        </option>
                    </select>
                    <InputError :message="errors.tax_rate_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="sale_price">{{
                        t('catalog.sale_price')
                    }}</Label>
                    <Input
                        id="sale_price"
                        name="sale_price"
                        required
                        inputmode="numeric"
                        pattern="[0-9 ]*"
                        class="h-11 tabular-nums"
                        :default-value="product?.sale_price ?? ''"
                        :readonly="pricesLocked"
                        :disabled="!canSave"
                    />
                    <InputError :message="errors.sale_price" />
                </div>
                <div class="grid gap-2">
                    <Label for="standard_cost">{{
                        t('catalog.standard_cost')
                    }}</Label>
                    <Input
                        id="standard_cost"
                        name="standard_cost"
                        inputmode="numeric"
                        pattern="[0-9 ]*"
                        class="h-11 tabular-nums"
                        :default-value="product?.standard_cost ?? ''"
                        :readonly="pricesLocked"
                        :disabled="!canSave"
                    />
                    <InputError :message="errors.standard_cost" />
                </div>
            </div>
            <p
                v-if="pricesLocked && canSave"
                class="-mt-2 text-sm text-muted-foreground"
            >
                {{ t('catalog.price_locked') }}
            </p>

            <div class="grid gap-2">
                <Label for="description">{{
                    t('catalog.description_label')
                }}</Label>
                <textarea
                    id="description"
                    name="description"
                    rows="3"
                    maxlength="2000"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base md:text-sm"
                    :disabled="!canSave"
                    >{{ product?.description ?? '' }}</textarea>
                <InputError :message="errors.description" />
            </div>

            <div v-if="!product" class="grid gap-2">
                <Label for="barcode">{{ t('catalog.barcode') }}</Label>
                <Input
                    id="barcode"
                    name="barcode"
                    maxlength="64"
                    inputmode="numeric"
                    autocomplete="off"
                    class="h-11"
                    aria-describedby="barcode-hint"
                />
                <p id="barcode-hint" class="text-sm text-muted-foreground">
                    {{ t('catalog.barcode_hint') }}
                </p>
                <InputError :message="errors.barcode" />
                <label class="flex min-h-11 items-center gap-3 text-sm">
                    <input
                        type="checkbox"
                        name="generate_internal_barcode"
                        value="1"
                        class="size-5"
                    />
                    {{ t('catalog.generate_barcode') }}
                </label>
            </div>

            <Button
                v-if="canSave"
                type="submit"
                class="h-11 w-full sm:w-fit"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                {{ product ? t('catalog.save') : t('catalog.create') }}
            </Button>
        </Form>

        <template v-if="product">
            <section class="space-y-4 border-t pt-6">
                <h2 class="text-lg font-semibold">
                    {{ t('catalog.barcodes_title') }}
                </h2>
                <p
                    v-if="product.barcodes.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('catalog.barcodes_empty') }}
                </p>
                <ul v-else class="divide-y rounded-lg border">
                    <li
                        v-for="code in product.barcodes"
                        :key="code.id"
                        class="flex items-center gap-3 p-3"
                    >
                        <Barcode
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1 truncate font-mono">{{
                            code.code
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            t(`catalog.symbologies.${code.symbology}`)
                        }}</span>
                        <span
                            v-if="code.is_primary"
                            class="rounded bg-muted px-2 py-0.5 text-xs font-medium"
                            >{{ t('catalog.primary') }}</span
                        >
                        <Button
                            v-if="can.update"
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-11 text-destructive"
                            :aria-label="
                                t('catalog.remove_barcode', { code: code.code })
                            "
                            @click="removeBarcode(code.id, code.code)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </li>
                </ul>
                <Form
                    v-if="can.update"
                    v-bind="barcodeStore.form(product.id)"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="grid gap-2 sm:grid-cols-[1fr_auto]"
                >
                    <Label for="new-barcode" class="sr-only">{{
                        t('catalog.barcode')
                    }}</Label>
                    <Input
                        id="new-barcode"
                        name="barcode"
                        maxlength="64"
                        inputmode="numeric"
                        autocomplete="off"
                        class="h-11"
                        :placeholder="t('catalog.barcode')"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        class="h-11"
                        :disabled="processing"
                        >{{ t('catalog.add_barcode') }}</Button
                    >
                    <InputError
                        :message="errors.barcode"
                        class="sm:col-span-2"
                    />
                </Form>
                <Form
                    v-if="can.update"
                    v-bind="barcodeStore.form(product.id)"
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <input type="hidden" name="generate" value="1" />
                    <Button
                        type="submit"
                        variant="ghost"
                        class="h-11"
                        :disabled="processing"
                        >{{ t('catalog.generate_barcode') }}</Button
                    >
                </Form>
            </section>

            <section class="space-y-4 border-t pt-6">
                <h2 class="text-lg font-semibold">
                    {{ t('catalog.image_title') }}
                </h2>
                <PhotoField
                    :image-url="product.image_url"
                    :alt="product.name"
                    :upload-url="imageStore(product.id).url"
                    :remove-url="imageDestroy(product.id).url"
                    :can-edit="can.update"
                    input-id="product-photo"
                />
            </section>

            <section v-if="can.archive" class="border-t pt-6">
                <Button
                    type="button"
                    variant="outline"
                    class="h-11"
                    @click="toggleArchive"
                >
                    <component
                        :is="product.archived ? ArchiveRestore : Archive"
                        class="size-4"
                    />
                    {{
                        product.archived
                            ? t('catalog.restore')
                            : t('catalog.archive')
                    }}
                </Button>
            </section>
        </template>
    </div>
</template>
