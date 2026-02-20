<?php

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/koitimetable:manage', context_system::instance());

$context = context_system::instance();
global $DB, $OUTPUT, $PAGE, $CFG;

$PAGE->set_url('/local/koitimetable/import.php');
$PAGE->set_context($context);
$PAGE->set_title('Timetable import');
$PAGE->set_heading('Timetable import');

$import = optional_param('import', 0, PARAM_BOOL);

echo $OUTPUT->header();
if (empty($CFG->t1_client_id) || empty($CFG->t1_client_secret)) {
    echo $OUTPUT->notification('T1 API credentials are not configured.', 'notifyerror');
    echo $OUTPUT->footer();
    exit;
    // You can find config.php in htdocs/moodle/config.php, or go up 2 directories from this file.
}

/* =========================================================
 * Handle import button
 * ======================================================= */
if ($import && confirm_sesskey()) {

    $curl = new curl([
        'timeout' => 60,
        'ssl_verifypeer' => true
    ]);

    /* === Step 1: OAuth token === */
    $oauthurl = $CFG->t1_api_base . '/oauth2/access_token';

    $oauthresponse = $curl->post($oauthurl, [
        'grant_type'    => 'client_credentials',
        'client_id'     => $CFG->t1_client_id,
        'client_secret' => $CFG->t1_client_secret
    ]);

    $oauthdata = json_decode($oauthresponse, true);

    if (empty($oauthdata['access_token'])) {
        echo $OUTPUT->notification('Failed to obtain OAuth token', 'notifyerror');
        echo $OUTPUT->footer();
        exit;
    }

    /* === Step 2: Fetch classtimes === */
    $endpoint = $CFG->t1_api_base . '/Api/RaaS/v2/classtimes?pageSize=1000000&page=1';

    $curl->setHeader([
        'Authorization: Bearer ' . $oauthdata['access_token'],
        'Content-Type: application/json'
    ]);

    $response = $curl->get($endpoint);
    $json = json_decode($response, true);

    if (empty($json['DataSet']) || !is_array($json['DataSet'])) {
        echo $OUTPUT->notification('Invalid API response', 'notifyerror');
        echo $OUTPUT->footer();
        exit;
    }

    /* === Step 3: Import === */
    $transaction = $DB->start_delegated_transaction();
    $DB->delete_records('local_koitimetable');

    $requiredkeys = [
        'ACTIVITY',
        'STARTDATE',
        'ENDDATE',
        'STARTTIME',
        'ENDTIME',
        'BUILDINGID',
        'ROOMID',
        'COMMENT'
    ];

    $inserted = 0;
    $skipped  = 0;

    foreach ($json['DataSet'] as $row) {

        foreach ($requiredkeys as $key) {
            if (!array_key_exists($key, $row)) {
                $skipped++;
                continue 2;
            }
        }

        $startdate = strtotime($row['STARTDATE']);
        $enddate   = strtotime($row['ENDDATE']);

        if (!$startdate || !$enddate || $enddate < $startdate || empty($row['COMMENT'])) {
            $skipped++;
            continue;
        }

        $record = new stdClass();
        $record->groupname = $row['COMMENT'];
        $record->startdate = $startdate;
        $record->enddate   = $enddate;
        $record->timestart = (int)$row['STARTTIME'];
        $record->timeend   = (int)$row['ENDTIME'];
        $record->building  = $row['BUILDINGID'];
        $record->room      = $row['ROOMID'];
        $record->activity  = (int)$row['ACTIVITY'];

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

/* =========================================================
 * Import button
 * ======================================================= */
$importurl = new moodle_url('/local/koitimetable/import.php', [
    'import'  => 1,
    'sesskey' => sesskey()
]);

echo html_writer::div(
    html_writer::link(
        $importurl,
        'Import timetable from T1',
        ['class' => 'btn btn-primary']
    ),
    'mt-4'
);

echo $OUTPUT->footer();
