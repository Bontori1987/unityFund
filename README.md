# UnityFund

A charity crowdfunding web application built for an Advanced Database Systems course project. UnityFund connects donors with fundraising campaigns, featuring real-time donation tracking, automated tax receipts via SQL triggers, and leaderboards powered by SQL window functions.

---

## Team & Responsibilities

| Member | Responsibility |
|--------|---------------|
| **Thinh** | Donation UI, top donors, receipts, SQL trigger, window functions|
| **Nhi** | Campaign content management, image gallery, campaign detail page |
| *(planned)* | MongoDB — threaded comments, user profiles, campaign metadata |

---

## Database Architecture

The project uses three databases, each suited to its data type:

| Database | Role | Status |
|----------|------|--------|
| **MS SQL Server** | Transactional data — users, donations, receipts, campaigns | ✅ Implemented |
| **MongoDB** | Document store — threaded comments, user profiles, campaign details (read-heavy, rarely updated) | 🔜 Planned |
| **MySQL** | *(removed — campaign data migrated to MS SQL)* | — |

> MongoDB is planned but not yet integrated. The `Campaigns` table in MS SQL currently stores title, description, category, goal, and status as a temporary measure until the MongoDB layer is built.

---

## Features

- **Kickstarter-style landing page** — campaign cards with category filters and search
- **Role-based access control** — Guest, Donor, Pending Organizer, Organizer, Admin
- **Donation flow** — campaign selection, preset amounts, anonymous giving
- **Auto tax receipts** — SQL `AFTER INSERT` trigger generates receipts for donations > $50
- **Running totals** — `SUM() OVER (PARTITION BY CampID ORDER BY Time)` window function
- **Top donors leaderboard** — `RANK() OVER (ORDER BY TotalDonated DESC)` window function
- **Admin dashboard** — approve organizer applications, manage campaign status
- **Campaign detail page** — full stats sidebar with MS SQL donation data

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | Bootstrap 5.3, Bootstrap Icons, Inter font |
| Backend | PHP 8+ |
| Primary DB | Microsoft SQL Server (T-SQL) via PDO `sqlsrv` driver |
| Document DB | MongoDB *(planned — comments, profiles, campaign details)* |

---

## Project Structure

```
unityfund/
├── api/                        # JSON endpoints (AJAX)
│   ├── campaign_donations.php
│   ├── campaign_progress.php
│   ├── process_donation.php
│   ├── update_campaign.php
│   └── update_user_role.php
├── assets/
│   ├── css/app.css             # Brand design system (Bootstrap overrides)
│   ├── js/app.js               # Shared JS utilities
│   ├── logo.jpg
│   └── uploads/                # User-uploaded images (gitignored)
├── includes/
│   ├── auth.php                # Session helpers & role functions
│   ├── header.php              # Unified navbar (Bootstrap 5)
│   └── footer.php              # Footer + Bootstrap JS
├── partner/                    # Teammate's campaign content pages
│   ├── campaign/campaign-detail.php
│   └── home/index.php
├── index.php                   # Landing page (category browse)
├── donate.php                  # Donation form
├── top_donors.php              # Leaderboard
├── receipts.php                # Tax receipts & donation history
├── running_total.php           # Window function visualisation
├── my_campaigns.php            # Organizer & admin dashboard
├── login.php
├── register.php
├── logout.php
├── schema.sql                  # Full MS SQL schema + trigger + views + migrations
├── db.example.php              # Credential template (copy → db.php)
└── .gitignore
```

---

## Setup

### 1. Database (MS SQL Server)

1. Create a database named `UnityFindDB` in SQL Server Management Studio.
2. Run `schema.sql` — creates tables, trigger, views, and sample data.
3. The migration blocks at the bottom of `schema.sql` safely add `Category` and `Description` columns to existing databases without data loss.

### 2. Database credentials

```bash
cp db.example.php db.php
```

Edit `db.php` and fill in your SQL Server name, username, and password.

### 3. Activate test accounts

Visit `/setup_test_accounts.php` once to write real bcrypt hashes for the sample users.

| Email | Password | Role |
|-------|----------|------|
| alice@example.com | donor123 | Donor |
| bob@example.com | donor456 | Donor |
| carol@example.com | donor789 | Donor |
| host@example.com | org123 | Organizer |
| admin@unityfund.com | admin123 | Admin |

> `setup_test_accounts.php` is gitignored — never committed.

---

## Key SQL Features

### Trigger — auto tax receipt
```sql
CREATE TRIGGER trg_GenerateTaxReceipt
ON Donations AFTER INSERT AS
BEGIN
    INSERT INTO Receipts (DonID, IssuedAt, TaxAmount)
    SELECT i.ID, GETDATE(), ROUND(i.Amt * 0.10, 2)
    FROM INSERTED i WHERE i.Amt > 50;
END;
```

### Window function — running total
```sql
SUM(d.Amt) OVER (
    PARTITION BY d.CampID
    ORDER BY d.Time
    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
) AS RunningTotal
```

### Window function — donor rank
```sql
RANK() OVER (ORDER BY TotalDonated DESC) AS OverallRank
```

---

## Role Permissions

| Action | Guest | Donor | Pending Org | Organizer | Admin |
|--------|:-----:|:-----:|:-----------:|:---------:|:-----:|
| Browse campaigns | ✓ | ✓ | ✓ | ✓ | ✓ |
| Donate | — | ✓ | ✓ | — | — |
| View receipts | — | ✓ | ✓ | — | ✓ |
| Create campaign | — | — | — | ✓ | ✓ |
| Approve campaign | — | — | — | — | ✓ |
| Approve organizer | — | — | — | — | ✓ |
