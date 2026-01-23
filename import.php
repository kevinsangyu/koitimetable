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
        'CLS_STREAM_DESC',
        'CLASS_START_DT',
        'CLASS_END_DT',
        'CLASS_START_TIME',
        'CLASS_END_TIME',
        'BUILDING_ID',
        'ROOM_ID',
        'CLS_STREAM_ID',
        'AVAIL_YR',
        'SPRD_CD'
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
            continue; // skip broken rows
        }

        $row = [];
        foreach ($headerindex as $key => $index) {
            $row[$key] = $line[$index] ?? null;
        }

        // Validate fields
        //If any required fields are empty, skip the row
        if (
            empty($row['CLS_STREAM_DESC']) ||
            empty($row['CLASS_START_DT']) ||
            empty($row['CLASS_END_DT']) ||
            empty($row['CLS_STREAM_ID'])
        ) {
            $skipped++;
            continue;
        }

        // If the classes end before they start, skip the row
        $startdate = strtotime($row['CLASS_START_DT']);
        $enddate   = strtotime($row['CLASS_END_DT']);

        if (!$startdate || !$enddate || $enddate < $startdate) {
            $skipped++;
            continue;
        }

        // Create record and insert
        $record = new stdClass();
        $record->groupname = $row['CLS_STREAM_DESC'];
        $record->startdate = strtotime($row['CLASS_START_DT']);
        $record->enddate   = strtotime($row['CLASS_END_DT']);
        $record->timestart = (int)$row['CLASS_START_TIME'];
        $record->timeend   = (int)$row['CLASS_END_TIME'];
        $record->building  = $row['BUILDING_ID'];
        $record->room      = $row['ROOM_ID'];
        $record->streamid  = (int)$row['CLS_STREAM_ID'];
        $record->year      = (int)$row['AVAIL_YR'];
        $record->studyperiod = (int)$row['SPRD_CD'];
        // consider adding class type, lecturer...

        try {
            $DB->insert_record('local_koitimetable', $record);
            $inserted++;
        } catch (Exception $e) {
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