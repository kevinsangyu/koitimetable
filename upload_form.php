<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class local_koitimetable_upload_form extends moodleform {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('filepicker', 'csvfile', 'Upload CSV', null, [
            'accepted_types' => ['.csv'],
            'maxbytes' => 0
        ]);

        $mform->addRule('csvfile', null, 'required');

        $this->add_action_buttons(false, 'Upload');
    }
}
