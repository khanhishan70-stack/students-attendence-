<?php
/**
 * SMART ATTENDANCE AI - PHASE 4
 * Students management screen.
 *
 * Lists students with search, class filter and status filter. Supports adding
 * a student (which also enrols them in a class), editing their profile,
 * enroling them into another class and deactivating them. Every state-changing
 * action is a POST protected by CSRF and redirects afterwards.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/students.php';

/* ---------------------------------------------------------------------------
 * Handle POST actions (create / update / enroll / toggle). All redirect back
 * to the list afterwards so a reload never re-submits the form.
 * ------------------------------------------------------------------------ */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_require();

    $action = (string) ($_POST['action'] ?? '');

    try {
        $pdo = db();
    } catch (Throwable $exception) {
        flash_set('error', 'The database is not available right now. No change was saved.');
        header('Location: students.php');
        exit;
    }

    if ($action === 'create') {
        $validation = student_validate($_POST, $pdo);

        if ($validation['errors'] === []) {
            $result = student_create($pdo, $validation['values']);

            flash_set($result['ok'] ? 'success' : 'error', $result['message']);

            header('Location: students.php');
            exit;
        }

        $_SESSION['student_form'] = [
            'mode'   => 'create',
            'errors' => $validation['errors'],
            'values' => $validation['values'],
        ];

        header('Location: students.php?form=add');
        exit;
    }

    if ($action === 'update') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $validation = student_validate($_POST, $pdo);

        if ($validation['errors'] === []) {
            $result = student_update($pdo, $studentId, $validation['values']);

            flash_set($result['ok'] ? 'success' : 'error', $result['message']);

            header('Location: students.php');
            exit;
        }

        $_SESSION['student_form'] = [
            'mode'      => 'edit',
            'student_id'=> $studentId,
            'errors'    => $validation['errors'],
            'values'    => $validation['values'],
        ];

        header('Location: students.php?form=edit&id=' . $studentId);
        exit;
    }

    if ($action === 'enroll') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $classId   = (int) ($_POST['class_id'] ?? 0);

        $enrollResult = student_enroll($pdo, $studentId, $classId);

        flash_set($enrollResult['created'] ? 'success' : 'error', $enrollResult['message']);

        header('Location: students.php?form=edit&id=' . $studentId);
        exit;
    }

    if ($action === 'toggle') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $active    = (int) ($_POST['is_active'] ?? 0) === 1;

        $toggleResult = student_set_active($pdo, $studentId, $active);

        flash_set('success', $toggleResult['message']);

        header('Location: students.php');
        exit;
    }

    flash_set('error', 'That action is not recognised. Nothing was changed.');
    header('Location: students.php');
    exit;
}

/* ---------------------------------------------------------------------------
 * Read the filters from the query string and load the page of students.
 * ------------------------------------------------------------------------ */

$filters = [
    'search'   => trim((string) ($_GET['q'] ?? '')),
    'class_id' => (int) ($_GET['class_id'] ?? 0),
    'status'   => (string) ($_GET['status'] ?? 'active'),
];

if (!in_array($filters['status'], ['active', 'inactive', 'all'], true)) {
    $filters['status'] = 'active';
}

$page = (int) ($_GET['page'] ?? 1);

$databaseAvailable = db_is_available();

$classes = [];
$listing = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];

if ($databaseAvailable) {
    try {
        $pdo      = db();
        $classes  = student_classes($pdo);
        $listing  = students_fetch_page($pdo, $filters, $page);
    } catch (Throwable $exception) {
        error_log('[smart-attendance] students list failed: ' . $exception->getMessage());
        $databaseAvailable = false;
    }
}

/* Work out which form (add / edit) is on screen, if any. */
$formMode = (string) ($_GET['form'] ?? '');

$oldForm = $_SESSION['student_form'] ?? null;
unset($_SESSION['student_form']);

$errors    = [];
$values    = [];
$editStudent = null;

