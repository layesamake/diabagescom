<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Inventory\StockAccess;
use App\Domain\Sales\PaymentService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sales\SalesDocumentController;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mode simple — lot 5 : liste tactile des montants dus par client.
 * Les paiements restent traités par PaymentService, partagé avec le mode expert.
 */
class SimpleCustomerController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) config('diaba.simple_mode.enabled'), 404);
    }

    public function index(StockAccess $access): Response
    {
        Gate::authorize('customer.view');

        $due = 'SUM(GREATEST(sales_documents.total - sales_documents.amount_paid - sales_documents.amount_credited, 0))';

        $customers = Customer::query()
            ->select('customers.id', 'customers.name', 'customers.phone')
            ->selectRaw("{$due} AS due")
            ->join('sales_documents', 'sales_documents.customer_id', '=', 'customers.id')
            ->whereIn('sales_documents.warehouse_id', $access->accessibleIds())
            ->where('sales_documents.type', SalesDocument::INVOICE)
            ->where('sales_documents.status', SalesDocument::VALIDATED)
            ->groupBy('customers.id', 'customers.name', 'customers.phone')
            ->havingRaw("{$due} > 0")
            ->orderByDesc(DB::raw($due))
            ->orderBy('customers.name')
            ->get()
            ->map(fn (Customer $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'due' => (int) $customer->getAttribute('due'),
            ])
            ->values();

        return Inertia::render('simple/Clients', [
            'total_due' => $customers->sum('due'),
            'customers' => $customers,
        ]);
    }

    public function show(Customer $customer, StockAccess $access): Response
    {
        Gate::authorize('customer.view');

        $invoices = $this->invoices($customer, $access);
        abort_if($invoices === [], 404);

        return Inertia::render('simple/Client', [
            'customer' => $customer->only(['id', 'name', 'phone']),
            'total_due' => array_sum(array_column($invoices, 'balance')),
            'invoices' => $invoices,
            'methods' => Payment::METHODS,
        ]);
    }

    public function pay(Request $request, Customer $customer, SalesDocument $document, StockAccess $access, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('cash.receive');
        abort_unless($document->customer_id === $customer->id, 404);
        abort_unless(in_array($document->warehouse_id, $access->accessibleIds(), true), 404);

        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'cash_session_id' => ['nullable', 'uuid'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $payments->receive($document, [[
            'method' => (string) $data['method'],
            'amount' => (int) $data['amount'],
        ]], $data['cash_session_id'] ?? null, $user, (string) $data['idempotency_key']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('sales.saved.payment')]);

        return to_route('simple.customers.show', $customer);
    }

    /**
     * @return list<array{id: string, number: string|null, balance: int, sessions: list<array{id: string, register: string, opened_by: string}>}>
     */
    private function invoices(Customer $customer, StockAccess $access): array
    {
        return array_values(SalesDocument::query()
            ->where('customer_id', $customer->id)
            ->whereIn('warehouse_id', $access->accessibleIds())
            ->where('type', SalesDocument::INVOICE)
            ->where('status', SalesDocument::VALIDATED)
            ->orderBy('issue_date')
            ->get()
            ->map(fn (SalesDocument $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'balance' => $invoice->balanceDue(),
                'sessions' => SalesDocumentController::openSessions($invoice->warehouse_id),
            ])
            ->filter(fn (array $invoice): bool => $invoice['balance'] > 0)
            ->all());
    }
}
