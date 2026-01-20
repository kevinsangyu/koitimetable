<?php
require('../../config.php');
require_login();

$PAGE->set_url('/local/koitimetable/index.php');
$PAGE->set_title(get_string('timetable', 'local_koitimetable'));
$PAGE->set_heading(get_string('timetable', 'local_koitimetable'));

echo $OUTPUT->header();

global $DB, $USER;

// 1. Get user's groups
$groups = groups_get_all_groups(0, $USER->id);
$groupnames = array_map(fn($g) => $g->name, $groups);

if (empty($groupnames)) {
    echo $OUTPUT->notification('You are not assigned to any groups.');
    echo $OUTPUT->footer();
    exit;
}

// 2. Query timetable
list($sqlin, $params) = $DB->get_in_or_equal($groupnames, SQL_PARAMS_NAMED);

$sql = "
    SELECT *
    FROM {local_koitimetable}
    WHERE groupname $sqlin
    ORDER BY startdate, timestart
";

$records = $DB->get_records_sql($sql, $params);

// 3. Render
$table = new html_table();
$table->head = ['Group', 'Date', 'Time', 'Location'];

foreach ($records as $r) {
    $table->data[] = [
        s($r->groupname),
        userdate($r->startdate, '%d %b %Y'),
        substr($r->timestart, 0, 2) . ':' . substr($r->timestart, 2, 2)
        . ' – ' .
        substr($r->timeend, 0, 2) . ':' . substr($r->timeend, 2, 2),
        s($r->building . ' ' . $r->room)
    ];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
