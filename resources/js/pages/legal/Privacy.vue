<script setup lang="ts">
import LegalPage from '@/components/LegalPage.vue';
</script>

<!-- Version provisoire : à faire valider par un juriste avant la mise en production. -->
<template>
    <LegalPage title="Politique de confidentialité" updated="12 octobre 2026">
        <p>
            DIABA GESCOM est un logiciel de gestion commerciale en ligne
            (ventes, stock, caisse, achats, comptabilité) destiné aux
            entreprises. Cette page explique quelles données nous traitons,
            pourquoi, et quels sont vos droits.
        </p>

        <h2>Responsable du traitement</h2>
        <p>
            Le service est édité par l’équipe DIABA GESCOM (Sénégal). Contact :
            <a class="underline" href="mailto:etiennesamake@gmail.com"
                >etiennesamake@gmail.com</a
            >. Chaque entreprise cliente reste responsable des données de ses
            propres clients et fournisseurs qu’elle enregistre dans le service.
        </p>

        <h2>Données traitées</h2>
        <ul>
            <li>
                Compte utilisateur : nom, adresse e-mail, mot de passe chiffré,
                réglages de double authentification.
            </li>
            <li>
                Données de l’entreprise : articles, stock, ventes, factures,
                paiements, caisse, achats, clients, fournisseurs, comptabilité.
            </li>
            <li>
                Données techniques : journal d’audit des actions (date, auteur,
                adresse IP) pour la sécurité et la traçabilité.
            </li>
        </ul>

        <h2>Finalités</h2>
        <p>
            Ces données servent uniquement à fournir le service : gérer
            l’activité de l’entreprise, produire ses documents, sécuriser les
            accès, assurer les sauvegardes et le support. Elles ne sont ni
            vendues, ni utilisées à des fins publicitaires.
        </p>

        <h2>Google Drive</h2>
        <p>
            Si vous reliez votre compte Google Drive, DIABA GESCOM demande
            uniquement l’autorisation d’accéder aux fichiers qu’il crée lui-même
            (autorisation « drive.file ») et à votre adresse e-mail pour
            l’afficher. Il s’en sert exclusivement pour déposer vos sauvegardes
            dans un dossier « DIABA GESCOM » et supprimer les plus anciennes. Il
            ne lit, ne modifie et ne partage aucun autre fichier de votre Drive.
        </p>
        <p>
            L’utilisation et le transfert par DIABA GESCOM des informations
            reçues des API Google respectent les
            <a
                class="underline"
                href="https://developers.google.com/terms/api-services-user-data-policy"
                rel="noopener"
                target="_blank"
                >règles relatives aux données utilisateur des services d’API
                Google</a
            >, y compris les exigences d’utilisation limitée (Limited Use). Vous
            pouvez retirer cet accès à tout moment depuis DIABA GESCOM
            (Paramètres → Sauvegarde → Déconnecter) ou depuis votre compte
            Google.
        </p>

        <h2>Hébergement et sécurité</h2>
        <p>
            Les données sont hébergées sur des serveurs sécurisés (connexion
            chiffrée HTTPS, accès restreints, double authentification pour les
            administrateurs). Les sauvegardes de la plateforme sont chiffrées
            avant d’être copiées hors du serveur. Les jetons d’accès à Google
            Drive sont chiffrés.
        </p>

        <h2>Durée de conservation</h2>
        <p>
            Les données de l’entreprise sont conservées tant que le compte est
            actif, puis selon les obligations légales de conservation des pièces
            comptables. Les archives de sauvegarde téléchargeables sont
            supprimées du serveur après 7 jours.
        </p>

        <h2>Vos droits</h2>
        <p>
            Conformément à la loi sénégalaise n° 2008-12 sur la protection des
            données à caractère personnel, vous disposez d’un droit d’accès, de
            rectification, d’opposition et de suppression. Écrivez-nous à
            l’adresse ci-dessus. Vous pouvez aussi saisir la Commission de
            protection des données personnelles (CDP).
        </p>
    </LegalPage>
</template>
