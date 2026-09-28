# Ghana Invoice (Symfony 7.4 + EasyAdmin 4)

A multi-company invoicing app. Each company has its own users, details and invoices.
It creates Ghana-style tax invoices with **NHIL**, **GETFund levy**, **VAT** (and the old COVID-19 levy, which you can switch on for pre-2026 invoices). You can print them or download them as PDFs.

## Requirements

- PHP 8.2+ with `intl`, `gd`, `mbstring`, `pdo_sqlite` (or `pdo_mysql`)
- Composer
- The Symfony CLI (optional, used for the local web server)

## Setup

```bash
cd invoice
composer install

php bin/console doctrine:database:create --if-not-exists
php bin/console make:migration
php bin/console doctrine:migrations:migrate -n

php bin/console app:create-user you@example.com "Your Name" --super-admin

symfony serve -d                      # or: php -S 127.0.0.1:8000 -t public
```

Open http://127.0.0.1:8000 and sign in.

## Users, roles and companies

| Role                  | Can do                                                                                                                               |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| **Super admin** (you) | See and manage **every** company, user and invoice. Create companies and users, and assign users to companies.                       |
| **Company admin**     | Edit **their own** company's details (logo, bank details, terms, tax defaults). Add, edit or deactivate their team. Create invoices. |
| **Member**            | Create and edit their company's invoices. Print them and download PDFs.                                                              |
|                       |

- **Each company sees only its own invoices.** This is enforced in three places: the list query, an access check on every record (`src/Security/TenantVoter.php`), and on save, where the company is always set from the signed-in user. The print and PDF links return 404 for other companies' invoices.
- **Onboarding:** add a user without a company, either in _Administration → Users_ or with `app:create-user`. When they first sign in, they're asked to set up their company, and they become its company admin.
- Company admins add teammates in _My company → Team_. Those users join the same company automatically.
- Invoice numbers run separately for each company (`INV-2026-0001`, …), so two companies can both have `INV-2026-0001`.
- Deactivated users can't sign in. You can't delete, demote or deactivate your own account.

Handy command options: `--company-admin`, `--company=<id>`, `--password=...`

## Using it

1. **My company → Company settings** (or the set-up screen on first sign-in): enter the company name, TIN, address, phone, email, logo, bank details, Mobile Money number, terms, and invoice defaults (number prefix, currency, days until payment is due, tax rates and method).
2. **Billing → New invoice**: the invoice number (`INV-2026-0001` and so on), invoice date, due date and tax rates fill in automatically. Enter who the invoice is billed to, then add lines (**Qty | Description | Unit price | Amount**). Each line's amount and the running totals (NHIL, GETFund, VAT, Total) update as you type.
3. Use **View / Print** or **PDF** from the invoice list or the edit page.

## How the tax is calculated

| Method                | When                                     | Formula (on subtotal S)                        | Effective                                |
| --------------------- | ---------------------------------------- | ---------------------------------------------- | ---------------------------------------- |
| **Current** (default) | From 1 Jan 2026 (VAT Act 2025, Act 1151) | NHIL 2.5%·S + GETFund 2.5%·S + VAT 15%·S       | **20%**                                  |
| **Pre-2026**          | Older invoices                           | Levies on S first, then VAT 15% × (S + levies) | 21.9% with 1% COVID levy, 20.75% without |

Each invoice saves its own copy of the rates and the method. If you change them in Settings later, invoices you already issued stay the same. You can also turn off "Charge VAT & levies" for customers or suppliers who aren't VAT-registered.

All maths is done in pesewas (integers), so totals never drift by a pesewa. The server recalculates everything on save; the live totals on the form are only a preview.

## Where things are

- `src/Entity/Invoice.php`: `recalculate()` holds the tax logic
- `src/Controller/Admin/*`: the EasyAdmin dashboard and the Invoice, Company and User CRUDs
- `src/Security/TenantVoter.php`: the rules for who can see what
- `src/Command/CreateUserCommand.php`: `app:create-user`
- `templates/invoice/document.html.twig`: the invoice layout, shared by the print view and the PDF
- `public/js/invoice-form.js`: live line amounts and totals on the form

## Security

You have to sign in to see any page other than `/login`. Passwords are hashed with Symfony's `auto` hasher, and the login form is CSRF-protected. Serve the app over HTTPS in production.
