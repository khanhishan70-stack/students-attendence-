# SMART ATTENDANCE AI

**Dual-Mode Digital Student Attendance Management System**

A second-year Diploma Computer Engineering DBMS project that will combine manual
roll-number attendance and mobile QR-code attendance in one responsive web application.

The project is being built in phases. This repository currently contains **Phase 1 only**:
the environment foundation and a responsive project landing page. No attendance
functionality, QR scanning, authentication, or database integration exists yet.

## Technology Stack

- Frontend: HTML5, CSS3, JavaScript
- Backend: PHP 8.2
- Database: MySQL / MariaDB (Phase 2)
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
├── index.php
├── README.md
├── .gitignore
├── config/
│   └── .gitkeep
├── assets/
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   └── app.js
│   └── images/
│       └── .gitkeep
├── database/
│   └── .gitkeep
└── docs/
    └── .gitkeep
```

- `config/` is reserved for local configuration such as database credentials in Phase 2.
- `database/` is reserved for SQL schema and seed files in Phase 2.
- `docs/` is reserved for project documentation.

## Starting Apache and MySQL

1. Open `C:\xampp\xampp-control.exe`.
2. Click **Start** next to **Apache**. Wait for the module to turn green.
3. Click **Start** next to **MySQL** when database work begins in Phase 2.
   Phase 1 does not require MySQL.
4. To stop the servers, click **Stop** for each running module.

Only one MySQL/MariaDB instance can use port 3306. A separate standalone
MySQL Server 8.4 installation exists on this computer but is not registered as a
service. Do not start both MySQL instances at the same time.

If a Start button in the Control Panel does not respond, the batch scripts work
as a fallback:

- `C:\xampp\apache_start.bat` — starts Apache
- `C:\xampp\mysql_start.bat` — starts MySQL (MariaDB)

Closing those command windows stops the corresponding server.

## Opening the Application

With Apache running, open:

```text
http://localhost/smart-attendance/
```

If Apache is not running, the URL will not load. Start Apache from the XAMPP
Control Panel first.

## Current Implementation Status

### Implemented in Phase 1

- Project directory structure.
- PHP-powered landing page at `index.php`.
- Responsive dark navy interface with blue and green accents.
- Separate CSS (`assets/css/style.css`) and JavaScript (`assets/js/app.js`) files.
- Informational cards for the two planned attendance modes.
- Phase status tracking on the page.
- Safe output escaping for dynamic values.

### Not Implemented

- Database schema and database connection.
- Login and user roles.
- Student and class management.
- Manual attendance.
- QR code generation or scanning.
- OCR or other AI features.
- Attendance reports and analytics.
- Any storage or retrieval of attendance data.

The landing page does not claim that these features work.

## Development Phases

1. **Phase 1 — Environment setup** (current): project foundation, landing page,
   local server verification.
2. **Phase 2 — Database design**: MySQL schema, relationships, and connection
   configuration.
3. **Phase 3 — Authentication and students**: accounts, roles, student records.
4. **Phase 4 — Manual attendance**: roll-number-based marking and session records.
5. **Phase 5 — QR attendance**: session QR codes and mobile check-in.
6. **Phase 6 — Reports and insights**: attendance reports, summaries, and
   AI-assisted analysis.

Phase 2 has not started. Database integration begins there.

## Configuration and Secrets

- Never commit database passwords, API keys, or private credentials.
- Keep local credentials in `config/` files that are excluded by `.gitignore`.
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
- **Styles or script missing:** confirm `assets/css/style.css` and
  `assets/js/app.js` exist and keep their relative paths unchanged.

## Notes

- Phase 1 creates the foundation only.
- The database schema is intentionally absent.
- No external paid services or API keys are used.
