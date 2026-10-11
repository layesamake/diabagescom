<script setup lang="ts">
import { Printer } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useTicketWidth } from '@/composables/useTicketWidth';
import type { TicketWidth } from '@/composables/useTicketWidth';
import { t } from '@/i18n';
import { ticket } from '@/routes/sales';

const props = withDefaults(
    defineProps<{ documentId: string; variant?: 'default' | 'outline' }>(),
    { variant: 'default' },
);

const { width } = useTicketWidth();
const widths: TicketWidth[] = [58, 80];

function print(): void {
    window.open(
        ticket(props.documentId, {
            query: { width: String(width.value), print: '1' },
        }).url,
        '_blank',
        'noopener,width=420,height=720',
    );
}
</script>

<template>
    <div class="inline-flex items-stretch">
        <Button :variant="variant" class="h-11 rounded-r-none" @click="print"
            ><Printer class="size-4" />{{ t('sales.ticket.print') }}</Button
        >
        <label class="sr-only" :for="`ticket-width-${documentId}`">{{
            t('sales.ticket.width')
        }}</label>
        <select
            :id="`ticket-width-${documentId}`"
            v-model.number="width"
            class="h-11 rounded-r-md border border-l-0 border-input bg-background px-2 text-sm"
            :title="t('sales.ticket.width')"
        >
            <option v-for="w in widths" :key="w" :value="w">{{ w }} mm</option>
        </select>
    </div>
</template>
