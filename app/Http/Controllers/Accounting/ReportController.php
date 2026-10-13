<?php

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\AccountingReports;
use App\Domain\Accounting\FinancialStatements;
use App\Domain\Audit\AuditLog;
use App\Domain\Reports\ReportExporter;
use App\Domain\Reports\TabularReport;
use App\Http\Controllers\Controller;
use App\Models\ChartAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * États comptables (écritures validées uniquement) et exports CSV journalisés.
 */
class ReportController extends Controller
{
    private const array REPORTS = ['balance', 'ledger', 'customers', 'suppliers', 'balance_sheet', 'income_statement'];

    public function index(Request $request, AccountingReports $reports, FinancialStatements $statements, AuditLog $audit, ReportExporter $exporter): Response|StreamedResponse|HttpResponse
    {
        Gate::authorize('accounting.view');

        $report = in_array($request->query('report'), self::REPORTS, true) ? (string) $request->query('report') : 'balance';
        $from = CarbonImmutable::parse((string) $request->query('from', CarbonImmutable::today()->startOfYear()->toDateString()));
        $to = CarbonImmutable::parse((string) $request->query('to', CarbonImmutable::today()->toDateString()));
        $accountCode = (string) $request->query('account', '');
        /** @var ChartAccount|null $account */
        $account = $accountCode !== '' ? ChartAccount::query()->where('code', $accountCode)->first() : null;

        // Un export du grand livre sans compte ne produirait qu'un fichier vide.
        abort_if($report === 'ledger' && $account === null && in_array($request->query('format'), ['csv', ...ReportExporter::FORMATS], true), 422, __('accounting.export.account_required'));

        $data = match ($report) {
            'ledger' => $account !== null ? $reports->generalLedger($account, $from, $to) : null,
            'customers' => $reports->partyBalances('customer', $to),
            'suppliers' => $reports->partyBalances('supplier', $to),
            'balance_sheet' => $statements->balanceSheet($to),
            'income_statement' => $statements->incomeStatement($from, $to),
            default => $reports->trialBalance($from, $to),
        };

        if ($request->query('format') === 'csv') {
            Gate::authorize('accounting.export');
            $audit->record('accounting.exported', metadata: ['report' => $report, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'account' => $accountCode ?: null]);

            return $this->csv($report, $data, "{$report}-{$from->toDateString()}-{$to->toDateString()}.csv");
        }

        if (in_array($request->query('format'), ReportExporter::FORMATS, true)) {
            Gate::authorize('accounting.export');
            $format = (string) $request->query('format');
            $audit->record('accounting.exported', metadata: ['report' => $report, 'format' => $format, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'account' => $accountCode ?: null]);

            return $exporter->respond($format, $this->tabular($report, $data, $from, $to), "{$report}-{$from->toDateString()}-{$to->toDateString()}");
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
                'balance_sheet', 'income_statement' => $this->statementRows($data),
                default => array_merge([['Compte', 'Intitulé', 'Solde d’ouverture', 'Débit', 'Crédit', 'Solde de clôture']],
                    array_map(fn (array $r) => [$r['code'], $r['name'], $r['opening'], $r['debit'], $r['credit'], $r['closing']], $data ?? [])),
            };

            foreach ($rows as $row) {
                fputcsv($out, $row, ';', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Rapport en tableaux, pour l'export Excel et PDF.
     *
     * @param  array<int|string, mixed>|null  $data
     */
    private function tabular(string $report, ?array $data, CarbonImmutable $from, CarbonImmutable $to): TabularReport
    {
        $title = __("accounting.report_titles.{$report}");
        $to = isset($data['clamped_to']) ? CarbonImmutable::parse((string) $data['clamped_to']) : $to;
        $period = in_array($report, ['balance_sheet', 'customers', 'suppliers'], true)
            ? __('accounting.export.as_of', ['date' => $to->format('d/m/Y')])
            : __('accounting.export.period', ['from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y')]);

        $sections = match ($report) {
            'ledger' => [TabularReport::section(null, ['Date', 'Journal', 'Pièce', 'Libellé', 'Débit', 'Crédit', 'Solde'], array_merge(
                [['Solde d’ouverture', '', '', '', '', '', (int) ($data['opening'] ?? 0)]],
                array_map(fn (array $l) => [$l['date'], $l['journal'], $l['number'], $l['label'], $l['debit'], $l['credit'], $l['balance']], $data['lines'] ?? []),
                [['Solde de clôture', '', '', '', '', '', (int) ($data['closing'] ?? 0)]],
            ), [0, count($data['lines'] ?? []) + 1])],
            'customers', 'suppliers' => [TabularReport::section(null, ['Code', 'Nom', 'Débit', 'Crédit', 'Solde'],
                array_map(fn (array $r) => [$r['code'], $r['name'], $r['debit'], $r['credit'], $r['balance']], $data ?? []))],
            'balance_sheet', 'income_statement' => array_map(function (array $table): array {
                $strong = [];

                foreach ($table['rows'] as $i => $line) {
                    if ($line['kind'] !== 'line') {
                        $strong[] = $i;
                    }
                }

                return TabularReport::section($table['title'], ['Poste', 'Comptes', 'Montant (XOF)'],
                    array_map(fn (array $l) => [$l['label'], $l['accounts'], $l['amount']], $table['rows']), $strong);
            }, $data['tables'] ?? []),
            default => [TabularReport::section(null, ['Compte', 'Intitulé', 'Solde d’ouverture', 'Débit', 'Crédit', 'Solde de clôture'],
                array_map(fn (array $r) => [$r['code'], $r['name'], $r['opening'], $r['debit'], $r['credit'], $r['closing']], $data ?? []))],
        };

        return new TabularReport($title, $period, $sections);
    }

    /**
     * Lignes d'un état de synthèse : un bloc par tableau (Actif, Passif…).
     *
     * @param  array<int|string, mixed>|null  $data
     * @return list<list<int|string>>
     */
    private function statementRows(?array $data): array
    {
        $rows = [];

        foreach ($data['tables'] ?? [] as $table) {
            $rows[] = [$table['title'], 'Comptes', 'Montant (XOF)'];

            foreach ($table['rows'] as $line) {
                $rows[] = [$line['label'], $line['accounts'], $line['amount']];
            }

            $rows[] = [];
        }

        return $rows;
    }
}
