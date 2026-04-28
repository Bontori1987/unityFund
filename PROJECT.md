# UnityFund — Project Summary
> Charity crowdfunding web platform built with PHP, MS SQL Server, and MongoDB.
> Written for collaborators to understand the full system at a glance.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Language | PHP 8.x (no framework) |
| Web Server | Apache via XAMPP |
| Primary DB | MS SQL Server (`UnityFindDB`) via PDO `sqlsrv` |
| Secondary DB | MongoDB (`unityfund`) via raw `MongoDB\Driver\*` (no Composer) |
| Frontend | Bootstrap 5.3 + Bootstrap Icons |
| Auth | PHP sessions |

---

## Project Root
```
c:\xampp\htdocs\unityfund\
```

---

## MS SQL Database — `UnityFindDB`

### Tables

#### `Users`
```sql
UserID      INT PK IDENTITY
Username    NVARCHAR(100)
Email       NVARCHAR(255) UNIQUE
Password    NVARCHAR(255)   -- bcrypt hash
Role        NVARCHAR(20)    -- 'donor' | 'pending_organizer' | 'organizer' | 'admin'
IsAnonymous BIT DEFAULT 0   -- global anonymous toggle
CreatedAt   DATETIME
```

#### `Campaigns`
```sql
CampID      INT PK IDENTITY
Title       NVARCHAR(255)
GoalAmt     DECIMAL(10,2)
HostID      INT FK → Users.UserID
Status      NVARCHAR(20)    -- 'pending' | 'active' | 'closed'
Category    NVARCHAR(50)    -- Technology | Arts | Community | Education | Environment | Health | Food | Other
Description NVARCHAR(MAX)
CreatedAt   DATETIME
```

#### `Donations`
```sql
ID          INT PK IDENTITY
CampID      INT FK → Campaigns.CampID
DonorID     INT FK → Users.UserID
Amt         DECIMAL(10,2) CHECK (Amt > 0)
Time        DATETIME DEFAULT GETDATE()
Message     NVARCHAR(500)
IsAnonymous BIT DEFAULT 0   -- per-donation anonymous flag
```

#### `Receipts`
```sql
ID          INT PK IDENTITY
DonID       INT FK → Donations.ID UNIQUE  -- one receipt per donation max
IssuedAt    DATETIME
TaxAmount   DECIMAL(10,2)   -- 10% of donation amount
```

### Trigger
**`trg_GenerateTaxReceipt`** — fires AFTER INSERT on Donations.
Auto-creates a receipt row in `Receipts` for any donation where `Amt > 50`.
TaxAmount = `Amt * 0.10`.

### Views (Window Functions)

**`vw_DonationRunningTotal`**
- `SUM(d.Amt) OVER (PARTITION BY d.CampID ORDER BY d.Time ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)` → `RunningTotal`
- `RANK() OVER (PARTITION BY d.CampID ORDER BY d.Amt DESC)` → `RankInCampaign`
- Respects anonymity: DonorID/DonorName are NULL/'Anonymous' when `d.IsAnonymous=1 OR u.IsAnonymous=1`

**`vw_TopDonors`**
- Aggregates total donated per user across all campaigns
- `RANK() OVER (ORDER BY SUM(d.Amt) DESC)` → `DonorRank`
- Excludes rows where `d.IsAnonymous=1 OR u.IsAnonymous=1`

### Table Relationships
```
Users (root)
  ├──< Campaigns     via HostID      (one organizer → many campaigns)
  └──< Donations     via DonorID     (one donor → many donations)
          ├──► Campaigns via CampID  (many donations → one campaign)
          └──< Receipts via DonID    (one donation → one receipt max)
```

---

## MongoDB Database — `unityfund`

Connection: `mongodb://localhost:27017`
Driver: raw `MongoDB\Driver\*` classes — no Composer needed.
Helper file: `includes/mongo.php` (gitignored — copy from `includes/mongo.example.php`)

### Collections

#### `user_profiles`
```json
{
  "user_id":    1,          // matches Users.UserID in MS SQL
  "bio":        "...",
  "location":   "...",
  "website":    "https://...",
  "avatar_url": "assets/uploads/avatars/avatar_1_1234567890.jpg",
  "joined_at":  ISODate,
  "updated_at": ISODate
}
```

#### `notifications`
```json
{
  "to_user_id":   5,        // recipient UserID
  "from_user_id": 1,        // sender UserID
  "type":         "change_request",
  "camp_id":      3,
  "camp_title":   "Clean Water Initiative",
  "change_type":  "name",   // 'name' | 'goal'
  "message":      "Please rename your campaign...",
  "read":         false,
  "created_at":   ISODate
}
```

#### `campaign_details`
Reserved for future use — planned migration of campaign descriptions from MS SQL.

---

## Key PHP Files

