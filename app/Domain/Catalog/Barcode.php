<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

/**
 * Code-barres normalisé et contrôlé.
 *
 * Formats acceptés (docs/02) : EAN-13, UPC-A, Code 128 et QR code. Les codes
 * numériques de 12 ou 13 chiffres doivent avoir une clé de contrôle valide :
 * une erreur de saisie ou de lecture ne doit pas créer un faux code.
 */
final readonly class Barcode
{
    public const string EAN13 = 'ean13';

    public const string UPCA = 'upca';

    public const string CODE128 = 'code128';

    public const string QR = 'qr';

    public const string INTERNAL = 'internal';

    private function __construct(public string $code, public string $symbology) {}

    /**
     * @throws InvalidArgumentException si le code est vide, trop long ou si la clé est fausse.
     */
    public static function parse(string $raw, ?string $symbology = null): self
    {
        // Les lecteurs USB/Bluetooth ajoutent parfois des espaces ou un retour chariot.
        $code = trim(preg_replace('/\s+/u', '', $raw) ?? '');

        if ($code === '' || mb_strlen($code) > 64 || preg_match('/^[!-~]+$/', $code) !== 1) {
            throw new InvalidArgumentException(__('catalog.barcode.invalid_format'));
        }

        $symbology ??= match (true) {
            preg_match('/^\d{13}$/', $code) === 1 => self::EAN13,
            preg_match('/^\d{12}$/', $code) === 1 => self::UPCA,
            default => self::CODE128,
        };

        if (in_array($symbology, [self::EAN13, self::INTERNAL], true) && ! self::validEan13($code)) {
            throw new InvalidArgumentException(__('catalog.barcode.invalid_checksum'));
        }

        if ($symbology === self::UPCA && ! self::validEan13('0'.$code)) {
            throw new InvalidArgumentException(__('catalog.barcode.invalid_checksum'));
        }

        return new self($code, $symbology);
    }

    /**
     * Code interne EAN-13 à préfixe « 2 » (plage réservée à l'usage en magasin).
     */
    public static function internal(int $sequence): self
    {
        if ($sequence < 1 || $sequence > 99_999_999_999) {
            throw new InvalidArgumentException('Internal barcode sequence out of range.');
        }

        $body = '2'.str_pad((string) $sequence, 11, '0', STR_PAD_LEFT);

        return new self($body.self::ean13CheckDigit($body), self::INTERNAL);
    }

    public static function validEan13(string $code): bool
    {
        return preg_match('/^\d{13}$/', $code) === 1
            && (int) $code[12] === self::ean13CheckDigit(substr($code, 0, 12));
    }

    private static function ean13CheckDigit(string $twelveDigits): int
    {
        $sum = 0;

        foreach (str_split($twelveDigits) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }
}
