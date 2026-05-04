# UnityFund

UnityFund is a fundraising platform built with PHP, SQL Server, MongoDB, Stripe Connect, and Gmail-based email delivery.

## Current architecture

- **MS SQL Server**
  - Users
  - Campaigns
  - Donations
  - Transactions
  - Receipts
- **MongoDB**
  - User profiles
  - Campaign descriptions and images
  - Threaded campaign comments
  - Organizer applications
  - In-app notifications
  - Email OTP challenges
  - Password reset tokens
- **Stripe Connect**
  - Platform collects donor payments
  - Platform fee: **5%**
  - Connected organizer accounts receive the rest
- **Gmail SMTP via PHPMailer**
  - Password reset email
  - Donation OTP
  - Campaign close OTP
  - Admin decision / change-request emails
  - Organizer application notice to admins
  - Welcome email on registration

## Major flows

### 1. Registration

- Registration requires a **Gmail address**
- New users are created as `donor`
- A welcome email is sent after successful registration

### 2. Organizer application

- Donor submits organizer application in `apply_organizer.php`
- Application is stored in MongoDB
- User role becomes `pending_organizer`
- Every admin email stored in the `Users` table receives a notice
- Admin approves or rejects in the dashboard
- Approval sends:
  - inbox notification
  - email notice
  - Stripe-required reminder

### 3. Stripe organizer onboarding

- After approval, organizer must connect Stripe before creating campaigns
- In sandbox mode, the app can fast-track connected account creation for demo use
- In live mode, standard Stripe onboarding still applies

### 4. Donation flow

- Donor selects campaign and amount
- Donor must verify a **Gmail OTP**
- App creates a Stripe PaymentIntent
- If organizer has a connected Stripe account:
  - donor pays the **platform**
  - platform keeps **5% application fee**
  - organizer connected account receives the campaign payout

### 5. Password recovery

- `forgot_password.php` sends a reset link to the registered Gmail address
- `reset_password.php` validates a MongoDB reset token and updates the SQL password hash

### 6. Organizer campaign close

- Organizer can close their own **active** campaign
- Closing requires a Gmail OTP
- Admin can still close or reactivate campaigns directly

## Email features

Implemented with **PHPMailer** using Gmail SMTP.

Current email use cases:

- welcome email on account creation
- forgot password / reset password
- donor OTP before payment
- organizer OTP before closing active campaign
- admin change request to organizer
- organizer application approval / rejection
- new organizer application notice to admins

## Local configuration

### 1. SQL Server

Create `db.php` from your local credentials.

This file is ignored by git.

### 2. MongoDB

Create or edit:

- `includes/mongo.php`

Expected local database:

- database name: `unityfund`

### 3. Stripe

Local Stripe configuration is loaded from:

- `includes/stripe.php`
- optional local override: `includes/stripe.local.php`

### 4. Gmail SMTP

Tracked example:

- `includes/mail.example.php`

Local secret file:

- `includes/mail.local.php`

This file is ignored by git.

Example structure:

```php
<?php
define('MAIL_SMTP_HOST', 'smtp.gmail.com');
define('MAIL_SMTP_PORT', 587);
define('MAIL_SMTP_SECURE', 'tls');
define('MAIL_SMTP_USERNAME', 'your-gmail@gmail.com');
define('MAIL_SMTP_PASSWORD', 'your-app-password');
define('MAIL_FROM_EMAIL', 'your-gmail@gmail.com');
define('MAIL_FROM_NAME', 'UnityFund');
define('MAIL_REPLY_TO', 'your-gmail@gmail.com');
define('MAIL_BASE_URL', 'http://localhost/unityfund');
```

## PHPMailer

Composer is not required in this repo right now.

PHPMailer source files are stored locally under:

- `lib/PHPMailer/src/`

## Important pages and endpoints

### Pages

- `register.php`
- `login.php`
- `forgot_password.php`
- `reset_password.php`
- `donate.php`
- `apply_organizer.php`
- `my_campaigns.php`
- `admin.php`

### APIs

- `api/create_payment_intent.php`
- `api/confirm_payment.php`
- `api/send_email_otp.php`
- `api/verify_email_otp.php`
- `api/update_campaign.php`
- `api/update_user_role.php`
- `api/request_change.php`
- `api/stripe_connect.php`

## Setup checklist

1. Configure SQL Server in `db.php`
2. Configure MongoDB in `includes/mongo.php`
3. Configure Stripe test keys locally
4. Configure Gmail SMTP in `includes/mail.local.php`
5. Make sure PHP can reach:
   - MongoDB
   - Stripe API
   - Gmail SMTP
6. Open the app under:
   - `http://localhost/unityfund`

## Security notes

- Do **not** commit:
  - `db.php`
  - `includes/mongo.php`
  - `includes/stripe.php`
  - `includes/stripe.local.php`
  - `includes/mail.local.php`
- Gmail SMTP should use an **App Password**, not a normal account password
- Password reset links and OTP codes are stored server-side and expire automatically by time checks in application logic

## Demo notes

- Stripe sandbox flow is configured around **destination charges**
- Organizer payout routing depends on Stripe connected account status
- Donation email OTP is required before card payment
- Organizer close email OTP is required before closing an active campaign