### Entry Pages
| File | Access | Purpose |
|------|--------|---------|
| `index.php` | Public | Browse active campaigns |
| `login.php` | Public | Session login |
| `register.php` | Public | New user registration; seeds MongoDB profile |
| `donate.php` | Donor+ | Donation form |
| `my_campaigns.php` | Organizer/Admin | Campaign management dashboard |
| `top_donors.php` | Public | Top 100 donor leaderboard |
| `profile.php` | Public* | User profile (`?id=X`); blocked if anonymous |
| `inbox.php` | Logged in | Notification inbox for all users |
| `partner/campaign/campaign-detail.php` | Public | Campaign detail + funding over time |

*Anonymous profiles return 403 even if URL is known.

### API Endpoints (`api/`)
| File | Method | Auth | Purpose |
|------|--------|------|---------|
| `update_campaign.php` | POST | Organizer/Admin | Create or edit campaign |
| `update_user_role.php` | POST | Admin | Approve/reject organizer applications |
| `campaign_donations.php` | GET | Organizer/Admin | Donation list per campaign |
| `update_profile.php` | POST | Self | Update bio, location, website, anonymous toggle |
| `upload_avatar.php` | POST | Self | Upload avatar image (max 2MB, MIME validated) |
| `request_change.php` | POST | Admin | Send change-request notification to organizer |
| `search_users.php` | GET | Admin | Search users by email |
| `mark_notifications_read.php` | POST | Self | Mark all or single notification as read |
| `process_donation.php` | POST | Donor+ | Insert donation record |
| `campaign_progress.php` | GET | Public | Campaign raised/goal stats |

### Includes
| File | Purpose |
|------|---------|
| `includes/auth.php` | Session helpers: `isLoggedIn()`, `isAdmin()`, `isOrganizer()`, `canDonate()`, `requireRole()`, `currentUser()` |
| `includes/mongo.php` | MongoDB helpers: `getProfile()`, `saveProfile()`, `seedProfile()`, `getNotifications()`, `countUnreadNotifications()`, `markNotificationsRead()`, `markOneNotificationRead()`, `sendChangeRequest()` |
| `includes/header.php` | Global nav; loads avatar + unread count from MongoDB on every request |
| `includes/footer.php` | Closes layout |
| `db.php` | MS SQL PDO connection (`$conn`) |

---

## Role System

| Role | Can Do |
|------|--------|
| `donor` | Browse, donate, view profiles, inbox |
| `pending_organizer` | Same as donor while awaiting approval |
| `organizer` | Above + create/edit own campaigns, view own donation history |
| `admin` | Everything + approve campaigns, approve organizers, view all donors (including anonymous), search users by email, send change requests |

Roles are stored in `Users.Role` and `$_SESSION['role']`.
Auth checks are in `includes/auth.php` and enforced at the top of every protected page/API.

---

## Privacy / Anonymous System

Two levels of anonymity checked together everywhere:
- `Donations.IsAnonymous` — per-donation flag set by donor at time of giving
- `Users.IsAnonymous` — global toggle in profile settings

**Rule:** if either is `1`, the donor is shown as Anonymous and DonorID is NULL.
Applied in: `vw_DonationRunningTotal`, `vw_TopDonors`, `api/campaign_donations.php`.
Admins bypass this — they see real names regardless.
Anonymous profiles return HTTP 403 when visited via `profile.php?id=X`.

---

## Donation Flow (Current)

```
donate.php → POST → api/process_donation.php
                          │
                    INSERT INTO Donations
                          │
                    trg_GenerateTaxReceipt fires
                          │ (if Amt > 50)
                    INSERT INTO Receipts
```

## Planned Enhancement — Payment Gateway

Add a `Transactions` table as a middle layer:
```
donate.php → POST card details → Stripe Sandbox API
                                        │
                              Stripe returns success/fail
                                        │
                         INSERT Transactions (status = success/failed)
                                        │
                              if success only:
                         INSERT Donations → trigger → Receipts
```
Stripe Connect is the target architecture:
- Platform account = UnityFund
- Connected accounts = Organizers (receive payouts)
- Customer objects = Donors (stored cards)

---

## Avatar Upload
- Stored at `assets/uploads/avatars/avatar_{userID}_{timestamp}.ext`
- MIME type validated server-side via `mime_content_type()` (not extension)
- Max 2MB
- Old avatar files deleted on new upload
- URL saved to MongoDB `user_profiles.avatar_url`

---

## Notification / Inbox System
- Stored in MongoDB `notifications` collection
- Currently sent by: admin → organizer via "Request Change" in admin dashboard
- All logged-in users have an inbox (`inbox.php`)
- Header shows unread count badge via `countUnreadNotifications()`
- Mark single or all as read via `api/mark_notifications_read.php`

---

## What Is NOT Yet Done
- Payment gateway integration (Stripe sandbox — planned)
- `Transactions` table (pending gateway implementation)
- Migration of campaign descriptions from MS SQL → MongoDB `campaign_details`
- `running_total.php` still exists on disk (gitignored, safe to delete)
