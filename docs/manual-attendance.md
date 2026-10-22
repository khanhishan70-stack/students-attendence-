# Manual Attendance (Phase 5)

Manual attendance is the classroom mode: a teacher signs in, starts a session
for a class and subject, then calls the register one roll number at a time.
Every mark is a real database row, saved the moment the button is pressed.

The screen is `manual-attendance.php`. It has three states — setup, marking and
summary — and only one is shown at a time.

## Requirements

- Apache and MySQL running (XAMPP Control Panel).
- The Phase 5 migration applied (see below).
- A teacher or admin account. Seeded accounts:

  | Role | Email | Password |
  | --- | --- | --- |
  | Admin | `admin@smartattendance.local` | `Admin@123` |
  | Teacher | `priya.sharma@smartattendance.local` | `Teacher@123` |

## Applying the Phase 5 migration

Phase 5 makes two schema changes. They have already been applied on this
machine, and `database/schema.sql` matches, so a fresh import needs no extra
step. To apply them to an existing database:

```bash
cd C:\xampp\htdocs\smart-attendance
C:\xampp\mysql\bin\mysql.exe -u root smart_attendance < database\migrations\phase5.sql
```

The migration is idempotent — running it twice does nothing the second time. It:

1. Adds `review` and `not_marked` to `attendance.status`, so a student can be
   flagged for a later look instead of being forced into present or absent.
2. Adds `attendance_sessions.seconds_per_student` (default 30) so each session
   remembers the countdown length it was started with.

## The workflow

### 1. Sign in

Opening `manual-attendance.php` while signed out redirects to `login.php` with
a `next=` parameter, and after a successful sign-in you are returned to the
attendance screen. The `next` value is only honoured when it is a local path, so
it can never send you to another site.

### 2. Start a session

Pick the class, subject, date, seconds per student and an optional label. The
subject list filters itself as you change class, but the server re-checks that
the subject really belongs to the class before anything is written.

- **Seconds per student** must be between 5 and 600. It only controls the
  countdown on screen; it is never used to write attendance.
- **One open session per class, subject and date.** The database deliberately
  allows several lectures of the same subject on the same day, so the
  application blocks a second *open* session for the same combination. If one is
  already running you can resume it from the recent-sessions table instead of
  starting a new one.

### 3. Mark the register

Students appear one at a time, in roll-number order (numeric-aware, so `2` sorts
before `10`). For each student choose:

| Button | Status | Meaning |
| --- | --- | --- |
| Present | `present` | In class |
| Absent | `absent` | Not in class |
| Review | `review` | Needs a later look (not counted present or absent) |
| Not marked | `not_marked` | Left undecided on purpose |

Pressing a button saves that mark immediately and moves to the next student. You
can also go back with **Previous**, or jump to any student from the roster strip
at the bottom. Keyboard shortcuts: `P` present, `A` absent, `R` review, `S`
skip (not marked), and the left/right arrows to move.

The header shows live counts (present / absent / review / not marked) and the
position in the register, so you always know how far through the class you are.

**Corrections are safe.** Re-marking a student updates their row instead of
adding a second one — the database has a unique key of one row per student per
session, and marking uses `INSERT ... ON DUPLICATE KEY UPDATE`. You can go back
to any student and change their mark as often as you like while the session is
open.

**A student can only be marked if they are enrolled in the session's class** (and
in the class's academic year). Marking anyone else is rejected with an
explanation rather than writing a wrong row.

### 4. The countdown timer

Each student gets the number of seconds chosen at setup. The timer is a ring
around the roll number that empties as time runs down.

- The timer is a **guide only**. It never writes to the database on its own, so
  an expired timer cannot create a wrong or duplicate record.
- When it reaches zero the student is left **not marked**, is flagged visually,
  and the screen advances to the next student once. You can go back and mark
  them properly at any time.
- Pressing a status button pauses the timer; it resumes if you keep working.
- Switching to another browser tab pauses the countdown so it does not run down
  while you are not looking.
- The "spoken roll call" toggle (a microphone button) reads each roll number and
  name out loud using the browser's built-in speech support. It is **off by
  default**, remembers your choice, and is disabled with a hint if the browser
  does not support it.

### 5. Finish the session

Press **Finish** when the register is done. The session is closed and locked:

- A closed session rejects any further mark — the screen switches to a read-only
  summary.
- Students who were never marked stay **not marked**. They are **never** silently
  converted to absent, so the record always reflects what actually happened.
- The summary screen shows present, absent, review and not-marked counts, a lock
  notice, and the full register in roll-number order.

## Files

| File | Role |
| --- | --- |
| `manual-attendance.php` | The screen: setup, marking and summary states |
| `includes/attendance.php` | Data layer: session create, roster, mark, finalize |
| `includes/auth.php` | Sign-in, sign-out, `require_login()` gate |
| `login.php`, `logout.php` | The sign-in and sign-out screens |
| `assets/js/attendance.js` | Timer, voice call-out, subject filter, shortcuts |
| `database/migrations/phase5.sql` | The `review` status and `seconds_per_student` column |

## Design decisions

- **Auth is the minimum needed to be secure.** Only the attendance screens are
  gated behind `require_login()`; the other screens still open as a guest, as
  they did before Phase 5. This keeps the change focused on attendance without
  silently locking the rest of the project.
- **The timer lives in the browser, not the database.** Writing a mark when the
  timer expires would risk creating wrong or duplicate records if the connection
  dropped or the page was refreshed mid-countdown. Leaving the student
  `not_marked` and letting the teacher decide is always safe.
- **`review` exists so a teacher is never forced to guess.** A student whose
  attendance is unclear can be flagged and counted separately, instead of being
  wrongly recorded as present or absent.

## Known limitations

- **No reopening a finished session.** Once closed, a session is locked for
  good. A correction can only be made while it is still open. There is no
  post-hoc edit or audit workflow yet.
- **The timer's "already advanced" state does not survive a refresh.** The timer
  is client-side; if you refresh the page the countdown restarts for the current
  student. Marks already saved are never affected — only the on-screen countdown
  resets.
- **No photographs.** The `students` table has no photo column, so the marking
  screen shows an initials avatar rather than a face.
- **No classes or subjects management UI.** The tables exist and are used here,
  but there is no screen to create or edit them yet.
- **Sign-in is per browser session.** Signing out destroys the session; there is
  no "remember me" and no password reset flow.
