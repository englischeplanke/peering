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
 * Unit tests for peering events.
 *
 * @package    mod_peering
 * @category   phpunit
 * @copyright  2013 Adrian Greeve <adrian@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_peering\event;

use testable_peering;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/peering/lib.php'); // Include the code to test.
require_once($CFG->dirroot . '/mod/peering/locallib.php'); // Include the code to test.
require_once($CFG->dirroot . '/lib/cronlib.php'); // Include the code to test.
require_once(__DIR__ . '/../fixtures/testable.php');


/**
 * Test cases for the internal peering api
 */
class events_test extends \advanced_testcase {

    /** @var \stdClass $peering Basic peering data stored in an object. */
    protected $peering;
    /** @var \stdClass $course Generated Random Course. */
    protected $course;
    /** @var stdClass mod info */
    protected $cm;
    /** @var context $context Course module context. */
    protected $context;

    /**
     * Set up the testing environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->setAdminUser();

        // Create a peering activity.
        $this->course = $this->getDataGenerator()->create_course();
        $this->peering = $this->getDataGenerator()->create_module('peering', array('course' => $this->course));
        $this->cm = get_coursemodule_from_instance('peering', $this->peering->id);
        $this->context = \context_module::instance($this->cm->id);
    }

    protected function tearDown(): void {
        $this->peering = null;
        $this->course = null;
        $this->cm = null;
        $this->context = null;
        parent::tearDown();
    }

    /**
     * This event is triggered in view.php and peering/lib.php through the function peering_cron().
     */
    public function test_phase_switched_event() {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Add additional peering information.
        $this->peering->phase = 20;
        $this->peering->phaseswitchassessment = 1;
        $this->peering->submissionend = time() - 1;

        $cm = get_coursemodule_from_instance('peering', $this->peering->id, $this->course->id, false, MUST_EXIST);
        $peering = new testable_peering($this->peering, $cm, $this->course);

        // The phase that we are switching to.
        $newphase = 30;
        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $peering->switch_phase($newphase);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the legacy log data is valid.
        $expected = array($this->course->id, 'peering', 'update switch phase', 'view.php?id=' . $this->cm->id,
            $newphase, $this->cm->id);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $sink->close();
    }

    public function test_assessment_evaluated() {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = get_coursemodule_from_instance('peering', $this->peering->id, $this->course->id, false, MUST_EXIST);

        $peering = new testable_peering($this->peering, $cm, $this->course);

        $assessments = array();
        $assessments[] = (object)array('reviewerid' => 2, 'gradinggrade' => null,
            'gradinggradeover' => null, 'aggregationid' => null, 'aggregatedgrade' => 12);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $peering->aggregate_grading_grades_process($assessments);
        $events = $sink->get_events();
        $event = reset($events);

        $this->assertInstanceOf('\mod_peering\event\assessment_evaluated', $event);
        $this->assertEquals('peering_aggregations', $event->objecttable);
        $this->assertEquals(\context_module::instance($cm->id), $event->get_context());
        $this->assertEventContextNotUsed($event);

        $sink->close();
    }

    public function test_assessment_reevaluated() {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cm = get_coursemodule_from_instance('peering', $this->peering->id, $this->course->id, false, MUST_EXIST);

        $peering = new testable_peering($this->peering, $cm, $this->course);

        $assessments = array();
        $assessments[] = (object)array('reviewerid' => 2, 'gradinggrade' => null, 'gradinggradeover' => null,
            'aggregationid' => 2, 'aggregatedgrade' => 12);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $peering->aggregate_grading_grades_process($assessments);
        $events = $sink->get_events();
        $event = reset($events);

        $this->assertInstanceOf('\mod_peering\event\assessment_reevaluated', $event);
        $this->assertEquals('peering_aggregations', $event->objecttable);
        $this->assertEquals(\context_module::instance($cm->id), $event->get_context());
        $expected = array($this->course->id, 'peering', 'update aggregate grade',
            'view.php?id=' . $event->get_context()->instanceid, $event->objectid, $event->get_context()->instanceid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $sink->close();
    }

    /**
     * There is no api involved so the best we can do is test legacy data by triggering event manually.
     */
    public function test_aggregate_grades_reset_event() {
        $this->resetAfterTest();
        $this->setAdminUser();

        $event = \mod_peering\event\assessment_evaluations_reset::create(array(
            'context'  => $this->context,
            'courseid' => $this->course->id,
            'other' => array('peeringid' => $this->peering->id)
        ));

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the legacy log data is valid.
        $expected = array($this->course->id, 'peering', 'update clear aggregated grade', 'view.php?id=' . $this->cm->id,
            $this->peering->id, $this->cm->id);
        $this->assertEventLegacyLogData($expected, $event);

        $sink->close();
    }

    /**
     * There is no api involved so the best we can do is test legacy data by triggering event manually.
     */
    public function test_instances_list_viewed_event() {
        $this->resetAfterTest();
        $this->setAdminUser();

        $context = \context_course::instance($this->course->id);

        $event = \mod_peering\event\course_module_instance_list_viewed::create(array('context' => $context));

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the legacy log data is valid.
        $expected = array($this->course->id, 'peering', 'view all', 'index.php?id=' . $this->course->id, '');
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $sink->close();
    }

    /**
     * There is no api involved so the best we can do is test legacy data by triggering event manually.
     */
    public function test_submission_created_event() {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $submissionid = 48;

        $event = \mod_peering\event\submission_created::create(array(
                'objectid'      => $submissionid,
                'context'       => $this->context,
                'courseid'      => $this->course->id,
                'relateduserid' => $user->id,
                'other'         => array(
                    'submissiontitle' => 'The submission title'
                )
            )
        );

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the legacy log data is valid.
        $expected = array($this->course->id, 'peering', 'add submission',
            'submission.php?cmid=' . $this->cm->id . '&id=' . $submissionid, $submissionid, $this->cm->id);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $sink->close();
    }

    /**
     * There is no api involved so the best we can do is test legacy data by triggering event manually.
     */
    public function test_submission_updated_event() {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $submissionid = 48;

        $event = \mod_peering\event\submission_updated::create(array(
                'objectid'      => $submissionid,
                'context'       => $this->context,
                'courseid'      => $this->course->id,
                'relateduserid' => $user->id,
                'other'         => array(
                    'submissiontitle' => 'The submission title'
                )
            )
        );

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the legacy log data is valid.
        $expected = array($this->course->id, 'peering', 'update submission',
            'submission.php?cmid=' . $this->cm->id . '&id=' . $submissionid, $submissionid, $this->cm->id);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $sink->close();
    }

    /**
     * There is no api involved so the best we can do is test legacy data by triggering event manually.
     */
    public function test_submission_viewed_event() {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $submissionid = 48;

        $event = \mod_peering\event\submission_viewed::create(array(
                'objectid'      => $submissionid,
                'context'       => $this->context,
                'courseid'      => $this->course->id,
                'relateduserid' => $user->id,
                'other'         => array(
                    'peeringid' => $this->peering->id
                )
            )
        );

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the legacy log data is valid.
        $expected = array($this->course->id, 'peering', 'view submission',
            'submission.php?cmid=' . $this->cm->id . '&id=' . $submissionid, $submissionid, $this->cm->id);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $sink->close();
    }
}
