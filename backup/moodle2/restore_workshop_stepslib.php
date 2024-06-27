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

/**
 * Define all the restore steps that will be used by the restore_peering_activity_task
 */

/**
 * Structure step to restore one peering activity
 */
class restore_peering_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {

        $paths = array();

        $userinfo = $this->get_setting_value('userinfo'); // are we including userinfo?

        ////////////////////////////////////////////////////////////////////////
        // XML interesting paths - non-user data
        ////////////////////////////////////////////////////////////////////////

        // root element describing peering instance
        $peering = new restore_path_element('peering', '/activity/peering');
        $paths[] = $peering;

        // Apply for 'peeringform' subplugins optional paths at peering level
        $this->add_subplugin_structure('peeringform', $peering);

        // Apply for 'peeringeval' subplugins optional paths at peering level
        $this->add_subplugin_structure('peeringeval', $peering);

        // example submissions
        $paths[] = new restore_path_element('peering_examplesubmission',
                       '/activity/peering/examplesubmissions/examplesubmission');

        // reference assessment of the example submission
        $referenceassessment = new restore_path_element('peering_referenceassessment',
                                   '/activity/peering/examplesubmissions/examplesubmission/referenceassessment');
        $paths[] = $referenceassessment;

        // Apply for 'peeringform' subplugins optional paths at referenceassessment level
        $this->add_subplugin_structure('peeringform', $referenceassessment);

        // End here if no-user data has been selected
        if (!$userinfo) {
            return $this->prepare_activity_structure($paths);
        }

        ////////////////////////////////////////////////////////////////////////
        // XML interesting paths - user data
        ////////////////////////////////////////////////////////////////////////

        // assessments of example submissions
        $exampleassessment = new restore_path_element('peering_exampleassessment',
                                 '/activity/peering/examplesubmissions/examplesubmission/exampleassessments/exampleassessment');
        $paths[] = $exampleassessment;

        // Apply for 'peeringform' subplugins optional paths at exampleassessment level
        $this->add_subplugin_structure('peeringform', $exampleassessment);

        // submissions
        $paths[] = new restore_path_element('peering_submission', '/activity/peering/submissions/submission');

        // allocated assessments
        $assessment = new restore_path_element('peering_assessment',
                          '/activity/peering/submissions/submission/assessments/assessment');
        $paths[] = $assessment;

        // Apply for 'peeringform' subplugins optional paths at assessment level
        $this->add_subplugin_structure('peeringform', $assessment);

        // aggregations of grading grades in this peering
        $paths[] = new restore_path_element('peering_aggregation', '/activity/peering/aggregations/aggregation');

        // Return the paths wrapped into standard activity structure
        return $this->prepare_activity_structure($paths);
    }

    protected function process_peering($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->strategy = clean_param($data->strategy, PARAM_PLUGIN);
        $data->evaluation = clean_param($data->evaluation, PARAM_PLUGIN);

        // Any changes to the list of dates that needs to be rolled should be same during course restore and course reset.
        // See MDL-9367.
        $data->submissionstart = $this->apply_date_offset($data->submissionstart);
        $data->submissionend = $this->apply_date_offset($data->submissionend);
        $data->assessmentstart = $this->apply_date_offset($data->assessmentstart);
        $data->assessmentend = $this->apply_date_offset($data->assessmentend);

        if ($data->nattachments == 0) {
            // Convert to the new method for disabling file submissions.
            $data->submissiontypefile = peering_SUBMISSION_TYPE_DISABLED;
            $data->submissiontypetext = peering_SUBMISSION_TYPE_REQUIRED;
            $data->nattachments = 1;
        }

        // insert the peering record
        $newitemid = $DB->insert_record('peering', $data);
        // immediately after inserting "activity" record, call this
        $this->apply_activity_instance($newitemid);
    }

    protected function process_peering_examplesubmission($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->peeringid = $this->get_new_parentid('peering');
        $data->example = 1;
        $data->authorid = $this->task->get_userid();

        $newitemid = $DB->insert_record('peering_submissions', $data);
        $this->set_mapping('peering_examplesubmission', $oldid, $newitemid, true); // Mapping with files
    }

    protected function process_peering_referenceassessment($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->submissionid = $this->get_new_parentid('peering_examplesubmission');
        $data->reviewerid = $this->task->get_userid();

        $newitemid = $DB->insert_record('peering_assessments', $data);
        $this->set_mapping('peering_referenceassessment', $oldid, $newitemid, true); // Mapping with files
    }

    protected function process_peering_exampleassessment($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->submissionid = $this->get_new_parentid('peering_examplesubmission');
        $data->reviewerid = $this->get_mappingid('user', $data->reviewerid);

        $newitemid = $DB->insert_record('peering_assessments', $data);
        $this->set_mapping('peering_exampleassessment', $oldid, $newitemid, true); // Mapping with files
    }

    protected function process_peering_submission($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->peeringid = $this->get_new_parentid('peering');
        $data->example = 0;
        $data->authorid = $this->get_mappingid('user', $data->authorid);

        $newitemid = $DB->insert_record('peering_submissions', $data);
        $this->set_mapping('peering_submission', $oldid, $newitemid, true); // Mapping with files
    }

    protected function process_peering_assessment($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->submissionid = $this->get_new_parentid('peering_submission');
        $data->reviewerid = $this->get_mappingid('user', $data->reviewerid);

        $newitemid = $DB->insert_record('peering_assessments', $data);
        $this->set_mapping('peering_assessment', $oldid, $newitemid, true); // Mapping with files
    }

    protected function process_peering_aggregation($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->peeringid = $this->get_new_parentid('peering');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('peering_aggregations', $data);
        $this->set_mapping('peering_aggregation', $oldid, $newitemid, true);
    }

    protected function after_execute() {
        // Add peering related files, no need to match by itemname (just internally handled context)
        $this->add_related_files('mod_peering', 'intro', null);
        $this->add_related_files('mod_peering', 'instructauthors', null);
        $this->add_related_files('mod_peering', 'instructreviewers', null);
        $this->add_related_files('mod_peering', 'conclusion', null);

        // Add example submission related files, matching by 'peering_examplesubmission' itemname
        $this->add_related_files('mod_peering', 'submission_content', 'peering_examplesubmission');
        $this->add_related_files('mod_peering', 'submission_attachment', 'peering_examplesubmission');

        // Add reference assessment related files, matching by 'peering_referenceassessment' itemname
        $this->add_related_files('mod_peering', 'overallfeedback_content', 'peering_referenceassessment');
        $this->add_related_files('mod_peering', 'overallfeedback_attachment', 'peering_referenceassessment');

        // Add example assessment related files, matching by 'peering_exampleassessment' itemname
        $this->add_related_files('mod_peering', 'overallfeedback_content', 'peering_exampleassessment');
        $this->add_related_files('mod_peering', 'overallfeedback_attachment', 'peering_exampleassessment');

        // Add submission related files, matching by 'peering_submission' itemname
        $this->add_related_files('mod_peering', 'submission_content', 'peering_submission');
        $this->add_related_files('mod_peering', 'submission_attachment', 'peering_submission');

        // Add assessment related files, matching by 'peering_assessment' itemname
        $this->add_related_files('mod_peering', 'overallfeedback_content', 'peering_assessment');
        $this->add_related_files('mod_peering', 'overallfeedback_attachment', 'peering_assessment');
    }
}
