<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, CircleAlert, CircleCheck } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import { formatDate } from '@/lib/dates';
import { index as productsIndex } from '@/routes/products';
import {
    apply as importApply,
    create as importCreate,
    show as importShow,
    store as importStore,
} from '@/routes/products/import';

type ImportState = {
    id: string;
    file_name: string;
    status: 'previewed' | 'applied' | 'discarded';
    rows_total: number;
    rows_valid: number;
    errors: { line: number; messages: string[] }[] | null;
    summary: Record<string, number> | null;
    applied_at: string | null;
    created_at: string;
};

defineProps<{
    batch: ImportState | null;
    recent: Pick<
        ImportState,
        | 'id'
        | 'file_name'
        | 'status'
        | 'rows_total'
        | 'rows_valid'
        | 'created_at'
        | 'applied_at'
    >[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: t('catalog.title'), href: productsIndex() },
            { title: t('import.title'), href: importCreate() },
        ],
    },
});

const example =
    'sku;nom;unite;prix_vente;tva;categorie;code_barres\nRIZ25;Riz parfumé 25 kg;SAC;17500;18;Épicerie;\nHUILE5;Huile 5 L;U;6500;18;Épicerie;';
</script>

<template>
    <Head :title="t('import.title')" />

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
            :title="t('import.title')"
            :description="t('import.description')"
        />

        <template v-if="!batch">
            <section class="space-y-2 rounded-lg border p-4 text-sm">
                <h2 class="font-semibold">{{ t('import.format_title') }}</h2>
                <p class="text-muted-foreground">
                    {{ t('import.format_text') }}
                </p>
                <p class="font-medium">{{ t('import.example') }}</p>
                <pre
                    class="overflow-x-auto rounded bg-muted p-3 font-mono text-xs"
                    >{{ example }}</pre>
            </section>

            <Form
                v-bind="importStore.form()"
                v-slot="{ errors, processing }"
                class="grid gap-3"
            >
                <Label for="file">{{ t('import.file') }}</Label>
                <input
                    id="file"
                    name="file"
                    type="file"
                    accept=".csv,text/csv,text/plain"
                    required
                    class="text-sm file:mr-3 file:min-h-11 file:rounded-md file:border file:bg-transparent file:px-3"
                />
                <InputError :message="errors.file" />
                <Button
                    type="submit"
                    class="h-11 w-full sm:w-fit"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />{{ t('import.analyze') }}
                </Button>
            </Form>

            <section v-if="recent.length" class="space-y-2">
                <h2 class="font-semibold">{{ t('import.recent') }}</h2>
                <ul class="divide-y rounded-lg border text-sm">
                    <li v-for="r in recent" :key="r.id">
                        <Link
                            :href="importShow(r.id)"
                            class="flex min-h-11 flex-wrap items-center gap-x-3 px-3 py-2 hover:bg-accent"
                        >
                            <span class="flex-1 truncate">{{
                                r.file_name
                            }}</span>
                            <span class="text-muted-foreground">{{
                                formatDate(r.created_at)
                            }}</span>
                            <span>{{ t(`import.status.${r.status}`) }}</span>
                        </Link>
                    </li>
                </ul>
            </section>
        </template>

        <template v-else>
            <section class="space-y-3 rounded-lg border p-4" aria-live="polite">
                <h2 class="font-semibold">
                    {{ t('import.preview_title') }} — {{ batch.file_name }}
                </h2>
                <p class="flex items-center gap-2">
                    <component
                        :is="batch.errors?.length ? CircleAlert : CircleCheck"
                        class="size-5"
                        :class="
                            batch.errors?.length
                                ? 'text-warning'
                                : 'text-[var(--color-brand-teal)]'
                        "
                        aria-hidden="true"
                    />
                    {{
                        t('import.rows', {
                            valid: batch.rows_valid,
                            total: batch.rows_total,
                        })
                    }}
                </p>
                <ul
                    v-if="batch.status === 'previewed' && batch.summary"
                    class="list-inside list-disc text-sm text-muted-foreground"
                >
                    <li>
                        {{
                            t('import.to_create', {
                                count: batch.summary.to_create ?? 0,
                            })
                        }}
                    </li>
                    <li>
                        {{
                            t('import.to_update', {
                                count: batch.summary.to_update ?? 0,
                            })
                        }}
                    </li>
                </ul>
                <template v-if="batch.status === 'applied' && batch.summary">
                    <p class="font-medium">
                        {{
                            t('import.applied', {
                                date: formatDate(batch.applied_at),
                            })
                        }}
                    </p>
                    <p class="text-sm">
                        {{
                            t('import.result', {
                                created: batch.summary.created ?? 0,
                                updated: batch.summary.updated ?? 0,
                                unchanged: batch.summary.unchanged ?? 0,
                                categories:
                                    batch.summary.categories_created ?? 0,
                            })
                        }}
                    </p>
                </template>
            </section>

            <section v-if="batch.errors?.length" class="space-y-2">
                <h2 class="font-semibold">{{ t('import.errors_title') }}</h2>
                <ul
                    class="divide-y rounded-lg border border-warning-border text-sm"
                >
                    <li
                        v-for="e in batch.errors"
                        :key="e.line"
                        class="grid gap-1 p-3"
                    >
                        <span class="font-medium">{{
                            t('import.line', { line: e.line })
                        }}</span>
                        <span
                            v-for="(m, i) in e.messages"
                            :key="i"
                            class="text-muted-foreground"
                            >{{ m }}</span
                        >
                    </li>
                </ul>
                <p class="text-sm">{{ t('import.apply_blocked') }}</p>
            </section>

            <Form
                v-if="batch.status === 'previewed' && !batch.errors?.length"
                v-bind="importApply.form(batch.id)"
                v-slot="{ errors, processing }"
                class="grid gap-2"
            >
                <InputError :message="errors.import" />
                <Button
                    type="submit"
                    class="h-11 w-full sm:w-fit"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />{{ t('import.apply') }}
                </Button>
            </Form>

            <Button as-child variant="outline" class="h-11">
                <Link :href="importCreate()">{{ t('import.new_import') }}</Link>
            </Button>
        </template>
    </div>
</template>
