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
 * At this page, teachers allocate submissions to students for a review
 *
 * The allocation logic itself is delegated to allocators - subplugins in ./allocation
 * folder.
 *
 * @package    mod_peering
 * @copyright  2024 Johann Mellin <johann.mellin@tuhh.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');
require_once(__DIR__.'/allocation/lib.php');

$cmid       = required_param('cmid', PARAM_INT);                    // course module
$method     = optional_param('method', 'manual', PARAM_ALPHA);      // method to use

$cm         = get_coursemodule_from_id('peering', $cmid, 0, false, MUST_EXIST);
$course     = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$peering   = $DB->get_record('peering', array('id' => $cm->instance), '*', MUST_EXIST);
$peering   = new peering($peering, $cm, $course);

$url = $peering->allocation_url($method);
$PAGE->set_url($url);

require_login($course, false, $cm);
$context = $PAGE->context;
require_capability('mod/peering:allocate', $context);

$PAGE->set_title($peering->name);
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('allocation', 'peering'), $peering->allocation_url($method));
$PAGE->activityheader->set_attrs([
    'hidecompletion' => true,
    'description' => ''
]);

$allocator  = $peering->allocator_instance($method);
$initresult = $allocator->init();

//
// Output starts here
//

$actionbar = new \mod_peering\output\actionbar($url, $peering);

$output = $PAGE->get_renderer('mod_peering');
echo $output->header();
echo $output->render_allocation_menu($actionbar);

if (is_null($initresult->get_status()) or $initresult->get_status() == peering_allocation_result::STATUS_VOID) {
    echo $output->container_start('allocator-ui');
    echo $allocator->ui();
    echo $output->container_end();
} else {
    echo $output->container_start('allocator-init-results');
    echo $output->render($initresult);
    echo $output->continue_button($peering->allocation_url($method));
    echo $output->container_end();
}
    
echo $output->footer();
