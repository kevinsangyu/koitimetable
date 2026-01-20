<?php
require('../../config.php');
require_login();

require_capability('local/koitimetable:manage', context_system::instance());

$PAGE->set_url('/local/koitimetable/import.php');
$PAGE->set_title('Import timetable');
$PAGE->set_heading('Import timetable');

echo $OUTPUT->header();

$csv = new csv_import_reader('KOI_import', 'KOI_import');

if ($content = $csv->get_file_content()) {
    $csv->load_csv_content($content, 'utf-8', ',');
    $rows = $csv->get_records();

    global $DB;

    foreach ($rows as $row) {
        $record = new stdClass();
        $record->groupname = $row['CLS_STREAM_DESC'];
        $record->year      = (int)$row['AVAIL_YR'];
        $record->studyperiod = $row['SPRD_CD'];
        $record->startdate = substr($row['CLASS_START_DT'], 0, 11);
        $record->enddate   = substr($row['CLASS_END_DT'], 0, 11);
        $record->timestart = (int)$row['CLASS_START_TIME'];
        $record->timeend   = (int)$row['CLASS_END_TIME'];
        $record->building  = $row['BUILDING_ID'];
        $record->room      = $row['ROOM_ID'];
        $record->groupid   = $row['CLS_STREAM_ID'];
        $record->staffid   = $row['STAFF_ID'];

        $DB->insert_record('local_koitimetable', $record);
    }

    echo $OUTPUT->notification('Import complete', 'notifysuccess');
}

echo $OUTPUT->footer();
