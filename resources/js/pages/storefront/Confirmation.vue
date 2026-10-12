<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { t } from '@/i18n';
import { show } from '@/routes/storefront';
const props = defineProps<{
    storefront: { slug: string };
    order: { number: string; total: number };
}>();
</script>

<template>
    <Head :title="t('storefront.public.confirmation')" />
    <main class="flex min-h-screen items-center justify-center bg-slate-50 p-6">
        <section
            class="w-full max-w-lg rounded-xl border bg-white p-8 text-center"
        >
            <h1 class="text-2xl font-bold">
                {{ t('storefront.public.confirmation') }}
            </h1>
            <p class="mt-3 text-muted-foreground">
                {{
                    t('storefront.public.confirmation_text', {
                        number: order.number,
                    })
                }}
            </p>
            <p class="mt-5 font-semibold">
                {{ order.number }} · {{ order.total }} XOF
            </p>
            <Link
                :href="show(storefront.slug)"
                class="mt-6 inline-flex min-h-11 items-center underline"
                >{{ t('common.back') }}</Link
            >
        </section>
    </main>
</template>
