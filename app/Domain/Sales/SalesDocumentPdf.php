<?php

namespace App\Domain\Sales;

use App\Domain\Organization\CompanyProfile;
use App\Models\SalesDocument;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * PDF d'un document de vente : A4 (devis, commande, facture, avoir) ou ticket
 * de caisse 80 mm / 58 mm. Utilisé pour l'impression, le partage et l'email.
 */
final class SalesDocumentPdf
{
    public const string FORMAT_A4 = 'a4';

    public const string FORMAT_RECEIPT = 'receipt';

    public const string FORMAT_RECEIPT_58 = 'receipt_58';

    /** @var list<string> */
    public const array FORMATS = [self::FORMAT_A4, self::FORMAT_RECEIPT, self::FORMAT_RECEIPT_58];

    /** Largeurs de papier thermique prises en charge (mm). */
    public const array TICKET_WIDTHS = [58, 80];

    public static function format(?string $value): string
    {
        return in_array($value, self::FORMATS, true) ? $value : self::FORMAT_A4;
    }

    public function render(SalesDocument $document, Tenant $tenant, string $format = self::FORMAT_A4): DomPdf
    {
        $receipt = $format !== self::FORMAT_A4;
        $width = $format === self::FORMAT_RECEIPT_58 ? 58 : 80;

        $pdf = Pdf::loadView($receipt ? 'pdf.receipt' : 'pdf.sales-document', [
            ...$this->viewData($document, $tenant),
            'width' => $width,
            'logo' => public_path('brand/logo-diaba-gescom-horizontal.png'),
        ]);

        // Ticket : largeur du papier (1 mm = 2,8346 pt), hauteur selon le nombre de lignes.
        $lines = $document->lines->count() * ($width === 58 ? 34 : 28);

        return $receipt
            ? $pdf->setPaper([0, 0, round($width * 2.8346, 2), 340 + $lines + 20 * $document->payments->count()])
            : $pdf->setPaper('a4');
    }

    /**
     * Données communes au PDF et au ticket imprimé depuis le navigateur.
     *
     * @return array<string, mixed>
     */
    public function viewData(SalesDocument $document, Tenant $tenant): array
    {
        $document->loadMissing(['lines', 'warehouse', 'payments', 'source:id,number', 'author:id,name']);
        $taxes = $document->lines->groupBy('tax_rate_basis_points')
            ->map(fn ($lines, $rate) => ['rate' => (int) $rate, 'base' => $lines->sum('line_excl_tax'), 'tax' => $lines->sum('line_tax')])
            ->sortKeys()->values();

        $company = app(CompanyProfile::class);

        return [
            'document' => $document, 'tenant' => $tenant, 'taxes' => $taxes,
            'companyLogo' => $company->pngDataUri($tenant, 'logo'),
            'companyStamp' => $company->pngDataUri($tenant, 'stamp'),
            'vatRegime' => __('company.vat_regimes.'.$company->of($tenant)->vat_regime),
            'companyInfo' => $company->documentInfo($tenant),
        ];
    }

    public function filename(SalesDocument $document, string $format = self::FORMAT_A4): string
    {
        return ($document->number ?? 'brouillon').($format !== self::FORMAT_A4 ? '-recu' : '').'.pdf';
    }
}
