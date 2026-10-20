<?php

namespace App\Http\Controllers\Simple;

use App\Domain\Inventory\StockAccess;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SalesDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
}
