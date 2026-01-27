<?php

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/upload_form.php');

require_login();
require_capability('local/koitimetable:manage', context_system::instance());

$context = context_system::instance();
global $DB;

$PAGE->set_url('/local/koitimetable/import.php');
$PAGE->set_context($context);
$PAGE->set_title('Manual Timetable import');
$PAGE->set_heading('Manual Timetable import');

$form = new local_koitimetable_upload_form();

echo $OUTPUT->header();

/* === Handle upload === */
if ($form->is_cancelled()) {
    redirect($PAGE->url);

} else if ($data = $form->get_data()) {

    file_save_draft_area_files(
        $data->csvfile,
        $context->id,
        'local_koitimetable',
        'csvfile',
        0,
        ['subdirs' => 0, 'maxfiles' => 1]
    );

    echo $OUTPUT->notification('CSV uploaded successfully', 'notifysuccess');


/* === Insert into DB === */


    $fs = get_file_storage();
    $files = $fs->get_area_files(
        $context->id,
        'local_koitimetable',
        'csvfile',
        0,
        'timemodified DESC',
        false
    );

    if (!$files) {
        echo $OUTPUT->notification('No CSV file found.', 'notifyerror');
        echo $OUTPUT->footer();
        exit;
    }

    $file = reset($files);
    $content = $file->get_content();

    /* === Parse CSV === */
    $lines = array_map('str_getcsv', explode("\n", trim($content)));
    $headers = array_shift($lines);

    $requiredheaders = [
        'CLS_BKG_CMT',
        'CLS_BKG_START_DT',
        'CLS_BKG_END_DT',
        'CLS_BKG_DAY',
        'CLS_BKG_START_TIME',
        'CLS_BKG_END_TIME',
        'BUILDING_ID',
        'ROOM_ID',
        'ACTIVITY'
    ];

    $missing = array_diff($requiredheaders, $headers);

    if (!empty($missing)) {
        echo $OUTPUT->notification(
            'Missing required CSV columns: ' . implode(', ', $missing),
            'notifyerror'
        );
        echo $OUTPUT->footer();
        exit;
    }

    /* Map headers to column index */
    $headerindex = array_flip($headers);

    // Safe import
    $transaction = $DB->start_delegated_transaction();

    /* Optional: clear old data first */
    $DB->delete_records('local_koitimetable');

    /* === Insert rows === */
    $inserted = 0;
    $skipped = 0;

    foreach ($lines as $line) {
        if (count($line) < count($headers)) {
            $skipped++;
            echo $OUTPUT->notification('Skipped: More fields than expected' . $line, 'notifyerror');
            continue; // skip broken rows
        }

        $row = [];
        foreach ($headerindex as $key => $index) {
            $row[$key] = $line[$index] ?? null;
        }

        // Validate fields
        //If any required fields are empty, skip the row
        if (
            empty($row['CLS_BKG_START_DT']) ||
            empty($row['CLS_BKG_END_DT']) ||
            empty($row['CLS_BKG_CMT'])
        ) {
            $skipped++;
            echo $OUTPUT->notification('Skipped: Missing required fields in row: ' . implode(', ', $line), 'notifyerror');
            continue;
        }

        // If the classes end before they start, skip the row
        $startdate = strtotime($row['CLS_BKG_START_DT']);
        $enddate   = strtotime($row['CLS_BKG_END_DT']);

        if (!$startdate || !$enddate || $enddate < $startdate) {
            $skipped++;
            echo $OUTPUT->notification('Skipped: Invalid date range', 'notifyerror');
            continue;
        }

        // Create record and insert
        $record = new stdClass();
        $record->groupname = $row['CLS_BKG_CMT'];
        $record->startdate = strtotime($row['CLS_BKG_START_DT']);
        $record->enddate   = strtotime($row['CLS_BKG_END_DT']);
        $record->timestart = (int)$row['CLS_BKG_START_TIME'];
        $record->timeend   = (int)$row['CLS_BKG_END_TIME'];
        $record->building  = $row['BUILDING_ID'];
        $record->room      = $row['ROOM_ID'];
        $record->activity  = (int)$row['ACTIVITY'];
        // consider adding class type, lecturer...

        try {
            $DB->insert_record('local_koitimetable', $record);
            $inserted++;
        } catch (Exception $e) {
            echo $OUTPUT->notification('Skipped: Caught in exception->' . $e->getMessage(), 'notifyerror');
            $skipped++;
        }
    }

    $transaction->allow_commit();

    echo $OUTPUT->notification(
        "Import complete: {$inserted} records inserted, {$skipped} skipped.",
        'notifysuccess'
    );
}

$form->display();

echo $OUTPUT->footer();