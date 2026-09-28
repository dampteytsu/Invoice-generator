<?php

namespace App\Entity;

use App\Repository\InvoiceItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InvoiceItemRepository::class)]
class InvoiceItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Invoice $invoice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\Positive(message: 'Quantity must be greater than zero.')]
    private string $quantity = '1.00';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Please describe the item or service.')]
    private string $description = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\PositiveOrZero]
    private string $unitPrice = '0.00';

    /** quantity × unit price, kept in sync by recalculate(). */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount = '0.00';

    public function __toString(): string
    {
        return $this->description;
    }

    public function getId(): ?int { return $this->id; }

    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function setInvoice(?Invoice $invoice): static { $this->invoice = $invoice; return $this; }

    public function getQuantity(): string { return $this->quantity; }
    public function setQuantity(string|float|int|null $quantity): static
    {
        $this->quantity = number_format((float) $quantity, 2, '.', '');
        $this->recalculate();
        return $this;
    }

    public function getDescription(): string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = (string) $description; return $this; }

    public function getUnitPrice(): string { return $this->unitPrice; }
    public function setUnitPrice(string|float|int|null $unitPrice): static
    {
        $this->unitPrice = number_format((float) $unitPrice, 2, '.', '');
        $this->recalculate();
        return $this;
    }

    public function getAmount(): string { return $this->amount; }

    /** Amount in pesewas (minor units) — used for exact totals. */
    public function getAmountMinor(): int
    {
        return (int) round((float) $this->amount * 100);
    }

    public function recalculate(): void
    {
        // Integer maths: qty in hundredths × price in pesewas, then round half-up.
        $qty = (int) round((float) $this->quantity * 100);
        $price = (int) round((float) $this->unitPrice * 100);
        $minor = (int) round($qty * $price / 100);
        $this->amount = number_format($minor / 100, 2, '.', '');
    }
}
