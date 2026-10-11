-- DIABA GESCOM — contrôles d'intégrité métier après restauration (exercice mensuel).
--
-- Chaque contrôle compte des anomalies ; il doit renvoyer 0. Un module absent de
-- l'archive (sauvegarde ancienne) est ignoré. Toute anomalie fait échouer
-- l'exercice (exception en fin de script, code de retour non nul de psql).
--
-- Usage : psql -v ON_ERROR_STOP=1 -d <base restaurée> -f integrity-checks.sql

\set QUIET on
\pset tuples_only on
\pset format unaligned
\o /dev/null

CREATE TEMPORARY TABLE drill_checks (name text PRIMARY KEY, anomalies bigint, skipped boolean);

CREATE FUNCTION pg_temp.check(name text, needed text[], query text) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    t text;
    n bigint;
BEGIN
    FOREACH t IN ARRAY needed LOOP
        IF to_regclass(t) IS NULL THEN
            INSERT INTO drill_checks VALUES (name, 0, true);
            RETURN;
        END IF;
    END LOOP;
    EXECUTE query INTO n;
    INSERT INTO drill_checks VALUES (name, COALESCE(n, 0), false);
END $$;

-- Comptabilité : écritures validées équilibrées, au moins deux lignes.
SELECT pg_temp.check('compta: écritures validées équilibrées', ARRAY['journal_entries', 'journal_entry_lines'], $q$
    SELECT count(*) FROM (
        SELECT e.id FROM journal_entries e LEFT JOIN journal_entry_lines l ON l.entry_id = e.id
        WHERE e.status = 'posted' GROUP BY e.id
        HAVING count(l.id) < 2 OR COALESCE(sum(l.debit), 0) <> COALESCE(sum(l.credit), 0) OR COALESCE(sum(l.debit), 0) = 0
    ) x $q$);

-- Comptabilité : balance générale globale (total débit = total crédit).
SELECT pg_temp.check('compta: balance générale', ARRAY['journal_entries', 'journal_entry_lines'], $q$
    SELECT CASE WHEN COALESCE(sum(l.debit), 0) = COALESCE(sum(l.credit), 0) THEN 0 ELSE 1 END
    FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE e.status = 'posted' $q$);

-- Stock : chaque solde = somme de ses mouvements (ledger append-only).
SELECT pg_temp.check('stock: soldes = somme des mouvements', ARRAY['inventory_balances', 'inventory_movements'], $q$
    SELECT count(*) FROM (
        SELECT COALESCE(b.warehouse_id, m.warehouse_id) w, COALESCE(b.product_id, m.product_id) p
        FROM inventory_balances b
        FULL JOIN (SELECT warehouse_id, product_id, sum(quantity_delta) q FROM inventory_movements GROUP BY 1, 2) m
            ON m.warehouse_id = b.warehouse_id AND m.product_id = b.product_id
        WHERE COALESCE(b.quantity_on_hand, 0) <> COALESCE(m.q, 0)
    ) x $q$);

-- Stock : chaque opération a au moins un mouvement.
SELECT pg_temp.check('stock: opérations sans mouvement', ARRAY['stock_operations', 'inventory_movements'], $q$
    SELECT count(*) FROM stock_operations o WHERE NOT EXISTS (SELECT 1 FROM inventory_movements m WHERE m.operation_id = o.id) $q$);

-- Ventes : total des documents = somme des lignes.
SELECT pg_temp.check('ventes: totaux = somme des lignes', ARRAY['sales_documents', 'sales_document_lines'], $q$
    SELECT count(*) FROM sales_documents d
    LEFT JOIN (SELECT document_id, sum(line_total) t, sum(line_excl_tax) h, sum(line_tax) v FROM sales_document_lines GROUP BY 1) l ON l.document_id = d.id
    WHERE d.status <> 'draft' AND (d.total, d.total_excl_tax, d.total_tax) IS DISTINCT FROM (COALESCE(l.t, 0), COALESCE(l.h, 0), COALESCE(l.v, 0)) $q$);

-- Ventes : montant payé d'une facture = encaissements − remboursements.
SELECT pg_temp.check('ventes: montant payé = paiements', ARRAY['sales_documents', 'payments'], $q$
    SELECT count(*) FROM sales_documents d
    LEFT JOIN (SELECT sales_document_id, sum(CASE WHEN direction = 'in' THEN amount ELSE -amount END) s FROM payments
               WHERE sales_document_id IS NOT NULL GROUP BY 1) p ON p.sales_document_id = d.id
    WHERE d.type = 'invoice' AND d.status = 'validated' AND d.amount_paid <> COALESCE(p.s, 0) $q$);

-- Ventes : montant crédité d'une facture = total de ses avoirs validés.
SELECT pg_temp.check('ventes: montant crédité = avoirs', ARRAY['sales_documents'], $q$
    SELECT count(*) FROM sales_documents d
    LEFT JOIN (SELECT source_document_id, sum(total) s FROM sales_documents
               WHERE type = 'credit_note' AND status = 'validated' GROUP BY 1) c ON c.source_document_id = d.id
    WHERE d.type = 'invoice' AND d.status = 'validated' AND d.amount_credited <> COALESCE(c.s, 0) $q$);

