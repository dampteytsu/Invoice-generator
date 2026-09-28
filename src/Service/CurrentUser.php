<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/** Small helper around the logged-in user and their company. */
class CurrentUser
{
    public function __construct(private readonly Security $security)
    {
    }

    public function user(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    public function company(): ?Company
    {
        return $this->user()?->getCompany();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function isCompanyAdmin(): bool
    {
        return (bool) $this->user()?->isCompanyAdmin();
    }

    /** Company users must have a company before they can do anything else. */
    public function needsOnboarding(): bool
    {
        return $this->user() !== null && !$this->isSuperAdmin() && $this->company() === null;
    }
}
