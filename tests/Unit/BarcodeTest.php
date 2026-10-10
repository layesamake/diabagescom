<?php

namespace Tests\Unit;

use App\Domain\Catalog\Barcode;
use InvalidArgumentException;
use Tests\TestCase;

class BarcodeTest extends TestCase
{
    public function test_valid_ean13_is_detected(): void
    {
        $barcode = Barcode::parse('6111234567895');

        $this->assertSame('ean13', $barcode->symbology);
    }

    public function test_scanner_whitespace_is_removed(): void
    {
        $this->assertSame('6111234567895', Barcode::parse(" 6111234567895\r\n")->code);
    }

    public function test_wrong_ean13_check_digit_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Barcode::parse('6111234567893');
    }

    public function test_upca_check_digit_is_verified(): void
    {
        $this->assertSame('upca', Barcode::parse('036000291452')->symbology);

        $this->expectException(InvalidArgumentException::class);
        Barcode::parse('036000291453');
    }

    public function test_alphanumeric_codes_are_code128(): void
    {
        $this->assertSame('code128', Barcode::parse('ABC-123')->symbology);
    }

    public function test_internal_codes_are_valid_ean13_in_the_in_store_range(): void
    {
        $barcode = Barcode::internal(42);

        $this->assertSame('internal', $barcode->symbology);
        $this->assertStringStartsWith('2', $barcode->code);
        $this->assertSame(13, strlen($barcode->code));
        $this->assertTrue(Barcode::validEan13($barcode->code));
    }

    public function test_control_characters_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Barcode::parse("abc\x07def");
    }
}
