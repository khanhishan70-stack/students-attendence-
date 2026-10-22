# SMART ATTENDANCE AI

**Dual-Mode Digital Student Attendance Management System**

A second-year Diploma Computer Engineering DBMS project that will combine manual
roll-number attendance and mobile QR-code attendance in one responsive web application.

The project is being built in phases. This repository currently contains **Phase 1 to
Phase 3**: the environment foundation, the responsive project landing page, the
complete relational database layer, and a responsive dashboard application shell.
Attendance screens, QR scanning, authentication and reports do not exist yet.

## Technology Stack

- Frontend: HTML5, CSS3, JavaScript (no frameworks, no external dependencies)
- Backend: PHP 8.2 (PDO / MySQL)
- Database: MariaDB 10.4 (MySQL compatible), InnoDB, utf8mb4
- Local server: Apache via XAMPP
- Database administration: phpMyAdmin
- Version control: Git (optional)

## Prerequisites

- Windows 11
- XAMPP 8.2.12 installed at `C:\xampp`
  - Apache 2.4.58
  - PHP 8.2.12
  - MariaDB 10.4.32
  - phpMyAdmin 5.2.1
- A modern browser (Chrome, Edge, Firefox)
- Git (optional, for version control)

## Project Directory Structure

```text
smart-attendance/
├── index.php              project landing page
├── dashboard.php          main dashboard screen
├── students.php           screen shell (Phase 4)
├── classes.php            screen shell (Phase 4)
├── subjects.php           screen shell (Phase 4)
├── manual-attendance.php  screen shell (Phase 5)
├── qr-attendance.php      screen shell (Phase 6)
├── reports.php            screen shell (Phase 7)
├── settings.php           screen shell (Phase 4)
├── README.md
├── .gitignore
├── .htaccess
├── config/
│   ├── database.example.php
│   ├── database.php          (local only, never committed)
│   ├── db.php
│   ├── helpers.php
│   └── .gitkeep
├── includes/
│   ├── layout.php            shared shell: head, sidebar, top bar, footer
│   └── data.php              dashboard queries (read only)
├── assets/
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   └── app.js
│   └── images/
│       └── .gitkeep
├── database/
│   ├── schema.sql
│   ├── seed.sql
│   └── .gitkeep
└── docs/
    ├── database.md
    └── .gitkeep
```

- `config/database.php` holds the local database credentials and is excluded by
  `.gitignore`. `config/database.example.php` is the tracked template.
- `config/db.php` is the PDO connection layer used by every page.
- `database/schema.sql` creates the structure, `database/seed.sql` loads demo data.
- `docs/database.md` documents the schema, the integrity rules and useful queries.

## Starting Apache and MySQL

1. Open `C:\xampp\xampp-control.exe`.
2. Click **Start** next to **Apache**. Wait for the module to turn green.
3. Click **Start** next to **MySQL**. The database is required from Phase 2 onwards.
4. To stop the servers, click **Stop** for each running module.

Only one MySQL/MariaDB instance can use port 3306. A separate standalone
MySQL Server 8.4 installation exists on this computer but is not registered as a
service. Do not start it and do not start XAMPP MySQL at the same time.

If a Start button in the Control Panel does not respond, the batch scripts work
as a fallback:

- `C:\xampp\apache_start.bat` — starts Apache
- `C:\xampp\mysql_start.bat` — starts MySQL (MariaDB)

Closing those command windows stops the corresponding server.

## Opening the Application

With Apache running, open:

```text
http://localhost/smart-attendance/          project landing page
http://localhost/smart-attendance/dashboard.php   dashboard application
```

The landing page shows a live database status line and the row count of every
table. The dashboard shows the same information as a proper application shell.
If the status reads **Unavailable**, the database is not running or the schema
has not been imported yet.

## Application Screens

| Screen | URL | Status |
| --- | --- | --- |
| Project overview | `/smart-attendance/` | Working |
| Dashboard | `/smart-attendance/dashboard.php` | Working, read-only data |
| Students | `/smart-attendance/students.php` | Placeholder for Phase 4 |
| Classes | `/smart-attendance/classes.php` | Placeholder for Phase 4 |
| Subjects | `/smart-attendance/subjects.php` | Placeholder for Phase 4 |
| Manual Attendance | `/smart-attendance/manual-attendance.php` | Placeholder for Phase 5 |
| QR Attendance | `/smart-attendance/qr-attendance.php` | Placeholder for Phase 6 |
| Reports | `/smart-attendance/reports.php` | Placeholder for Phase 7 |
| Settings | `/smart-attendance/settings.php` | Placeholder for Phase 4 |

## Database Setup

The schema has already been imported into `smart_attendance` on this machine. To
re-import it on a fresh machine:

```bash
cd C:\xampp\htdocs\smart-attendance
C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root < database\seed.sql
```

