import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { initializeRequestErrors } from '@/lib/requestErrors';
import { registerServiceWorker } from '@/offline/serviceWorker';
import { vAmount } from '@/lib/amountDirective';

const appName = import.meta.env.VITE_APP_NAME || 'DIABA GESCOM';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name.startsWith('billing/'):
            case name.startsWith('legal/'):
                return null;
            case name.startsWith('onboarding/'):
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('amount', vAmount);
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#00A88A',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// Erreurs serveur ou réseau : message clair plutôt que la page brute.
initializeRequestErrors();

// PWA : cache de l'application pour la vente hors connexion.
registerServiceWorker();
