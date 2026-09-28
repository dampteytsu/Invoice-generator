<?php

namespace App\Validator;

use App\Entity\Company;
use App\Service\BankDetailsAudit;
use App\Service\CurrentUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class BankDetailsChangeAuthorizedValidator extends ConstraintValidator
{
    public function __construct(
        private readonly BankDetailsAudit $audit,
        private readonly CurrentUser $current,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof BankDetailsChangeAuthorized) {
            throw new UnexpectedTypeException($constraint, BankDetailsChangeAuthorized::class);
        }
        // New companies (first set-up) and unchanged details need no confirmation.
        if (!$value instanceof Company || $value->getId() === null || !$this->audit->detectChanges($value)) {
            return;
        }

        $user = $this->current->user();
        $password = (string) $value->getConfirmPassword();

        if ($password === '') {
            $this->context->buildViolation($constraint->missingMessage)->atPath('confirmPassword')->addViolation();
        } elseif (!$user || !$this->hasher->isPasswordValid($user, $password)) {
            $this->context->buildViolation($constraint->invalidMessage)->atPath('confirmPassword')->addViolation();
        }
    }
}
