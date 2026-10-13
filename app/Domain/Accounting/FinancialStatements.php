<?php

namespace App\Domain\Accounting;

use Carbon\CarbonImmutable;

/**
 * États de synthèse SYSCOHADA (présentation simplifiée), calculés uniquement
 * sur les écritures validées : bilan à une date, compte de résultat sur une
 * période.
 *
 * Chaque compte alimente une seule ligne, donc le bilan équilibre toujours si
 * les écritures équilibrent ; l'écart éventuel est exposé (« balanced »).
 * Le résultat de l'exercice en cours est calculé sur les classes 6, 7 et 8 ;
 * après clôture, il figure en 1301/1309 dans l'exercice suivant.
 *
 * Présentation à faire valider par l'expert-comptable (docs/05) : ce n'est pas
 * encore la liasse officielle détaillée (notes annexes, colonnes N-1).
 *
 * Chaque ligne : label, amount (XOF), kind (line | subtotal | total), accounts.
 */
final class FinancialStatements
{
    public function __construct(
        private readonly AccountingReports $reports,
        private readonly FiscalCalendar $calendar,
    ) {}

    /**
     * Bilan à la date $at, depuis le début de l'exercice qui la contient.
     *
     * @return array{tables: list<array{title: string, rows: list<array{label: string, amount: int, kind: string, accounts: string}>}>, balanced: bool, difference: int, result: int}|null null si la date est hors exercice
     */
    public function balanceSheet(CarbonImmutable $at): ?array
    {
        $start = $this->calendar->periodFor($at)?->fiscalYear->starts_on;

        if ($start === null) {
            return null;
        }

        $b = array_fill_keys([
            'capital', 'reserves', 'carry', 'result_posted', 'other_equity', 'financial_debt',
            'fixed_gross', 'fixed_depreciation', 'stocks', 'receivables', 'cash_assets',
            'suppliers', 'tax_social', 'other_payables', 'cash_liabilities', 'result_current',
        ], 0);

        foreach ($this->reports->trialBalance(CarbonImmutable::parse($start->toDateString()), $at) as $row) {
            $code = $row['code'];
            $closing = $row['closing']; // débit positif, crédit négatif

            if ($row['class'] === 1) {
                $b[match (true) {
                    str_starts_with($code, '10') => 'capital',
                    str_starts_with($code, '11') => 'reserves',
                    str_starts_with($code, '12') => 'carry',
                    str_starts_with($code, '13') => 'result_posted',
                    str_starts_with($code, '14'), str_starts_with($code, '15') => 'other_equity',
                    default => 'financial_debt',
                }] -= $closing;
            } elseif ($row['class'] === 2) {
                if (str_starts_with($code, '28') || str_starts_with($code, '29')) {
                    $b['fixed_depreciation'] -= $closing;
                } else {
                    $b['fixed_gross'] += $closing;
                }
            } elseif ($row['class'] === 3) {
                $b['stocks'] += $closing;
            } elseif ($row['class'] === 4) {
                // Compte par compte : solde débiteur = créance, solde créditeur = dette.
                if ($closing >= 0) {
                    $b['receivables'] += $closing;
                } else {
                    $b[match (true) {
                        str_starts_with($code, '40') => 'suppliers',
                        str_starts_with($code, '42'), str_starts_with($code, '43'), str_starts_with($code, '44') => 'tax_social',
                        default => 'other_payables',
                    }] -= $closing;
                }
            } elseif ($row['class'] === 5) {
                if ($closing >= 0) {
                    $b['cash_assets'] += $closing;
                } else {
                    $b['cash_liabilities'] -= $closing;
                }
            } elseif ($row['class'] <= 8) {
                $b['result_current'] -= $closing;
            }
        }

        $fixedNet = $b['fixed_gross'] - $b['fixed_depreciation'];
        $current = $b['stocks'] + $b['receivables'];
        $assets = $fixedNet + $current + $b['cash_assets'];
        $result = $b['result_posted'] + $b['result_current'];
        $equity = $b['capital'] + $b['reserves'] + $b['carry'] + $b['other_equity'] + $result;
        $payables = $b['suppliers'] + $b['tax_social'] + $b['other_payables'];
        $liabilities = $equity + $b['financial_debt'] + $payables + $b['cash_liabilities'];

        return [
            'tables' => [
                ['title' => __('accounting.statements.assets'), 'rows' => [
                    $this->line('fixed_gross', $b['fixed_gross'], '21-27'),
                    $this->line('fixed_depreciation', -$b['fixed_depreciation'], '28-29'),
                    $this->line('fixed_net', $fixedNet, '', 'subtotal'),
                    $this->line('stocks', $b['stocks'], '31-39'),
                    $this->line('receivables', $b['receivables'], '40-49'),
                    $this->line('current_assets', $current, '', 'subtotal'),
                    $this->line('cash_assets', $b['cash_assets'], '50-58'),
                    $this->line('total_assets', $assets, '', 'total'),
                ]],
                ['title' => __('accounting.statements.liabilities'), 'rows' => [
                    $this->line('capital', $b['capital'], '10'),
                    $this->line('reserves', $b['reserves'], '11'),
                    $this->line('carry', $b['carry'], '12'),
                    $this->line('other_equity', $b['other_equity'], '14-15'),
                    $this->line('result_year', $result, '13 / 6-8'),
                    $this->line('equity', $equity, '', 'subtotal'),
                    $this->line('financial_debt', $b['financial_debt'], '16-19'),
                    $this->line('suppliers', $b['suppliers'], '40'),
                    $this->line('tax_social', $b['tax_social'], '42-44'),
                    $this->line('other_payables', $b['other_payables'], '41, 45-49'),
                    $this->line('current_liabilities', $payables, '', 'subtotal'),
                    $this->line('cash_liabilities', $b['cash_liabilities'], '50-58'),
                    $this->line('total_liabilities', $liabilities, '', 'total'),
                ]],
            ],
            'balanced' => $assets === $liabilities,
            'difference' => $assets - $liabilities,
            'result' => $result,
        ];
    }

