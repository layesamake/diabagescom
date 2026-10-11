<script setup lang="ts">
import { Copy, Mail, MessageCircle, Share2 } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAppContext } from '@/composables/useAppContext';
import { t } from '@/i18n';
import { postJson } from '@/lib/http';
import { formatXof } from '@/lib/money';
import { email as emailRoute, share as shareRoute } from '@/routes/sales';

const props = defineProps<{
    documentId: string;
    number: string;
    typeLabel: string;
    total: number;
    /** Ticket proposé pour les ventes (factures), A4 pour tous les documents. */
    receipt: boolean;
    contact: { phone: string | null; email: string | null };
}>();

const LINK_DAYS = 30;
const { context } = useAppContext();
const format = ref<'a4' | 'receipt'>('a4');
const phone = ref(props.contact.phone ?? '');
const email = ref(props.contact.email ?? '');
const message = ref('');
const busy = ref<'whatsapp' | 'email' | 'link' | null>(null);
const emailError = ref('');

/** Numéro sénégalais à 9 chiffres complété par l'indicatif 221 ; vide = choisir dans WhatsApp. */
function whatsappNumber(raw: string): string {
    const digits = raw.replace(/\D/g, '').replace(/^00/, '');

    return digits.length === 9 ? `221${digits}` : digits;
}

async function createLink(
    channel: 'whatsapp' | 'link',
): Promise<string | null> {
    const { ok, data } = await postJson<{ url: string }>(
        shareRoute(props.documentId).url,
        { format: format.value, channel },
    );

    if (!ok) {
        toast.error(t('sales.share.failed'));

        return null;
    }

    return data.url;
}

async function sendWhatsapp(): Promise<void> {
    // Fenêtre ouverte tout de suite (sinon bloquée par le navigateur), remplie ensuite.
    const popup = window.open('', '_blank');
    busy.value = 'whatsapp';
    const url = await createLink('whatsapp');
    busy.value = null;

    if (!url) {
        popup?.close();

        return;
    }

    const text = t('sales.share.whatsapp_message', {
        type: props.typeLabel.toLowerCase(),
        number: props.number,
        company: context.value?.tenant.name ?? '',
        total: formatXof(props.total),
        url,
    });
    const target = `https://wa.me/${whatsappNumber(phone.value)}?text=${encodeURIComponent(text)}`;

    if (popup) {
        popup.opener = null;
        popup.location.href = target;
    } else {
        window.location.href = target;
    }
}

async function sendEmail(): Promise<void> {
    emailError.value = '';
    busy.value = 'email';
    const { ok, status, data } = await postJson<{
        errors?: Record<string, string[]>;
    }>(emailRoute(props.documentId).url, {
        email: email.value,
        format: format.value,
        message: message.value || null,
    });
    busy.value = null;

    if (ok) {
        toast.success(t('sales.share.email_sent', { email: email.value }));
        message.value = '';
    } else if (status === 422) {
        emailError.value = data.errors?.email?.[0] ?? t('sales.share.failed');
    } else {
        toast.error(t('sales.share.failed'));
    }
}

async function copyLink(): Promise<void> {
    busy.value = 'link';
    const url = await createLink('link');
    busy.value = null;

    if (url) {
        await navigator.clipboard.writeText(url);
        toast.success(t('sales.share.link_copied'));
    }
}
</script>

<template>
    <Dialog>
        <DialogTrigger as-child>
            <Button variant="outline" class="h-11"
                ><Share2 class="size-4" />{{ t('sales.share.button') }}</Button
            >
        </DialogTrigger>
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    t('sales.share.title', { number: `${typeLabel} ${number}` })
                }}</DialogTitle>
                <DialogDescription>{{
                    t('sales.share.description', { days: LINK_DAYS })
                }}</DialogDescription>
            </DialogHeader>

            <fieldset v-if="receipt" class="space-y-2">
                <legend class="mb-2 text-sm font-medium">
                    {{ t('sales.share.format') }}
                </legend>
                <div class="grid grid-cols-2 gap-2">
                    <label
                        v-for="option in ['a4', 'receipt'] as const"
                        :key="option"
                        class="flex min-h-11 cursor-pointer items-center justify-center rounded-md border px-3 text-sm font-medium has-checked:border-primary has-checked:bg-primary/10 has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                    >
                        <input
                            v-model="format"
                            type="radio"
                            name="share-format"
                            :value="option"
                            class="sr-only"
                        />
                        {{ t(`sales.share.format_${option}`) }}
                    </label>
                </div>
            </fieldset>

            <section class="space-y-2 rounded-lg border p-4">
                <h3 class="flex items-center gap-2 font-semibold">
                    <MessageCircle class="size-4 text-success" />{{
                        t('sales.share.whatsapp_title')
                    }}
                </h3>
                <Label for="share-phone">{{ t('sales.share.phone') }}</Label>
                <Input
                    id="share-phone"
                    v-model="phone"
                    type="tel"
                    inputmode="tel"
                    autocomplete="off"
                    class="h-11"
                />
                <p class="text-sm text-muted-foreground">
                    {{ t('sales.share.phone_hint') }}
                </p>
                <Button
                    class="h-11 w-full"
                    :disabled="busy !== null"
                    @click="sendWhatsapp"
                    ><MessageCircle class="size-4" />{{
                        t('sales.share.whatsapp_send')
                    }}</Button
                >
            </section>

            <form
                class="space-y-2 rounded-lg border p-4"
                @submit.prevent="sendEmail"
            >
                <h3 class="flex items-center gap-2 font-semibold">
                    <Mail class="size-4 text-info" />{{
                        t('sales.share.email_title')
                    }}
                </h3>
                <Label for="share-email">{{ t('sales.share.email') }}</Label>
                <Input
                    id="share-email"
                    v-model="email"
                    type="email"
                    required
                    autocomplete="off"
                    class="h-11"
                />
                <InputError :message="emailError" />
                <Label for="share-message">{{
                    t('sales.share.message')
                }}</Label>
                <textarea
                    id="share-message"
                    v-model="message"
                    rows="2"
                    maxlength="1000"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base md:text-sm"
                />
                <Button
                    type="submit"
                    variant="secondary"
                    class="h-11 w-full"
                    :disabled="busy !== null || !email"
                    ><Mail class="size-4" />{{
                        t('sales.share.email_send')
                    }}</Button
                >
            </form>

            <Button
                variant="ghost"
                class="h-11"
                :disabled="busy !== null"
                @click="copyLink"
                ><Copy class="size-4" />{{ t('sales.share.link_copy') }}</Button
            >
        </DialogContent>
    </Dialog>
</template>
