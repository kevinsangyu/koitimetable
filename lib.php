<?php
defined('MOODLE_INTERNAL') || die();

function local_koitimetable_extend_navigation(global_navigation $navigation) {
    $navigation->add(
        "MY TIMETABLE",
        new moodle_url('/local/koitimetable/index.php')
    );
}