    /**
     * Compte de résultat de la période [$from, $to] (soldes intermédiaires de gestion).
     *
     * Une période qui dépasse la fin de l'exercice de $from est ramenée à cette fin (clé « clamped_to »)
     * plutôt que de mélanger deux exercices.
     *
     * @return array{tables: list<array{title: string, rows: list<array{label: string, amount: int, kind: string, accounts: string}>}>, balanced: bool, difference: int, result: int, clamped_to: string|null}
     */
    public function incomeStatement(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $yearEnd = $this->calendar->periodFor($from)?->fiscalYear->ends_on;
        $clampedTo = null;

        if ($yearEnd !== null && $to->toDateString() > $yearEnd->toDateString()) {
            $to = CarbonImmutable::parse($yearEnd->toDateString());
            $clampedTo = $to->toDateString();
        }

        $p = array_fill_keys([
            'sales_goods', 'purchases_goods', 'stock_variation', 'other_sales', 'other_products',
            'other_purchases', 'transport', 'external', 'taxes', 'other_charges', 'personnel', 'depreciation',
            'financial_products', 'financial_charges', 'hao_products', 'hao_charges', 'income_tax',
        ], 0);

        foreach ($this->reports->trialBalance($from, $to) as $row) {
            if ($row['class'] < 6 || $row['class'] > 8) {
                continue;
            }

            $code = $row['code'];
            $charge = $row['debit'] - $row['credit'];
            $product = -$charge;
            $two = substr($code, 0, 2);

            if ($row['class'] === 6) {
                $p[match (true) {
                    str_starts_with($code, '601') => 'purchases_goods',
                    str_starts_with($code, '603') => 'stock_variation',
                    $two === '67' => 'financial_charges',
                    $two === '61' => 'transport',
                    $two === '62', $two === '63' => 'external',
                    $two === '64' => 'taxes',
                    $two === '66' => 'personnel',
                    $two === '68', $two === '69' => 'depreciation',
                    $two === '60' => 'other_purchases',
                    default => 'other_charges',
                }] += $charge;
            } elseif ($row['class'] === 7) {
                $p[match (true) {
                    str_starts_with($code, '701') => 'sales_goods',
                    $two === '70' => 'other_sales',
                    $two === '77' => 'financial_products',
                    default => 'other_products',
                }] += $product;
            } elseif ($two === '89') {
                $p['income_tax'] += $charge;
            } elseif (in_array($two, ['81', '83', '85', '87'], true)) {
                $p['hao_charges'] += $charge;
            } else {
                $p['hao_products'] += $product;
            }
        }

        $margin = $p['sales_goods'] - $p['purchases_goods'] - $p['stock_variation'];
        $operatingProducts = $p['other_sales'] + $p['other_products'];
        $operatingCharges = $p['other_purchases'] + $p['transport'] + $p['external'] + $p['taxes'] + $p['other_charges'] + $p['personnel'] + $p['depreciation'];
        $operating = $margin + $operatingProducts - $operatingCharges;
        $financial = $p['financial_products'] - $p['financial_charges'];
        $ordinary = $operating + $financial;
        $hao = $p['hao_products'] - $p['hao_charges'];
        $net = $ordinary + $hao - $p['income_tax'];

        return [
            'tables' => [['title' => __('accounting.statements.income'), 'rows' => [
                $this->line('sales_goods', $p['sales_goods'], '701'),
                $this->line('purchases_goods', -$p['purchases_goods'], '601'),
                $this->line('stock_variation', -$p['stock_variation'], '603'),
                $this->line('commercial_margin', $margin, '', 'subtotal'),
                $this->line('other_sales', $p['other_sales'], '702-707'),
                $this->line('other_products', $p['other_products'], '71-75, 78-79'),
                $this->line('other_purchases', -$p['other_purchases'], '602-608'),
                $this->line('transport', -$p['transport'], '61'),
                $this->line('external', -$p['external'], '62-63'),
                $this->line('taxes', -$p['taxes'], '64'),
                $this->line('other_charges', -$p['other_charges'], '65'),
                $this->line('personnel', -$p['personnel'], '66'),
                $this->line('depreciation', -$p['depreciation'], '68-69'),
                $this->line('operating_result', $operating, '', 'subtotal'),
                $this->line('financial_products', $p['financial_products'], '77'),
                $this->line('financial_charges', -$p['financial_charges'], '67'),
                $this->line('financial_result', $financial, '', 'subtotal'),
                $this->line('ordinary_result', $ordinary, '', 'subtotal'),
                $this->line('hao_products', $p['hao_products'], '82, 84, 86, 88'),
                $this->line('hao_charges', -$p['hao_charges'], '81, 83, 85, 87'),
                $this->line('hao_result', $hao, '', 'subtotal'),
                $this->line('income_tax', -$p['income_tax'], '89'),
                $this->line('net_result', $net, '', 'total'),
            ]]],
            'balanced' => true,
            'difference' => 0,
            'result' => $net,
            'clamped_to' => $clampedTo,
        ];
    }

    /**
     * @return array{label: string, amount: int, kind: string, accounts: string}
     */
    private function line(string $key, int $amount, string $accounts, string $kind = 'line'): array
    {
        return ['label' => __("accounting.statements.lines.{$key}"), 'amount' => $amount, 'kind' => $kind, 'accounts' => $accounts];
    }
}