Both scripts are idempotent — importing them again never drops a table or
duplicates a row. The same files can be imported through phpMyAdmin
(**Import** tab, select `smart_attendance`).

If `config/database.php` is missing, copy `config/database.example.php` to
`config/database.php` and adjust the values.

## Current Implementation Status

### Implemented in Phase 1

- Project directory structure.
- PHP-powered landing page at `index.php`.
- Responsive dark navy interface with blue and green accents.
- Separate CSS (`assets/css/style.css`) and JavaScript (`assets/js/app.js`) files.
- Informational cards for the two planned attendance modes.
- Phase status tracking on the page.
- Safe output escaping for dynamic values.

### Implemented in Phase 2

- `smart_attendance` database with seven InnoDB tables and utf8mb4 encoding.
- Foreign keys across classes, students, subjects, sessions, users and attendance.
- Unique constraints for email, class identity, subject code, enrolment and
  attendance per student per session.
- Four triggers that enforce class enrolment and roll number uniqueness.
- Composite foreign key guaranteeing a session uses a subject of its own class.
- Reporting view `v_attendance_records` plus report oriented indexes.
- PDO connection layer in `config/db.php` with prepared statements and strict
  SQL mode.
- Idempotent seed data (2 users, 2 classes, 6 students, 6 enrolments,
  3 subjects, 2 sessions, 8 attendance records).
- Landing page now shows live connection state and table row counts.

### Implemented in Phase 3

- Responsive application shell: fixed sidebar on desktop, off-canvas drawer with
  backdrop on phones and tablets.
- Shared layout in `includes/layout.php` reused by every screen.
- Dashboard with four live statistic cards: total students, present today,
  absent today and average attendance.
- Recent attendance sessions panel with present, absent and pending counts.
- CSS based attendance overview chart built from real daily aggregates.
- Low attendance panel for students below 75%.
- Quick actions panel linking to the six management screens.
- Status panel showing connection state, session count and marked records.
- Accessible navigation: skip link, `aria-current` on the active item, drawer
  toggle with `aria-expanded`, focus handling, Escape to close and focus trap.
- Mobile friendly tables that turn into labelled record cards on small screens.
- Placeholder screens for Students, Classes, Subjects, Manual Attendance,
  QR Attendance, Reports and Settings — each states clearly that it is not built.

### Not Implemented

- Login screens and session handling.
- Creating, editing or deleting records of any kind.
- Manual attendance marking.
- QR code generation or scanning.
- OCR or other AI features.
- Attendance report screens and exports.

The dashboard only reads verified records. Nothing on it writes to the database.

## Development Phases

1. **Phase 1 — Environment setup** (done): project foundation, landing page,
   local server verification.
2. **Phase 2 — Database design** (done): MySQL schema, relationships, triggers,
   seed data, connection configuration and live status on the landing page.
3. **Phase 3 — Dashboard UI** (done): responsive application shell, navigation,
   live statistics, session list, attendance chart, low attendance list and
   screen placeholders.
4. **Phase 4 — Accounts and academic records**: sign-in, roles, students,
   classes and subjects.
5. **Phase 5 — Manual attendance**: roll-number-based marking and session records.
6. **Phase 6 — QR attendance**: session QR codes and mobile check-in.
7. **Phase 7 — Reports and insights**: attendance reports, summaries, and
   AI-assisted analysis.

Phase 4 has not started.

## Configuration and Secrets

- Never commit database passwords, API keys, or private credentials.
- Keep local credentials in `config/database.php`, which is excluded by `.gitignore`.
- Passwords in the database are stored as bcrypt hashes only.
- The `.gitignore` also excludes environment files, logs, temporary files, editor
  folders, and local database dumps.

## Troubleshooting

- **Apache will not start:** another program may be using port 80 or 443. Check
  with `netstat -ano | findstr :80` and stop the conflicting program. Do not
  change firewall rules or system settings to work around this.
- **Page not found:** confirm the folder is exactly
  `C:\xampp\htdocs\smart-attendance` and that Apache is running.
- **PHP source code appears in the browser:** make sure the page is opened through
  `http://localhost/...`, not by double-clicking `index.php`.
- **MySQL will not start:** another MySQL instance may already use port 3306.
  Stop one instance before starting the other.
- **Database shows as unavailable:** confirm MySQL is running in the XAMPP Control
  Panel, that `config/database.php` exists, and that `database/schema.sql` has
  been imported.
- **Styles or script missing:** confirm `assets/css/style.css` and
  `assets/js/app.js` exist and keep their relative paths unchanged.

## Notes

- Phase 1 creates the foundation, Phase 2 adds the data layer and Phase 3 adds
  the responsive dashboard interface.
- No external paid services or API keys are used.
- The database contains demo records only, used for testing the schema.
- The dashboard is read-only in this phase: it reports what is really stored.
