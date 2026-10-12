<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';
import { privacy, terms } from '@/routes/legal';

defineProps<{ title: string; updated: string }>();
</script>

<template>
    <Head :title="title" />

    <div class="min-h-screen bg-background text-foreground">
        <header class="border-b">
            <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-4">
                <Link
                    :href="home()"
                    class="flex items-center gap-2 font-semibold"
                >
                    <AppLogoIcon class="size-8" />DIABA GESCOM
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-3xl space-y-6 px-4 py-10">
            <div class="space-y-1">
                <h1 class="text-3xl font-semibold tracking-tight">
                    {{ title }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    Dernière mise à jour : {{ updated }}
                </p>
            </div>
            <div
                class="space-y-4 leading-relaxed [&_h2]:mt-8 [&_h2]:text-xl [&_h2]:font-semibold [&_li]:ml-5 [&_li]:list-disc [&_ul]:space-y-1"
            >
                <slot />
            </div>
        </main>

        <footer class="border-t">
            <nav
                class="mx-auto flex max-w-3xl flex-wrap gap-4 px-4 py-6 text-sm text-muted-foreground"
            >
                <Link :href="privacy()" class="hover:text-foreground"
                    >Confidentialité</Link
                >
                <Link :href="terms()" class="hover:text-foreground"
                    >Conditions d’utilisation</Link
                >
            </nav>
        </footer>
    </div>
</template>
