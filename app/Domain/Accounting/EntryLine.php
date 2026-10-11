<?php

namespace App\Domain\Accounting;

/**
 * Ligne d'écriture à passer : un compte (par son code), un montant au débit OU
 * au crédit en XOF entiers, un libellé et un tiers éventuel (auxiliaire).
 */
final readonly class EntryLine
{
    public function __construct(
        public string $accountCode,
        public int $debit,
        public int $credit,
        public string $label,
        public ?string $partyType = null,
        public ?string $partyId = null,
    ) {}

    public static function debit(string $accountCode, int $amount, string $label, ?string $partyType = null, ?string $partyId = null): self
    {
        return new self($accountCode, $amount, 0, $label, $partyType, $partyId);
    }

    public static function credit(string $accountCode, int $amount, string $label, ?string $partyType = null, ?string $partyId = null): self
    {
        return new self($accountCode, 0, $amount, $label, $partyType, $partyId);
    }

    public function reversed(): self
    {
        return new self($this->accountCode, $this->credit, $this->debit, $this->label, $this->partyType, $this->partyId);
    }
}
