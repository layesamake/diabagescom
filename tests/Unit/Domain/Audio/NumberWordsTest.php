<?php

namespace Tests\Unit\Domain\Audio;

use App\Domain\Audio\NumberWords;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie la règle française citée dans le cahier des charges :
 * 45 750 → quarante + cinq + mille + sept + cent + cinquante (+ francs).
 */
class NumberWordsTest extends TestCase
{
    public function test_zero(): void
    {
        $this->assertSame(['CHF-01'], NumberWords::nombre(0));
    }

    public function test_simple_units_and_teens(): void
    {
        $this->assertSame(['CHF-02'], NumberWords::nombre(1));
        $this->assertSame(['CHF-17'], NumberWords::nombre(16));
        $this->assertSame(['CHF-11', 'CHF-08'], NumberWords::nombre(17)); // dix-sept
    }

    public function test_tens_with_et(): void
    {
        $this->assertSame(['CHF-18'], NumberWords::nombre(20)); // vingt
        $this->assertSame(['CHF-18', 'CHF-24', 'CHF-02'], NumberWords::nombre(21)); // vingt et un
        $this->assertSame(['CHF-20', 'CHF-06'], NumberWords::nombre(45)); // quarante cinq
    }

    public function test_seventy_to_ninety_nine(): void
    {
        $this->assertSame(['CHF-22', 'CHF-11'], NumberWords::nombre(70)); // soixante dix
        $this->assertSame(['CHF-22', 'CHF-24', 'CHF-12'], NumberWords::nombre(71)); // soixante et onze
        $this->assertSame(['CHF-23'], NumberWords::nombre(80)); // quatre-vingt
        $this->assertSame(['CHF-23', 'CHF-02'], NumberWords::nombre(81)); // quatre-vingt un (jamais « et »)
        $this->assertSame(['CHF-23', 'CHF-11'], NumberWords::nombre(90)); // quatre-vingt dix
        $this->assertSame(['CHF-23', 'CHF-12'], NumberWords::nombre(91)); // quatre-vingt onze
        $this->assertSame(['CHF-23', 'CHF-11', 'CHF-10'], NumberWords::nombre(99)); // quatre-vingt dix neuf
    }

    public function test_hundreds(): void
    {
        $this->assertSame(['CHF-25'], NumberWords::nombre(100)); // cent (jamais « un cent »)
        $this->assertSame(['CHF-25', 'CHF-02'], NumberWords::nombre(101));
        $this->assertSame(['CHF-03', 'CHF-25'], NumberWords::nombre(200)); // deux cent
    }

    public function test_thousands(): void
    {
        $this->assertSame(['CHF-26'], NumberWords::nombre(1000)); // mille (jamais « un mille »)
        $this->assertSame(['CHF-03', 'CHF-26'], NumberWords::nombre(2000)); // deux mille
    }

    public function test_cahier_des_charges_example(): void
    {
        // 45 750 → quarante, cinq, mille, sept, cent, cinquante.
        $this->assertSame(
            ['CHF-20', 'CHF-06', 'CHF-26', 'CHF-08', 'CHF-25', 'CHF-21'],
            NumberWords::nombre(45750),
        );
    }

    public function test_million(): void
    {
        $this->assertSame(['CHF-02', 'CHF-27'], NumberWords::nombre(1_000_000)); // un million
    }

    public function test_montant_adds_francs(): void
    {
        $this->assertSame(['CHF-26', 'CHF-28'], NumberWords::montant(1000)); // mille francs
    }

    public function test_heure_with_and_without_minutes(): void
    {
        $this->assertSame(['CHF-15', 'CHF-29'], NumberWords::heure(14, 0)); // quatorze heures
        $this->assertSame(['CHF-15', 'CHF-29', 'CHF-06', 'CHF-30'], NumberWords::heure(14, 5)); // quatorze heures cinq minutes
    }
}
