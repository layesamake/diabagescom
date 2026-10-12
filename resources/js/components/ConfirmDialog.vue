<script setup lang="ts">
import { CircleAlert, CircleHelp } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { confirmState, settleConfirm } from '@/composables/useConfirm';
import { t } from '@/i18n';

const canConfirm = computed(
    () => !confirmState.input?.required || confirmState.value.trim().length > 0,
);

function onOpenChange(open: boolean): void {
    if (!open) {
        settleConfirm(null);
    }
}

function confirm(): void {
    if (canConfirm.value) {
        settleConfirm(confirmState.value);
    }
}
</script>

<template>
    <Dialog :open="confirmState.open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-md" :show-close-button="false">
            <form class="grid gap-5" @submit.prevent="confirm">
                <DialogHeader class="gap-3 text-left">
                    <span
                        class="flex size-11 items-center justify-center rounded-full"
                        :class="
                            confirmState.tone === 'danger'
                                ? 'bg-danger-soft text-danger'
                                : 'bg-primary/10 text-primary'
                        "
                    >
                        <CircleAlert
                            v-if="confirmState.tone === 'danger'"
                            class="size-5"
                            aria-hidden="true"
                        />
                        <CircleHelp v-else class="size-5" aria-hidden="true" />
                    </span>
                    <DialogTitle class="text-lg leading-snug">{{
                        confirmState.title
                    }}</DialogTitle>
                    <DialogDescription
                        v-if="confirmState.message"
                        class="text-base leading-relaxed"
                        >{{ confirmState.message }}</DialogDescription
                    >
                </DialogHeader>

                <div v-if="confirmState.input" class="grid gap-2">
                    <Label for="confirm-input">{{
                        confirmState.input.label
                    }}</Label>
                    <textarea
                        id="confirm-input"
                        v-model="confirmState.value"
                        v-focus
                        rows="3"
                        maxlength="500"
                        :placeholder="confirmState.input.placeholder"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none md:text-sm"
                    />
                </div>

                <DialogFooter class="gap-2 sm:gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        class="h-11"
                        @click="settleConfirm(null)"
                        >{{
                            confirmState.cancelLabel ?? t('confirm.cancel')
                        }}</Button
                    >
                    <Button
                        type="submit"
                        class="h-11"
                        :variant="
                            confirmState.tone === 'danger'
                                ? 'destructive'
                                : 'default'
                        "
                        :disabled="!canConfirm"
                        >{{
                            confirmState.confirmLabel ?? t('confirm.ok')
                        }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
