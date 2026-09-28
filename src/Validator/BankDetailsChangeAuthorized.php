<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Changing an existing company's bank / Mobile Money details requires the
 * signed-in user to re-enter their password.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class BankDetailsChangeAuthorized extends Constraint
{
    public string $missingMessage = 'Enter your password to confirm the change to your payment details.';
    public string $invalidMessage = 'That password is not correct. Your payment details were not changed.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
