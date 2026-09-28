<?php

namespace App\Security;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Keeps each company's data private.
 *
 *  - Super admins can access everything.
 *  - Invoice: any user of the same company.
 *  - Company: company admins of that company (members cannot edit company details).
 *  - User:    company admins of the same company, or the user themself.
 */
class TenantVoter extends Voter
{
    public const ACCESS = 'TENANT_ACCESS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ACCESS
            && ($subject === null || $subject instanceof Invoice || $subject instanceof Company || $subject instanceof User);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Entities being created on a "new" form: the CRUD controllers decide
        // (and force the right company on save).
        if ($subject === null || $subject->getId() === null) {
            return true;
        }

        $myCompany = $user->getCompany();
        if (!$myCompany) {
            return false;
        }

        return match (true) {
            $subject instanceof Invoice => self::same($subject->getCompany(), $myCompany),
            $subject instanceof Company => $user->isCompanyAdmin() && self::same($subject, $myCompany),
            $subject instanceof User => $subject === $user
                || ($user->isCompanyAdmin() && self::same($subject->getCompany(), $myCompany)),
        };
    }

    private static function same(?Company $a, ?Company $b): bool
    {
        return $a !== null && $b !== null && $a->getId() !== null && $a->getId() === $b->getId();
    }
}
