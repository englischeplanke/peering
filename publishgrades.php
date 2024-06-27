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
 * Change the current phase of the peering
 *
 * @package    mod_peering
 * @copyright  2024 Johann Mellin <johann.mellin@tuhh.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');

$cmid       = required_param('cmid', PARAM_INT);            // course module
$confirm    = optional_param('confirm', false, PARAM_BOOL); // confirmation

$cm         = get_coursemodule_from_id('peering', $cmid, 0, false, MUST_EXIST);
$course     = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$peering   = $DB->get_record('peering', array('id' => $cm->instance), '*', MUST_EXIST);
$peering   = new peering($peering, $cm, $course);

$PAGE->set_url($peering->publishgrades_url($peering->phase), array('cmid' => $cmid, 'phase' => $peering->phase));

require_login($course, false, $cm);
require_capability('mod/peering:switchphase', $PAGE->context);

if ($confirm) {
    if (!confirm_sesskey()) {
        throw new moodle_exception('confirmsesskeybad');
    }
    if (!$peering->publish_grades()) {
        throw new \moodle_exception('errorswitchingphase', 'peering', $peering->view_url());
    }
    redirect($peering->view_url());
}

$PAGE->set_title($peering->name);
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->set_attrs([
    'hidecompletion' => true,
    'description' => ''
]);
$PAGE->navbar->add(get_string('switchingphase', 'peering'));

$PAGE->set_secondary_active_tab("modulepage");

//
// Output starts here
//
echo $OUTPUT->header();
$continuebtn = new single_button(
    new moodle_url($PAGE->url, array('confirm' => 1)), get_string('continue'), 'post', true);
$continuebtn->class .= ' mr-3';

echo $OUTPUT->confirm(get_string('dopublishgrades', 'peering'),
                        $continuebtn, $peering->view_url());
echo $OUTPUT->footer();

