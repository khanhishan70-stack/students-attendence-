# SMART ATTENDANCE AI — Phase 2 Database Design

Relational design for the Dual-Mode Digital Student Attendance Management System.

- Database: `smart_attendance`
- Server target: MariaDB 10.4 (bundled with XAMPP 8.2.12)
- Storage engine: InnoDB (required for foreign keys and transactions)
- Charset / collation: `utf8mb4` / `utf8mb4_unicode_ci`
- Scripts: [`database/schema.sql`](../database/schema.sql), [`database/seed.sql`](../database/seed.sql)

## Entity relationship diagram

```text
                        ┌──────────────┐
                        │    users     │  admin / teacher
                        └──────┬───────┘
                               │ created_by / marked_by
                               ▼
┌──────────────┐        ┌────────────────────┐        ┌──────────────┐
│   classes    │──1:N──▶│ attendance_sessions│◀──1:N──│   subjects   │
│ dept/year/div│        │ mode: manual|qr    │        │ class_id+code│
└──────┬───────┘        │ status: sched|open │        └──────▲───────┘
       │                │        |closed       │               │
       │ 1:N            └─────────┬──────────┘               │ class_id
       ▼                            │ 1:N                     │
┌──────────────┐                   ▼                         │
│  enrollments │◀────────────┌──────────────┐                │
│ student+class│             │  attendance  │────────────────┘
│  +year       │             │ present/     │
└──────┬───────┘             │ absent/      │
       │ student_id          │ not_marked   │
       ▼                     └──────┬───────┘
┌──────────────┐                    │
│   students   │────────────────────┘
│ roll_number  │
└──────────────┘
```

`enrollments` is a junction table, so one student can belong to several classes in
different academic years. `subjects` carries `class_id`, and `attendance_sessions`
references subjects with a composite foreign key `(class_id, subject_id)`, which is
what guarantees that a session can never point at a subject from another class.

## Tables

### users

| Column | Type | Notes |
| --- | --- | --- |
| id | INT UNSIGNED | Primary key, auto increment |
| full_name | VARCHAR(120) | Required |
| email | VARCHAR(190) | **Unique** |
| password_hash | VARCHAR(255) | bcrypt hash only, never plain text |
| role | ENUM('admin','teacher') | Default `teacher` |
| is_active | TINYINT(1) | Default 1 |
| created_at / updated_at | TIMESTAMP | Auto set and auto updated |

### classes

| Column | Type | Notes |
| --- | --- | --- |
| id | INT UNSIGNED | Primary key |
| department | VARCHAR(100) | Part of the unique identity |
| academic_year | VARCHAR(20) | Part of the unique identity, e.g. `2025-26` |
| division | VARCHAR(20) | Part of the unique identity |
| created_at | TIMESTAMP | Auto set |

Unique key on `(department, academic_year, division)` stops duplicate class definitions.

### students

| Column | Type | Notes |
| --- | --- | --- |
| id | INT UNSIGNED | Primary key |
| roll_number | VARCHAR(30) | **Text**, so `01`, `CS01` and `2A` all work |
| full_name | VARCHAR(120) | Required |
| email | VARCHAR(190) | Optional |
| is_active | TINYINT(1) | Default 1 |
| created_at / updated_at | TIMESTAMP | Auto set and auto updated |

Roll numbers are not globally unique: the same value may exist in different classes
and years. Uniqueness is enforced per class and academic year.

### enrollments

| Column | Type | Notes |
| --- | --- | --- |
| id | INT UNSIGNED | Primary key |
| student_id | INT UNSIGNED | FK → `students.id`, cascade delete |
| class_id | INT UNSIGNED | FK → `classes.id`, cascade delete |
| enrollment_year | VARCHAR(20) | Academic year of the enrolment |
| created_at | TIMESTAMP | Auto set |

Unique key on `(student_id, class_id, enrollment_year)` prevents duplicate enrolment
of the same student in the same class for the same year, while still allowing a
student to join a different class in a different year.

### subjects

| Column | Type | Notes |
| --- | --- | --- |
| id | INT UNSIGNED | Primary key |
| class_id | INT UNSIGNED | FK → `classes.id`, cascade delete |
| subject_code | VARCHAR(30) | Unique inside the class |
| subject_name | VARCHAR(120) | Required |
| is_active | TINYINT(1) | Default 1 |
| created_at | TIMESTAMP | Auto set |

Extra unique key on `(class_id, id)` exists purely to support the composite foreign
key used by `attendance_sessions`.

### attendance_sessions

| Column | Type | Notes |
| --- | --- | --- |
| id | INT UNSIGNED | Primary key |
| class_id | INT UNSIGNED | FK → `classes.id` |
| subject_id | INT UNSIGNED | FK → `subjects.id` and composite FK → `subjects(class_id, id)` |
| created_by | INT UNSIGNED | FK → `users.id`, set null on delete |
| session_date | DATE | Lecture date |
| session_label | VARCHAR(100) | e.g. `Lecture 1` |
| attendance_mode | ENUM('manual','qr') | Default `manual` |
| status | ENUM('scheduled','open','closed') | Default `scheduled` |
| started_at / closed_at | DATETIME | Nullable session window |
| created_at | TIMESTAMP | Auto set |

