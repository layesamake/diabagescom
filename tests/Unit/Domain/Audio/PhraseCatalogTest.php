<?php

namespace Tests\Unit\Domain\Audio;

use App\Domain\Audio\PhraseCatalog;
use PHPUnit\Framework\TestCase;

class PhraseCatalogTest extends TestCase
{
    public function test_counts_match_the_cahier_des_charges(): void
    {
        $this->assertCount(153, PhraseCatalog::all());
        $this->assertCount(123, array_filter(PhraseCatalog::all(), fn (array $p): bool => $p['group'] !== 'Chiffres'));
        $this->assertCount(30, PhraseCatalog::codesInGroup('Chiffres'));
    }

    public function test_core_groups_total_71_recordings(): void
    {
        $this->assertCount(71, PhraseCatalog::coreCodes());
    }

    public function test_a_phrase_without_variable_has_one_segment(): void
    {
        $this->assertSame(1, PhraseCatalog::segmentCount('COM-01'));
        $this->assertSame([0], PhraseCatalog::requiredSegments('COM-01'));
    }

    public function test_a_phrase_with_one_variable_has_two_segments(): void
    {
        $this->assertSame(2, PhraseCatalog::segmentCount('VEN-05'));
        $this->assertSame([0, 1], PhraseCatalog::requiredSegments('VEN-05'));
        $this->assertSame(['montant'], PhraseCatalog::tokens('VEN-05'));
    }

    public function test_two_consecutive_variables_skip_the_blank_middle_segment(): void
    {
        // « Il reste {nombre} {article}. » : rien à dire entre les deux variables.
        $this->assertSame(3, PhraseCatalog::segmentCount('STK-04'));
        $this->assertSame([0, 2], PhraseCatalog::requiredSegments('STK-04'));
    }

    public function test_two_variables_with_text_between_keep_the_middle_segment(): void
    {
        // « {client} vous doit {montant}. » : « vous doit » doit être dit.
        $this->assertSame(3, PhraseCatalog::segmentCount('CLI-02'));
        $this->assertSame([0, 1, 2], PhraseCatalog::requiredSegments('CLI-02'));
    }

    public function test_unknown_code_does_not_exist(): void
    {
        $this->assertFalse(PhraseCatalog::exists('XXX-99'));
        $this->assertTrue(PhraseCatalog::exists('VEN-05'));
    }
}
