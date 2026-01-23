<?php

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/upload_form.php');

require_login();
require_capability('local/koitimetable:view', context_system::instance());

$context = context_system::instance();

$PAGE->set_url('/local/koitimetable/import.php');
$PAGE->set_context($context);
$PAGE->set_title('CSV Viewer');
$PAGE->set_heading('CSV Viewer');

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
}


/* === Insert into DB === */
global $DB;

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

/* Map headers to column index */
$headerindex = array_flip($headers);

/* Optional: clear old data first */
$DB->delete_records('local_koitimetable');

/* === Insert rows === */
$inserted = 0;

foreach ($lines as $line) {
    if (count($line) < count($headers)) {
        continue; // skip broken rows
    }

    $row = [];
    foreach ($headerindex as $key => $index) {
        $row[$key] = $line[$index] ?? null;
    }

    $record = new stdClass();
    $record->groupname = $row['CLS_STREAM_DESC'];
    $record->startdate = strtotime($row['CLASS_START_DT']);
    $record->enddate   = strtotime($row['CLASS_END_DT']);
    $record->timestart = (int)$row['CLASS_START_TIME'];
    $record->timeend   = (int)$row['CLASS_END_TIME'];
    $record->building  = $row['BUILDING_ID'];
    $record->room      = $row['ROOM_ID'];
    $record->streamid  = (int)$row['CLS_STREAM_ID'];
    $record->year      = (int)$row['AVAIL_YEAR'];
    $record->studyperiod = (int)$row['SPRD_CD'];
    // consider adding class type, lecturer...

    $DB->insert_record('local_koitimetable', $record);
    $inserted++;
}

echo $OUTPUT->notification(
    "{$inserted} timetable records imported successfully.",
    'notifysuccess'
);
