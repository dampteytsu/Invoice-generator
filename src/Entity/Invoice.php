<?php

namespace App\Entity;

use App\Repository\InvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_company_invoice_number', columns: ['company_id', 'invoice_number'])]
#[UniqueEntity(fields: ['company', 'invoiceNumber'], errorPath: 'invoiceNumber', message: 'This invoice number is already in use.')]
class Invoice
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        'Draft' => self::STATUS_DRAFT,
        'Sent' => self::STATUS_SENT,
        'Paid' => self::STATUS_PAID,
        'Cancelled' => self::STATUS_CANCELLED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The company that issued the invoice (tenant). */
    #[ORM\ManyToOne(inversedBy: 'invoices')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Choose the company issuing this invoice.')]
    private ?Company $company = null;

    // ── Invoice details ─────────────────────────────────────
    /** Left blank → generated on save (e.g. INV-2026-0001). */
    #[ORM\Column(length: 50)]
    private string $invoiceNumber = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $invoiceDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(propertyPath: 'invoiceDate', message: 'The due date cannot be before the invoice date.')]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    // ── Bill to ─────────────────────────────────────────────
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Who is this invoice billed to?')]
    private string $billToName = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $billToAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $billToContact = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $billToTin = null;

    // ── Items ───────────────────────────────────────────────
    /** @var Collection<int, InvoiceItem> */
    #[ORM\OneToMany(targetEntity: InvoiceItem::class, mappedBy: 'invoice', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'Add at least one line item.')]
    private Collection $items;

    // ── Tax configuration (snapshot from settings at creation) ──
    #[ORM\Column]
    private bool $taxable = true;

    #[ORM\Column(length: 20)]
    private string $taxMode = TaxMode::SIMPLE;

    #[ORM\Column(length: 3)]
    private string $currency = 'GHS';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\Range(min: 0, max: 100)]
    private string $vatRate = '15.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\Range(min: 0, max: 100)]
    private string $nhilRate = '2.50';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\Range(min: 0, max: 100)]
    private string $getfundRate = '2.50';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\Range(min: 0, max: 100)]
    private string $covidRate = '0.00';

    // ── Computed totals (stored so lists & reports are fast) ──
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $subtotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $nhilAmount = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $getfundAmount = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $covidAmount = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $vatAmount = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->invoiceNumber;
    }

    /** Copy currency, tax mode and rates from the company's defaults. */
    public function applySettings(Company $settings): static
    {
        $this->currency = $settings->getCurrency();
        $this->taxMode = $settings->getTaxMode();
        $this->vatRate = $settings->getVatRate();
        $this->nhilRate = $settings->getNhilRate();
        $this->getfundRate = $settings->getGetfundRate();
        $this->covidRate = $settings->getCovidRate();

        return $this;
    }

    /**
     * Ghana invoice maths. All work is done in pesewas (integers) to avoid
     * floating-point drift.
     *
     *   Subtotal     = Σ (qty × unit price)
     *   NHIL         = Subtotal × NHIL%
     *   GETFund      = Subtotal × GETFund%
     *   COVID-19     = Subtotal × COVID%          (0% from 2026)
     *   VAT base     = Subtotal                   (simple / 2026+)
     *                = Subtotal + all levies      (compound / pre-2026)
     *   VAT          = VAT base × VAT%
     *   Total        = Subtotal + levies + VAT
     */
    #[ORM\PrePersist]
    public function recalculate(): void
    {
        $sub = 0;
        foreach ($this->items as $item) {
            $item->recalculate();
            $sub += $item->getAmountMinor();
        }

        $nhil = $getfund = $covid = $vat = 0;

        if ($this->taxable) {
            $nhil = self::pct($sub, $this->nhilRate);
            $getfund = self::pct($sub, $this->getfundRate);
            $covid = self::pct($sub, $this->covidRate);
            $vatBase = $this->taxMode === TaxMode::COMPOUND ? $sub + $nhil + $getfund + $covid : $sub;
            $vat = self::pct($vatBase, $this->vatRate);
        }

        $this->subtotal = self::money($sub);
        $this->nhilAmount = self::money($nhil);
        $this->getfundAmount = self::money($getfund);
        $this->covidAmount = self::money($covid);
        $this->vatAmount = self::money($vat);
        $this->total = self::money($sub + $nhil + $getfund + $covid + $vat);
    }

    private static function pct(int $minor, string $rate): int
    {
        return (int) round($minor * (float) $rate / 100);
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    /** Subtotal + levies — the base VAT is charged on in the pre-2026 method. */
    public function getLeviesTotal(): string
    {
        return number_format((float) $this->nhilAmount + (float) $this->getfundAmount + (float) $this->covidAmount, 2, '.', '');
    }

    public function getValueBeforeVat(): string
    {
        return number_format((float) $this->subtotal + (float) $this->getLeviesTotal(), 2, '.', '');
    }

    public function isOverdue(): bool
    {
        return $this->dueDate !== null
            && !in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED], true)
            && $this->dueDate < new \DateTimeImmutable('today');
    }

    // ── Getters / setters ───────────────────────────────────
    public function getId(): ?int { return $this->id; }

    public function getCompany(): ?Company { return $this->company; }
    public function setCompany(?Company $company): static { $this->company = $company; return $this; }

    public function getInvoiceNumber(): string { return $this->invoiceNumber; }
    public function setInvoiceNumber(?string $v): static { $this->invoiceNumber = trim((string) $v); return $this; }

    public function getInvoiceDate(): ?\DateTimeImmutable { return $this->invoiceDate; }
    public function setInvoiceDate(?\DateTimeImmutable $v): static { $this->invoiceDate = $v; return $this; }

    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
    public function setDueDate(?\DateTimeImmutable $v): static { $this->dueDate = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(?string $v): static { $this->status = $v ?: self::STATUS_DRAFT; return $this; }

    public function getStatusLabel(): string
    {
        return array_search($this->status, self::STATUSES, true) ?: ucfirst($this->status);
    }

    public function getBillToName(): string { return $this->billToName; }
    public function setBillToName(?string $v): static { $this->billToName = (string) $v; return $this; }

    public function getBillToAddress(): ?string { return $this->billToAddress; }
    public function setBillToAddress(?string $v): static { $this->billToAddress = $v; return $this; }

    public function getBillToContact(): ?string { return $this->billToContact; }
    public function setBillToContact(?string $v): static { $this->billToContact = $v; return $this; }

    public function getBillToTin(): ?string { return $this->billToTin; }
    public function setBillToTin(?string $v): static { $this->billToTin = $v; return $this; }

    /** @return Collection<int, InvoiceItem> */
    public function getItems(): Collection { return $this->items; }

    public function addItem(InvoiceItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setInvoice($this);
        }
        return $this;
    }

    public function removeItem(InvoiceItem $item): static
    {
        if ($this->items->removeElement($item) && $item->getInvoice() === $this) {
            $item->setInvoice(null);
        }
        return $this;
    }

    public function isTaxable(): bool { return $this->taxable; }
    public function setTaxable(?bool $v): static { $this->taxable = (bool) $v; return $this; }

    public function getTaxMode(): string { return $this->taxMode; }
    public function setTaxMode(?string $v): static { $this->taxMode = $v ?: TaxMode::SIMPLE; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(?string $v): static { $this->currency = strtoupper(trim((string) $v)) ?: 'GHS'; return $this; }

    public function getVatRate(): string { return $this->vatRate; }
    public function setVatRate(string|float|null $v): static { $this->vatRate = number_format((float) $v, 2, '.', ''); return $this; }

    public function getNhilRate(): string { return $this->nhilRate; }
    public function setNhilRate(string|float|null $v): static { $this->nhilRate = number_format((float) $v, 2, '.', ''); return $this; }

    public function getGetfundRate(): string { return $this->getfundRate; }
    public function setGetfundRate(string|float|null $v): static { $this->getfundRate = number_format((float) $v, 2, '.', ''); return $this; }

    public function getCovidRate(): string { return $this->covidRate; }
    public function setCovidRate(string|float|null $v): static { $this->covidRate = number_format((float) $v, 2, '.', ''); return $this; }

    public function getSubtotal(): string { return $this->subtotal; }
    public function getNhilAmount(): string { return $this->nhilAmount; }
    public function getGetfundAmount(): string { return $this->getfundAmount; }
    public function getCovidAmount(): string { return $this->covidAmount; }
    public function getVatAmount(): string { return $this->vatAmount; }
    public function getTotal(): string { return $this->total; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): static { $this->notes = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
