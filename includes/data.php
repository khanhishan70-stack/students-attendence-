<?php
/**
 * SMART ATTENDANCE AI - PHASE 3
 *
 * Dashboard data access.
 *
 * Every figure shown on the dashboard comes from these queries. Nothing is
 * hardcoded and nothing is invented: when the database cannot be reached the
 * page falls back to empty states instead of showing made-up numbers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

function fetch_dashboard_data(): array
{
    $data = [
        'available'      => false,
        'students'       => 0,
        'present_today'  => 0,
        'absent_today'   => 0,
        'sessions_today' => 0,
        'average'        => null,
        'marked_total'   => 0,
        'sessions'       => [],
        'overview'       => [],
        'low'            => [],
    ];

    try {
        $pdo = db();
    } catch (Throwable $exception) {
        error_log('[smart-attendance] dashboard data unavailable: ' . $exception->getMessage());
        return $data;
    }

    $data['available'] = true;

    $data['students'] = safe_count(
        $pdo,
        'SELECT COUNT(*) FROM students WHERE is_active = 1',
        'student count'
    );

    $data['present_today'] = safe_count(
        $pdo,
        "SELECT COUNT(*)
           FROM attendance a
           JOIN attendance_sessions se ON se.id = a.session_id
          WHERE se.session_date = CURDATE() AND a.status = 'present'",
        'present today'
    );

    $data['absent_today'] = safe_count(
        $pdo,
        "SELECT COUNT(*)
           FROM attendance a
           JOIN attendance_sessions se ON se.id = a.session_id
          WHERE se.session_date = CURDATE() AND a.status = 'absent'",
        'absent today'
    );

    $data['sessions_today'] = safe_count(
        $pdo,
        'SELECT COUNT(*) FROM attendance_sessions WHERE session_date = CURDATE()',
        'sessions today'
    );

    try {
        $average = $pdo->query(
            "SELECT ROUND(
                    100.0 * SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END)
                    / NULLIF(SUM(CASE WHEN a.status IN ('present','absent') THEN 1 ELSE 0 END), 0),
                    1
                ) AS percentage,
                SUM(CASE WHEN a.status IN ('present','absent') THEN 1 ELSE 0 END) AS marked
               FROM attendance a"
        )->fetch();

        if ($average !== false && $average['percentage'] !== null) {
            $data['average'] = (float) $average['percentage'];
            $data['marked_total'] = (int) $average['marked'];
        }
    } catch (Throwable $exception) {
        error_log('[smart-attendance] average attendance unavailable: ' . $exception->getMessage());
    }

    try {
        $data['sessions'] = fetch_recent_sessions($pdo, 6);
    } catch (Throwable $exception) {
        error_log('[smart-attendance] recent sessions unavailable: ' . $exception->getMessage());
    }

    try {
        $data['overview'] = fetch_attendance_overview($pdo, 7);
    } catch (Throwable $exception) {
        error_log('[smart-attendance] attendance overview unavailable: ' . $exception->getMessage());
    }

    try {
        $data['low'] = fetch_low_attendance($pdo, 75.0, 6);
    } catch (Throwable $exception) {
        error_log('[smart-attendance] low attendance list unavailable: ' . $exception->getMessage());
    }

    return $data;
}

/**
 * Runs a count query and returns 0 if that single query fails.
 */
function safe_count(PDO $pdo, string $sql, string $label): int
{
    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (Throwable $exception) {
        error_log('[smart-attendance] ' . $label . ' unavailable: ' . $exception->getMessage());

        return 0;
    }
}

function fetch_recent_sessions(PDO $pdo, int $limit): array
{
    $statement = $pdo->prepare(
        'SELECT se.id,
                se.session_date,
                se.session_label,
                se.attendance_mode,
                se.status,
                c.division,
                c.academic_year,
                su.subject_code,
                su.subject_name,
                (SELECT COUNT(*) FROM attendance a1
                  WHERE a1.session_id = se.id AND a1.status = \'present\')   AS present_count,
                (SELECT COUNT(*) FROM attendance a2
                  WHERE a2.session_id = se.id AND a2.status = \'absent\')    AS absent_count,
                (SELECT COUNT(*) FROM attendance a3
                  WHERE a3.session_id = se.id AND a3.status = \'not_marked\') AS pending_count
           FROM attendance_sessions se
           JOIN classes c  ON c.id  = se.class_id
           JOIN subjects su ON su.id = se.subject_id
          ORDER BY se.session_date DESC, se.id DESC
          LIMIT :limit'
    );

    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function fetch_attendance_overview(PDO $pdo, int $days): array
{
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

    $statement = $pdo->prepare(
        'SELECT DATE(se.session_date) AS day,
                SUM(CASE WHEN a.status = \'present\' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN a.status IN (\'present\',\'absent\') THEN 1 ELSE 0 END) AS marked_count
           FROM attendance a
           JOIN attendance_sessions se ON se.id = a.session_id
          WHERE se.session_date >= :from
          GROUP BY DATE(se.session_date)
          ORDER BY day ASC'
    );

    $statement->bindValue(':from', $from, PDO::PARAM_STR);
    $statement->execute();

    $rows = [];

    foreach ($statement->fetchAll() as $row) {
        $marked = (int) $row['marked_count'];
        $present = (int) $row['present_count'];

        $rows[] = [
            'day'        => (string) $row['day'],
            'label'      => date('j M', strtotime((string) $row['day'])),
            'present'    => $present,
            'marked'     => $marked,
            'percentage' => $marked > 0 ? round(($present / $marked) * 100, 1) : 0.0,
        ];
    }

    return $rows;
}

function fetch_low_attendance(PDO $pdo, float $threshold, int $limit): array
{
    $statement = $pdo->prepare(
        'SELECT st.id,
                st.roll_number,
                st.full_name,
                c.division,
                c.academic_year,
                SUM(CASE WHEN a.status = \'present\' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN a.status IN (\'present\',\'absent\') THEN 1 ELSE 0 END) AS marked_count
           FROM attendance a
           JOIN students st          ON st.id = a.student_id
           JOIN attendance_sessions se ON se.id = a.session_id
           JOIN classes c            ON c.id  = se.class_id
          WHERE st.is_active = 1
          GROUP BY st.id, st.roll_number, st.full_name, c.division, c.academic_year
         HAVING SUM(CASE WHEN a.status IN (\'present\',\'absent\') THEN 1 ELSE 0 END) > 0
            AND (SUM(CASE WHEN a.status = \'present\' THEN 1 ELSE 0 END) * 100.0
                 / SUM(CASE WHEN a.status IN (\'present\',\'absent\') THEN 1 ELSE 0 END)) < :threshold
          ORDER BY (SUM(CASE WHEN a.status = \'present\' THEN 1 ELSE 0 END) * 100.0
                    / SUM(CASE WHEN a.status IN (\'present\',\'absent\') THEN 1 ELSE 0 END)) ASC,
                   st.roll_number ASC
          LIMIT :limit'
    );

    $statement->bindValue(':threshold', $threshold, PDO::PARAM_STR);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    $rows = [];

    foreach ($statement->fetchAll() as $row) {
        $marked   = (int) $row['marked_count'];
        $present  = (int) $row['present_count'];

        $rows[] = [
            'id'         => (int) $row['id'],
            'roll'       => (string) $row['roll_number'],
            'name'       => (string) $row['full_name'],
            'division'   => (string) $row['division'],
            'year'       => (string) $row['academic_year'],
            'present'    => $present,
            'marked'     => $marked,
            'percentage' => $marked > 0 ? round(($present / $marked) * 100, 1) : 0.0,
        ];
    }

    return $rows;
}
