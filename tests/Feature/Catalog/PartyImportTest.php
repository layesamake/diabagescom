<?php

namespace Tests\Feature\Catalog;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditEvent;
use App\Models\CatalogImport;
use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\SalesTestHelpers;
use Tests\TestCase;

class PartyImportTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function upload(array $setup, string $type, string $content): CatalogImport
    {
        $response = $this->actingAs($setup['user'])->post(route("{$type}.import.store"), ['file' => UploadedFile::fake()->createWithContent('contacts.csv', $content)]);
        $response->assertSessionHasNoErrors();

        // L'import créé est celui de la redirection (jamais « le plus récent » : deux imports
        // dans la même seconde ont la même date).
        $id = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

        return app(TenantContext::class)->runAs($setup['tenant'], fn () => CatalogImport::query()->findOrFail($id));
    }

    private function rejected(array $setup, string $type, string $content): void
    {
        $this->actingAs($setup['user'])->post(route("{$type}.import.store"), ['file' => UploadedFile::fake()->createWithContent('contacts.csv', $content)])->assertSessionHasErrors('file');
    }

    /** @return Collection<int, Customer> */
    private function customers(array $setup)
    {
        return app(TenantContext::class)->runAs($setup['tenant'], fn () => Customer::query()->orderBy('name')->get());
    }

    public function test_customers_are_previewed_then_applied_with_automatic_codes(): void
    {
        $setup = $this->createTenantWithMember();
        $import = $this->upload($setup, 'customers', "code;nom;telephone;email;adresse;ville;ninea;delai_paiement;notes;plafond_credit\n;Boutique Aminata;77 123 45 67;aminata@exemple.sn;12 rue Carnot;Dakar;006123456 2G2;30;Client régulier;500 000\nCL-9;Moussa Ndiaye;+221 76 000 00 00;;;Thiès;;;;\n");

        $this->assertSame('previewed', $import->status);
        $this->assertSame('customers', $import->type);
        $this->assertSame(['to_create' => 2, 'to_update' => 0], $import->summary);
        $this->assertCount(0, $this->customers($setup)); // L'aperçu n'écrit rien.

        $this->actingAs($setup['user'])->post(route('customers.import.apply', $import->id))->assertSessionHasNoErrors();

        $customers = $this->customers($setup);
        $this->assertCount(2, $customers);
        $aminata = $customers->firstWhere('name', 'Boutique Aminata');
        $this->assertSame(500000, $aminata->credit_limit);
        $this->assertSame(30, $aminata->payment_term_days);
        $this->assertNotEmpty($aminata->code); // code attribué automatiquement
        $this->assertSame('CL-9', $customers->firstWhere('name', 'Moussa Ndiaye')->code);

        $this->assertSame('applied', $import->refresh()->status);
        $this->assertTrue(AuditEvent::query()->withoutGlobalScopes()->where('action', 'catalog.import_applied')->exists());

        // Un import ne s'applique qu'une fois.
        $this->actingAs($setup['user'])->post(route('customers.import.apply', $import->id))->assertSessionHasErrors('import');
        $this->assertCount(2, $this->customers($setup));
    }

    public function test_reimporting_updates_without_duplicates_and_never_erases_values(): void
    {
        $setup = $this->createTenantWithMember();
        $first = $this->upload($setup, 'customers', "code;nom;telephone;email;ville\nCL-1;Aminata;77 123 45 67;aminata@exemple.sn;Dakar\n;Ibrahima Sow;76 111 22 33;;Rufisque\n");
        $this->actingAs($setup['user'])->post(route('customers.import.apply', $first->id));

        // Rapprochement : par code (CL-1), puis par téléphone (sans code, format différent) ; cellules vides ignorées.
        $second = $this->upload($setup, 'customers', "code;nom;telephone;email;ville\nCL-1;Aminata Diop;;;Saint-Louis\n;Ibrahima Sow;+221 76 111 22 33;ibra@exemple.sn;\n");
        $this->assertSame(['to_create' => 0, 'to_update' => 2], $second->summary);
        $this->actingAs($setup['user'])->post(route('customers.import.apply', $second->id))->assertSessionHasNoErrors();

        $customers = $this->customers($setup);
        $this->assertCount(2, $customers); // aucun doublon
        $aminata = $customers->firstWhere('code', 'CL-1');
        $this->assertSame('Aminata Diop', $aminata->name);
        $this->assertSame('Saint-Louis', $aminata->city);
        $this->assertSame('77 123 45 67', $aminata->phone); // non effacé
        $this->assertSame('aminata@exemple.sn', $aminata->email);
        $ibra = $customers->firstWhere('name', 'Ibrahima Sow');
        $this->assertSame('ibra@exemple.sn', $ibra->email);
        $this->assertSame('Rufisque', $ibra->city); // non effacé

        // Troisième passage identique : plus rien ne change.
        $third = $this->upload($setup, 'customers', "code;nom;telephone;email;ville\nCL-1;Aminata Diop;;;Saint-Louis\n");
        $this->actingAs($setup['user'])->post(route('customers.import.apply', $third->id));
        $this->assertSame(1, $third->refresh()->summary['unchanged']);
    }

    public function test_errors_are_reported_line_by_line_and_block_the_import(): void
    {
        $setup = $this->createTenantWithMember();
        $import = $this->upload($setup, 'customers', "nom;telephone;email;delai_paiement;plafond_credit;code\n"
            ."A;77 123 45 67;;;;\n"                       // ligne 2 : nom trop court
            ."Bon client;appelez-moi;;;;\n"               // ligne 3 : téléphone
            ."Autre;77 000 00 00;pas-un-email;;;\n"       // ligne 4 : e-mail
            ."Délai;;;400;;\n"                            // ligne 5 : délai
            ."Plafond;;;;12,5;\n"                         // ligne 6 : plafond décimal
            ."Code;;;;;!!\n"                              // ligne 7 : code invalide
            ."Valide;;ok@exemple.sn;15;1000;\n"
            ."Valide bis;;ok@exemple.sn;;;\n");           // ligne 9 : même e-mail que la ligne 8

        $this->assertSame(8, $import->rows_total);
        $this->assertSame([2, 3, 4, 5, 6, 7, 9], collect($import->errors)->pluck('line')->all());

        $this->actingAs($setup['user'])->post(route('customers.import.apply', $import->id))->assertSessionHasErrors('import');
        $this->assertCount(0, $this->customers($setup)); // tout ou rien
    }

    public function test_file_level_problems_are_refused(): void
    {
        $setup = $this->createTenantWithMember();

        $this->rejected($setup, 'customers', "telephone;email\n77 123 45 67;a@b.sn\n"); // pas de colonne nom
        $this->rejected($setup, 'customers', "nom\n"); // en-tête seul
    }

    public function test_suppliers_have_their_own_import_without_credit_limit(): void
    {
        $setup = $this->createTenantWithMember();
        $import = $this->upload($setup, 'suppliers', "nom;telephone;plafond_credit\nGrossiste Diop;33 800 00 00;999\n");
        $this->assertSame('suppliers', $import->type);
        $this->actingAs($setup['user'])->post(route('suppliers.import.apply', $import->id))->assertSessionHasNoErrors();

        $supplier = app(TenantContext::class)->runAs($setup['tenant'], fn () => Supplier::query()->firstOrFail());
        $this->assertSame('Grossiste Diop', $supplier->name);
        $this->assertCount(0, $this->customers($setup));

        // Un import de clients n'est pas applicable côté fournisseurs.
        $customersImport = $this->upload($setup, 'customers', "nom\nAminata\n");
        $this->actingAs($setup['user'])->post(route('suppliers.import.apply', $customersImport->id))->assertNotFound();
        $this->actingAs($setup['user'])->get(route('suppliers.import.show', $customersImport->id))->assertNotFound();
    }

    public function test_windows_1252_files_and_comma_separators_are_understood(): void
    {
        $setup = $this->createTenantWithMember();
        $content = mb_convert_encoding("nom,ville\nÉlimane Thiam,Thiès\n", 'Windows-1252', 'UTF-8');
        $import = $this->upload($setup, 'customers', $content);
        $this->actingAs($setup['user'])->post(route('customers.import.apply', $import->id));

        $customer = $this->customers($setup)->sole();
        $this->assertSame('Élimane Thiam', $customer->name);
        $this->assertSame('Thiès', $customer->city);
    }

    public function test_permissions_isolation_and_template(): void
    {
        $setup = $this->createTenantWithMember();
        // Droit de gérer les clients (direction : lecture seule) et les fournisseurs (caissier : aucun).
        $management = $this->member($setup, 'management');
        $this->actingAs($management['user'])->get(route('customers.import.create'))->assertForbidden();
        $this->actingAs($management['user'])->post(route('customers.import.store'), ['file' => UploadedFile::fake()->createWithContent('c.csv', "nom\nAminata\n")])->assertForbidden();
        $cashier = $this->member($setup, 'cashier');
        $this->actingAs($cashier['user'])->get(route('suppliers.import.create'))->assertForbidden();
        $this->actingAs($cashier['user'])->get(route('suppliers.import.template'))->assertForbidden();

        $import = $this->upload($setup, 'customers', "nom\nAminata\n");
        $other = $this->createTenantWithMember();
        $this->actingAs($other['user'])->get(route('customers.import.show', $import->id))->assertNotFound();
        $this->actingAs($other['user'])->post(route('customers.import.apply', $import->id))->assertNotFound();

        foreach (['customers.import.template' => 'modele-clients.csv', 'suppliers.import.template' => 'modele-fournisseurs.csv', 'products.import.template' => 'modele-articles.csv'] as $route => $file) {
            $response = $this->actingAs($setup['user'])->get(route($route));
            $response->assertOk()->assertHeader('content-disposition', 'attachment; filename='.$file);
            $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
        }

        // Le modèle d'articles se réimporte tel quel.
        $template = $this->actingAs($setup['user'])->get(route('products.import.template'))->streamedContent();
        $this->actingAs($setup['user'])->post(route('products.import.store'), ['file' => UploadedFile::fake()->createWithContent('modele.csv', $template)])->assertSessionHasNoErrors();
    }
}