There is deliberately **no** unique key on `(subject_id, session_date)` because the same
subject can have several lectures on one day.

### attendance

| Column | Type | Notes |
| --- | --- | --- |
| id | INT UNSIGNED | Primary key |
| session_id | INT UNSIGNED | FK → `attendance_sessions.id`, cascade delete |
| student_id | INT UNSIGNED | FK → `students.id`, cascade delete |
| status | ENUM('present','absent','not_marked') | Default `not_marked` |
| marked_by | INT UNSIGNED | FK → `users.id`, set null on delete |
| marked_at | DATETIME | When the record was written |
| updated_at | TIMESTAMP | Auto updated |

Unique key on `(session_id, student_id)` means one row per student per session, so a
correction is an `UPDATE` of that row and can never create a duplicate.

## Where each rule is enforced

| Rule | Mechanism |
| --- | --- |
| Email is unique | Unique key on `users.email` |
| Role, mode and status values | `ENUM` columns plus `STRICT_TRANS_TABLES` on the application connection |
| Subject code unique per class | Unique key `(class_id, subject_code)` |
| Session subject belongs to session class | Composite FK `(class_id, subject_id)` → `subjects(class_id, id)` |
| Student enrolled in the session class | Trigger `trg_attendance_enrollment_check` |
| Same check when a record is corrected | Trigger `trg_attendance_enrollment_check_upd` |
| Roll number unique per class and year | Trigger `trg_enrollments_roll_unique` |
| Roll number change cannot clash | Trigger `trg_students_roll_unique_update` |
| One attendance row per student per session | Unique key `(session_id, student_id)` |
| Duplicate enrolment | Unique key `(student_id, class_id, enrollment_year)` |

> The XAMPP MariaDB server runs without strict mode by default, which would silently
> store invalid `ENUM` values as an empty string. The application therefore sets
> `sql_mode = STRICT_TRANS_TABLES,STRICT_ALL_TABLES,...` on every connection in
> `config/db.php`. This is a session-level setting and does not change the server.

## Indexes added for reporting

- `attendance(session_id, status)` — attendance sheet of one lecture
- `attendance(student_id)` — history of one student
- `attendance(status)` — filter present / absent
- `attendance(marked_at)` — date range reports
- `attendance_sessions(class_id, session_date)` — lectures of a class on a day
- `attendance_sessions(subject_id, session_date)` — lectures of a subject on a day
- `attendance_sessions(status)` — open sessions for the QR screen
- `enrollments(class_id, enrollment_year)` — class roster of an academic year

## Reporting view

`v_attendance_records` joins attendance with session, class, subject and student so
reports do not need five joins every time:

```sql
SELECT roll_number, student_name, subject_code, session_date, attendance_status
FROM v_attendance_records
WHERE class_id = 1 AND session_date = '2026-10-20';
```

## Importing the schema

Command line:

```bash
cd C:\xampp\htdocs\smart-attendance
C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root < database\seed.sql
```

phpMyAdmin:

1. Open `http://localhost/phpmyadmin`.
2. Select the `smart_attendance` database (created by `schema.sql`).
3. Open the **Import** tab, choose `database/schema.sql`, press **Go**.
4. Repeat for `database/seed.sql`.

Both scripts are idempotent, so importing them twice does not duplicate tables or
rows, and neither script drops or deletes existing data.

## Seed data

| Table | Rows | Content |
| --- | --- | --- |
| users | 2 | One admin, one teacher |
| classes | 2 | `2025-26 / A` and `2024-25 / B` |
| students | 6 | Roll numbers `01`–`04` and `CS01`–`CS02` |
| enrollments | 6 | Each student joined to the class of that year |
| subjects | 3 | DBMS and WEB for the current class, DBMS for the old one |
| attendance_sessions | 2 | One closed manual session, one open QR session |
| attendance | 8 | Present / absent / not marked records for both sessions |

Local demo accounts (development only, change before any real deployment):

| Account | Password | Role |
| --- | --- | --- |
| `admin@smartattendance.local` | `Admin@123` | admin |
| `priya.sharma@smartattendance.local` | `Teacher@123` | teacher |

## Useful queries for later phases

```sql
-- roster of a class for an academic year
SELECT st.roll_number, st.full_name
FROM students st
JOIN enrollments e ON e.student_id = st.id
WHERE e.class_id = 1 AND e.enrollment_year = '2025-26'
ORDER BY st.roll_number;

-- attendance percentage of one student
SELECT
    COUNT(CASE WHEN a.status = 'present' THEN 1 END) AS present_count,
    COUNT(*) AS total_count,
    ROUND(100 * COUNT(CASE WHEN a.status = 'present' THEN 1 END) / COUNT(*), 2) AS percentage
FROM attendance a
JOIN attendance_sessions se ON se.id = a.session_id
WHERE a.student_id = 101;

-- daily attendance sheet for one class
SELECT se.session_label, su.subject_name, se.session_date,
       SUM(a.status = 'present') AS present_count,
       SUM(a.status = 'absent') AS absent_count
FROM attendance_sessions se
JOIN subjects su ON su.id = se.subject_id
LEFT JOIN attendance a ON a.session_id = se.id
WHERE se.class_id = 1 AND se.session_date = '2026-10-20'
GROUP BY se.id, su.subject_name;
```
