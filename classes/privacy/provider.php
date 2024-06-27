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
 * Defines {@link \mod_peering\privacy\provider} class.
 *
 * @package     mod_peering
 * @category    privacy
 * @copyright   2018 David Mudrák <david@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_peering\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\deletion_criteria;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/mod/peering/locallib.php');

/**
 * Privacy API implementation for the peering activity module.
 *
 * @copyright 2018 David Mudrák <david@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_userlist_provider,
        \core_privacy\local\request\user_preference_provider,
        \core_privacy\local\request\plugin\provider {

    /**
     * Describe all the places where the peering module stores some personal data.
     *
     * @param collection $collection Collection of items to add metadata to.
     * @return collection Collection with our added items.
     */
    public static function get_metadata(collection $collection) : collection {

        $collection->add_database_table('peering_submissions', [
            'peeringid' => 'privacy:metadata:peeringid',
            'authorid' => 'privacy:metadata:authorid',
            'example' => 'privacy:metadata:example',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
            'title' => 'privacy:metadata:submissiontitle',
            'content' => 'privacy:metadata:submissioncontent',
            'contentformat' => 'privacy:metadata:submissioncontentformat',
            'grade' => 'privacy:metadata:submissiongrade',
            'gradeover' => 'privacy:metadata:submissiongradeover',
            'feedbackauthor' => 'privacy:metadata:feedbackauthor',
            'feedbackauthorformat' => 'privacy:metadata:feedbackauthorformat',
            'published' => 'privacy:metadata:published',
            'late' => 'privacy:metadata:late',
        ], 'privacy:metadata:peeringsubmissions');

        $collection->add_database_table('peering_assessments', [
            'submissionid' => 'privacy:metadata:submissionid',
            'reviewerid' => 'privacy:metadata:reviewerid',
            'weight' => 'privacy:metadata:weight',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
            'grade' => 'privacy:metadata:assessmentgrade',
            'gradinggrade' => 'privacy:metadata:assessmentgradinggrade',
            'gradinggradeover' => 'privacy:metadata:assessmentgradinggradeover',
            'feedbackauthor' => 'privacy:metadata:feedbackauthor',
            'feedbackauthorformat' => 'privacy:metadata:feedbackauthorformat',
            'feedbackreviewer' => 'privacy:metadata:feedbackreviewer',
            'feedbackreviewerformat' => 'privacy:metadata:feedbackreviewerformat',
        ], 'privacy:metadata:peeringassessments');

        $collection->add_database_table('peering_grades', [
            'assessmentid' => 'privacy:metadata:assessmentid',
            'strategy' => 'privacy:metadata:strategy',
            'dimensionid' => 'privacy:metadata:dimensionid',
            'grade' => 'privacy:metadata:dimensiongrade',
            'peercomment' => 'privacy:metadata:peercomment',
            'peercommentformat' => 'privacy:metadata:peercommentformat',
        ], 'privacy:metadata:peeringgrades');

        $collection->add_database_table('peering_aggregations', [
            'peeringid' => 'privacy:metadata:peeringid',
            'userid' => 'privacy:metadata:userid',
            'gradinggrade' => 'privacy:metadata:aggregatedgradinggrade',
            'timegraded' => 'privacy:metadata:timeaggregated',
        ], 'privacy:metadata:peeringaggregations');

        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:subsystem:corefiles');
        $collection->add_subsystem_link('core_plagiarism', [], 'privacy:metadata:subsystem:coreplagiarism');

        $userprefs = self::get_user_prefs();
        foreach ($userprefs as $userpref) {
            if ($userpref === 'peering_perpage') {
                $collection->add_user_preference('peering_perpage', 'privacy:metadata:preference:perpage');
            } else {
                $summary = str_replace('peering-', '', $userpref);
                $collection->add_user_preference($userpref, "privacy:metadata:preference:$summary");
            }
        }

        return $collection;
    }

    /**
     * Get the list of contexts that contain personal data for the specified user.
     *
     * User has personal data in the peering if any of the following cases happens:
     *
     * - the user has submitted in the peering
     * - the user has overridden a submission grade
     * - the user has been assigned as a reviewer of a submission
     * - the user has overridden a grading grade
     * - the user has a grading grade (existing or to be calculated)
     *
     * @param int $userid ID of the user.
     * @return contextlist List of contexts containing the user's personal data.
     */
    public static function get_contexts_for_userid(int $userid) : contextlist {

        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON cm.module = m.id AND m.name = :module
                  JOIN {context} ctx ON ctx.contextlevel = :contextlevel AND ctx.instanceid = cm.id
                  JOIN {peering} w ON cm.instance = w.id
             LEFT JOIN {peering_submissions} ws ON ws.peeringid = w.id
             LEFT JOIN {peering_assessments} wa ON wa.submissionid = ws.id AND (
                    wa.reviewerid = :wareviewerid
                        OR
                    wa.gradinggradeoverby = :wagradinggradeoverby
                )
             LEFT JOIN {peering_aggregations} wr ON wr.peeringid = w.id AND wr.userid = :wruserid
                 WHERE ws.authorid = :wsauthorid
                    OR ws.gradeoverby = :wsgradeoverby
                    OR wa.id IS NOT NULL
                    OR wr.id IS NOT NULL";

        $params = [
            'module' => 'peering',
            'contextlevel' => CONTEXT_MODULE,
            'wsauthorid' => $userid,
            'wsgradeoverby' => $userid,
            'wareviewerid' => $userid,
            'wagradinggradeoverby' => $userid,
            'wruserid' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users within a specific context.
     *
     * @param userlist $userlist To be filled list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $params = [
            'instanceid' => $context->instanceid,
            'module' => 'peering',
        ];

        // One query to fetch them all, one query to find them, one query to bring them all and into the userlist add them.
        $sql = "SELECT ws.authorid, ws.gradeoverby, wa.reviewerid, wa.gradinggradeoverby, wr.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON cm.module = m.id AND m.name = :module
                  JOIN {peering} w ON cm.instance = w.id
                  JOIN {peering_submissions} ws ON ws.peeringid = w.id
             LEFT JOIN {peering_assessments} wa ON wa.submissionid = ws.id
             LEFT JOIN {peering_aggregations} wr ON wr.peeringid = w.id
                 WHERE cm.id = :instanceid";

        $userids = [];
        $rs = $DB->get_recordset_sql($sql, $params);

        foreach ($rs as $r) {
            if ($r->authorid) {
                $userids[$r->authorid] = true;
            }
            if ($r->gradeoverby) {
                $userids[$r->gradeoverby] = true;
            }
            if ($r->reviewerid) {
                $userids[$r->reviewerid] = true;
            }
            if ($r->gradinggradeoverby) {
                $userids[$r->gradinggradeoverby] = true;
            }
            if ($r->userid) {
                $userids[$r->userid] = true;
            }
        }

        $rs->close();

        if ($userids) {
            $userlist->add_users(array_keys($userids));
        }
    }

    /**
     * Export personal data stored in the given contexts.
     *
     * @param approved_contextlist $contextlist List of contexts approved for export.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (!count($contextlist)) {
            return;
        }

        $user = $contextlist->get_user();

        // Export general information about all peerings.
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            $data = helper::get_context_data($context, $user);
            static::append_extra_peering_data($context, $user, $data, []);
            writer::with_context($context)->export_data([], $data);
            helper::export_context_files($context, $user);
        }

        // Export the user's own submission and all example submissions he/she created.
        static::export_submissions($contextlist);

        // Export all given assessments.
        static::export_assessments($contextlist);
    }

    /**
     * Export user preferences controlled by this plugin.
     *
     * @param int $userid ID of the user we are exporting data for
     */
    public static function export_user_preferences(int $userid) {
        $userprefs = self::get_user_prefs();
        $expandstr = get_string('expand');
        $collapsestr = get_string('collapse');
        foreach ($userprefs as $userpref) {
            $userprefval = get_user_preferences($userpref, null, $userid);
            if ($userprefval !== null) {
                $langid = str_replace('peering-', '', $userpref);
                $description = get_string("privacy:metadata:preference:$langid", 'mod_peering');
                if ($userpref === 'peering_perpage') {
                    writer::export_user_preference('mod_peering', $userpref, $userprefval,
                            get_string('privacy:metadata:preference:perpage', 'mod_peering'));
                } else {
                    writer::export_user_preference('mod_peering', $userpref,
                        $userprefval == 1 ? $collapsestr : $expandstr, $description);
                }
            }
        }
    }

    /**
     * Append additional relevant data into the base data about the peering instance.
     *
     * Relevant are data that are important for interpreting or evaluating the performance of the user expressed in
     * his/her exported personal data. For example, we need to know what were the instructions for submissions or what
     * was the phase of the peering when it was exported.
     *
     * @param context $context peering module content.
     * @param stdClass $user User for which we are exporting data.
     * @param stdClass $data Base data about the peering instance to append to.
     * @param array $subcontext Subcontext path items to eventually write files into.
     */
    protected static function append_extra_peering_data(\context $context, \stdClass $user, \stdClass $data, array $subcontext) {
        global $DB;

        if ($context->contextlevel != CONTEXT_MODULE) {
            throw new \coding_exception('Unexpected context provided');
        }

        $sql = "SELECT w.instructauthors, w.instructauthorsformat, w.instructreviewers, w.instructreviewersformat, w.phase,
                       w.strategy, w.evaluation, w.latesubmissions, w.submissionstart, w.submissionend, w.assessmentstart,
                       w.assessmentend, w.conclusion, w.conclusionformat
                  FROM {course_modules} cm
                  JOIN {peering} w ON cm.instance = w.id
                 WHERE cm.id = :cmid";

        $params = [
            'cmid' => $context->instanceid,
        ];

        $record = $DB->get_record_sql($sql, $params, MUST_EXIST);
        $writer = writer::with_context($context);

        if ($record->phase >= \peering::PHASE_SUBMISSION) {
            $data->instructauthors = $writer->rewrite_pluginfile_urls($subcontext, 'mod_peering', 'instructauthors', 0,
                $record->instructauthors);
            $data->instructauthorsformat = $record->instructauthorsformat;
        }

        if ($record->phase >= \peering::PHASE_ASSESSMENT) {
            $data->instructreviewers = $writer->rewrite_pluginfile_urls($subcontext, 'mod_peering', 'instructreviewers', 0,
                $record->instructreviewers);
            $data->instructreviewersformat = $record->instructreviewersformat;
        }

        if ($record->phase >= \peering::PHASE_CLOSED) {
            $data->conclusion = $writer->rewrite_pluginfile_urls($subcontext, 'mod_peering', 'conclusion', 0, $record->conclusion);
            $data->conclusionformat = $record->conclusionformat;
        }

        $data->strategy = \peering::available_strategies_list()[$record->strategy];
        $data->evaluation = \peering::available_evaluators_list()[$record->evaluation];
        $data->latesubmissions = transform::yesno($record->latesubmissions);
        $data->submissionstart = $record->submissionstart ? transform::datetime($record->submissionstart) : null;
        $data->submissionend = $record->submissionend ? transform::datetime($record->submissionend) : null;
        $data->assessmentstart = $record->assessmentstart ? transform::datetime($record->assessmentstart) : null;
        $data->assessmentend = $record->assessmentend ? transform::datetime($record->assessmentend) : null;

        switch ($record->phase) {
            case \peering::PHASE_SETUP:
                $data->phase = get_string('phasesetup', 'mod_peering');
                break;
            case \peering::PHASE_SUBMISSION:
                $data->phase = get_string('phasesubmission', 'mod_peering');
                break;
            case \peering::PHASE_ASSESSMENT:
                $data->phase = get_string('phaseassessment', 'mod_peering');
                break;
            case \peering::PHASE_EVALUATION:
                $data->phase = get_string('phaseevaluation', 'mod_peering');
                break;
            case \peering::PHASE_CLOSED:
                $data->phase = get_string('phaseclosed', 'mod_peering');
                break;
        }

        $writer->export_area_files($subcontext, 'mod_peering', 'instructauthors', 0);
        $writer->export_area_files($subcontext, 'mod_peering', 'instructreviewers', 0);
        $writer->export_area_files($subcontext, 'mod_peering', 'conclusion', 0);
    }

    /**
     * Export all user's submissions and example submissions he/she created in the given contexts.
     *
     * @param approved_contextlist $contextlist List of contexts approved for export.
     */
    protected static function export_submissions(approved_contextlist $contextlist) {
        global $DB;

        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);
        $user = $contextlist->get_user();

        $sql = "SELECT ws.id, ws.authorid, ws.example, ws.timecreated, ws.timemodified, ws.title,
                       ws.content, ws.contentformat, ws.grade, ws.gradeover, ws.feedbackauthor, ws.feedbackauthorformat,
                       ws.published, ws.late,
                       w.phase, w.course, cm.id AS cmid, ".\context_helper::get_preload_record_columns_sql('ctx')."
                  FROM {course_modules} cm
                  JOIN {modules} m ON cm.module = m.id AND m.name = :module
                  JOIN {context} ctx ON ctx.contextlevel = :contextlevel AND ctx.instanceid = cm.id
                  JOIN {peering} w ON cm.instance = w.id
                  JOIN {peering_submissions} ws ON ws.peeringid = w.id AND ws.authorid = :authorid
                 WHERE ctx.id {$contextsql}";

        $params = $contextparams + [
            'module' => 'peering',
            'contextlevel' => CONTEXT_MODULE,
            'authorid' => $user->id,
        ];

        $rs = $DB->get_recordset_sql($sql, $params);

        foreach ($rs as $record) {
            \context_helper::preload_from_record($record);
            $context = \context_module::instance($record->cmid);
            $writer = \core_privacy\local\request\writer::with_context($context);

            if ($record->example) {
                $subcontext = [get_string('examplesubmissions', 'mod_peering'), $record->id];
                $mysubmission = null;
            } else {
                $subcontext = [get_string('mysubmission', 'mod_peering')];
                $mysubmission = $record;
            }

            $phase = $record->phase;
            $courseid = $record->course;

            $data = (object) [
                'example' => transform::yesno($record->example),
                'timecreated' => transform::datetime($record->timecreated),
                'timemodified' => $record->timemodified ? transform::datetime($record->timemodified) : null,
                'title' => $record->title,
                'content' => $writer->rewrite_pluginfile_urls($subcontext, 'mod_peering',
                    'submission_content', $record->id, $record->content),
                'contentformat' => $record->contentformat,
                'grade' => $record->grade,
                'gradeover' => $record->gradeover,
                'feedbackauthor' => $record->feedbackauthor,
                'feedbackauthorformat' => $record->feedbackauthorformat,
                'published' => transform::yesno($record->published),
                'late' => transform::yesno($record->late),
            ];

            $writer->export_data($subcontext, $data);
            $writer->export_area_files($subcontext, 'mod_peering', 'submission_content', $record->id);
            $writer->export_area_files($subcontext, 'mod_peering', 'submission_attachment', $record->id);

            // Export peer-assessments of my submission if the peering was closed. We do not export received
            // assessments from peers before they were actually effective. Before the peering is closed, grades are not
            // pushed into the gradebook. So peer assessments did not affect evaluation of the user's performance and
            // they should not be considered as their personal data. This is different from assessments given by the
            // user that are always exported.
            if ($mysubmission && $phase == \peering::PHASE_CLOSED) {
                $assessments = $DB->get_records('peering_assessments', ['submissionid' => $mysubmission->id], '',
                    'id, reviewerid, weight, timecreated, timemodified, grade, feedbackauthor, feedbackauthorformat');

                foreach ($assessments as $assessment) {
                    $assid = $assessment->id;
                    $assessment->selfassessment = transform::yesno($assessment->reviewerid == $user->id);
                    $assessment->timecreated = transform::datetime($assessment->timecreated);
                    $assessment->timemodified = $assessment->timemodified ? transform::datetime($assessment->timemodified) : null;
                    $assessment->feedbackauthor = $writer->rewrite_pluginfile_urls($subcontext,
                        'mod_peering', 'overallfeedback_content', $assid, $assessment->feedbackauthor);

                    $assessmentsubcontext = array_merge($subcontext, [get_string('assessments', 'mod_peering'), $assid]);

                    unset($assessment->id);
                    unset($assessment->reviewerid);

                    $writer->export_data($assessmentsubcontext, $assessment);
                    $writer->export_area_files($assessmentsubcontext, 'mod_peering', 'overallfeedback_content', $assid);
                    $writer->export_area_files($assessmentsubcontext, 'mod_peering', 'overallfeedback_attachment', $assid);

                    // Export details of how the assessment forms were filled.
                    static::export_assessment_forms($user, $context, $assessmentsubcontext, $assid);
                }
            }

            // Export plagiarism data related to the submission content.
            // The last $linkarray argument consistent with how we call {@link plagiarism_get_links()} in the renderer.
            \core_plagiarism\privacy\provider::export_plagiarism_user_data($user->id, $context, $subcontext, [
                'userid' => $user->id,
                'content' => format_text($data->content, $data->contentformat, ['overflowdiv' => true]),
                'cmid' => $context->instanceid,
                'course' => $courseid,
            ]);
        }

        $rs->close();
    }

    /**
     * Export all assessments given by the user.
     *
     * @param approved_contextlist $contextlist List of contexts approved for export.
     */
    protected static function export_assessments(approved_contextlist $contextlist) {
        global $DB;

        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);
        $user = $contextlist->get_user();

        $sql = "SELECT ws.authorid, ws.example, ws.timecreated, ws.timemodified, ws.title, ws.content, ws.contentformat,
                       wa.id, wa.submissionid, wa.reviewerid, wa.weight, wa.timecreated, wa.timemodified, wa.grade,
                       wa.gradinggrade, wa.gradinggradeover, wa.feedbackauthor, wa.feedbackauthorformat, wa.feedbackreviewer,
                       wa.feedbackreviewerformat, cm.id AS cmid, ".\context_helper::get_preload_record_columns_sql('ctx')."
                  FROM {course_modules} cm
                  JOIN {modules} m ON cm.module = m.id AND m.name = :module
                  JOIN {context} ctx ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {peering} w ON cm.instance = w.id
                  JOIN {peering_submissions} ws ON ws.peeringid = w.id
                  JOIN {peering_assessments} wa ON wa.submissionid = ws.id AND wa.reviewerid = :reviewerid
                 WHERE ctx.id {$contextsql}";

        $params = $contextparams + [
            'module' => 'peering',
            'contextlevel' => CONTEXT_MODULE,
            'reviewerid' => $user->id,
        ];

        $rs = $DB->get_recordset_sql($sql, $params);

        foreach ($rs as $record) {
            \context_helper::preload_from_record($record);
            $context = \context_module::instance($record->cmid);
            $writer = \core_privacy\local\request\writer::with_context($context);
            $subcontext = [get_string('myassessments', 'mod_peering'), $record->id];

            $data = (object) [
                'weight' => $record->weight,
                'timecreated' => transform::datetime($record->timecreated),
                'timemodified' => $record->timemodified ? transform::datetime($record->timemodified) : null,
                'grade' => $record->grade,
                'gradinggrade' => $record->gradinggrade,
                'gradinggradeover' => $record->gradinggradeover,
                'feedbackauthor' => $writer->rewrite_pluginfile_urls($subcontext, 'mod_peering',
                    'overallfeedback_content', $record->id, $record->feedbackauthor),
                'feedbackauthorformat' => $record->feedbackauthorformat,
                'feedbackreviewer' => $record->feedbackreviewer,
                'feedbackreviewerformat' => $record->feedbackreviewerformat,
            ];

            $submission = (object) [
                'myownsubmission' => transform::yesno($record->authorid == $user->id),
                'example' => transform::yesno($record->example),
                'timecreated' => transform::datetime($record->timecreated),
                'timemodified' => $record->timemodified ? transform::datetime($record->timemodified) : null,
                'title' => $record->title,
                'content' => $writer->rewrite_pluginfile_urls($subcontext, 'mod_peering',
                    'submission_content', $record->submissionid, $record->content),
                'contentformat' => $record->contentformat,
            ];

            $writer->export_data($subcontext, $data);
            $writer->export_related_data($subcontext, 'submission', $submission);
            $writer->export_area_files($subcontext, 'mod_peering', 'overallfeedback_content', $record->id);
            $writer->export_area_files($subcontext, 'mod_peering', 'overallfeedback_attachment', $record->id);
            $writer->export_area_files($subcontext, 'mod_peering', 'submission_content', $record->submissionid);
            $writer->export_area_files($subcontext, 'mod_peering', 'submission_attachment', $record->submissionid);

            // Export details of how the assessment forms were filled.
            static::export_assessment_forms($user, $context, $subcontext, $record->id);
        }

        $rs->close();
    }

    /**
     * Export the grading strategy data related to the particular assessment.
     *
     * @param stdClass $user User we are exporting for
     * @param context $context peering activity content
     * @param array $subcontext Subcontext path of the assessment
     * @param int $assessmentid ID of the exported assessment
     */
    protected static function export_assessment_forms(\stdClass $user, \context $context, array $subcontext, int $assessmentid) {

        foreach (\peering::available_strategies_list() as $strategy => $title) {
            $providername = '\peeringform_'.$strategy.'\privacy\provider';

            if (is_subclass_of($providername, '\mod_peering\privacy\peeringform_provider')) {
                component_class_callback($providername, 'export_assessment_form',
                    [
                        $user,
                        $context,
                        array_merge($subcontext, [get_string('assessmentform', 'mod_peering'), $title]),
                        $assessmentid,
                    ]
                );

            } else {
                debugging('Missing class '.$providername.' implementing peeringform_provider interface', DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Delete personal data for all users in the context.
     *
     * @param context $context Context to delete personal data from.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $CFG, $DB;
        require_once($CFG->libdir.'/gradelib.php');

        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('peering', $context->instanceid, 0, false, IGNORE_MISSING);

        if (!$cm) {
            // Probably some kind of expired context.
            return;
        }

        $peering = $DB->get_record('peering', ['id' => $cm->instance], 'id, course', MUST_EXIST);

        $submissions = $DB->get_records('peering_submissions', ['peeringid' => $peering->id], '', 'id');
        $assessments = $DB->get_records_list('peering_assessments', 'submissionid', array_keys($submissions), '', 'id');

        $DB->delete_records('peering_aggregations', ['peeringid' => $peering->id]);
        $DB->delete_records_list('peering_grades', 'assessmentid', array_keys($assessments));
        $DB->delete_records_list('peering_assessments', 'id', array_keys($assessments));
        $DB->delete_records_list('peering_submissions', 'id', array_keys($submissions));

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_peering', 'submission_content');
        $fs->delete_area_files($context->id, 'mod_peering', 'submission_attachment');
        $fs->delete_area_files($context->id, 'mod_peering', 'overallfeedback_content');
        $fs->delete_area_files($context->id, 'mod_peering', 'overallfeedback_attachment');

        grade_update('mod/peering', $peering->course, 'mod', 'peering', $peering->id, 0, null, ['reset' => true]);
        grade_update('mod/peering', $peering->course, 'mod', 'peering', $peering->id, 1, null, ['reset' => true]);

        \core_plagiarism\privacy\provider::delete_plagiarism_for_context($context);
    }

    /**
     * Delete personal data for the user in a list of contexts.
     *
     * Removing assessments of submissions from the peering is not trivial. Removing one user's data can easily affect
     * other users' grades and completion criteria. So we replace the non-essential contents with a "deleted" message,
     * but keep the actual info in place. The argument is that one's right for privacy should not overweight others'
     * right for accessing their own personal data and be evaluated on their basis.
     *
     * @param approved_contextlist $contextlist List of contexts to delete data from.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);
        $user = $contextlist->get_user();
        $fs = get_file_storage();

        // Replace sensitive data in all submissions by the user in the given contexts.

        $sql = "SELECT ws.id AS submissionid
                  FROM {course_modules} cm
                  JOIN {modules} m ON cm.module = m.id AND m.name = :module
                  JOIN {context} ctx ON ctx.contextlevel = :contextlevel AND ctx.instanceid = cm.id
                  JOIN {peering} w ON cm.instance = w.id
                  JOIN {peering_submissions} ws ON ws.peeringid = w.id AND ws.authorid = :authorid
                 WHERE ctx.id {$contextsql}";

        $params = $contextparams + [
            'module' => 'peering',
            'contextlevel' => CONTEXT_MODULE,
            'authorid' => $user->id,
        ];

        $submissionids = $DB->get_fieldset_sql($sql, $params);

        if ($submissionids) {
            list($submissionidsql, $submissionidparams) = $DB->get_in_or_equal($submissionids, SQL_PARAMS_NAMED);

            $DB->set_field_select('peering_submissions', 'title', get_string('privacy:request:delete:title',
                'mod_peering'), "id $submissionidsql", $submissionidparams);
            $DB->set_field_select('peering_submissions', 'content', get_string('privacy:request:delete:content',
                'mod_peering'), "id $submissionidsql", $submissionidparams);
            $DB->set_field_select('peering_submissions', 'feedbackauthor', get_string('privacy:request:delete:content',
                'mod_peering'), "id $submissionidsql", $submissionidparams);

            foreach ($contextlist->get_contextids() as $contextid) {
                $fs->delete_area_files_select($contextid, 'mod_peering', 'submission_content',
                    $submissionidsql, $submissionidparams);
                $fs->delete_area_files_select($contextid, 'mod_peering', 'submission_attachment',
                    $submissionidsql, $submissionidparams);
            }
        }

        // Replace personal data in received assessments - feedback is seen as belonging to the recipient.

        $sql = "SELECT wa.id AS assessmentid
                  FROM {course_modules} cm
                  JOIN {modules} m ON cm.module = m.id AND m.name = :module
                  JOIN {context} ctx ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {peering} w ON cm.instance = w.id
                  JOIN {peering_submissions} ws ON ws.peeringid = w.id AND ws.authorid = :authorid
                  JOIN {peering_assessments} wa ON wa.submissionid = ws.id
                 WHERE ctx.id {$contextsql}";

        $params = $contextparams + [
            'module' => 'peering',
            'contextlevel' => CONTEXT_MODULE,
            'authorid' => $user->id,
        ];

        $assessmentids = $DB->get_fieldset_sql($sql, $params);

        if ($assessmentids) {
            list($assessmentidsql, $assessmentidparams) = $DB->get_in_or_equal($assessmentids, SQL_PARAMS_NAMED);

            $DB->set_field_select('peering_assessments', 'feedbackauthor', get_string('privacy:request:delete:content',
                'mod_peering'), "id $assessmentidsql", $assessmentidparams);

            foreach ($contextlist->get_contextids() as $contextid) {
                $fs->delete_area_files_select($contextid, 'mod_peering', 'overallfeedback_content',
                    $assessmentidsql, $assessmentidparams);
                $fs->delete_area_files_select($contextid, 'mod_peering', 'overallfeedback_attachment',
                    $assessmentidsql, $assessmentidparams);
            }
        }

        // Replace sensitive data in provided assessments records.

        $sql = "SELECT wa.id AS assessmentid
                  FROM {course_modules} cm
                  JOIN {modules} m ON cm.module = m.id AND m.name = :module
                  JOIN {context} ctx ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {peering} w ON cm.instance = w.id
                  JOIN {peering_submissions} ws ON ws.peeringid = w.id
                  JOIN {peering_assessments} wa ON wa.submissionid = ws.id AND wa.reviewerid = :reviewerid
                 WHERE ctx.id {$contextsql}";

        $params = $contextparams + [
            'module' => 'peering',
            'contextlevel' => CONTEXT_MODULE,
            'reviewerid' => $user->id,
        ];

        $assessmentids = $DB->get_fieldset_sql($sql, $params);

        if ($assessmentids) {
            list($assessmentidsql, $assessmentidparams) = $DB->get_in_or_equal($assessmentids, SQL_PARAMS_NAMED);

            $DB->set_field_select('peering_assessments', 'feedbackreviewer', get_string('privacy:request:delete:content',
                'mod_peering'), "id $assessmentidsql", $assessmentidparams);
        }

        foreach ($contextlist as $context) {
            \core_plagiarism\privacy\provider::delete_plagiarism_for_user($user->id, $context);
        }
    }

    /**
     * Delete personal data for multiple users within a single peering context.
     *
     * See documentation for {@link self::delete_data_for_user()} for more details on what we do and don't actually
     * delete and why.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $fs = get_file_storage();

        if ($context->contextlevel != CONTEXT_MODULE) {
            // This should not happen but let's be double sure when it comes to deleting data.
            return;
        }

        $cm = get_coursemodule_from_id('peering', $context->instanceid, 0, false, IGNORE_MISSING);

        if (!$cm) {
            // Probably some kind of expired context.
            return;
        }

        $userids = $userlist->get_userids();

        if (!$userids) {
            return;
        }

        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // Erase sensitive data in all submissions by all the users in the given context.

        $sql = "SELECT ws.id AS submissionid
                  FROM {peering} w
                  JOIN {peering_submissions} ws ON ws.peeringid = w.id
                 WHERE w.id = :peeringid AND ws.authorid $usersql";

        $params = $userparams + [
            'peeringid' => $cm->instance,
        ];

        $submissionids = $DB->get_fieldset_sql($sql, $params);

        if ($submissionids) {
            list($submissionidsql, $submissionidparams) = $DB->get_in_or_equal($submissionids, SQL_PARAMS_NAMED);

            $DB->set_field_select('peering_submissions', 'title', get_string('privacy:request:delete:title',
                'mod_peering'), "id $submissionidsql", $submissionidparams);
            $DB->set_field_select('peering_submissions', 'content', get_string('privacy:request:delete:content',
                'mod_peering'), "id $submissionidsql", $submissionidparams);
            $DB->set_field_select('peering_submissions', 'feedbackauthor', get_string('privacy:request:delete:content',
                'mod_peering'), "id $submissionidsql", $submissionidparams);

            $fs->delete_area_files_select($context->id, 'mod_peering', 'submission_content',
                $submissionidsql, $submissionidparams);
            $fs->delete_area_files_select($context->id, 'mod_peering', 'submission_attachment',
                $submissionidsql, $submissionidparams);
        }

        // Erase personal data in received assessments - feedback is seen as belonging to the recipient.

        $sql = "SELECT wa.id AS assessmentid
                  FROM {peering} w
                  JOIN {peering_submissions} ws ON ws.peeringid = w.id
                  JOIN {peering_assessments} wa ON wa.submissionid = ws.id
                 WHERE w.id = :peeringid AND ws.authorid $usersql";

        $params = $userparams + [
            'peeringid' => $cm->instance,
        ];

        $assessmentids = $DB->get_fieldset_sql($sql, $params);

        if ($assessmentids) {
            list($assessmentidsql, $assessmentidparams) = $DB->get_in_or_equal($assessmentids, SQL_PARAMS_NAMED);

            $DB->set_field_select('peering_assessments', 'feedbackauthor', get_string('privacy:request:delete:content',
                'mod_peering'), "id $assessmentidsql", $assessmentidparams);

            $fs->delete_area_files_select($context->id, 'mod_peering', 'overallfeedback_content',
                $assessmentidsql, $assessmentidparams);
            $fs->delete_area_files_select($context->id, 'mod_peering', 'overallfeedback_attachment',
                $assessmentidsql, $assessmentidparams);
        }

        // Erase sensitive data in provided assessments records.

        $sql = "SELECT wa.id AS assessmentid
                  FROM {peering} w
                  JOIN {peering_submissions} ws ON ws.peeringid = w.id
                  JOIN {peering_assessments} wa ON wa.submissionid = ws.id
                 WHERE w.id = :peeringid AND wa.reviewerid $usersql";

        $params = $userparams + [
            'peeringid' => $cm->instance,
        ];

        $assessmentids = $DB->get_fieldset_sql($sql, $params);

        if ($assessmentids) {
            list($assessmentidsql, $assessmentidparams) = $DB->get_in_or_equal($assessmentids, SQL_PARAMS_NAMED);

            $DB->set_field_select('peering_assessments', 'feedbackreviewer', get_string('privacy:request:delete:content',
                'mod_peering'), "id $assessmentidsql", $assessmentidparams);
        }

        foreach ($userids as $userid) {
            \core_plagiarism\privacy\provider::delete_plagiarism_for_user($userid, $context);
        }
    }

    /**
     * Get the user preferences.
     *
     * @return array List of user preferences
     */
    protected static function get_user_prefs(): array {
        return [
            'peering_perpage',
            'peering-viewlet-allexamples-collapsed',
            'peering-viewlet-allsubmissions-collapsed',
            'peering-viewlet-assessmentform-collapsed',
            'peering-viewlet-assignedassessments-collapsed',
            'peering-viewlet-cleargrades-collapsed',
            'peering-viewlet-conclusion-collapsed',
            'peering-viewlet-examples-collapsed',
            'peering-viewlet-examplesfail-collapsed',
            'peering-viewlet-gradereport-collapsed',
            'peering-viewlet-instructauthors-collapsed',
            'peering-viewlet-instructreviewers-collapsed',
            'peering-viewlet-intro-collapsed',
            'peering-viewlet-overallfeedback-collapsed',
            'peering-viewlet-ownsubmission-collapsed',
            'peering-viewlet-publicsubmissions-collapsed',
            'peering-viewlet-yourgrades-collapsed'
        ];
    }
}
