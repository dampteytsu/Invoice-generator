<?php

namespace App\Entity;

use App\Repository\BankDetailsChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit record of a change to a company's payment details.
 * Account and Mobile Money numbers are stored masked (last 4 digits only),
 * so the log itself never becomes a second copy of the sensitive data.
 */
#[ORM\Entity(repositoryClass: BankDetailsChangeRepository::class)]
#[ORM\Index(name: 'idx_bank_change_company_date', columns: ['company_id', 'changed_at'])]
class BankDetailsChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    /** Kept as text (not a relation) so the record survives if the user is deleted. */
    #[ORM\Column(length: 255)]
    private string $changedBy;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    /** @var array<string, array{label: string, old: ?string, new: ?string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $changes;

    public function __construct(Company $company, string $changedBy, ?string $ipAddress, array $changes)
    {
        $this->company = $company;
        $this->changedBy = $changedBy;
        $this->ipAddress = $ipAddress;
        $this->changes = $changes;
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCompany(): Company { return $this->company; }
    public function getChangedBy(): string { return $this->changedBy; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }

    /** @return array<string, array{label: string, old: ?string, new: ?string}> */
    public function getChanges(): array { return $this->changes; }

    /** One line per field: "Account number: ••••0123 → ••••4567". */
    public function getSummary(): string
    {
        return implode("\n", array_map(
            fn (array $c) => sprintf('%s: %s → %s', $c['label'], $c['old'] ?? '(empty)', $c['new'] ?? '(empty)'),
            $this->changes,
        ));
    }
}
