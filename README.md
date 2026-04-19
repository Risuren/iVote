# iVOTE CS — Setup Guide

## 1. Database Setup
1. Open phpMyAdmin or your MySQL client
2. Run `ivote_cs.sql` — this creates the database, all tables, and the default admin account

## 2. Ignoreable Files
Here are the files unrelated to local hosting kaya di ko na nilagay sa file structure hahahaha ito yung chinat ko sayo
1. Procfile
2. composer.json
3. nixpacks.toml
4. php.ini
5. router.php
   
## 3. Configure Database Connection
Edit `/includes/db.php` and set your credentials:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'ivote_cs');
```
## 4. First Login (Admin)
```
Student ID : ADM-9901
Password   : Passwordnije
```

## File Structure
```
ivote/
├── schema.sql (now ivote_cs.sql)                  ← Run once in MySQL
├── index.php                   ← Guest homepage
├── login.php                   ← Login + Register
├── logout.php                  ← Session destroyer
├── pending.php                 ← Awaiting approval screen
├── about.php                   ← About the team
├── .htaccess                   ← Security + routing
│
├── includes/
│   ├── db.php                  ← Database connection
│   ├── auth.php                ← Session guards + helpers
│   ├── navbar.php              ← Shared navbar (all roles)
│   └── admin_sidebar.php       ← Admin sidebar
│   └── footer.php              ← Footer (hindi pa shared sa iba haha hindi ko na shinare)
│   └── rate_limiter.php       ←  Rate limiter
│
├── admin/
│   ├── dashboard.php           ← Admin overview
│   ├── verification.php        ← Approve / reject students
│   ├── accounts.php            ← View / delete voter accounts
│   ├── elections.php           ← Create / manage elections
│   └── candidates.php          ← Register candidates per position
│
├── student/
│   ├── dashboard.php           ← Student home + live results
│   ├── vote.php                ← Ballot casting page
│   └── account.php             ← Profile + document upload
│   │__ summary.php             ← Ballot finalizing page
│
├── assets/
│   ├── css/shared.css          ← Global styles (navbar, sidebar, etc.)
│   ├── js/shared.js            ← Dropdown + modal JS
│   └── img/logo.png            ← Place your logo here
│
└── uploads/
    ├── .htaccess               ← Blocks PHP execution in uploads
    └── documents/              ← Student COR/ID uploads (server-writable)
```

