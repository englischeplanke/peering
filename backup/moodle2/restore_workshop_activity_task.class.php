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
 * @package   mod_peering
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/peering/backup/moodle2/restore_peering_stepslib.php'); // Because it exists (must)

/**
 * peering restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 */
class restore_peering_activity_task extends restore_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // Choice only has one structure step
        $this->add_step(new restore_peering_activity_structure_step('peering_structure', 'peering.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder
     */
    static public function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('peering',
                          array('intro', 'instructauthors', 'instructreviewers', 'conclusion'), 'peering');
        $contents[] = new restore_decode_content('peering_submissions',
                          array('content', 'feedbackauthor'), 'peering_submission');
        $contents[] = new restore_decode_content('peering_assessments',
                          array('feedbackauthor', 'feedbackreviewer'), 'peering_assessment');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder
     */
    static public function define_decode_rules() {
        $rules = array();

        $rules[] = new restore_decode_rule('peeringVIEWBYID', '/mod/peering/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('peeringINDEX', '/mod/peering/index.php?id=$1', 'course');

        return $rules;

    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * peering logs. It must return one array
     * of {@link restore_log_rule} objects
     */
    static public function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('peering', 'add', 'view.php?id={course_module}', '{peering}');
        $rules[] = new restore_log_rule('peering', 'update', 'view.php?id={course_module}', '{peering}');
        $rules[] = new restore_log_rule('peering', 'view', 'view.php?id={course_module}', '{peering}');

        $rules[] = new restore_log_rule('peering', 'add assessment',
                       'assessment.php?asid={peering_assessment}', '{peering_submission}');
        $rules[] = new restore_log_rule('peering', 'update assessment',
                       'assessment.php?asid={peering_assessment}', '{peering_submission}');

        $rules[] = new restore_log_rule('peering', 'add reference assessment',
                       'exassessment.php?asid={peering_referenceassessment}', '{peering_examplesubmission}');
        $rules[] = new restore_log_rule('peering', 'update reference assessment',
                       'exassessment.php?asid={peering_referenceassessment}', '{peering_examplesubmission}');

        $rules[] = new restore_log_rule('peering', 'add example assessment',
                       'exassessment.php?asid={peering_exampleassessment}', '{peering_examplesubmission}');
        $rules[] = new restore_log_rule('peering', 'update example assessment',
                       'exassessment.php?asid={peering_exampleassessment}', '{peering_examplesubmission}');

        $rules[] = new restore_log_rule('peering', 'view submission',
                       'submission.php?cmid={course_module}&id={peering_submission}', '{peering_submission}');
        $rules[] = new restore_log_rule('peering', 'add submission',
                       'submission.php?cmid={course_module}&id={peering_submission}', '{peering_submission}');
        $rules[] = new restore_log_rule('peering', 'update submission',
                       'submission.php?cmid={course_module}&id={peering_submission}', '{peering_submission}');

        $rules[] = new restore_log_rule('peering', 'view example',
                       'exsubmission.php?cmid={course_module}&id={peering_examplesubmission}', '{peering_examplesubmission}');
        $rules[] = new restore_log_rule('peering', 'add example',
                       'exsubmission.php?cmid={course_module}&id={peering_examplesubmission}', '{peering_examplesubmission}');
        $rules[] = new restore_log_rule('peering', 'update example',
                       'exsubmission.php?cmid={course_module}&id={peering_examplesubmission}', '{peering_examplesubmission}');

        $rules[] = new restore_log_rule('peering', 'update aggregate grades', 'view.php?id={course_module}', '{peering}');
        $rules[] = new restore_log_rule('peering', 'update switch phase', 'view.php?id={course_module}', '[phase]');
        $rules[] = new restore_log_rule('peering', 'update clear aggregated grades', 'view.php?id={course_module}', '{peering}');
        $rules[] = new restore_log_rule('peering', 'update clear assessments', 'view.php?id={course_module}', '{peering}');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * course logs. It must return one array
     * of {@link restore_log_rule} objects
     *
     * Note this rules are applied when restoring course logs
     * by the restore final task, but are defined here at
     * activity level. All them are rules not linked to any module instance (cmid = 0)
     */
    static public function define_restore_log_rules_for_course() {
        $rules = array();

        $rules[] = new restore_log_rule('peering', 'view all', 'index.php?id={course}', null);

        return $rules;
    }
}
