<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LayoutGrid, LogOut, Settings } from '@lucide/vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { useAppContext } from '@/composables/useAppContext';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import { mode as simpleMode } from '@/routes/simple';
import type { User } from '@/types';
import { t } from '@/i18n';
import { confirmAction } from '@/composables/useConfirm';
import { unsentCommandCount } from '@/offline/commandGuard';
import { clearOfflineData, syncState } from '@/offline/sync';

type Props = {
    user: User;
};

const { context } = useAppContext();

function versModeSimple(): void {
    router.post(simpleMode().url, { mode: 'simple' });
}

// Déconnexion : prévenir si des ventes hors ligne ne sont pas envoyées, puis
// effacer la base locale et les caches de l'appareil (docs/08).
const confirmLogout = (): boolean => {
    // Les commandes « à traiter » (refusées, en conflit, à vérifier) comptent aussi :
    // elles seraient supprimées de l'appareil sans que personne les ait vues.
    const unsent = unsentCommandCount(syncState);

    if (unsent === 0) {
        void clearOfflineData();

        return true;
    }

    // Ventes en attente : on demande d'abord, puis on se déconnecte si l'utilisateur confirme.
    void confirmAction({
        title: t('offline.logout_pending', { count: unsent }),
        confirmLabel: t('confirm.logout'),
        tone: 'danger',
    }).then((confirmed) => {
        if (confirmed) {
            void clearOfflineData().then(() => router.post(logout().url));
        }
    });

    return false;
};

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                {{ t('nav.settings') }}
            </Link>
        </DropdownMenuItem>
        <!-- Bascule vers l'interface en images (absente si le mode simple
             n'est pas activé sur cet environnement). -->
        <DropdownMenuItem
            v-if="context?.simple_mode_available"
            :as-child="true"
        >
            <button
                type="button"
                class="block w-full cursor-pointer text-left"
                @click="versModeSimple"
            >
                <LayoutGrid class="mr-2 inline h-4 w-4" />
                {{ t('nav.simple_mode') }}
            </button>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            :on-before="confirmLogout"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            {{ t('common.logout') }}
        </Link>
    </DropdownMenuItem>
</template>