if ($formMode === 'add') {
    $formMode = 'add';
    $errors   = $oldForm['errors'] ?? [];
    $values   = $oldForm['values'] ?? [];
} elseif ($formMode === 'edit') {
    $studentId = (int) ($_GET['id'] ?? 0);

    if ($databaseAvailable) {
        $editStudent = student_find($pdo, $studentId);
    }

    if ($editStudent === null) {
        flash_set('error', 'That student could not be found.');
        header('Location: students.php');
        exit;
    }

    $errors = $oldForm['errors'] ?? [];
    $values = $oldForm['values'] ?? [
        'roll_number' => (string) $editStudent['roll_number'],
        'full_name'   => (string) $editStudent['full_name'],
        'email'       => (string) ($editStudent['email'] ?? ''),
        'class_id'    => (int) ($editStudent['enrollments'][0]['class_id'] ?? 0),
    ];
} else {
    $formMode = '';
}

/* Class options shared by the filters and the forms. */
$classOptions = [];

foreach ($classes as $class) {
    $classOptions[] = [
        'value'    => (string) $class['id'],
        'label'    => $class['department'] . ' ' . $class['academic_year'] . ' · ' . $class['division'],
        'selected' => false,
    ];
}

$statusOptions = [
    ['value' => 'active',   'label' => 'Active only',   'selected' => $filters['status'] === 'active'],
    ['value' => 'inactive', 'label' => 'Inactive only', 'selected' => $filters['status'] === 'inactive'],
    ['value' => 'all',      'label' => 'All students',  'selected' => $filters['status'] === 'all'],
];

render_page_start('students', 'Students');
?>
<section class="page-head">
    <div class="page-head-copy">
        <p class="page-kicker">Phase 4 · student management</p>
        <h2 class="page-title">Students</h2>
        <p class="page-sub">
            Search students, edit their profiles, enrol them into a class and
            deactivate them without touching their attendance history.
        </p>
    </div>

    <div class="page-head-actions">
        <?php if ($databaseAvailable && $formMode === ''): ?>
            <a class="btn btn-primary" href="students.php?form=add">
                <?= app_icon('plus') ?>Add student
            </a>
        <?php endif; ?>
    </div>
</section>

<?php if (!$databaseAvailable): ?>
    <div class="notice notice-danger" role="status">
        <strong>The database is not reachable right now.</strong>
        <p>Start MySQL from the XAMPP Control Panel and reload this page.</p>
    </div>
<?php endif; ?>

