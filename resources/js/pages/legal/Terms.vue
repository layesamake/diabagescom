<script setup lang="ts">
import LegalPage from '@/components/LegalPage.vue';
</script>

<!-- Version provisoire : à faire valider par un juriste avant la mise en production. -->
<template>
    <LegalPage title="Conditions d’utilisation" updated="12 octobre 2026">
        <p>
            Les présentes conditions encadrent l’utilisation de DIABA GESCOM,
            logiciel de gestion commerciale en ligne. En créant un compte, vous
            les acceptez.
        </p>

        <h2>Le service</h2>
        <p>
            DIABA GESCOM permet de gérer ventes, stock, caisse, achats et
            comptabilité. Les fonctions disponibles dépendent de la formule
            choisie. Un essai gratuit peut être proposé ; à son terme, un
            abonnement est nécessaire pour continuer à enregistrer des
            opérations.
        </p>

        <h2>Votre compte</h2>
        <ul>
            <li>
                Vous êtes responsable de la confidentialité de vos identifiants
                et des accès que vous donnez à vos collaborateurs.
            </li>
            <li>
                Les administrateurs doivent activer la double authentification.
            </li>
            <li>
                Vous vous engagez à saisir des informations exactes et à
                utiliser le service conformément à la loi.
            </li>
        </ul>

        <h2>Vos données</h2>
        <p>
            Les données que vous enregistrez vous appartiennent. Vous pouvez à
            tout moment en télécharger une sauvegarde complète (Paramètres →
            Sauvegarde). Leur traitement est décrit dans la politique de
            confidentialité.
        </p>

        <h2>Documents et règles comptables</h2>
        <p>
            Les factures, écritures et rapports sont produits à partir de vos
            saisies. Les règles comptables et fiscales intégrées doivent être
            validées par votre expert-comptable ; DIABA GESCOM ne remplace pas
            son conseil.
        </p>

        <h2>Disponibilité</h2>
        <p>
            Nous faisons notre possible pour assurer un service continu et
            sauvegardé chaque jour. Des interruptions ponctuelles peuvent
            survenir pour maintenance. Le mode hors connexion permet de
            continuer à vendre ; les ventes sont validées à la synchronisation.
        </p>

        <h2>Contact</h2>
        <p>
            Pour toute question :
            <a class="underline" href="mailto:etiennesamake@gmail.com"
                >etiennesamake@gmail.com</a
            >.
        </p>
    </LegalPage>
</template>
