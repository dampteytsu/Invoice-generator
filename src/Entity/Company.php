<?php

namespace App\Entity;

use App\Repository\CompanyRepository;
use App\Validator\BankDetailsChangeAuthorized;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company (tenant) that issues invoices. Each company has its own details,
 * logo, bank details, terms, tax defaults, users and invoices.
 */
#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[BankDetailsChangeAuthorized]
class Company
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ── Company ──────────────────────────────────────────────
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Please enter your company name.')]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    /** Ghana Revenue Authority Taxpayer Identification Number (TIN). */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $tin = null;

    /** Filename inside public/uploads/logo. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    /** Accent colour used on invoices/PDFs (hex, e.g. #0f5c4d). */
    #[ORM\Column(length: 7, options: ['default' => '#0f5c4d'])]
    #[Assert\NotBlank]
    #[Assert\CssColor(formats: Assert\CssColor::HEX_LONG, message: 'Please pick a colour like #0f5c4d.')]
    private string $brandColor = '#0f5c4d';

    // ── Terms ───────────────────────────────────────────────
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $terms = null;

    // ── Bank details ────────────────────────────────────────
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $bankName = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $bankAccountName = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $bankAccountNumber = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $bankBranch = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $swiftCode = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $mobileMoney = null;

    /** Not persisted: password re-entered to authorise a change of payment details. */
    private ?string $confirmPassword = null;

    // ── Invoice defaults ────────────────────────────────────
    #[ORM\Column(length: 20)]
    private string $invoicePrefix = 'INV';

    #[ORM\Column(length: 3)]
    #[Assert\NotBlank]
    #[Assert\Length(exactly: 3)]
    private string $currency = 'GHS';

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $defaultDueDays = 30;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [TaxMode::SIMPLE, TaxMode::COMPOUND])]
    private string $taxMode = TaxMode::SIMPLE;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\Range(min: 0, max: 100)]
    private string $vatRate = '15.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\Range(min: 0, max: 100)]
    private string $nhilRate = '2.50';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\Range(min: 0, max: 100)]
    private string $getfundRate = '2.50';

    /** Abolished from 1 Jan 2026 — kept (default 0) for older invoices. */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\Range(min: 0, max: 100)]
    private string $covidRate = '0.00';

    /** @var Collection<int, User> */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'company')]
    private Collection $users;

    /** @var Collection<int, Invoice> */
    #[ORM\OneToMany(targetEntity: Invoice::class, mappedBy: 'company', cascade: ['remove'])]
    private Collection $invoices;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->invoices = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : 'New company';
    }

    /** @return Collection<int, User> */
    public function getUsers(): Collection { return $this->users; }

    /** @return Collection<int, Invoice> */
    public function getInvoices(): Collection { return $this->invoices; }

    public function getUserCount(): int { return $this->users->count(); }
    public function getInvoiceCount(): int { return $this->invoices->count(); }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(?string $name): static { $this->name = (string) $name; return $this; }

    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): static { $this->address = $address; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): static { $this->phone = $phone; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email; return $this; }

    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $website): static { $this->website = $website; return $this; }

    public function getTin(): ?string { return $this->tin; }
    public function setTin(?string $tin): static { $this->tin = $tin; return $this; }

    public function getLogo(): ?string { return $this->logo; }
    public function setLogo(?string $logo): static { $this->logo = $logo; return $this; }

    public function getBrandColor(): string { return $this->brandColor; }
    public function setBrandColor(?string $v): static
    {
        $v = strtolower(trim((string) $v));
        $this->brandColor = preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : '#0f5c4d';
        return $this;
    }

    /** Very light tint of the brand colour (backgrounds). */
    public function getBrandColorLight(): string
    {
        [$r, $g, $b] = $this->rgb();
        $mix = fn (int $c) => (int) round($c * 0.08 + 255 * 0.92);

        return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
    }

    /** White or near-black, whichever reads better on the brand colour. */
    public function getBrandTextColor(): string
    {
        return $this->luminance() > 0.4 ? '#1f2933' : '#ffffff';
    }

    /** Brand colour for text on a white page — light colours are darkened so they stay readable. */
    public function getBrandColorOnWhite(): string
    {
        if ($this->luminance() <= 0.25) {
            return $this->brandColor;
        }
        [$r, $g, $b] = $this->rgb();
        $dark = fn (int $c) => (int) round($c * 0.55);

        return sprintf('#%02x%02x%02x', $dark($r), $dark($g), $dark($b));
    }

    private function luminance(): float
    {
        [$r, $g, $b] = array_map(function (int $c) {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $this->rgb());

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** @return array{int,int,int} */
    private function rgb(): array
    {
        return array_map('hexdec', str_split(substr($this->brandColor, 1), 2));
    }

    public function getTerms(): ?string { return $this->terms; }
    public function setTerms(?string $terms): static { $this->terms = $terms; return $this; }

    public function getBankName(): ?string { return $this->bankName; }
    public function setBankName(?string $bankName): static { $this->bankName = $bankName; return $this; }

    public function getBankAccountName(): ?string { return $this->bankAccountName; }
    public function setBankAccountName(?string $v): static { $this->bankAccountName = $v; return $this; }

    public function getBankAccountNumber(): ?string { return $this->bankAccountNumber; }
    public function setBankAccountNumber(?string $v): static { $this->bankAccountNumber = $v; return $this; }

    public function getBankBranch(): ?string { return $this->bankBranch; }
    public function setBankBranch(?string $v): static { $this->bankBranch = $v; return $this; }

    public function getSwiftCode(): ?string { return $this->swiftCode; }
    public function setSwiftCode(?string $v): static { $this->swiftCode = $v; return $this; }

    public function getMobileMoney(): ?string { return $this->mobileMoney; }
    public function setMobileMoney(?string $v): static { $this->mobileMoney = $v; return $this; }

    public function getConfirmPassword(): ?string { return $this->confirmPassword; }
    public function setConfirmPassword(?string $v): static { $this->confirmPassword = $v; return $this; }

    public function getMaskedBankAccountNumber(): ?string { return self::maskDigits($this->bankAccountNumber); }
    public function getMaskedMobileMoney(): ?string { return self::maskDigits($this->mobileMoney); }

    /** "0241234567 (Kele Ltd)" → "••••••4567 (Kele Ltd)": hides every digit except the last four. */
    public static function maskDigits(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $total = preg_match_all('/\d/', $value);
        $seen = 0;

        return preg_replace_callback('/\d/', function (array $m) use (&$seen, $total) {
            return ++$seen <= $total - 4 ? '•' : $m[0];
        }, $value);
    }

    public function hasBankDetails(): bool
    {
        return (bool) ($this->bankName || $this->bankAccountName || $this->bankAccountNumber
            || $this->bankBranch || $this->swiftCode || $this->mobileMoney);
    }

    public function getInvoicePrefix(): string { return $this->invoicePrefix; }
    public function setInvoicePrefix(?string $v): static { $this->invoicePrefix = trim((string) $v); return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(?string $v): static { $this->currency = strtoupper(trim((string) $v)); return $this; }

    public function getDefaultDueDays(): int { return $this->defaultDueDays; }
    public function setDefaultDueDays(?int $v): static { $this->defaultDueDays = (int) $v; return $this; }

    public function getTaxMode(): string { return $this->taxMode; }
    public function setTaxMode(?string $v): static { $this->taxMode = $v ?: TaxMode::SIMPLE; return $this; }

    public function getVatRate(): string { return $this->vatRate; }
    public function setVatRate(string|float|null $v): static { $this->vatRate = self::rate($v); return $this; }

    public function getNhilRate(): string { return $this->nhilRate; }
    public function setNhilRate(string|float|null $v): static { $this->nhilRate = self::rate($v); return $this; }

    public function getGetfundRate(): string { return $this->getfundRate; }
    public function setGetfundRate(string|float|null $v): static { $this->getfundRate = self::rate($v); return $this; }

    public function getCovidRate(): string { return $this->covidRate; }
    public function setCovidRate(string|float|null $v): static { $this->covidRate = self::rate($v); return $this; }

    private static function rate(string|float|null $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }
}
