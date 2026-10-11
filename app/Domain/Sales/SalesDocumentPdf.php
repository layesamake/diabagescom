<?php

namespace App\Domain\Sales;

use App\Models\SalesDocument;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * PDF d'un document de vente : A4 (devis, commande, facture, avoir) ou ticket
 * de caisse. Utilisé pour l'impression, le lien de partage et l'envoi par email.
 */
final class SalesDocumentPdf
{
    public const string FORMAT_A4 = 'a4';

    public const string FORMAT_RECEIPT = 'receipt';

    /** @var list<string> */
    public const array FORMATS = [self::FORMAT_A4, self::FORMAT_RECEIPT];

    public function render(SalesDocument $document, Tenant $tenant, string $format = self::FORMAT_A4): DomPdf
    {
        $document->loadMissing(['lines', 'warehouse', 'payments', 'source:id,number', 'author:id,name']);
        $receipt = $format === self::FORMAT_RECEIPT;
        $taxes = $document->lines->groupBy('tax_rate_basis_points')
            ->map(fn ($lines, $rate) => ['rate' => (int) $rate, 'base' => $lines->sum('line_excl_tax'), 'tax' => $lines->sum('line_tax')])
            ->sortKeys()->values();

        $pdf = Pdf::loadView($receipt ? 'pdf.receipt' : 'pdf.sales-document', [
            'document' => $document,
            'tenant' => $tenant,
            'taxes' => $taxes,
            'logo' => public_path('brand/logo-diaba-gescom-horizontal.png'),
        ]);

        // Ticket : 80 mm de large, hauteur selon le nombre de lignes.
        return $receipt
            ? $pdf->setPaper([0, 0, 226.77, 340 + 28 * $document->lines->count() + 20 * $document->payments->count()])
            : $pdf->setPaper('a4');
    }

    public function filename(SalesDocument $document, string $format = self::FORMAT_A4): string
    {
        return ($document->number ?? 'brouillon').($format === self::FORMAT_RECEIPT ? '-recu' : '').'.pdf';
    }
}
