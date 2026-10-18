<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le journal des paiements fait foi : le montant payé d'une facture doit égaler la somme
 * signée de ses paiements (encaissements moins remboursements), et un paiement en espèces
 * a un mouvement de caisse, un seul.
 *
 * Le service tient déjà ces totaux à jour sous verrou ; ces contrôles sont le filet de la
 * base contre un script, une correction manuelle ou un futur chemin applicatif qui
 * déclarerait une facture payée sans justificatif.
 *
 * Les contrôles sont différés à la validation de la transaction : un paiement est inscrit
 * avant la mise à jour de la facture, l'ensemble doit seulement être cohérent à la fin.
 * La restauration d'une sauvegarde réinsère l'historique dans l'ordre de l'archive et
 * l'annonce pour sa transaction (diaba.restore), comme pour les sessions de caisse.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION diaba_check_payment_aggregates() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                sales_id uuid;
                invoice_id uuid;
                recorded bigint;
                expected bigint;
            BEGIN
                IF current_setting('diaba.restore', true) = 'on' THEN
                    RETURN NULL;
                END IF;

                -- NEW n'a que les colonnes de sa table : on ne lit chaque champ que dans sa branche.
                IF TG_TABLE_NAME = 'sales_documents' THEN
                    sales_id := NEW.id;
                ELSIF TG_TABLE_NAME = 'supplier_invoices' THEN
                    invoice_id := NEW.id;
                ELSE
                    sales_id := NEW.sales_document_id;
                    invoice_id := NEW.supplier_invoice_id;

                    IF NEW.method = 'cash' AND NOT EXISTS (SELECT 1 FROM cash_movements WHERE payment_id = NEW.id) THEN
                        RAISE EXCEPTION 'cash payment % has no cash movement', NEW.id USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                IF sales_id IS NOT NULL THEN
                    SELECT amount_paid INTO recorded FROM sales_documents WHERE id = sales_id;

                    IF FOUND THEN
                        SELECT COALESCE(SUM(CASE kind WHEN 'customer_payment' THEN amount WHEN 'customer_refund' THEN -amount ELSE 0 END), 0)
                            INTO expected FROM payments WHERE sales_document_id = sales_id;

                        IF recorded <> expected THEN
                            RAISE EXCEPTION 'sales document amount_paid (%) differs from its payments (%)', recorded, expected
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                END IF;

                IF invoice_id IS NOT NULL THEN
                    SELECT amount_paid INTO recorded FROM supplier_invoices WHERE id = invoice_id;

                    IF FOUND THEN
                        SELECT COALESCE(SUM(CASE kind WHEN 'supplier_payment' THEN amount WHEN 'supplier_refund' THEN -amount ELSE 0 END), 0)
                            INTO expected FROM payments WHERE supplier_invoice_id = invoice_id;

                        IF recorded <> expected THEN
                            RAISE EXCEPTION 'supplier invoice amount_paid (%) differs from its payments (%)', recorded, expected
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                END IF;

                RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER sales_documents_payment_aggregate
                AFTER INSERT OR UPDATE OF amount_paid ON sales_documents
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION diaba_check_payment_aggregates();

            CREATE CONSTRAINT TRIGGER supplier_invoices_payment_aggregate
                AFTER INSERT OR UPDATE OF amount_paid ON supplier_invoices
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION diaba_check_payment_aggregates();

            CREATE CONSTRAINT TRIGGER payments_aggregate
                AFTER INSERT ON payments
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION diaba_check_payment_aggregates();

            -- Un paiement n'a jamais deux mouvements de caisse.
            CREATE UNIQUE INDEX cash_movements_payment_unique ON cash_movements (payment_id) WHERE payment_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS cash_movements_payment_unique;
            DROP TRIGGER IF EXISTS payments_aggregate ON payments;
            DROP TRIGGER IF EXISTS supplier_invoices_payment_aggregate ON supplier_invoices;
            DROP TRIGGER IF EXISTS sales_documents_payment_aggregate ON sales_documents;
            DROP FUNCTION IF EXISTS diaba_check_payment_aggregates();
        SQL);
    }
};
