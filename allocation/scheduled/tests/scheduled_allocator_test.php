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

namespace peeringallocation_scheduled;

/**
 * Test for the scheduled allocator.
 *
 * @package peeringallocation_scheduled
 * @copyright 2020 Jaume I University <https://www.uji.es/>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduled_allocator_test extends \advanced_testcase {

    /** @var \stdClass $course The course where the tests will be run */
    private $course;

    /** @var \peering $peering The peering where the tests will be run */
    private $peering;

    /** @var \stdClass $peeringcm The peering course module instance */
    private $peeringcm;

    /** @var \stdClass[] $students An array of student enrolled in $course */
    private $students;

    /**
     * Tests that student submissions get automatically alocated after the submission deadline and when the peering
     * "Switch to the next phase after the submissions deadline" checkbox is active.
     */
    public function test_that_allocator_in_executed_on_submission_end_when_phaseswitchassessment_is_active(): void {
        global $DB;

        $this->resetAfterTest();

        $this->setup_test_course_and_peering();

        $this->activate_switch_to_the_next_phase_after_submission_deadline();
        $this->set_the_submission_deadline_in_the_past();
        $this->activate_the_scheduled_allocator();

        $peeringgenerator = $this->getDataGenerator()->get_plugin_generator('mod_peering');

        cron_setup_user();

        // Let the students add submissions.
        $this->peering->switch_phase(\peering::PHASE_SUBMISSION);

        // Create some submissions.
        foreach ($this->students as $student) {
            $peeringgenerator->create_submission($this->peering->id, $student->id);
        }

        // No allocations yet.
        $this->assertEmpty($this->peering->get_allocations());

        /* Execute the tasks that will do the transition and allocation thing.
         * We expect the peering cron to do the whole work: change the phase and
         * allocate the submissions.
         */
        $this->execute_peering_cron_task();

        $peeringdb = $DB->get_record('peering', ['id' => $this->peering->id]);
        $peering = new \peering($peeringdb, $this->peeringcm, $this->course);

        $this->assertEquals(\peering::PHASE_ASSESSMENT, $peering->phase);
        $this->assertNotEmpty($peering->get_allocations());
    }

    /**
     * No allocations are performed if the allocator is not enabled.
     */
    public function test_that_allocator_is_not_executed_when_its_not_active(): void {
        global $DB;

        $this->resetAfterTest();

        $this->setup_test_course_and_peering();
        $this->activate_switch_to_the_next_phase_after_submission_deadline();
        $this->set_the_submission_deadline_in_the_past();

        $peeringgenerator = $this->getDataGenerator()->get_plugin_generator('mod_peering');

        cron_setup_user();

        // Let the students add submissions.
        $this->peering->switch_phase(\peering::PHASE_SUBMISSION);

        // Create some submissions.
        foreach ($this->students as $student) {
            $peeringgenerator->create_submission($this->peering->id, $student->id);
        }

        // No allocations yet.
        $this->assertEmpty($this->peering->get_allocations());

        // Transition to the assessment phase.
        $this->execute_peering_cron_task();

        $peeringdb = $DB->get_record('peering', ['id' => $this->peering->id]);
        $peering = new \peering($peeringdb, $this->peeringcm, $this->course);

        // No allocations too.
        $this->assertEquals(\peering::PHASE_ASSESSMENT, $peering->phase);
        $this->assertEmpty($peering->get_allocations());
    }

    /**
     * Activates and configures the scheduled allocator for the peering.
     */
    private function activate_the_scheduled_allocator(): void {

        $settings = \peering_random_allocator_setting::instance_from_object((object)[
            'numofreviews' => count($this->students),
            'numper' => 1,
            'removecurrentuser' => true,
            'excludesamegroup' => false,
            'assesswosubmission' => true,
            'addselfassessment' => false
        ]);

        $allocator = new \peering_scheduled_allocator($this->peering);

        $storesettingsmethod = new \ReflectionMethod('peering_scheduled_allocator', 'store_settings');
        $storesettingsmethod->setAccessible(true);
        $storesettingsmethod->invoke($allocator, true, true, $settings, new \peering_allocation_result($allocator));
    }

    /**
     * Creates a minimum common setup to execute tests:
     */
    protected function setup_test_course_and_peering(): void {
        $this->setAdminUser();

        $datagenerator = $this->getDataGenerator();

        $this->course = $datagenerator->create_course();

        $this->students = [];
        for ($i = 0; $i < 10; $i++) {
            $this->students[] = $datagenerator->create_and_enrol($this->course);
        }

        $peeringdb = $datagenerator->create_module('peering', [
            'course' => $this->course,
            'name' => 'Test peering',
        ]);
        $this->peeringcm = get_coursemodule_from_instance('peering', $peeringdb->id, $this->course->id, false, MUST_EXIST);
        $this->peering = new \peering($peeringdb, $this->peeringcm, $this->course);
    }

    /**
     * Executes the peering cron task.
     */
    protected function execute_peering_cron_task(): void {
        ob_start();
        $cron = new \mod_peering\task\cron_task();
        $cron->execute();
        ob_end_clean();
    }

    /**
     * Executes the scheduled allocator cron task.
     */
    protected function execute_allocator_cron_task(): void {
        ob_start();
        $cron = new \peeringallocation_scheduled\task\cron_task();
        $cron->execute();
        ob_end_clean();
    }

    /**
     * Activates the "Switch to the next phase after the submissions deadline" flag in the peering.
     */
    protected function activate_switch_to_the_next_phase_after_submission_deadline(): void {
        global $DB;
        $DB->set_field('peering', 'phaseswitchassessment', 1, ['id' => $this->peering->id]);
    }

    /**
     * Sets the submission deadline in a past time.
     */
    protected function set_the_submission_deadline_in_the_past(): void {
        global $DB;
        $DB->set_field('peering', 'submissionend', time() - 1, ['id' => $this->peering->id]);
    }
}
