<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['email'], message: 'A user with this email already exists.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** Platform owner: manages every company and user. */
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';
    /** Manages their own company: settings, team members, invoices. */
    public const ROLE_COMPANY_ADMIN = 'ROLE_COMPANY_ADMIN';
    /** Creates and manages invoices for their company. */
    public const ROLE_USER = 'ROLE_USER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email = '';

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private string $fullName = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    /** Not persisted — set from forms / the console command, hashed before saving. */
    #[Assert\Length(min: 8, minMessage: 'Use at least {{ limit }} characters.')]
    private ?string $plainPassword = null;

    #[ORM\ManyToOne(inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->fullName !== '' ? $this->fullName.' <'.$this->email.'>' : $this->email;
    }

    public function getId(): ?int { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = mb_strtolower(trim((string) $email)); return $this; }

    public function getUserIdentifier(): string
    {
        return $this->email !== '' ? $this->email : throw new \LogicException('User has no email.');
    }

    public function getFullName(): string { return $this->fullName; }
    public function setFullName(?string $v): static { $this->fullName = trim((string) $v); return $this; }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = self::ROLE_USER;

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(?array $roles): static
    {
        $this->roles = array_values(array_unique(array_filter((array) $roles, fn ($r) => $r !== self::ROLE_USER)));
        return $this;
    }

    public function addRole(string $role): static
    {
        return $this->setRoles([...$this->roles, $role]);
    }

    public function isSuperAdmin(): bool { return in_array(self::ROLE_SUPER_ADMIN, $this->roles, true); }
    public function isCompanyAdmin(): bool { return in_array(self::ROLE_COMPANY_ADMIN, $this->roles, true); }

    /** Single-choice role used by the Users admin screen. */
    public function getRole(): string
    {
        return match (true) {
            $this->isSuperAdmin() => self::ROLE_SUPER_ADMIN,
            $this->isCompanyAdmin() => self::ROLE_COMPANY_ADMIN,
            default => self::ROLE_USER,
        };
    }

    public function setRole(?string $role): static
    {
        return $this->setRoles($role && $role !== self::ROLE_USER ? [$role] : []);
    }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $hash): static { $this->password = $hash; return $this; }

    public function getPlainPassword(): ?string { return $this->plainPassword; }
    public function setPlainPassword(?string $v): static { $this->plainPassword = $v !== '' ? $v : null; return $this; }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    public function getCompany(): ?Company { return $this->company; }
    public function setCompany(?Company $company): static { $this->company = $company; return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(?bool $v): static { $this->active = (bool) $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

}
