<?php

namespace App\Domain\Inventory;

use InvalidArgumentException;
use Stringable;

/**
 * Quantité exacte à 3 décimales (bcmath), jamais de flottant.
 * Représentation canonique : chaîne « 12.500 » (format PostgreSQL numeric(15,3)).
 */
final readonly class Quantity implements Stringable
{
    public const int SCALE = 3;

    private const string MAX = '999999999999.999';

    /** @var numeric-string */
    public string $value;

    /**
     * @param  numeric-string  $value
     */
    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Accepte « 12 », « 12,5 », « 12.5 », « 1 250,75 » (saisie française).
     */
    public static function of(string|int $raw): self
    {
        $normalized = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $raw));

        if (preg_match('/^-?\d{1,12}(\.\d{1,3})?$/', $normalized) !== 1 || ! is_numeric($normalized)) {
            throw new InvalidArgumentException(__('inventory.errors.invalid_quantity'));
        }

        return new self(bcadd($normalized, '0', self::SCALE));
    }

    public static function zero(): self
    {
        return new self('0.000');
    }

    public function add(self $other): self
    {
        return self::bounded(bcadd($this->value, $other->value, self::SCALE));
    }

    public function sub(self $other): self
    {
        return self::bounded(bcsub($this->value, $other->value, self::SCALE));
    }

    public function negate(): self
    {
        return new self(bcmul($this->value, '-1', self::SCALE));
    }

    public function compare(self $other): int
    {
        return bccomp($this->value, $other->value, self::SCALE);
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->value, '0', self::SCALE) > 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->value, '0', self::SCALE) < 0;
    }

    public function isWhole(): bool
    {
        return bccomp($this->value, bcadd($this->value, '0', 0), self::SCALE) === 0;
    }

    /**
     * Montant XOF entier = quantité × prix unitaire, arrondi au franc le plus proche
     * (demi vers le haut, en valeur absolue).
     */
    public function times(int $unitAmount): int
    {
        $exact = bcmul($this->value, (string) $unitAmount, self::SCALE);

        return (int) bcadd($exact, bccomp($exact, '0', self::SCALE) >= 0 ? '0.5' : '-0.5', 0);
    }

    /**
     * Affichage français sans flottant : « 1 250,5 », « 12 », « -3,25 ».
     */
    public function format(): string
    {
        $negative = str_starts_with($this->value, '-');
        [$whole, $fraction] = explode('.', ltrim($this->value, '-'));
        $grouped = trim(strrev(chunk_split(strrev($whole), 3, ' ')));
        $fraction = rtrim($fraction, '0');

        return ($negative ? '-' : '').$grouped.($fraction !== '' ? ','.$fraction : '');
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @param  numeric-string  $value
     */
    private static function bounded(string $value): self
    {
        if (bccomp(ltrim($value, '-'), self::MAX, self::SCALE) > 0) {
            throw new InvalidArgumentException(__('inventory.errors.invalid_quantity'));
        }

        return new self($value);
    }
}
