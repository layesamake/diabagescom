<?php

namespace Tests\Unit;

use App\Domain\Documents\TrialWatermark;
use Tests\TestCase;

/**
 * Filigrane de la version d'essai : une seule source de vérité et couverture
 * de tous les documents imprimés ou partagés, hors étiquettes code-barres.
 */
class TrialWatermarkTest extends TestCase
{
    public function test_it_is_disabled_by_default_and_exposes_the_diaba_logo_when_enabled(): void
    {
        $watermark = app(TrialWatermark::class);

        config()->set('diaba.trial_watermark.enabled', false);
        $this->assertFalse($watermark->enabled());
        $this->assertNull($watermark->logoDataUri());

        config()->set('diaba.trial_watermark.enabled', true);
        $this->assertTrue($watermark->enabled());
        $this->assertStringStartsWith('data:image/png;base64,', (string) $watermark->logoDataUri());
    }

    public function test_every_printed_or_shared_document_includes_the_shared_watermark(): void
    {
        $views = [
            'pdf/sales-document.blade.php',
            'pdf/receipt.blade.php',
            'pdf/report.blade.php',
            'pdf/saas-document.blade.php',
            'print/ticket.blade.php',
        ];

        foreach ($views as $view) {
            $contents = (string) file_get_contents(resource_path('views/'.$view));
            $this->assertStringContainsString("@include('partials.trial-watermark')", $contents, $view);
        }

        $labels = (string) file_get_contents(resource_path('views/print/labels.blade.php'));
        $this->assertStringNotContainsString('trial-watermark', $labels);
    }
}
