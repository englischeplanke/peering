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

namespace mod_peering\backup;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/peering/locallib.php');
require_once($CFG->dirroot . '/mod/peering/lib.php');
require_once($CFG->libdir . "/phpunit/classes/restore_date_testcase.php");
require_once($CFG->dirroot . "/mod/peering/tests/fixtures/testable.php");

/**
 * Restore date tests.
 *
 * @package    mod_peering
 * @copyright  2017 onwards Ankit Agarwal <ankit.agrr@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_date_test extends \restore_date_testcase {

    /**
     * Test restore dates.
     */
    public function test_restore_dates() {
        global $DB, $USER;

        // Create peering data.
        $record = ['submissionstart' => 100, 'submissionend' => 100, 'assessmentend' => 100, 'assessmentstart' => 100];
        list($course, $peering) = $this->create_course_and_module('peering', $record);
        $peeringgenerator = $this->getDataGenerator()->get_plugin_generator('mod_peering');
        $subid = $peeringgenerator->create_submission($peering->id, $USER->id);
        $exsubid = $peeringgenerator->create_submission($peering->id, $USER->id, ['example' => 1]);
        $peeringgenerator->create_assessment($subid, $USER->id);
        $peeringgenerator->create_assessment($exsubid, $USER->id, ['weight' => 0]);
        $peeringgenerator->create_assessment($exsubid, $USER->id);

        // Set time fields to a constant for easy validation.
        $timestamp = 100;
        $DB->set_field('peering_submissions', 'timecreated', $timestamp);
        $DB->set_field('peering_submissions', 'timemodified', $timestamp);
        $DB->set_field('peering_assessments', 'timecreated', $timestamp);
        $DB->set_field('peering_assessments', 'timemodified', $timestamp);

        // Do backup and restore.
        $newcourseid = $this->backup_and_restore($course);
        $newpeering = $DB->get_record('peering', ['course' => $newcourseid]);

        $this->assertFieldsNotRolledForward($peering, $newpeering, ['timemodified']);
        $props = ['submissionstart', 'submissionend', 'assessmentend', 'assessmentstart'];
        $this->assertFieldsRolledForward($peering, $newpeering, $props);

        $submissions = $DB->get_records('peering_submissions', ['peeringid' => $newpeering->id]);
        // peering submission time checks.
        foreach ($submissions as $submission) {
            $this->assertEquals($timestamp, $submission->timecreated);
            $this->assertEquals($timestamp, $submission->timemodified);
            $assessments = $DB->get_records('peering_assessments', ['submissionid' => $submission->id]);
            // peering assessment time checks.
            foreach ($assessments as $assessment) {
                $this->assertEquals($timestamp, $assessment->timecreated);
                $this->assertEquals($timestamp, $assessment->timemodified);
            }
        }
    }
}
