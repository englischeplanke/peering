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
 * Defines all the backup steps that will be used by {@link backup_peering_activity_task}
 *
 * @package     mod_peering
 * @category    backup
 * @copyright   2010 David Mudrak <david.mudrak@gmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Defines the complete peering structure for backup, with file and id annotations
 *
 * @link http://docs.moodle.org/dev/peering for XML structure diagram
 */
class backup_peering_activity_structure_step extends backup_activity_structure_step {

    /**
     * Defines the structure of the 'peering' element inside the peering.xml file
     *
     * @return backup_nested_element
     */
    protected function define_structure() {

        // are we including userinfo?
        $userinfo = $this->get_setting_value('userinfo');

        ////////////////////////////////////////////////////////////////////////
        // XML nodes declaration - non-user data
        ////////////////////////////////////////////////////////////////////////

        // root element describing peering instance
        $peering = new backup_nested_element('peering', array('id'), array(
            'name', 'intro', 'introformat', 'instructauthors',
            'instructauthorsformat', 'instructreviewers',
            'instructreviewersformat', 'timemodified', 'phase', 'useexamples',
            'usepeerassessment', 'useselfassessment', 'grade', 'gradinggrade',
            'strategy', 'evaluation', 'gradedecimals', 'submissiontypetext', 'submissiontypefile', 'nattachments',
            'submissionfiletypes', 'latesubmissions', 'maxbytes', 'examplesmode', 'submissionstart',
            'submissionend', 'assessmentstart', 'assessmentend',
            'conclusion', 'conclusionformat', 'overallfeedbackmode',
            'overallfeedbackfiles', 'overallfeedbackfiletypes', 'overallfeedbackmaxbytes'));

        // assessment forms definition
        $this->add_subplugin_structure('peeringform', $peering, true);

        // grading evaluations data
        $this->add_subplugin_structure('peeringeval', $peering, true);

        // example submissions
        $examplesubmissions = new backup_nested_element('examplesubmissions');
        $examplesubmission  = new backup_nested_element('examplesubmission', array('id'), array(
            'timecreated', 'timemodified', 'title', 'content', 'contentformat',
            'contenttrust', 'attachment'));

        // reference assessment of the example submission
        $referenceassessment  = new backup_nested_element('referenceassessment', array('id'), array(
            'timecreated', 'timemodified', 'grade', 'feedbackauthor', 'feedbackauthorformat',
            'feedbackauthorattachment'));

        // dimension grades for the reference assessment (that is how the form is filled)
        $this->add_subplugin_structure('peeringform', $referenceassessment, true);

        ////////////////////////////////////////////////////////////////////////
        // XML nodes declaration - user data
        ////////////////////////////////////////////////////////////////////////

        // assessments of example submissions
        $exampleassessments = new backup_nested_element('exampleassessments');
        $exampleassessment  = new backup_nested_element('exampleassessment', array('id'), array(
            'reviewerid', 'weight', 'timecreated', 'timemodified', 'grade',
            'gradinggrade', 'gradinggradeover', 'gradinggradeoverby',
            'feedbackauthor', 'feedbackauthorformat', 'feedbackauthorattachment',
            'feedbackreviewer', 'feedbackreviewerformat'));

        // dimension grades for the assessment of example submission (that is assessment forms are filled)
        $this->add_subplugin_structure('peeringform', $exampleassessment, true);

        // submissions
        $submissions = new backup_nested_element('submissions');
        $submission  = new backup_nested_element('submission', array('id'), array(
            'authorid', 'timecreated', 'timemodified', 'title', 'content',
            'contentformat', 'contenttrust', 'attachment', 'grade',
            'gradeover', 'gradeoverby', 'feedbackauthor',
            'feedbackauthorformat', 'timegraded', 'published', 'late'));

        // allocated assessments
        $assessments = new backup_nested_element('assessments');
        $assessment  = new backup_nested_element('assessment', array('id'), array(
            'reviewerid', 'weight', 'timecreated', 'timemodified', 'grade',
            'gradinggrade', 'gradinggradeover', 'gradinggradeoverby',
            'feedbackauthor', 'feedbackauthorformat', 'feedbackauthorattachment',
            'feedbackreviewer', 'feedbackreviewerformat'));

        // dimension grades for the assessment (that is assessment forms are filled)
        $this->add_subplugin_structure('peeringform', $assessment, true);

        // aggregations of grading grades in this peering
        $aggregations = new backup_nested_element('aggregations');
        $aggregation = new backup_nested_element('aggregation', array('id'), array(
            'userid', 'gradinggrade', 'timegraded'));

        ////////////////////////////////////////////////////////////////////////
        // build the tree in the order needed for restore
        ////////////////////////////////////////////////////////////////////////
        $peering->add_child($examplesubmissions);
        $examplesubmissions->add_child($examplesubmission);

        $examplesubmission->add_child($referenceassessment);

        $examplesubmission->add_child($exampleassessments);
        $exampleassessments->add_child($exampleassessment);

        $peering->add_child($submissions);
        $submissions->add_child($submission);

        $submission->add_child($assessments);
        $assessments->add_child($assessment);

        $peering->add_child($aggregations);
        $aggregations->add_child($aggregation);

        ////////////////////////////////////////////////////////////////////////
        // data sources - non-user data
        ////////////////////////////////////////////////////////////////////////

        $peering->set_source_table('peering', array('id' => backup::VAR_ACTIVITYID));

        $examplesubmission->set_source_sql("
            SELECT *
              FROM {peering_submissions}
             WHERE peeringid = ? AND example = 1",
            array(backup::VAR_PARENTID));

        $referenceassessment->set_source_sql("
            SELECT *
              FROM {peering_assessments}
             WHERE weight = 1 AND submissionid = ?",
            array(backup::VAR_PARENTID));

        ////////////////////////////////////////////////////////////////////////
        // data sources - user related data
        ////////////////////////////////////////////////////////////////////////

        if ($userinfo) {

            $exampleassessment->set_source_sql("
                SELECT *
                  FROM {peering_assessments}
                 WHERE weight = 0 AND submissionid = ?",
                array(backup::VAR_PARENTID));

            $submission->set_source_sql("
                SELECT *
                  FROM {peering_submissions}
                 WHERE peeringid = ? AND example = 0",
                 array(backup::VAR_PARENTID));  // must use SQL here, for the same reason as above

            $assessment->set_source_table('peering_assessments', array('submissionid' => backup::VAR_PARENTID));

            $aggregation->set_source_table('peering_aggregations', array('peeringid' => backup::VAR_PARENTID));
        }

        ////////////////////////////////////////////////////////////////////////
        // id annotations
        ////////////////////////////////////////////////////////////////////////

        $exampleassessment->annotate_ids('user', 'reviewerid');
        $submission->annotate_ids('user', 'authorid');
        $submission->annotate_ids('user', 'gradeoverby');
        $assessment->annotate_ids('user', 'reviewerid');
        $assessment->annotate_ids('user', 'gradinggradeoverby');
        $aggregation->annotate_ids('user', 'userid');

        ////////////////////////////////////////////////////////////////////////
        // file annotations
        ////////////////////////////////////////////////////////////////////////

        $peering->annotate_files('mod_peering', 'intro', null); // no itemid used
        $peering->annotate_files('mod_peering', 'instructauthors', null); // no itemid used
        $peering->annotate_files('mod_peering', 'instructreviewers', null); // no itemid used
        $peering->annotate_files('mod_peering', 'conclusion', null); // no itemid used

        $examplesubmission->annotate_files('mod_peering', 'submission_content', 'id');
        $examplesubmission->annotate_files('mod_peering', 'submission_attachment', 'id');

        $referenceassessment->annotate_files('mod_peering', 'overallfeedback_content', 'id');
        $referenceassessment->annotate_files('mod_peering', 'overallfeedback_attachment', 'id');

        $exampleassessment->annotate_files('mod_peering', 'overallfeedback_content', 'id');
        $exampleassessment->annotate_files('mod_peering', 'overallfeedback_attachment', 'id');

        $submission->annotate_files('mod_peering', 'submission_content', 'id');
        $submission->annotate_files('mod_peering', 'submission_attachment', 'id');

        $assessment->annotate_files('mod_peering', 'overallfeedback_content', 'id');
        $assessment->annotate_files('mod_peering', 'overallfeedback_attachment', 'id');

        // return the root element (peering), wrapped into standard activity structure
        return $this->prepare_activity_structure($peering);
    }
}
