<?php

namespace App\Service;

use App\Entity\BankDetailsChange;
use App\Entity\Company;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Detects, records and announces changes to a company's payment details.
 */
class BankDetailsAudit
{
    /** Fields that decide where customers send money. */
    public const FIELDS = [
        'bankName' => 'Bank',
        'bankAccountName' => 'Account name',
        'bankAccountNumber' => 'Account number',
        'bankBranch' => 'Branch',
        'swiftCode' => 'SWIFT / Sort code',
        'mobileMoney' => 'Mobile Money',
    ];

    /** Fields whose values are masked in the log and in emails. */
    private const MASKED = ['bankAccountNumber', 'mobileMoney'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $current,
        private readonly RequestStack $requests,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly string $mailFrom,
    ) {
    }

    /**
     * Compares the submitted values with what is in the database (Doctrine's
     * original data). Must be called before flush().
     *
     * @return array<string, array{label: string, old: ?string, new: ?string}> masked old/new values
     */
    public function detectChanges(Company $company): array
    {
        $original = $company->getId() === null ? [] : $this->em->getUnitOfWork()->getOriginalEntityData($company);

        $changes = [];
        foreach (self::FIELDS as $field => $label) {
            $old = self::normalise($original[$field] ?? null);
            $new = self::normalise($company->{'get'.ucfirst($field)}());
            if ($old !== $new) {
                $masked = in_array($field, self::MASKED, true);
                $changes[$field] = [
                    'label' => $label,
                    'old' => $masked ? Company::maskDigits($old) : $old,
                    'new' => $masked ? Company::maskDigits($new) : $new,
                ];
            }
        }

        return $changes;
    }

    /** Persists an audit entry (flushed together with the company). */
    public function record(Company $company, array $changes): ?BankDetailsChange
    {
        if (!$changes) {
            return null;
        }
        $user = $this->current->user();
        $entry = new BankDetailsChange(
            $company,
            $user ? sprintf('%s <%s>', $user->getFullName(), $user->getEmail()) : 'system',
            $this->requests->getMainRequest()?->getClientIp(),
            $changes,
        );
        $this->em->persist($entry);

        return $entry;
    }

    /** Emails every active admin of the company. Never blocks the save if mail fails. */
    public function notify(BankDetailsChange $entry): void
    {
        $company = $entry->getCompany();
        $recipients = [];
        foreach ($company->getUsers() as $member) {
            if ($member->isActive() && $member->isCompanyAdmin()) {
                $recipients[] = new Address($member->getEmail(), $member->getFullName());
            }
        }
        if (!$recipients) {
            return;
        }

        $email = (new Email())
            ->from($this->mailFrom)
            ->to(...$recipients)
            ->subject(sprintf('[%s] Payment details on your invoices were changed', $company->getName()))
            ->text($this->twig->render('email/bank_details_changed.txt.twig', ['entry' => $entry, 'company' => $company]));

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Could not send bank-details change notification: '.$e->getMessage(), ['company' => $company->getId()]);
        }
    }

    private static function normalise(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
