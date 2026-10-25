-- Vérification en lecture seule : cohérence entre factures, paiements et mouvements de caisse.
-- À lancer avant d'activer le garde-fou (migration 2026_11_01_000100_guard_payment_aggregates).
-- Résultat attendu : chaque contrôle renvoie 0 ligne. Aucune donnée n'est modifiée.
--
--   docker compose exec -T postgres psql -U <utilisateur> -d <base> -f - < docs/runbooks/verification-paiements.sql

BEGIN READ ONLY;

-- Synthèse : une ligne par contrôle, avec le nombre d'anomalies.
SELECT 'factures clients : montant payé différent des paiements' AS controle, count(*) AS anomalies
FROM sales_documents d
WHERE d.amount_paid <> COALESCE((
    SELECT SUM(CASE p.kind WHEN 'customer_payment' THEN p.amount WHEN 'customer_refund' THEN -p.amount ELSE 0 END)
    FROM payments p WHERE p.sales_document_id = d.id), 0)
UNION ALL
SELECT 'factures fournisseurs : montant payé différent des paiements', count(*)
FROM supplier_invoices i
WHERE i.amount_paid <> COALESCE((
    SELECT SUM(CASE p.kind WHEN 'supplier_payment' THEN p.amount WHEN 'supplier_refund' THEN -p.amount ELSE 0 END)
    FROM payments p WHERE p.supplier_invoice_id = i.id), 0)
UNION ALL
SELECT 'paiements en espèces sans mouvement de caisse', count(*)
FROM payments p
WHERE p.method = 'cash' AND NOT EXISTS (SELECT 1 FROM cash_movements m WHERE m.payment_id = p.id)
UNION ALL
SELECT 'paiements avec plusieurs mouvements de caisse', count(*)
FROM (SELECT payment_id FROM cash_movements WHERE payment_id IS NOT NULL GROUP BY payment_id HAVING count(*) > 1) x;

-- Détail des factures clients en écart (vide si tout est cohérent).
SELECT d.tenant_id, d.number, d.total, d.amount_paid AS enregistre,
       COALESCE(SUM(CASE p.kind WHEN 'customer_payment' THEN p.amount WHEN 'customer_refund' THEN -p.amount ELSE 0 END), 0) AS somme_paiements
FROM sales_documents d
LEFT JOIN payments p ON p.sales_document_id = d.id
GROUP BY d.id
HAVING d.amount_paid <> COALESCE(SUM(CASE p.kind WHEN 'customer_payment' THEN p.amount WHEN 'customer_refund' THEN -p.amount ELSE 0 END), 0)
ORDER BY d.tenant_id, d.number;

-- Détail des factures fournisseurs en écart (vide si tout est cohérent).
SELECT i.tenant_id, i.number, i.total, i.amount_paid AS enregistre,
       COALESCE(SUM(CASE p.kind WHEN 'supplier_payment' THEN p.amount WHEN 'supplier_refund' THEN -p.amount ELSE 0 END), 0) AS somme_paiements
FROM supplier_invoices i
LEFT JOIN payments p ON p.supplier_invoice_id = i.id
GROUP BY i.id
HAVING i.amount_paid <> COALESCE(SUM(CASE p.kind WHEN 'supplier_payment' THEN p.amount WHEN 'supplier_refund' THEN -p.amount ELSE 0 END), 0)
ORDER BY i.tenant_id, i.number;

ROLLBACK;