-- Achats : montant réglé d'une facture fournisseur = règlements.
SELECT pg_temp.check('achats: montant réglé = règlements', ARRAY['supplier_invoices', 'payments'], $q$
    SELECT count(*) FROM supplier_invoices i
    LEFT JOIN (SELECT supplier_invoice_id, sum(CASE WHEN direction = 'out' THEN amount ELSE -amount END) s FROM payments
               WHERE supplier_invoice_id IS NOT NULL GROUP BY 1) p ON p.supplier_invoice_id = i.id
    WHERE i.status = 'validated' AND i.amount_paid <> COALESCE(p.s, 0) $q$);

-- Caisse : solde attendu d'une session fermée = fond de caisse + mouvements d'espèces.
SELECT pg_temp.check('caisse: solde attendu des sessions fermées', ARRAY['cash_sessions', 'cash_movements'], $q$
    SELECT count(*) FROM cash_sessions s
    LEFT JOIN (SELECT session_id, sum(amount) t FROM cash_movements GROUP BY 1) m ON m.session_id = s.id
    WHERE s.status = 'closed' AND s.expected_amount <> s.opening_amount + COALESCE(m.t, 0) $q$);

-- Caisse : chaque paiement en espèces a son mouvement de caisse.
SELECT pg_temp.check('caisse: paiements espèces tracés', ARRAY['payments', 'cash_movements'], $q$
    SELECT count(*) FROM payments p WHERE p.method = 'cash'
      AND NOT EXISTS (SELECT 1 FROM cash_movements m WHERE m.payment_id = p.id) $q$);

-- Numérotation continue : pour chaque série (entreprise + préfixe annuel), numéros 1..N sans trou ni doublon.
SELECT pg_temp.check('numérotation: séries continues', ARRAY['sales_documents', 'journal_entries', 'payments', 'stock_transfers', 'inventory_counts', 'purchase_orders', 'goods_receipts', 'supplier_invoices', 'supplier_returns'], $q$
    SELECT count(*) FROM (
        SELECT tenant_id, substring(number from '^(.*)-[0-9]+$') serie,
               count(*) n, max(substring(number from '-([0-9]+)$')::bigint) hi, count(DISTINCT number) d
        FROM (
            SELECT tenant_id, number FROM sales_documents WHERE number IS NOT NULL
            UNION ALL SELECT tenant_id, number FROM journal_entries WHERE number IS NOT NULL
            UNION ALL SELECT tenant_id, number FROM payments
            UNION ALL SELECT tenant_id, number FROM stock_transfers WHERE number IS NOT NULL
            UNION ALL SELECT tenant_id, number FROM inventory_counts WHERE number IS NOT NULL
            UNION ALL SELECT tenant_id, number FROM purchase_orders WHERE number IS NOT NULL
            UNION ALL SELECT tenant_id, number FROM goods_receipts
            UNION ALL SELECT tenant_id, number FROM supplier_invoices WHERE number IS NOT NULL
            UNION ALL SELECT tenant_id, number FROM supplier_returns
        ) all_numbers
        GROUP BY 1, 2
        HAVING count(*) <> max(substring(number from '-([0-9]+)$')::bigint) OR count(*) <> count(DISTINCT number)
    ) x $q$);

-- Comptabilisation : une écriture automatique pointe vers une opération réellement traitée.
SELECT pg_temp.check('compta: écritures automatiques tracées', ARRAY['journal_entries', 'accounting_postings'], $q$
    SELECT count(*) FROM journal_entries e
    WHERE e.source_type IS NOT NULL AND e.source_type <> 'FiscalYear'
      AND NOT EXISTS (SELECT 1 FROM accounting_postings a WHERE a.entry_id = e.id) $q$);

-- Isolation : aucune ligne d'écriture ne mélange deux entreprises avec son en-tête.
SELECT pg_temp.check('isolation: lignes et en-têtes de la même entreprise', ARRAY['journal_entries', 'journal_entry_lines', 'sales_documents', 'sales_document_lines'], $q$
    SELECT (SELECT count(*) FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.tenant_id <> e.tenant_id)
         + (SELECT count(*) FROM sales_document_lines l JOIN sales_documents d ON d.id = l.document_id WHERE l.tenant_id <> d.tenant_id) $q$);

\o
SELECT CASE WHEN skipped THEN '  -  ' WHEN anomalies = 0 THEN '  OK ' ELSE '  KO ' END || name
       || CASE WHEN skipped THEN ' (module absent de l''archive)' WHEN anomalies > 0 THEN ' : ' || anomalies || ' anomalie(s)' ELSE '' END
FROM drill_checks ORDER BY name;

DO $$
DECLARE
    total bigint;
BEGIN
    SELECT sum(anomalies) INTO total FROM drill_checks;
    IF total > 0 THEN
        RAISE EXCEPTION 'Contrôles métier : % anomalie(s) — sauvegarde NON validée', total;
    END IF;
END $$;
