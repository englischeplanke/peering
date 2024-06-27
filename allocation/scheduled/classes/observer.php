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
 * Event observers for peeringallocation_scheduled.
 *
 * @package peeringallocation_scheduled
 * @copyright 2013 Adrian Greeve <adrian@moodle.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace peeringallocation_scheduled;
defined('MOODLE_INTERNAL') || die();

/**
 * Class for peeringallocation_scheduled observers.
 *
 * @package peeringallocation_scheduled
 * @copyright 2013 Adrian Greeve <adrian@moodle.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Triggered when the '\mod_peering\event\course_module_viewed' event is triggered.
     *
     * This does the same job as {@link peeringallocation_scheduled_cron()} but for the
     * single peering. The idea is that we do not need to wait for cron to execute.
     * Displaying the peering main view.php can trigger the scheduled allocation, too.
     *
     * @param \mod_peering\event\course_module_viewed $event
     * @return bool
     */
    public static function peering_viewed($event) {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/peering/locallib.php');

        $peering = $event->get_record_snapshot('peering', $event->objectid);
        $course   = $event->get_record_snapshot('course', $event->courseid);
        $cm       = $event->get_record_snapshot('course_modules', $event->contextinstanceid);

        $peering = new \peering($peering, $cm, $course);
        $now = time();

        // Non-expensive check to see if the scheduled allocation can even happen.
        if ($peering->phase == \peering::PHASE_SUBMISSION and $peering->submissionend > 0 and $peering->submissionend < $now) {

            // Make sure the scheduled allocation has been configured for this peering, that it has not
            // been executed yet and that the passed peering record is still valid.
            $sql = "SELECT a.id
                      FROM {peeringallocation_scheduled} a
                      JOIN {peering} w ON a.peeringid = w.id
                     WHERE w.id = :peeringid
                           AND a.enabled = 1
                           AND w.phase = :phase
                           AND w.submissionend > 0
                           AND w.submissionend < :now
                           AND (a.timeallocated IS NULL OR a.timeallocated < w.submissionend)";
            $params = array('peeringid' => $peering->id, 'phase' => \peering::PHASE_SUBMISSION, 'now' => $now);

            if ($DB->record_exists_sql($sql, $params)) {
                // Allocate submissions for assessments.
                $allocator = $peering->allocator_instance('scheduled');
                $result = $allocator->execute();
                // Todo inform the teachers about the results.
            }
        }
        return true;
    }

    /**
     * Called when the '\mod_peering\event\phase_automatically_switched' event is triggered.
     *
     * This observer handles the phase_automatically_switched event triggered when phaseswithassesment is active
     * and the phase is automatically switched.
     *
     * When this happens, this situation can occur:
     *
     *     * cron_task transition the peering to PHASE_ASESSMENT.
     *     * scheduled_allocator task executes.
     *     * scheduled_allocator task cannot allocate parcipants because peering is not
     *       in PHASE_SUBMISSION state (it's in PHASE_ASSESMENT).
     *
     * @param \mod_peering\event\phase_automatically_switched $event
     */
    public static function phase_automatically_switched(\mod_peering\event\phase_automatically_switched $event) {
        if ($event->other['previouspeeringphase'] != \peering::PHASE_SUBMISSION) {
            return;
        }
        if ($event->other['targetpeeringphase'] != \peering::PHASE_ASSESSMENT) {
            return;
        }

        $peering = $event->get_record_snapshot('peering', $event->objectid);
        $course   = $event->get_record_snapshot('course', $event->courseid);
        $cm       = $event->get_record_snapshot('course_modules', $event->contextinstanceid);

        $peering = new \peering($peering, $cm, $course);
        if ($peering->phase != \peering::PHASE_ASSESSMENT) {
            return;
        }

        $allocator = $peering->allocator_instance('scheduled');
        // We know that we come from PHASE_SUBMISSION so we tell the allocator not to test for the PHASE_SUBMISSION state.
        $allocator->execute(false);
    }
}
