# Student Imports — CSV and OCR

**SMART ATTENDANCE AI · Phase 4**

This document describes the two bulk student import paths: a CSV file and a
photographed or scanned list read with real OCR. Both paths share the same
validation, the same session-staged preview, and the same single-transaction
execution, so the rules and the guarantees are identical no matter where the
rows came from.

---

## 1. What both paths guarantee

- **Nothing is written until you confirm.** An uploaded file or image is parsed
  and validated first. The database is only touched after you press
  *Confirm import* on the preview screen.
- **Every row is validated before the batch runs.** Roll number format, name
  length, email format, roll-number clashes inside the target class and
  already-enrolled duplicates are all checked up front and shown per row.
- **The whole batch commits in one transaction.** Each row runs inside its own
  database savepoint, so one bad row is skipped without rolling back the rows
  before it. If the final commit fails, everything rolls back — a half import
  never happens.
- **Existing student profiles are never silently overwritten.** If a row matches
  an existing student by roll number *and* full name, that profile is reused and
  only the enrolment is added. The name and email already stored are left alone.
- **Attendance history is never deleted.** Deactivating a student or changing
  their details keeps every attendance record. Nothing in the application
  deletes attendance rows.

---

## 2. CSV import

Screen: `http://localhost/smart-attendance/csv-import.php`

### File format

The first row must be the header. Three columns are recognised, in this order:

```csv
roll_number,full_name,email
01,Aarav Sharma,aarav.sharma@smartattendance.local
02,Diya Patel,diya.patel@smartattendance.local
03,Rohan Mehta,
```

- `roll_number` — required. Up to 30 characters. Letters, numbers, spaces,
  dots, slashes and hyphens. Leading zeros are preserved exactly as typed.
- `full_name` — required. 2 to 120 characters.
- `email` — optional. If present it must be a valid address. Leave it empty
  rather than typing placeholder text.

A ready-to-download sample with these headers is on the upload screen and at
`csv-template.php`. The class is chosen on the upload form, not in the file,
so the same file can be loaded into different classes.

The parser accepts a UTF-8 or plain ASCII file, handles a byte-order mark,
quoted fields, and both comma and semicolon delimiters. The header row must
contain all three column names; the order does not matter. Up to 2000 data rows
and 2 MB per upload.

### Per-row statuses on the preview

| Status | Meaning | What happens on confirm |
| --- | --- | --- |
| **Ready** | The row passed every check. | Imported (new student) or reused (existing profile). |
| **Skipped** | The student already exists and is already enrolled in this class for this year, or the row appears again in the same file. | Nothing is written for this row. |
| **Conflict** | Another student already uses this roll number in the target class for this academic year. | Nothing is written for this row. |
| **Invalid** | A required field is missing, a value is out of range, or the email is not valid. | Nothing is written for this row. |

The *Ready* count drives the confirm button. If it is zero the button is
disabled and the import cannot start.

### Roll numbers and academic years

Roll numbers are **only unique inside a class and academic year**, enforced by a
database trigger, not globally. The same roll number can exist in another class
or another year. The academic year used for every row in a batch is the year of
the class chosen on the upload form, so the enrolment always matches the class
record — the rule the attendance trigger depends on.

---

## 3. OCR import

Screen: `http://localhost/smart-attendance/ocr-import.php`

### What it is

Real OCR. The page calls the local [Tesseract](https://github.com/tesseract-ocr/tesseract)
engine (installed outside the web root, see `README.md` → *OCR Engine Setup*),
which reads the image and returns the text it recognises. The text is then
parsed into candidate rows. Nothing is simulated and no cloud or paid API is
involved.

### How to use it

1. Photograph or scan a printed student list. Lay it flat, light it evenly and
   keep the whole list in frame. A roll-number column followed by a name column
   works best.
2. Choose the target class and upload the image (PNG, JPG, BMP, TIFF, WEBP or
   GIF, up to 4 MB).
3. The engine runs and the page shows a review table with every recognised row:
   roll number, name and email if one was on the line. Each row has an
   *Include* checkbox, and every field is editable — OCR is never trusted blindly.
4. Rows the parser is unsure about are flagged **Check** with a note, for
   example when only one space separates the roll number and the name, or the
   name looks like a single word.
5. The raw text the engine read is available under *Show the raw text the engine
   read*, so you can compare the recognition against the photo.
6. Untick any row you do not want, correct the rest, then press
   *Send to import preview*. The rows go through the same validation and the
   same single-transaction import as a CSV file.

### What the parser accepts

- `01  Aarav Sharma` — roll number first, separated by whitespace
- `CS01  Kabir Singh` — alphanumeric roll numbers
- `01 - Aarav Sharma` or `01. Aarav Sharma` — common printed separators
- `Aarav Sharma 01` — roll number at the end of the line (flagged for review)
- `05  Ananya Iyer  ananya.iyer@x.local` — an email anywhere on the line

Header lines (`Roll No.`, `Student Name`, `Sr No.` and similar), page furniture
and lines with no roll/name pair are ignored and listed under the review table
with the reason. A roll number must contain at least one digit, which keeps
words like "Attendance" out of the roll column.

### Engine status

The screen shows the engine version and its language-model path at the top when
the engine is available. If Tesseract is missing the screen explains what to
fix and the CSV import still works. The engine runs with a hard timeout
(default 30 seconds) so a hung process is killed instead of blocking the
request. The uploaded image is deleted from the temporary folder as soon as the
text has been read.

---

## 4. Security notes

- Both screens require a CSRF token on every POST. A missing or wrong token
  returns HTTP 400 and nothing is written.
- Uploads are validated on extension **and** on the MIME type detected from the
  file content, never on the browser-supplied type. The file is stored under a
  random name, so the original name never reaches the disk.
- The temporary folder for OCR images (`C:\xampp\tmp\smart-attendance-ocr`) is
  outside the web root and is not reachable from a browser.
- Every SQL statement uses a prepared statement. No user input is ever
  concatenated into SQL.
- Uploaded files are deleted as soon as they have been processed.

---

## 5. Troubleshooting

- **The OCR screen says the engine is not available.** Check the paths in
  `config/ocr.php` (or `config/ocr.local.php`), and confirm
  `tesseract.exe --version` runs from a command prompt.
- **No rows were recognised.** The photo is probably too blurry, too dark or
  angled. Retake it straight on with even light, or check the raw text block to
  see what the engine actually read.
- **A row is flagged Check.** Open the raw text, compare it with the photo and
  correct the field in the review table before sending the rows to the preview.
- **A CSV row says Conflict.** Another student in the target class already uses
  that roll number for this academic year. Either fix the roll number in the
  file or choose the correct class on the upload form.
- **A CSV row says Skipped.** The student is already enrolled in that class for
  that year, or the same row appears twice in the file. Nothing needs to be done.
