<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Scheduled allocator's settings
 *
 * @package     peeringallocation_scheduled
 * @subpackage  mod_peering
 * @copyright   2012 David Mudrak <david@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/lib/formslib.php');
require_once(__DIR__ . '/../random/settings_form.php'); // parent form

/**
 * Allocator settings form
 *
 * This is used by {@see peering_scheduled_allocator::ui()} to set up allocation parameters.
 */
class peering_scheduled_allocator_form extends peering_random_allocator_form {

    /**
     * Definition of the setting form elements
     */
    public function definition() {
        global $OUTPUT;

        $mform = $this->_form;
        $peering = $this->_customdata['peering'];
        $current = $this->_customdata['current'];

        if (!empty($peering->submissionend)) {
            $strtimeexpected = peering::timestamp_formats($peering->submissionend);
        }

        if (!empty($current->timeallocated)) {
            $strtimeexecuted = peering::timestamp_formats($current->timeallocated);
        }

        $mform->addElement('header', 'scheduledallocationsettings', get_string('scheduledallocationsettings', 'peeringallocation_scheduled'));
        $mform->addHelpButton('scheduledallocationsettings', 'scheduledallocationsettings', 'peeringallocation_scheduled');

        $mform->addElement('checkbox', 'enablescheduled', get_string('enablescheduled', 'peeringallocation_scheduled'), get_string('enablescheduledinfo', 'peeringallocation_scheduled'), 1);

        $mform->addElement('header', 'scheduledallocationinfo', get_string('currentstatus', 'peeringallocation_scheduled'));

        if ($current === false) {
            $mform->addElement('static', 'infostatus', get_string('currentstatusexecution', 'peeringallocation_scheduled'),
                get_string('resultdisabled', 'peeringallocation_scheduled').' '. $OUTPUT->pix_icon('i/invalid', ''));

        } else {
            if (!empty($current->timeallocated)) {
                $mform->addElement('static', 'infostatus', get_string('currentstatusexecution', 'peeringallocation_scheduled'),
                    get_string('currentstatusexecution1', 'peeringallocation_scheduled', $strtimeexecuted).' '.
                    $OUTPUT->pix_icon('i/valid', ''));

                if ($current->resultstatus == peering_allocation_result::STATUS_EXECUTED) {
                    $strstatus = get_string('resultexecuted', 'peeringallocation_scheduled').' '.
                        $OUTPUT->pix_icon('i/valid', '');

                } else if ($current->resultstatus == peering_allocation_result::STATUS_FAILED) {
                    $strstatus = get_string('resultfailed', 'peeringallocation_scheduled').' '.
                        $OUTPUT->pix_icon('i/invalid', '');

                } else {
                    $strstatus = get_string('resultvoid', 'peeringallocation_scheduled').' '.
                        $OUTPUT->pix_icon('i/invalid', '');

                }

                if (!empty($current->resultmessage)) {
                    $strstatus .= html_writer::empty_tag('br').$current->resultmessage; // yes, this is ugly. better solution suggestions are welcome.
                }
                $mform->addElement('static', 'inforesult', get_string('currentstatusresult', 'peeringallocation_scheduled'), $strstatus);

                if ($current->timeallocated < $peering->submissionend) {
                    $mform->addElement('static', 'infoexpected', get_string('currentstatusnext', 'peeringallocation_scheduled'),
                        get_string('currentstatusexecution2', 'peeringallocation_scheduled', $strtimeexpected).' '.
                        $OUTPUT->pix_icon('i/caution', ''));
                    $mform->addHelpButton('infoexpected', 'currentstatusnext', 'peeringallocation_scheduled');
                } else {
                    $mform->addElement('checkbox', 'reenablescheduled', get_string('currentstatusreset', 'peeringallocation_scheduled'),
                       get_string('currentstatusresetinfo', 'peeringallocation_scheduled'));
                    $mform->addHelpButton('reenablescheduled', 'currentstatusreset', 'peeringallocation_scheduled');
                }

            } else if (empty($current->enabled)) {
                $mform->addElement('static', 'infostatus', get_string('currentstatusexecution', 'peeringallocation_scheduled'),
                    get_string('resultdisabled', 'peeringallocation_scheduled').' '.
                        $OUTPUT->pix_icon('i/invalid', ''));

            } else if ($peering->phase != peering::PHASE_SUBMISSION) {
                $mform->addElement('static', 'infostatus', get_string('currentstatusexecution', 'peeringallocation_scheduled'),
                    get_string('resultfailed', 'peeringallocation_scheduled').' '.
                    $OUTPUT->pix_icon('i/invalid', '') .
                    html_writer::empty_tag('br').
                    get_string('resultfailedphase', 'peeringallocation_scheduled'));

            } else if (empty($peering->submissionend)) {
                $mform->addElement('static', 'infostatus', get_string('currentstatusexecution', 'peeringallocation_scheduled'),
                    get_string('resultfailed', 'peeringallocation_scheduled').' '.
                    $OUTPUT->pix_icon('i/invalid', '') .
                    html_writer::empty_tag('br').
                    get_string('resultfaileddeadline', 'peeringallocation_scheduled'));

            } else if ($peering->submissionend < time()) {
                // next cron will execute it
                $mform->addElement('static', 'infostatus', get_string('currentstatusexecution', 'peeringallocation_scheduled'),
                    get_string('currentstatusexecution4', 'peeringallocation_scheduled').' '.
                    $OUTPUT->pix_icon('i/caution', ''));

            } else {
                $mform->addElement('static', 'infostatus', get_string('currentstatusexecution', 'peeringallocation_scheduled'),
                    get_string('currentstatusexecution3', 'peeringallocation_scheduled', $strtimeexpected).' '.
                    $OUTPUT->pix_icon('i/caution', ''));
            }
        }

        parent::definition();

        $mform->addHelpButton('randomallocationsettings', 'randomallocationsettings', 'peeringallocation_scheduled');
    }
}
