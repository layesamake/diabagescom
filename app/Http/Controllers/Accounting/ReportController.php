<?php

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\AccountingReports;
use App\Domain\Audit\AuditLog;
use App\Http\Controllers\Controller;
use App\Models\ChartAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * États comptables (écritures validées uniquement) et exports CSV journalisés.
 */
class ReportController extends Controller
{
    public function index(Request $request, AccountingReports $reports, AuditLog $audit): Response|StreamedResponse
    {
        Gate::authorize('accounting.view');

        $report = in_array($request->query('report'), ['balance', 'ledger', 'customers', 'suppliers'], true) ? (string) $request->query('report') : 'balance';
        $from = CarbonImmutable::parse((string) $request->query('from', CarbonImmutable::today()->startOfYear()->toDateString()));
        $to = CarbonImmutable::parse((string) $request->query('to', CarbonImmutable::today()->toDateString()));
        $accountCode = (string) $request->query('account', '');
        /** @var ChartAccount|null $account */
        $account = $accountCode !== '' ? ChartAccount::query()->where('code', $accountCode)->first() : null;

        $data = match ($report) {
            'ledger' => $account !== null ? $reports->generalLedger($account, $from, $to) : null,
            'customers' => $reports->partyBalances('customer', $to),
            'suppliers' => $reports->partyBalances('supplier', $to),
            default => $reports->trialBalance($from, $to),
        };

        if ($request->query('format') === 'csv') {
            Gate::authorize('accounting.export');
            $audit->record('accounting.exported', metadata: ['report' => $report, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'account' => $accountCode ?: null]);

            return $this->csv($report, $data, "{$report}-{$from->toDateString()}-{$to->toDateString()}.csv");
        }

        return Inertia::render('accounting/Reports', [
            'report' => $report,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'account' => $accountCode],
            'data' => $data,
            'accounts' => ChartAccount::query()->orderBy('code')->get(['code', 'name']),
            'can' => ['export' => Gate::allows('accounting.export')],
        ]);
    }

    /**
     * @param  array<int|string, mixed>|null  $data
     */
    private function csv(string $report, ?array $data, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report, $data): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF"); // BOM : accents corrects dans Excel

            $rows = match ($report) {
                'ledger' => array_merge([['Date', 'Journal', 'Pièce', 'Libellé', 'Débit', 'Crédit', 'Solde']],
                    array_map(fn (array $l) => [$l['date'], $l['journal'], $l['number'], $l['label'], $l['debit'], $l['credit'], $l['balance']], $data['lines'] ?? [])),
                'customers', 'suppliers' => array_merge([['Code', 'Nom', 'Débit', 'Crédit', 'Solde']],
                    array_map(fn (array $r) => [$r['code'], $r['name'], $r['debit'], $r['credit'], $r['balance']], $data ?? [])),
                default => array_merge([['Compte', 'Intitulé', 'Solde d’ouverture', 'Débit', 'Crédit', 'Solde de clôture']],
                    array_map(fn (array $r) => [$r['code'], $r['name'], $r['opening'], $r['debit'], $r['credit'], $r['closing']], $data ?? [])),
            };

            foreach ($rows as $row) {
                fputcsv($out, $row, ';', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
