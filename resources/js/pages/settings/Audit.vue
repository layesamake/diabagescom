<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { auditActionLabel, t } from '@/i18n';
import { index as auditIndex } from '@/routes/audit';

type AuditRow = {
    id: string;
    action: string;
    entity_type: string | null;
    actor: string | null;
    created_at: string;
    ip: string | null;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: t('audit.page_title'), href: auditIndex() }],
    },
});

defineProps<{
    events: {
        data: AuditRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

const formatter = new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'short',
    timeStyle: 'medium',
});
</script>

<template>
    <Head :title="t('audit.page_title')" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('audit.heading')"
            :description="t('audit.description')"
        />

        <p
            v-if="events.data.length === 0"
            class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground"
        >
            {{ t('audit.empty') }}
        </p>

        <!-- Liste en cartes : lisible sur smartphone, sans défilement horizontal. -->
        <ol v-else class="divide-y rounded-lg border">
            <li
                v-for="event in events.data"
                :key="event.id"
                class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr_auto] sm:items-center sm:gap-4"
            >
                <time
                    :datetime="event.created_at"
                    class="text-sm text-muted-foreground"
                >
                    {{ formatter.format(new Date(event.created_at)) }}
                </time>
                <span class="font-medium">{{
                    auditActionLabel(event.action)
                }}</span>
                <span class="text-sm text-muted-foreground">
                    {{ event.actor ?? t('audit.system') }}
                </span>
            </li>
        </ol>

        <nav
            v-if="events.prev_page_url || events.next_page_url"
            class="flex justify-between"
        >
            <Link
                v-if="events.prev_page_url"
                :href="events.prev_page_url"
                class="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
            >
                {{ t('common.previous') }}
            </Link>
            <span v-else />
            <Link
                v-if="events.next_page_url"
                :href="events.next_page_url"
                class="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
            >
                {{ t('common.next') }}
            </Link>
        </nav>
    </div>
</template>