<?php if ($databaseAvailable && $formMode === 'add'): ?>
    <section class="panel" aria-labelledby="add-title">
        <div class="panel-head">
            <div>
                <h3 id="add-title">Add a student</h3>
                <p class="panel-sub">The student is created and enrolled in the chosen class in one step</p>
            </div>
            <a class="btn btn-ghost" href="students.php">Cancel</a>
        </div>

        <div class="panel-body">
            <form class="form-grid" method="post" action="students.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">

                <?php render_field([
                    'name'     => 'roll_number',
                    'label'    => 'Roll number',
                    'type'     => 'text',
                    'value'    => (string) ($values['roll_number'] ?? ''),
                    'required' => true,
                    'hint'     => 'Unique inside the class. Leading zeros are kept, e.g. 01.',
                    'error'    => (string) ($errors['roll_number'] ?? ''),
                ]); ?>

                <?php render_field([
                    'name'     => 'full_name',
                    'label'    => 'Full name',
                    'type'     => 'text',
                    'value'    => (string) ($values['full_name'] ?? ''),
                    'required' => true,
                    'error'    => (string) ($errors['full_name'] ?? ''),
                ]); ?>

                <?php render_field([
                    'name'  => 'email',
                    'label' => 'Email',
                    'type'  => 'email',
                    'value' => (string) ($values['email'] ?? ''),
                    'hint'  => 'Optional. Used for reports later.',
                    'error' => (string) ($errors['email'] ?? ''),
                ]); ?>

                <?php render_field([
                    'name'     => 'class_id',
                    'label'    => 'Class',
                    'type'     => 'select',
                    'value'    => (string) ($values['class_id'] ?? ''),
                    'required' => true,
                    'options'  => array_merge(
                        [['value' => '0', 'label' => 'Choose a class…', 'selected' => (int) ($values['class_id'] ?? 0) === 0]],
                        array_map(static function (array $option) use ($values): array {
                            $option['selected'] = (string) ($values['class_id'] ?? '') === $option['value'];
                            return $option;
                        }, $classOptions)
                    ),
                    'hint'    => 'The academic year is taken from the class record.',
                    'error'   => (string) ($errors['class_id'] ?? ''),
                ]); ?>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Create student</button>
                    <a class="btn btn-ghost" href="students.php">Cancel</a>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<?php if ($databaseAvailable && $formMode === 'edit' && $editStudent !== null): ?>
    <section class="panel" aria-labelledby="edit-title">
        <div class="panel-head">
            <div>
                <h3 id="edit-title">Edit student</h3>
                <p class="panel-sub">
                    Roll number <strong><?= e((string) $editStudent['roll_number']) ?></strong>
                    · <?= e((string) $editStudent['full_name']) ?>
                </p>
            </div>
            <a class="btn btn-ghost" href="students.php">Back to list</a>
        </div>

        <div class="panel-body">
            <form class="form-grid" method="post" action="students.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="student_id" value="<?= (int) $editStudent['id'] ?>">

                <?php render_field([
                    'name'     => 'roll_number',
                    'label'    => 'Roll number',
                    'type'     => 'text',
                    'value'    => (string) ($values['roll_number'] ?? ''),
                    'required' => true,
                    'error'    => (string) ($errors['roll_number'] ?? ''),
                ]); ?>

                <?php render_field([
                    'name'     => 'full_name',
                    'label'    => 'Full name',
                    'type'     => 'text',
                    'value'    => (string) ($values['full_name'] ?? ''),
                    'required' => true,
                    'error'    => (string) ($errors['full_name'] ?? ''),
                ]); ?>

                <?php render_field([
                    'name'  => 'email',
                    'label' => 'Email',
                    'type'  => 'email',
                    'value' => (string) ($values['email'] ?? ''),
                    'error' => (string) ($errors['email'] ?? ''),
                ]); ?>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Save changes</button>
                    <a class="btn btn-ghost" href="students.php">Cancel</a>
                </div>
            </form>

            <div class="enroll-block">
                <h4 class="enroll-title">Enrolments</h4>
                <p class="panel-sub">A student may sit in a class each academic year. History is kept.</p>

                <?php if ($editStudent['enrollments'] === []): ?>
                    <p class="panel-sub">This student is not enrolled in any class yet.</p>
                <?php else: ?>
                    <ul class="enroll-list">
                        <?php foreach ($editStudent['enrollments'] as $enrollment): ?>
                            <li>
                                <span>
                                    <?= e((string) $enrollment['department']) ?>
                                    <?= e((string) $enrollment['academic_year']) ?>
                                    · <?= e((string) $enrollment['division']) ?>
                                </span>
                                <span class="badge badge-blue"><?= e((string) $enrollment['enrollment_year']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <form class="enroll-form" method="post" action="students.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enroll">
                    <input type="hidden" name="student_id" value="<?= (int) $editStudent['id'] ?>">

                    <label class="field-label" for="enroll_class">Add enrolment</label>
                    <div class="enroll-row">
                        <select class="field-control" id="enroll_class" name="class_id" required>
                            <option value="0">Choose a class…</option>
                            <?php foreach ($classOptions as $option): ?>
                                <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-ghost" type="submit">Enrol</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($databaseAvailable && $formMode === ''): ?>
    <section class="panel" aria-labelledby="filters-title">
        <div class="panel-head">
            <div>
                <h3 id="filters-title">Filters</h3>
                <p class="panel-sub"><?= (int) $listing['total'] ?> student(s) match the current filters</p>
            </div>
        </div>

        <div class="panel-body">
            <form class="filter-bar" method="get" action="students.php">
                <div class="field">
                    <label class="field-label" for="q">Search</label>
                    <input class="field-control" type="search" id="q" name="q"
                           value="<?= e($filters['search']) ?>" placeholder="Roll number or name">
                </div>

                <div class="field">
                    <label class="field-label" for="class_id">Class</label>
                    <select class="field-control" id="class_id" name="class_id">
                        <option value="0">All classes</option>
                        <?php foreach ($classOptions as $option): ?>
                            <option value="<?= e($option['value']) ?>"
                                <?= $filters['class_id'] === (int) $option['value'] ? 'selected' : '' ?>>
                                <?= e($option['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="field-label" for="status">Status</label>
                    <select class="field-control" id="status" name="status">
                        <?php foreach ($statusOptions as $option): ?>
                            <option value="<?= e($option['value']) ?>"
                                <?= $option['selected'] ? 'selected' : '' ?>>
                                <?= e($option['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button class="btn btn-ghost" type="submit">Apply filters</button>
                    <a class="btn btn-ghost" href="students.php">Reset</a>
                </div>
            </form>
        </div>
    </section>

    <section class="panel" aria-labelledby="list-title">
        <div class="panel-head">
            <div>
                <h3 id="list-title">Student list</h3>
                <p class="panel-sub">Showing page <?= (int) $listing['page'] ?> of <?= (int) $listing['pages'] ?></p>
            </div>

            <div class="head-actions">
                <a class="btn btn-ghost" href="csv-import.php">CSV import</a>
                <a class="btn btn-ghost" href="ocr-import.php">OCR import</a>
            </div>
        </div>

        <?php if ($listing['rows'] === []): ?>
            <?php render_empty_state(
                'No students found',
                'No student matches the current filters. Add a student or import a CSV file.',
                'users'
            ); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table" data-responsive>
                    <caption class="sr-only">Students with their class and status</caption>
                    <thead>
                        <tr>
                            <th scope="col">Roll No.</th>
                            <th scope="col">Student</th>
                            <th scope="col">Class</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listing['rows'] as $student): ?>
                            <?php
                            $primary  = $student['enroll_primary'] ?? null;
                            $isActive = (int) $student['is_active'] === 1;
                            ?>
                            <tr>
                                <td data-label="Roll No."><span class="cell-strong"><?= e((string) $student['roll_number']) ?></span></td>
                                <td data-label="Student">
                                    <span class="cell-strong"><?= e((string) $student['full_name']) ?></span>
                                    <?php if (!empty($student['email'])): ?>
                                        <span class="cell-sub"><?= e((string) $student['email']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Class">
                                    <?php if ($primary !== null): ?>
                                        <?= e((string) $primary['division']) ?>
                                        <span class="cell-sub">
                                            <?= e((string) $primary['academic_year']) ?>
                                            <?php if ((int) $student['enroll_count'] > 1): ?>
                                                · +<?= (int) $student['enroll_count'] - 1 ?> more
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="cell-sub">Not enrolled</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status">
                                    <?php if ($isActive): ?>
                                        <span class="badge badge-green">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-grey">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Actions">
                                    <div class="row-actions">
                                        <a class="btn btn-ghost btn-sm" href="students.php?form=edit&id=<?= (int) $student['id'] ?>">
                                            Edit
                                        </a>

                                        <form method="post" action="students.php" data-confirm
                                              data-confirm-text="Deactivate <?= e((string) $student['full_name']) ?>? Their attendance history will be kept.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="student_id" value="<?= (int) $student['id'] ?>">
                                            <input type="hidden" name="is_active" value="<?= $isActive ? '0' : '1' ?>">
                                            <button class="btn btn-ghost btn-sm" type="submit">
                                                <?= $isActive ? 'Deactivate' : 'Reactivate' ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($listing['pages'] > 1): ?>
            <nav class="pagination" aria-label="Student list pages">
                <?php for ($n = 1; $n <= $listing['pages']; $n++): ?>
                    <?php
                    $query = $filters;
                    $query['page'] = $n;
                    $href  = 'students.php?' . http_build_query(array_filter($query, static fn ($v) => $v !== '' && $v !== 0 && $v !== null));
                    $isCurrent = $n === $listing['page'];
                    ?>
                    <a class="pagination-link<?= $isCurrent ? ' is-current' : '' ?>"
                       href="<?= e($href) ?>"
                       <?= $isCurrent ? 'aria-current="page"' : '' ?>>
                        <?= $n ?>
                    </a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php
render_page_end();
