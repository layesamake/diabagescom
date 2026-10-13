<?php

namespace App\Domain\Reports;

use App\Domain\Organization\CompanyProfile;
use App\Domain\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Export d'un rapport en Excel (.xlsx) ou PDF, avec le nom et le logo de
 * l'entreprise. L'appelant journalise l'export (audit) avant de répondre.
 */
final class ReportExporter
{
    public const array FORMATS = ['xlsx', 'pdf'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly XlsxWriter $xlsx,
        private readonly CompanyProfile $company,
    ) {}

    public function respond(string $format, TabularReport $report, string $basename): Response
    {
        $tenant = $this->context->tenant();
        $file = Str::slug($basename).'.'.$format;

        if ($format === 'xlsx') {
            return response($this->xlsx->build($report, $tenant->display_name), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$file.'"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $pdf = Pdf::loadView('pdf.report', [
            'report' => $report,
            'tenant' => $tenant,
            'logo' => $this->company->pngDataUri($tenant, 'logo'),
            'generatedAt' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', $report->maxColumns() > 5 ? 'landscape' : 'portrait');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
