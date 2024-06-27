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
 * peering external API
 *
 * @package    mod_peering
 * @category   external
 * @copyright  2017 Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.4
 */

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/externallib.php");
require_once($CFG->dirroot . '/mod/peering/locallib.php');

use mod_peering\external\peering_summary_exporter;
use mod_peering\external\submission_exporter;
use mod_peering\external\assessment_exporter;

/**
 * peering external functions
 *
 * @package    mod_peering
 * @category   external
 * @copyright  2017 Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.4
 */
class mod_peering_external extends external_api {

    /**
     * Describes the parameters for get_peerings_by_courses.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function get_peerings_by_courses_parameters() {
        return new external_function_parameters (
            array(
                'courseids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Course id'), 'Array of course ids', VALUE_DEFAULT, array()
                ),
            )
        );
    }

    /**
     * Returns a list of peerings in a provided list of courses.
     * If no list is provided all peerings that the user can view will be returned.
     *
     * @param array $courseids course ids
     * @return array of warnings and peerings
     * @since Moodle 3.4
     */
    public static function get_peerings_by_courses($courseids = array()) {
        global $PAGE;

        $warnings = array();
        $returnedpeerings = array();

        $params = array(
            'courseids' => $courseids,
        );
        $params = self::validate_parameters(self::get_peerings_by_courses_parameters(), $params);

        $mycourses = array();
        if (empty($params['courseids'])) {
            $mycourses = enrol_get_my_courses();
            $params['courseids'] = array_keys($mycourses);
        }

        // Ensure there are courseids to loop through.
        if (!empty($params['courseids'])) {

            list($courses, $warnings) = external_util::validate_courses($params['courseids'], $mycourses);
            $output = $PAGE->get_renderer('core');

            // Get the peerings in this course, this function checks users visibility permissions.
            // We can avoid then additional validate_context calls.
            $peerings = get_all_instances_in_courses("peering", $courses);
            foreach ($peerings as $peering) {

                $context = context_module::instance($peering->coursemodule);
                // Remove fields that are not from the peering (added by get_all_instances_in_courses).
                unset($peering->coursemodule, $peering->context, $peering->visible, $peering->section, $peering->groupmode,
                        $peering->groupingid);

                $exporter = new peering_summary_exporter($peering, array('context' => $context));
                $returnedpeerings[] = $exporter->export($output);
            }
        }

        $result = array(
            'peerings' => $returnedpeerings,
            'warnings' => $warnings
        );
        return $result;
    }

    /**
     * Describes the get_peerings_by_courses return value.
     *
     * @return external_single_structure
     * @since Moodle 3.4
     */
    public static function get_peerings_by_courses_returns() {
        return new external_single_structure(
            array(
                'peerings' => new external_multiple_structure(
                    peering_summary_exporter::get_read_structure()
                ),
                'warnings' => new external_warnings(),
            )
        );
    }

    /**
     * Utility function for validating a peering.
     *
     * @param int $peeringid peering instance id
     * @return array array containing the peering object, course, context and course module objects
     * @since  Moodle 3.4
     */
    protected static function validate_peering($peeringid) {
        global $DB, $USER;

        // Request and permission validation.
        $peering = $DB->get_record('peering', array('id' => $peeringid), '*', MUST_EXIST);
        list($course, $cm) = get_course_and_cm_from_instance($peering, 'peering');

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        $peering = new peering($peering, $cm, $course);

        return array($peering, $course, $cm, $context);
    }


    /**
     * Describes the parameters for get_peering_access_information.
     *
     * @return external_external_function_parameters
     * @since Moodle 3.4
     */
    public static function get_peering_access_information_parameters() {
        return new external_function_parameters (
            array(
                'peeringid' => new external_value(PARAM_INT, 'peering instance id.')
            )
        );
    }

    /**
     * Return access information for a given peering.
     *
     * @param int $peeringid peering instance id
     * @return array of warnings and the access information
     * @since Moodle 3.4
     * @throws  moodle_exception
     */
    public static function get_peering_access_information($peeringid) {
        global $USER;

        $params = self::validate_parameters(self::get_peering_access_information_parameters(), array('peeringid' => $peeringid));

        list($peering, $course, $cm, $context) = self::validate_peering($params['peeringid']);

        $result = array();
        // Return all the available capabilities.
        $capabilities = load_capability_def('mod_peering');
        foreach ($capabilities as $capname => $capdata) {
            // Get fields like cansubmit so it is consistent with the access_information function implemented in other modules.
            $field = 'can' . str_replace('mod/peering:', '', $capname);
            $result[$field] = has_capability($capname, $context);
        }

        // Now, specific features access information.
        $result['creatingsubmissionallowed'] = $peering->creating_submission_allowed($USER->id);
        $result['modifyingsubmissionallowed'] = $peering->modifying_submission_allowed($USER->id);
        $result['assessingallowed'] = $peering->assessing_allowed($USER->id);
        $result['assessingexamplesallowed'] = $peering->assessing_examples_allowed();
        if (is_null($result['assessingexamplesallowed'])) {
            $result['assessingexamplesallowed'] = false;
        }
        $result['examplesassessedbeforesubmission'] = $peering->check_examples_assessed_before_submission($USER->id);
        list($result['examplesassessedbeforeassessment'], $code) = $peering->check_examples_assessed_before_assessment($USER->id);

        $result['warnings'] = array();
        return $result;
    }

    /**
     * Describes the get_peering_access_information return value.
     *
     * @return external_single_structure
     * @since Moodle 3.4
     */
    public static function get_peering_access_information_returns() {

        $structure = array(
            'creatingsubmissionallowed' => new external_value(PARAM_BOOL,
                'Is the given user allowed to create their submission?'),
            'modifyingsubmissionallowed' => new external_value(PARAM_BOOL,
                'Is the user allowed to modify his existing submission?'),
            'assessingallowed' => new external_value(PARAM_BOOL,
                'Is the user allowed to create/edit his assessments?'),
            'assessingexamplesallowed' => new external_value(PARAM_BOOL,
                'Are reviewers allowed to create/edit their assessments of the example submissions?.'),
            'examplesassessedbeforesubmission' => new external_value(PARAM_BOOL,
                'Whether the given user has assessed all his required examples before submission
                (always true if there are not examples to assess or not configured to check before submission).'),
            'examplesassessedbeforeassessment' => new external_value(PARAM_BOOL,
                'Whether the given user has assessed all his required examples before assessment
                (always true if there are not examples to assessor not configured to check before assessment).'),
            'warnings' => new external_warnings()
        );

        $capabilities = load_capability_def('mod_peering');
        foreach ($capabilities as $capname => $capdata) {
            // Get fields like cansubmit so it is consistent with the access_information function implemented in other modules.
            $field = 'can' . str_replace('mod/peering:', '', $capname);
            $structure[$field] = new external_value(PARAM_BOOL, 'Whether the user has the capability ' . $capname . ' allowed.');
        }

        return new external_single_structure($structure);
    }

    /**
     * Describes the parameters for get_user_plan.
     *
     * @return external_external_function_parameters
     * @since Moodle 3.4
     */
    public static function get_user_plan_parameters() {
        return new external_function_parameters (
            array(
                'peeringid' => new external_value(PARAM_INT, 'peering instance id.'),
                'userid' => new external_value(PARAM_INT, 'User id (empty or 0 for current user).', VALUE_DEFAULT, 0),
            )
        );
    }

    /**
     * Return the planner information for the given user.
     *
     * @param int $peeringid peering instance id
     * @param int $userid user id
     * @return array of warnings and the user plan
     * @since Moodle 3.4
     * @throws  moodle_exception
     */
    public static function get_user_plan($peeringid, $userid = 0) {
        global $USER;

        $params = array(
            'peeringid' => $peeringid,
            'userid' => $userid,
        );
        $params = self::validate_parameters(self::get_user_plan_parameters(), $params);

        list($peering, $course, $cm, $context) = self::validate_peering($params['peeringid']);

        // Extra checks so only users with permissions can view other users plans.
        if (empty($params['userid']) || $params['userid'] == $USER->id) {
            $userid = $USER->id;
        } else {
            require_capability('moodle/course:manageactivities', $context);
            $user = core_user::get_user($params['userid'], '*', MUST_EXIST);
            core_user::require_active_user($user);
            if (!$peering->check_group_membership($user->id)) {
                throw new moodle_exception('notingroup');
            }
            $userid = $user->id;
        }

        // Get the user plan information ready for external functions.
        $userplan = new peering_user_plan($peering, $userid);
        $userplan = array('phases' => $userplan->phases, 'examples' => $userplan->get_examples());
        foreach ($userplan['phases'] as $phasecode => $phase) {
            $phase->code = $phasecode;
            $userplan['phases'][$phasecode] = (array) $phase;
            foreach ($userplan['phases'][$phasecode]['tasks'] as $taskcode => $task) {
                $task->code = $taskcode;
                if ($task->link instanceof moodle_url) {
                    $task->link = $task->link->out(false);
                }
                $userplan['phases'][$phasecode]['tasks'][$taskcode] = (array) $task;
            }
            foreach ($userplan['phases'][$phasecode]['actions'] as $actioncode => $action) {
                if ($action->url instanceof moodle_url) {
                    $action->url = $action->url->out(false);
                }
                $userplan['phases'][$phasecode]['actions'][$actioncode] = (array) $action;
            }
        }

        $result['userplan'] = $userplan;
        $result['warnings'] = array();
        return $result;
    }

    /**
     * Describes the get_user_plan return value.
     *
     * @return external_single_structure
     * @since Moodle 3.4
     */
    public static function get_user_plan_returns() {
        return new external_single_structure(
            array(
                'userplan' => new external_single_structure(
                    array(
                        'phases' => new external_multiple_structure(
                            new external_single_structure(
                                array(
                                    'code' => new external_value(PARAM_INT, 'Phase code.'),
                                    'title' => new external_value(PARAM_NOTAGS, 'Phase title.'),
                                    'active' => new external_value(PARAM_BOOL, 'Whether is the active task.'),
                                    'tasks' => new external_multiple_structure(
                                        new external_single_structure(
                                            array(
                                                'code' => new external_value(PARAM_ALPHA, 'Task code.'),
                                                'title' => new external_value(PARAM_RAW, 'Task title.'),
                                                'link' => new external_value(PARAM_URL, 'Link to task.'),
                                                'details' => new external_value(PARAM_RAW, 'Task details.', VALUE_OPTIONAL),
                                                'completed' => new external_value(PARAM_NOTAGS,
                                                    'Completion information (maybe empty, maybe a boolean or generic info.'),
                                            )
                                        )
                                    ),
                                    'actions' => new external_multiple_structure(
                                        new external_single_structure(
                                            array(
                                                'type' => new external_value(PARAM_ALPHA, 'Action type.', VALUE_OPTIONAL),
                                                'label' => new external_value(PARAM_RAW, 'Action label.', VALUE_OPTIONAL),
                                                'url' => new external_value(PARAM_URL, 'Link to action.'),
                                                'method' => new external_value(PARAM_ALPHA, 'Get or post.', VALUE_OPTIONAL),
                                            )
                                        )
                                    ),
                                )
                            )
                        ),
                        'examples' => new external_multiple_structure(
                            new external_single_structure(
                                array(
                                    'id' => new external_value(PARAM_INT, 'Example submission id.'),
                                    'title' => new external_value(PARAM_RAW, 'Example submission title.'),
                                    'assessmentid' => new external_value(PARAM_INT, 'Example submission assessment id.'),
                                    'grade' => new external_value(PARAM_FLOAT, 'The submission grade.'),
                                    'gradinggrade' => new external_value(PARAM_FLOAT, 'The assessment grade.'),
                                )
                            )
                        ),
                    )
                ),
                'warnings' => new external_warnings(),
            )
        );
    }

    /**
     * Describes the parameters for view_peering.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function view_peering_parameters() {
        return new external_function_parameters (
            array(
                'peeringid' => new external_value(PARAM_INT, 'peering instance id'),
            )
        );
    }

    /**
     * Trigger the course module viewed event and update the module completion status.
     *
     * @param int $peeringid peering instance id
     * @return array of warnings and status result
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function view_peering($peeringid) {

        $params = array('peeringid' => $peeringid);
        $params = self::validate_parameters(self::view_peering_parameters(), $params);
        $warnings = array();

        list($peering, $course, $cm, $context) = self::validate_peering($params['peeringid']);

        $peering->set_module_viewed();

        $result = array(
            'status' => true,
            'warnings' => $warnings,
        );
        return $result;
    }

    /**
     * Describes the view_peering return value.
     *
     * @return external_single_structure
     * @since Moodle 3.4
     */
    public static function view_peering_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'warnings' => new external_warnings(),
            )
        );
    }

    /**
     * Returns the description of the external function parameters.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function add_submission_parameters() {
        return new external_function_parameters(array(
            'peeringid' => new external_value(PARAM_INT, 'peering id'),
            'title' => new external_value(PARAM_TEXT, 'Submission title'),
            'content' => new external_value(PARAM_RAW, 'Submission text content', VALUE_DEFAULT, ''),
            'contentformat' => new external_value(PARAM_INT, 'The format used for the content', VALUE_DEFAULT, FORMAT_MOODLE),
            'inlineattachmentsid' => new external_value(PARAM_INT, 'The draft file area id for inline attachments in the content',
                VALUE_DEFAULT, 0),
            'attachmentsid' => new external_value(PARAM_INT, 'The draft file area id for attachments', VALUE_DEFAULT, 0),
        ));
    }

    /**
     * Add a new submission to a given peering.
     *
     * @param int $peeringid the peering id
     * @param string $title             the submission title
     * @param string  $content          the submission text content
     * @param int  $contentformat       the format used for the content
     * @param int $inlineattachmentsid  the draft file area id for inline attachments in the content
     * @param int $attachmentsid        the draft file area id for attachments
     * @return array Containing the new created submission id and warnings.
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function add_submission($peeringid, $title, $content = '', $contentformat = FORMAT_MOODLE,
            $inlineattachmentsid = 0, $attachmentsid = 0) {
        global $USER;

        $params = self::validate_parameters(self::add_submission_parameters(), array(
            'peeringid' => $peeringid,
            'title' => $title,
            'content' => $content,
            'contentformat' => $contentformat,
            'inlineattachmentsid' => $inlineattachmentsid,
            'attachmentsid' => $attachmentsid,
        ));
        $warnings = array();

        // Get and validate the peering.
        list($peering, $course, $cm, $context) = self::validate_peering($params['peeringid']);
        require_capability('mod/peering:submit', $context);

        // Check if we can submit now.
        $canaddsubmission = $peering->creating_submission_allowed($USER->id);
        $canaddsubmission = $canaddsubmission && $peering->check_examples_assessed_before_submission($USER->id);
        if (!$canaddsubmission) {
            throw new moodle_exception('nopermissions', 'error', '', 'add submission');
        }

        // Prepare the submission object.
        $submission = new stdClass;
        $submission->id = null;
        $submission->cmid = $cm->id;
        $submission->example = 0;
        $submission->title = trim($params['title']);
        $submission->content_editor = array(
            'text' => $params['content'],
            'format' => $params['contentformat'],
            'itemid' => $params['inlineattachmentsid'],
        );
        $submission->attachment_filemanager = $params['attachmentsid'];

        if (empty($submission->title)) {
            throw new moodle_exception('errorinvalidparam', 'webservice', '', 'title');
        }

        $errors = $peering->validate_submission_data((array) $submission);
        // We can get several errors, return them in warnings.
        if (!empty($errors)) {
            $submission->id = 0;
            foreach ($errors as $itemname => $message) {
                $warnings[] = array(
                    'item' => $itemname,
                    'itemid' => 0,
                    'warningcode' => 'fielderror',
                    'message' => s($message)
                );
            }
            return array(
                'status' => false,
                'warnings' => $warnings
            );
        } else {
            $submission->id = $peering->edit_submission($submission);
            return array(
                'status' => true,
                'submissionid' => $submission->id,
                'warnings' => $warnings
            );
        }
    }

    /**
     * Returns the description of the external function return value.
     *
     * @return external_description
     * @since Moodle 3.4
     */
    public static function add_submission_returns() {
        return new external_single_structure(array(
            'status' => new external_value(PARAM_BOOL, 'True if the submission was created false otherwise.'),
            'submissionid' => new external_value(PARAM_INT, 'New peering submission id.', VALUE_OPTIONAL),
            'warnings' => new external_warnings()
        ));
    }

    /**
     * Returns the description of the external function parameters.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function update_submission_parameters() {
        return new external_function_parameters(array(
            'submissionid' => new external_value(PARAM_INT, 'Submission id'),
            'title' => new external_value(PARAM_TEXT, 'Submission title'),
            'content' => new external_value(PARAM_RAW, 'Submission text content', VALUE_DEFAULT, ''),
            'contentformat' => new external_value(PARAM_INT, 'The format used for the content', VALUE_DEFAULT, FORMAT_MOODLE),
            'inlineattachmentsid' => new external_value(PARAM_INT, 'The draft file area id for inline attachments in the content',
                VALUE_DEFAULT, 0),
            'attachmentsid' => new external_value(PARAM_INT, 'The draft file area id for attachments', VALUE_DEFAULT, 0),
        ));
    }


    /**
     * Updates the given submission.
     *
     * @param int $submissionid         the submission id
     * @param string $title             the submission title
     * @param string  $content          the submission text content
     * @param int  $contentformat       the format used for the content
     * @param int $inlineattachmentsid  the draft file area id for inline attachments in the content
     * @param int $attachmentsid        the draft file area id for attachments
     * @return array whether the submission was updated and warnings.
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function update_submission($submissionid, $title, $content = '', $contentformat = FORMAT_MOODLE,
            $inlineattachmentsid = 0, $attachmentsid = 0) {
        global $USER, $DB;

        $params = self::validate_parameters(self::update_submission_parameters(), array(
            'submissionid' => $submissionid,
            'title' => $title,
            'content' => $content,
            'contentformat' => $contentformat,
            'inlineattachmentsid' => $inlineattachmentsid,
            'attachmentsid' => $attachmentsid,
        ));
        $warnings = array();

        // Get and validate the submission and peering.
        $submission = $DB->get_record('peering_submissions', array('id' => $params['submissionid']), '*', MUST_EXIST);
        list($peering, $course, $cm, $context) = self::validate_peering($submission->peeringid);
        require_capability('mod/peering:submit', $context);

        // Check if we can update the submission.
        $canupdatesubmission = $submission->authorid == $USER->id;
        $canupdatesubmission = $canupdatesubmission && $peering->modifying_submission_allowed($USER->id);
        $canupdatesubmission = $canupdatesubmission && $peering->check_examples_assessed_before_submission($USER->id);
        if (!$canupdatesubmission) {
            throw new moodle_exception('nopermissions', 'error', '', 'update submission');
        }

        // Prepare the submission object.
        $submission->title = trim($params['title']);
        if (empty($submission->title)) {
            throw new moodle_exception('errorinvalidparam', 'webservice', '', 'title');
        }
        $submission->content_editor = array(
            'text' => $params['content'],
            'format' => $params['contentformat'],
            'itemid' => $params['inlineattachmentsid'],
        );
        $submission->attachment_filemanager = $params['attachmentsid'];

        $errors = $peering->validate_submission_data((array) $submission);
        // We can get several errors, return them in warnings.
        if (!empty($errors)) {
            $status = false;
            foreach ($errors as $itemname => $message) {
                $warnings[] = array(
                    'item' => $itemname,
                    'itemid' => 0,
                    'warningcode' => 'fielderror',
                    'message' => s($message)
                );
            }
        } else {
            $status = true;
            $submission->id = $peering->edit_submission($submission);
        }

        return array(
            'status' => $status,
            'warnings' => $warnings
        );
    }

    /**
     * Returns the description of the external function return value.
     *
     * @return external_description
     * @since Moodle 3.4
     */
    public static function update_submission_returns() {
        return new external_single_structure(array(
            'status' => new external_value(PARAM_BOOL, 'True if the submission was updated false otherwise.'),
            'warnings' => new external_warnings()
        ));
    }

    /**
     * Returns the description of the external function parameters.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function delete_submission_parameters() {
        return new external_function_parameters(
            array(
                'submissionid' => new external_value(PARAM_INT, 'Submission id'),
            )
        );
    }


    /**
     * Deletes the given submission.
     *
     * @param int $submissionid the submission id.
     * @return array containing the result status and warnings.
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function delete_submission($submissionid) {
        global $USER, $DB;

        $params = self::validate_parameters(self::delete_submission_parameters(), array('submissionid' => $submissionid));
        $warnings = array();

        // Get and validate the submission and peering.
        $submission = $DB->get_record('peering_submissions', array('id' => $params['submissionid']), '*', MUST_EXIST);
        list($peering, $course, $cm, $context) = self::validate_peering($submission->peeringid);

        // Check if we can delete the submission.
        if (!has_capability('mod/peering:deletesubmissions', $context)) {
            require_capability('mod/peering:submit', $context);
            // We can delete our own submission, on time and not yet assessed.
            $candeletesubmission = $submission->authorid == $USER->id;
            $candeletesubmission = $candeletesubmission && $peering->modifying_submission_allowed($USER->id);
            $candeletesubmission = $candeletesubmission && count($peering->get_assessments_of_submission($submission->id)) == 0;
            if (!$candeletesubmission) {
                throw new moodle_exception('nopermissions', 'error', '', 'delete submission');
            }
        }

        $peering->delete_submission($submission);

        return array(
            'status' => true,
            'warnings' => $warnings
        );
    }

    /**
     * Returns the description of the external function return value.
     *
     * @return external_description
     * @since Moodle 3.4
     */
    public static function delete_submission_returns() {
        return new external_single_structure(array(
            'status' => new external_value(PARAM_BOOL, 'True if the submission was deleted.'),
            'warnings' => new external_warnings()
        ));
    }

    /**
     * Helper method for returning the submission data according the current user capabilities and current phase.
     *
     * @param  stdClass $submission the submission data
     * @param  peering $peering   the peering class
     * @param  bool $canviewauthorpublished whether the user has the capability mod/peering:viewauthorpublished on
     * @param  bool $canviewauthornames whether the user has the capability mod/peering:vviewauthornames on
     * @param  bool $canviewallsubmissions whether the user has the capability mod/peering:viewallsubmissions on
     * @return stdClass object with the submission data filtered
     * @since Moodle 3.4
     */
    protected static function prepare_submission_for_external($submission, peering $peering, $canviewauthorpublished = null,
            $canviewauthornames = null, $canviewallsubmissions = null) {
        global $USER;

        if (is_null($canviewauthorpublished)) {
            $canviewauthorpublished = has_capability('mod/peering:viewauthorpublished', $peering->context);
        }
        if (is_null($canviewauthornames)) {
            $canviewauthornames = has_capability('mod/peering:viewauthornames', $peering->context);
        }
        if (is_null($canviewallsubmissions)) {
            $canviewallsubmissions = has_capability('mod/peering:viewallsubmissions', $peering->context);
        }

        $ownsubmission = $submission->authorid == $USER->id;
        if (!$canviewauthornames && !$ownsubmission) {
            $submission->authorid = 0;
        }

        // Remove grade, gradeover, gradeoverby, feedbackauthor and timegraded for non-teachers or invalid phase.
        // WS mod_peering_external::get_grades should be used for retrieving grades by students.
        if ($peering->phase < peering::PHASE_EVALUATION || !$canviewallsubmissions) {
            $properties = submission_exporter::properties_definition();
            foreach ($properties as $attribute => $settings) {
                // Special case, the feedbackauthor (and who did it) should be returned if the peering is closed and
                // the user can view it.
                if (($attribute == 'feedbackauthor' || $attribute == 'gradeoverby') &&
                        $peering->phase == peering::PHASE_CLOSED && $ownsubmission) {
                    continue;
                }
                if (!empty($settings['optional'])) {
                    unset($submission->{$attribute});
                }
            }
        }
        return $submission;
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function get_submissions_parameters() {
        return new external_function_parameters(
            array(
                'peeringid' => new external_value(PARAM_INT, 'peering instance id.'),
                'userid' => new external_value(PARAM_INT, 'Get submissions done by this user. Use 0 or empty for the current user',
                                                VALUE_DEFAULT, 0),
                'groupid' => new external_value(PARAM_INT, 'Group id, 0 means that the function will determine the user group.
                                                   It will return submissions done by users in the given group.',
                                                   VALUE_DEFAULT, 0),
                'page' => new external_value(PARAM_INT, 'The page of records to return.', VALUE_DEFAULT, 0),
                'perpage' => new external_value(PARAM_INT, 'The number of records to return per page.', VALUE_DEFAULT, 0),
            )
        );
    }

    /**
     * Retrieves all the peering submissions visible by the current user or the one done by the given user
     * (except example submissions).
     *
     * @param int $peeringid       the peering instance id
     * @param int $userid           get submissions done by this user
     * @param int $groupid          (optional) group id, 0 means that the function will determine the user group
     * @param int $page             page of records to return
     * @param int $perpage          number of records to return per page
     * @return array of warnings and the entries
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function get_submissions($peeringid, $userid = 0, $groupid = 0, $page = 0, $perpage = 0) {
        global $PAGE, $USER;

        $params = array('peeringid' => $peeringid, 'userid' => $userid, 'groupid' => $groupid,
            'page' => $page, 'perpage' => $perpage);
        $params = self::validate_parameters(self::get_submissions_parameters(), $params);
        $submissions = $warnings = array();

        list($peering, $course, $cm, $context) = self::validate_peering($params['peeringid']);

        if (empty($params['groupid'])) {
            // Check to see if groups are being used here.
            if ($groupmode = groups_get_activity_groupmode($cm)) {
                $groupid = groups_get_activity_group($cm);
                // Determine is the group is visible to user (this is particullary for the group 0 -> all groups).
                if (!groups_group_visible($groupid, $course, $cm)) {
                    throw new moodle_exception('notingroup');
                }
            } else {
                $groupid = 0;
            }
        }

        if (!empty($params['userid']) && $params['userid'] != $USER->id) {
            $user = core_user::get_user($params['userid'], '*', MUST_EXIST);
            core_user::require_active_user($user);
            if (!$peering->check_group_membership($user->id)) {
                throw new moodle_exception('notingroup');
            }
        }

        $totalfilesize = 0;
        list($submissionsrecords, $totalcount) =
            $peering->get_visible_submissions($params['userid'], $groupid, $params['page'], $params['perpage']);

        if ($totalcount) {

            $canviewauthorpublished = has_capability('mod/peering:viewauthorpublished', $context);
            $canviewauthornames = has_capability('mod/peering:viewauthornames', $context);
            $canviewallsubmissions = has_capability('mod/peering:viewallsubmissions', $context);

            $related = array('context' => $context);
            foreach ($submissionsrecords as $submission) {
                $submission = self::prepare_submission_for_external($submission, $peering, $canviewauthorpublished,
                    $canviewauthornames, $canviewallsubmissions);

                $exporter = new submission_exporter($submission, $related);
                $submissions[] = $exporter->export($PAGE->get_renderer('core'));
            }

            // Retrieve total files size for the submissions (so external clients know how many data they'd need to download).
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_peering', array('submission_content', 'submission_attachment'));
            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }
                $totalfilesize += $file->get_filesize();
            }
        }

        return array(
            'submissions' => $submissions,
            'totalcount' => $totalcount,
            'totalfilesize' => $totalfilesize,
        );
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.4
     */
    public static function get_submissions_returns() {
        return new external_single_structure(
            array(
                'submissions' => new external_multiple_structure(
                    submission_exporter::get_read_structure()
                ),
                'totalcount' => new external_value(PARAM_INT, 'Total count of submissions.'),
                'totalfilesize' => new external_value(PARAM_INT, 'Total size (bytes) of the files attached to all the
                    submissions (even the ones not returned due to pagination).'),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Helper method for validating a submission.
     *
     * @param  stdClass   $submission submission object
     * @param  peering   $peering     peering instance
     * @return void
     * @since  Moodle 3.4
     */
    protected static function validate_submission($submission, peering $peering) {
        global $USER;

        $peeringclosed = $peering->phase == peering::PHASE_CLOSED;
        $canviewpublished = has_capability('mod/peering:viewpublishedsubmissions', $peering->context);

        $canview = $submission->authorid == $USER->id;  // I did it.
        $canview = $canview || !empty($peering->get_assessment_of_submission_by_user($submission->id, $USER->id));  // I reviewed.
        $canview = $canview || has_capability('mod/peering:viewallsubmissions', $peering->context); // I can view all.
        $canview = $canview || ($submission->published && $peeringclosed && $canviewpublished);    // It has been published.

        if ($canview) {
            // Here we should check if the user share group.
            if ($submission->authorid != $USER->id &&
                    !groups_user_groups_visible($peering->course, $submission->authorid, $peering->cm)) {
                throw new moodle_exception('notingroup');
            }
        } else {
            throw new moodle_exception('nopermissions', 'error', '', 'view submission');
        }
    }

    /**
     * Returns the description of the external function parameters.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function get_submission_parameters() {
        return new external_function_parameters(
            array(
                'submissionid' => new external_value(PARAM_INT, 'Submission id'),
            )
        );
    }


    /**
     * Retrieves the given submission.
     *
     * @param int $submissionid the submission id
     * @return array containing the submission and warnings.
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function get_submission($submissionid) {
        global $USER, $DB, $PAGE;

        $params = self::validate_parameters(self::get_submission_parameters(), array('submissionid' => $submissionid));
        $warnings = array();

        // Get and validate the submission and peering.
        $submission = $DB->get_record('peering_submissions', array('id' => $params['submissionid']), '*', MUST_EXIST);
        list($peering, $course, $cm, $context) = self::validate_peering($submission->peeringid);

        self::validate_submission($submission, $peering);

        $submission = self::prepare_submission_for_external($submission, $peering);

        $related = array('context' => $context);
        $exporter = new submission_exporter($submission, $related);
        return array(
            'submission' => $exporter->export($PAGE->get_renderer('core')),
            'warnings' => $warnings
        );
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.4
     */
    public static function get_submission_returns() {
        return new external_single_structure(
            array(
                'submission' => submission_exporter::get_read_structure(),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Helper method for validating if the current user can view the submission assessments.
     *
     * @param  stdClass   $submission submission object
     * @param  peering   $peering     peering instance
     * @return void
     * @since  Moodle 3.4
     */
    protected static function check_view_submission_assessments($submission, peering $peering) {
        global $USER;

        $ownsubmission = $submission->authorid == $USER->id;
        $canview = has_capability('mod/peering:viewallassessments', $peering->context) ||
            ($ownsubmission && $peering->assessments_available());

        if ($canview) {
            // Here we should check if the user share group.
            if ($submission->authorid != $USER->id &&
                    !groups_user_groups_visible($peering->course, $submission->authorid, $peering->cm)) {
                throw new moodle_exception('notingroup');
            }
        } else {
            throw new moodle_exception('nopermissions', 'error', '', 'view assessment');
        }
    }

    /**
     * Helper method for returning the assessment data according the current user capabilities and current phase.
     *
     * @param  stdClass $assessment the assessment data
     * @param  peering $peering   the peering class
     * @return stdClass object with the assessment data filtered or null if is not viewable yet
     * @since Moodle 3.4
     */
    protected static function prepare_assessment_for_external($assessment, peering $peering) {
        global $USER;
        static $canviewallassessments = null;
        static $canviewreviewers = null;
        static $canoverridegrades = null;

        // Remove all the properties that does not belong to the assessment table.
        $properties = assessment_exporter::properties_definition();
        foreach ($assessment as $key => $value) {
            if (!isset($properties[$key])) {
                unset($assessment->{$key});
            }
        }

        if (is_null($canviewallassessments)) {
            $canviewallassessments = has_capability('mod/peering:viewallassessments', $peering->context);
        }
        if (is_null($canviewreviewers)) {
            $canviewreviewers = has_capability('mod/peering:viewreviewernames', $peering->context);
        }
        if (is_null($canoverridegrades)) {
            $canoverridegrades = has_capability('mod/peering:overridegrades', $peering->context);
        }

        $isreviewer = $assessment->reviewerid == $USER->id;

        if (!$isreviewer && is_null($assessment->grade) && !$canviewallassessments) {
            // Students do not see peer-assessment that are not graded yet.
            return null;
        }

        // Remove the feedback for the reviewer if:
        // I can't see it in the evaluation phase because I'm not a teacher or the reviewer AND
        // I can't see it in the assessment phase because I'm not a teacher.
        if (($peering->phase < peering::PHASE_EVALUATION || !($isreviewer || $canviewallassessments)) &&
                ($peering->phase < peering::PHASE_ASSESSMENT || !$canviewallassessments) ) {
            // Remove all the feedback information (all the optional fields).
            foreach ($properties as $attribute => $settings) {
                if (!empty($settings['optional'])) {
                    unset($assessment->{$attribute});
                }
            }
        }

        if (!$isreviewer && !$canviewreviewers) {
            $assessment->reviewerid = 0;
        }

        return $assessment;
    }

    /**
     * Returns the description of the external function parameters.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function get_submission_assessments_parameters() {
        return new external_function_parameters(
            array(
                'submissionid' => new external_value(PARAM_INT, 'Submission id'),
            )
        );
    }


    /**
     * Retrieves the given submission assessments.
     *
     * @param int $submissionid the submission id
     * @return array containing the assessments and warnings.
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function get_submission_assessments($submissionid) {
        global $USER, $DB, $PAGE;

        $params = self::validate_parameters(self::get_submission_assessments_parameters(), array('submissionid' => $submissionid));
        $warnings = $assessments = array();

        // Get and validate the submission and peering.
        $submission = $DB->get_record('peering_submissions', array('id' => $params['submissionid']), '*', MUST_EXIST);
        list($peering, $course, $cm, $context) = self::validate_peering($submission->peeringid);

        // Check that we can get the assessments and get them.
        self::check_view_submission_assessments($submission, $peering);
        $assessmentsrecords = $peering->get_assessments_of_submission($submission->id);

        $related = array('context' => $context);
        foreach ($assessmentsrecords as $assessment) {
            $assessment = self::prepare_assessment_for_external($assessment, $peering);
            if (empty($assessment)) {
                continue;
            }
            $exporter = new assessment_exporter($assessment, $related);
            $assessments[] = $exporter->export($PAGE->get_renderer('core'));
        }

        return array(
            'assessments' => $assessments,
            'warnings' => $warnings
        );
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.4
     */
    public static function get_submission_assessments_returns() {
        return new external_single_structure(
            array(
                'assessments' => new external_multiple_structure(
                    assessment_exporter::get_read_structure()
                ),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Returns the description of the external function parameters.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function get_assessment_parameters() {
        return new external_function_parameters(
            array(
                'assessmentid' => new external_value(PARAM_INT, 'Assessment id'),
            )
        );
    }


    /**
     * Retrieves the given assessment.
     *
     * @param int $assessmentid the assessment id
     * @return array containing the assessment and warnings.
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function get_assessment($assessmentid) {
        global $DB, $PAGE;

        $params = self::validate_parameters(self::get_assessment_parameters(), array('assessmentid' => $assessmentid));
        $warnings = array();

        // Get and validate the assessment, submission and peering.
        $assessment = $DB->get_record('peering_assessments', array('id' => $params['assessmentid']), '*', MUST_EXIST);
        $submission = $DB->get_record('peering_submissions', array('id' => $assessment->submissionid), '*', MUST_EXIST);
        list($peering, $course, $cm, $context) = self::validate_peering($submission->peeringid);

        // Check that we can get the assessment.
        $peering->check_view_assessment($assessment, $submission);

        $assessment = $peering->get_assessment_by_id($assessment->id);
        $assessment = self::prepare_assessment_for_external($assessment, $peering);
        if (empty($assessment)) {
            throw new moodle_exception('nopermissions', 'error', '', 'view assessment');
        }
        $related = array('context' => $context);
        $exporter = new assessment_exporter($assessment, $related);

        return array(
            'assessment' => $exporter->export($PAGE->get_renderer('core')),
            'warnings' => $warnings
        );
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.4
     */
    public static function get_assessment_returns() {
        return new external_single_structure(
            array(
                'assessment' => assessment_exporter::get_read_structure(),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Returns the description of the external function parameters.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function get_assessment_form_definition_parameters() {
        return new external_function_parameters(
            array(
                'assessmentid' => new external_value(PARAM_INT, 'Assessment id'),
                'mode' => new external_value(PARAM_ALPHA, 'The form mode (assessment or preview)', VALUE_DEFAULT, 'assessment'),
            )
        );
    }


    /**
     * Retrieves the assessment form definition (data required to be able to display the assessment form).
     *
     * @param int $assessmentid the assessment id
     * @param string $mode the form mode (assessment or preview)
     * @return array containing the assessment and warnings.
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function get_assessment_form_definition($assessmentid, $mode = 'assessment') {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::get_assessment_form_definition_parameters(), array('assessmentid' => $assessmentid, 'mode' => $mode)
        );
        $warnings = $pending = array();

        if ($params['mode'] != 'assessment' && $params['mode'] != 'preview') {
            throw new invalid_parameter_exception('Invalid value for mode parameter (value: ' . $params['mode'] . ')');
        }

        // Get and validate the assessment, submission and peering.
        $assessment = $DB->get_record('peering_assessments', array('id' => $params['assessmentid']), '*', MUST_EXIST);
        $submission = $DB->get_record('peering_submissions', array('id' => $assessment->submissionid), '*', MUST_EXIST);
        list($peering, $course, $cm, $context) = self::validate_peering($submission->peeringid);

        // Check we can view the assessment (so we can get the form data).
        $peering->check_view_assessment($assessment, $submission);

        $cansetassessmentweight = has_capability('mod/peering:allocate', $context);
        $pending = $peering->get_pending_assessments_by_reviewer($assessment->reviewerid, $assessment->id);

        // Retrieve the data from the strategy plugin.
        $strategy = $peering->grading_strategy_instance();
        $strategyname = str_replace('_strategy', '', get_class($strategy)); // Get strategy name.
        $mform = $strategy->get_assessment_form(null, $params['mode'], $assessment, true,
            array('editableweight' => $cansetassessmentweight, 'pending' => !empty($pending)));
        $formdata = $mform->get_customdata();

        $result = array(
            'dimenssionscount' => $formdata['nodims'],
            'descriptionfiles' => external_util::get_area_files($context->id, $strategyname, 'description'),
            'warnings' => $warnings
        );
        // Include missing dimension fields.
        for ($i = 0; $i < $formdata['nodims']; $i++) {
            $formdata['fields']->{'gradeid__idx_' . $i} = 0;
            $formdata['fields']->{'peercomment__idx_' . $i} = '';
        }

        // Convert all the form data for external.
        foreach (array('options', 'fields', 'current') as $typeofdata) {
            $result[$typeofdata] = array();

            if (!empty($formdata[$typeofdata])) {
                $alldata = (array) $formdata[$typeofdata];
                foreach ($alldata as $key => $val) {
                    if (strpos($key, 'description__idx_')) {
                        // Format dimension description.
                        $id = str_replace('description__idx_', '', $key);
                        list($val, $format) = external_format_text($val, $alldata['dimensionid__idx_' . $id . 'format'],
                            $context->id, $strategyname, 'description', $alldata['dimensionid__idx_' . $id]);
                    }
                    $result[$typeofdata][] = array(
                        'name' => $key,
                        'value' => $val
                    );
                }
            }
        }

        // Get dimensions info.
        $grader = $peering->grading_strategy_instance();
        $result['dimensionsinfo'] = $grader->get_dimensions_info();

        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.4
     */
    public static function get_assessment_form_definition_returns() {
        return new external_single_structure(
            array(
                'dimenssionscount' => new external_value(PARAM_INT, 'The number of dimenssions used by the form.'),
                'descriptionfiles' => new external_files('Files in the description text'),
                'options' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'name' => new external_value(PARAM_ALPHANUMEXT, 'Option name.'),
                            'value' => new external_value(PARAM_NOTAGS, 'Option value.')
                        )
                    ), 'The form options.'
                ),
                'fields' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'name' => new external_value(PARAM_ALPHANUMEXT, 'Field name.'),
                            'value' => new external_value(PARAM_RAW, 'Field default value.')
                        )
                    ), 'The form fields.'
                ),
                'current' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'name' => new external_value(PARAM_ALPHANUMEXT, 'Field name.'),
                            'value' => new external_value(PARAM_RAW, 'Current field value.')
                        )
                    ), 'The current field values.'
                ),
                'dimensionsinfo' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'id' => new external_value(PARAM_INT, 'Dimension id.'),
                            'min' => new external_value(PARAM_FLOAT, 'Minimum grade for the dimension.'),
                            'max' => new external_value(PARAM_FLOAT, 'Maximum grade for the dimension.'),
                            'weight' => new external_value(PARAM_TEXT, 'The weight of the dimension.'),
                            'scale' => new external_value(PARAM_TEXT, 'Scale items (if used).', VALUE_OPTIONAL),
                        )
                    ), 'The dimensions general information.'
                ),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Returns the description of the external function parameters.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function get_reviewer_assessments_parameters() {
        return new external_function_parameters(
            array(
                'peeringid' => new external_value(PARAM_INT, 'peering instance id.'),
                'userid' => new external_value(PARAM_INT, 'User id who did the assessment review (empty or 0 for current user).',
                    VALUE_DEFAULT, 0),
            )
        );
    }


    /**
     * Retrieves all the assessments reviewed by the given user.
     *
     * @param int $peeringid   the peering instance id
     * @param int $userid       the reviewer user id
     * @return array containing the user assessments and warnings.
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function get_reviewer_assessments($peeringid, $userid = 0) {
        global $USER, $DB, $PAGE;

        $params = self::validate_parameters(
            self::get_reviewer_assessments_parameters(), array('peeringid' => $peeringid, 'userid' => $userid)
        );
        $warnings = $assessments = array();

        // Get and validate the submission and peering.
        list($peering, $course, $cm, $context) = self::validate_peering($params['peeringid']);

        // Extra checks so only users with permissions can view other users assessments.
        if (empty($params['userid']) || $params['userid'] == $USER->id) {
            $userid = $USER->id;
            list($assessed, $notice) = $peering->check_examples_assessed_before_assessment($userid);
            if (!$assessed) {
                throw new moodle_exception($notice, 'mod_peering');
            }
            if ($peering->phase < peering::PHASE_ASSESSMENT) {    // Can view assessments only in assessment phase onwards.
                throw new moodle_exception('nopermissions', 'error', '', 'view assessments');
            }
        } else {
            require_capability('mod/peering:viewallassessments', $context);
            $user = core_user::get_user($params['userid'], '*', MUST_EXIST);
            core_user::require_active_user($user);
            if (!$peering->check_group_membership($user->id)) {
                throw new moodle_exception('notingroup');
            }
            $userid = $user->id;
        }
        // Now get all my assessments (includes those pending review).
        $assessmentsrecords = $peering->get_assessments_by_reviewer($userid);

        $related = array('context' => $context);
        foreach ($assessmentsrecords as $assessment) {
            $assessment = self::prepare_assessment_for_external($assessment, $peering);
            if (empty($assessment)) {
                continue;
            }
            $exporter = new assessment_exporter($assessment, $related);
            $assessments[] = $exporter->export($PAGE->get_renderer('core'));
        }

        return array(
            'assessments' => $assessments,
            'warnings' => $warnings
        );
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.4
     */
    public static function get_reviewer_assessments_returns() {
        return new external_single_structure(
            array(
                'assessments' => new external_multiple_structure(
                    assessment_exporter::get_read_structure()
                ),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Returns the description of the external function parameters.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function update_assessment_parameters() {
        return new external_function_parameters(
            array(
                'assessmentid' => new external_value(PARAM_INT, 'Assessment id.'),
                'data' => new external_multiple_structure (
                    new external_single_structure(
                        array(
                            'name' => new external_value(PARAM_ALPHANUMEXT,
                                'The assessment data (use WS get_assessment_form_definition for obtaining the data to sent).
                                Apart from that data, you can optionally send:
                                feedbackauthor (str); the feedback for the submission author
                                feedbackauthorformat (int); the format of the feedbackauthor
                                feedbackauthorinlineattachmentsid (int); the draft file area for the editor attachments
                                feedbackauthorattachmentsid (int); the draft file area id for the feedback attachments'
                            ),
                            'value' => new external_value(PARAM_RAW, 'The value of the option.')
                        )
                    ), 'Assessment data'
                )
            )
        );
    }


    /**
     * Updates an assessment.
     *
     * @param int $assessmentid the assessment id
     * @param array $data the assessment data
     * @return array indicates if the assessment was updated, the new raw grade and possible warnings.
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function update_assessment($assessmentid, $data) {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::update_assessment_parameters(), array('assessmentid' => $assessmentid, 'data' => $data)
        );
        $warnings = array();

        // Get and validate the assessment, submission and peering.
        $assessment = $DB->get_record('peering_assessments', array('id' => $params['assessmentid']), '*', MUST_EXIST);
        $submission = $DB->get_record('peering_submissions', array('id' => $assessment->submissionid), '*', MUST_EXIST);
        list($peering, $course, $cm, $context) = self::validate_peering($submission->peeringid);

        // Check we can edit the assessment.
        $peering->check_edit_assessment($assessment, $submission);

        // Process data.
        $data = new stdClass;
        $data->feedbackauthor_editor = array();

        foreach ($params['data'] as $wsdata) {
            $name = trim($wsdata['name']);
            switch ($name) {
                case 'feedbackauthor':
                    $data->feedbackauthor_editor['text'] = $wsdata['value'];
                    break;
                case 'feedbackauthorformat':
                    $data->feedbackauthor_editor['format'] = clean_param($wsdata['value'], PARAM_FORMAT);
                    break;
                case 'feedbackauthorinlineattachmentsid':
                    $data->feedbackauthor_editor['itemid'] = clean_param($wsdata['value'], PARAM_INT);
                    break;
                case 'feedbackauthorattachmentsid':
                    $data->feedbackauthorattachment_filemanager = clean_param($wsdata['value'], PARAM_INT);
                    break;
                default:
                    $data->{$wsdata['name']} = $wsdata['value'];    // Validation will be done in the form->validation.
            }
        }

        $cansetassessmentweight = has_capability('mod/peering:allocate', $context);
        $pending = $peering->get_pending_assessments_by_reviewer($assessment->reviewerid, $assessment->id);
        // Retrieve the data from the strategy plugin.
        $strategy = $peering->grading_strategy_instance();
        $mform = $strategy->get_assessment_form(null, 'assessment', $assessment, true,
            array('editableweight' => $cansetassessmentweight, 'pending' => !empty($pending)));

        $errors = $mform->validation((array) $data, array());
        // We can get several errors, return them in warnings.
        if (!empty($errors)) {
            $status = false;
            $rawgrade = null;
            foreach ($errors as $itemname => $message) {
                $warnings[] = array(
                    'item' => $itemname,
                    'itemid' => 0,
                    'warningcode' => 'fielderror',
                    'message' => s($message)
                );
            }
        } else {
            $rawgrade = $peering->edit_assessment($assessment, $submission, $data, $strategy);
            $status = true;
        }

        return array(
            'status' => $status,
            'rawgrade' => $rawgrade,
            'warnings' => $warnings,
        );
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.4
     */
    public static function update_assessment_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'status: true if the assessment was added or updated false otherwise.'),
                'rawgrade' => new external_value(PARAM_FLOAT, 'Raw percentual grade (0.00000 to 100.00000) for submission.',
                    VALUE_OPTIONAL),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Returns the description of the external function parameters.
     *
     * @return external_external_function_parameters
     * @since Moodle 3.4
     */
    public static function get_grades_parameters() {
        return new external_function_parameters (
            array(
                'peeringid' => new external_value(PARAM_INT, 'peering instance id.'),
                'userid' => new external_value(PARAM_INT, 'User id (empty or 0 for current user).', VALUE_DEFAULT, 0),
            )
        );
    }

    /**
     * Returns the grades information for the given peering and user.
     *
     * @param int $peeringid peering instance id
     * @param int $userid user id
     * @return array of warnings and the user plan
     * @since Moodle 3.4
     * @throws  moodle_exception
     */
    public static function get_grades($peeringid, $userid = 0) {
        global $USER;

        $params = array(
            'peeringid' => $peeringid,
            'userid' => $userid,
        );
        $params = self::validate_parameters(self::get_grades_parameters(), $params);
        $warnings = array();

        list($peering, $course, $cm, $context) = self::validate_peering($params['peeringid']);

        // Extra checks so only users with permissions can view other users plans.
        if (empty($params['userid']) || $params['userid'] == $USER->id) {
            $userid = $USER->id;
        } else {
            require_capability('mod/peering:viewallassessments', $context);
            $user = core_user::get_user($params['userid'], '*', MUST_EXIST);
            core_user::require_active_user($user);
            if (!$peering->check_group_membership($user->id)) {
                throw new moodle_exception('notingroup');
            }
            $userid = $user->id;
        }

        $finalgrades = $peering->get_gradebook_grades($userid);

        $result = array('warnings' => $warnings);
        if ($finalgrades !== false) {
            if (!empty($finalgrades->submissiongrade)) {
                if (is_numeric($finalgrades->submissiongrade->grade)) {
                    $result['submissionrawgrade'] = $finalgrades->submissiongrade->grade;
                }
                $result['submissionlongstrgrade'] = $finalgrades->submissiongrade->str_long_grade;
                $result['submissiongradehidden'] = $finalgrades->submissiongrade->hidden;
            }
            if (!empty($finalgrades->assessmentgrade)) {
                if (is_numeric($finalgrades->assessmentgrade->grade)) {
                    $result['assessmentrawgrade'] = $finalgrades->assessmentgrade->grade;
                }
                $result['assessmentlongstrgrade'] = $finalgrades->assessmentgrade->str_long_grade;
                $result['assessmentgradehidden'] = $finalgrades->assessmentgrade->hidden;
            }
        }

        return $result;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     * @since Moodle 3.4
     */
    public static function get_grades_returns() {
        return new external_single_structure(
            array(
                'assessmentrawgrade' => new external_value(PARAM_FLOAT, 'The assessment raw (numeric) grade.', VALUE_OPTIONAL),
                'assessmentlongstrgrade' => new external_value(PARAM_NOTAGS, 'The assessment string grade.', VALUE_OPTIONAL),
                'assessmentgradehidden' => new external_value(PARAM_BOOL, 'Whether the grade is hidden or not.', VALUE_OPTIONAL),
                'submissionrawgrade' => new external_value(PARAM_FLOAT, 'The submission raw (numeric) grade.', VALUE_OPTIONAL),
                'submissionlongstrgrade' => new external_value(PARAM_NOTAGS, 'The submission string grade.', VALUE_OPTIONAL),
                'submissiongradehidden' => new external_value(PARAM_BOOL, 'Whether the grade is hidden or not.', VALUE_OPTIONAL),
                'warnings' => new external_warnings(),
            )
        );
    }

    /**
     * Returns the description of the external function parameters.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function evaluate_assessment_parameters() {
        return new external_function_parameters(
            array(
                'assessmentid' => new external_value(PARAM_INT, 'Assessment id.'),
                'feedbacktext' => new external_value(PARAM_RAW, 'The feedback for the reviewer.', VALUE_DEFAULT, ''),
                'feedbackformat' => new external_value(PARAM_INT, 'The feedback format for text.', VALUE_DEFAULT, FORMAT_MOODLE),
                'weight' => new external_value(PARAM_INT, 'The new weight for the assessment.', VALUE_DEFAULT, 1),
                'gradinggradeover' => new external_value(PARAM_ALPHANUMEXT, 'The new grading grade.', VALUE_DEFAULT, ''),
            )
        );
    }


    /**
     * Evaluates an assessment (used by teachers for provide feedback to the reviewer).
     *
     * @param int $assessmentid the assessment id
     * @param str $feedbacktext the feedback for the reviewer
     * @param int $feedbackformat the feedback format for the reviewer text
     * @param int $weight the new weight for the assessment
     * @param mixed $gradinggradeover the new grading grade (empty for no overriding the grade)
     * @return array containing the status and warnings.
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function evaluate_assessment($assessmentid, $feedbacktext = '', $feedbackformat = FORMAT_MOODLE, $weight = 1,
            $gradinggradeover = '') {
        global $DB;

        $params = self::validate_parameters(
            self::evaluate_assessment_parameters(),
            array(
                'assessmentid' => $assessmentid,
                'feedbacktext' => $feedbacktext,
                'feedbackformat' => $feedbackformat,
                'weight' => $weight,
                'gradinggradeover' => $gradinggradeover,
            )
        );
        $warnings = array();

        // Get and validate the assessment, submission and peering.
        $assessment = $DB->get_record('peering_assessments', array('id' => $params['assessmentid']), '*', MUST_EXIST);
        $submission = $DB->get_record('peering_submissions', array('id' => $assessment->submissionid), '*', MUST_EXIST);
        list($peering, $course, $cm, $context) = self::validate_peering($submission->peeringid);

        // Check we can evaluate the assessment.
        $peering->check_view_assessment($assessment, $submission);
        $cansetassessmentweight = has_capability('mod/peering:allocate', $context);
        $canoverridegrades      = has_capability('mod/peering:overridegrades', $context);
        if (!$canoverridegrades && !$cansetassessmentweight) {
            throw new moodle_exception('nopermissions', 'error', '', 'evaluate assessments');
        }

        // Process data.
        $data = new stdClass;
        $data->asid = $assessment->id;
        $data->feedbackreviewer_editor = array(
            'text' => $params['feedbacktext'],
            'format' => $params['feedbackformat'],
        );
        $data->weight = $params['weight'];
        $data->gradinggradeover = $params['gradinggradeover'];

        $options = array(
            'editable' => true,
            'editableweight' => $cansetassessmentweight,
            'overridablegradinggrade' => $canoverridegrades
        );
        $feedbackform = $peering->get_feedbackreviewer_form(null, $assessment, $options);

        $errors = $feedbackform->validation((array) $data, array());
        // Extra checks for the new grade and weight.
        $possibleweights = peering::available_assessment_weights_list();
        if ($data->weight < 0 || $data->weight > max(array_keys($possibleweights))) {
            $errors['weight'] = 'The new weight must be higher or equal to 0 and cannot be higher than the maximum weight for
                assessment.';
        }
        if (is_numeric($data->gradinggradeover) &&
                ($data->gradinggradeover < 0 || $data->gradinggradeover > $peering->gradinggrade)) {
            $errors['gradinggradeover'] = 'The new grade must be higher or equal to 0 and cannot be higher than the maximum grade
                for assessment.';
        }

        // We can get several errors, return them in warnings.
        if (!empty($errors)) {
            $status = false;
            foreach ($errors as $itemname => $message) {
                $warnings[] = array(
                    'item' => $itemname,
                    'itemid' => 0,
                    'warningcode' => 'fielderror',
                    'message' => s($message)
                );
            }
        } else {
            $peering->evaluate_assessment($assessment, $data, $cansetassessmentweight, $canoverridegrades);
            $status = true;
        }

        return array(
            'status' => $status,
            'warnings' => $warnings,
        );
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.4
     */
    public static function evaluate_assessment_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'status: true if the assessment was evaluated, false otherwise.'),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function get_grades_report_parameters() {
        return new external_function_parameters(
            array(
                'peeringid' => new external_value(PARAM_INT, 'peering instance id.'),
                'groupid' => new external_value(PARAM_INT, 'Group id, 0 means that the function will determine the user group.',
                                                   VALUE_DEFAULT, 0),
                'sortby' => new external_value(PARAM_ALPHA, 'sort by this element: lastname, firstname, submissiontitle,
                    submissionmodified, submissiongrade, gradinggrade.', VALUE_DEFAULT, 'lastname'),
                'sortdirection' => new external_value(PARAM_ALPHA, 'sort direction: ASC or DESC', VALUE_DEFAULT, 'ASC'),
                'page' => new external_value(PARAM_INT, 'The page of records to return.', VALUE_DEFAULT, 0),
                'perpage' => new external_value(PARAM_INT, 'The number of records to return per page.', VALUE_DEFAULT, 0),
            )
        );
    }

    /**
     * Retrieves the assessment grades report.
     *
     * @param int $peeringid       the peering instance id
     * @param int $groupid          (optional) group id, 0 means that the function will determine the user group
     * @param string $sortby        sort by this element
     * @param string $sortdirection sort direction: ASC or DESC
     * @param int $page             page of records to return
     * @param int $perpage          number of records to return per page
     * @return array of warnings and the report data
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function get_grades_report($peeringid, $groupid = 0, $sortby = 'lastname', $sortdirection = 'ASC',
            $page = 0, $perpage = 0) {
        global $USER;

        $params = array('peeringid' => $peeringid, 'groupid' => $groupid, 'sortby' => $sortby, 'sortdirection' => $sortdirection,
            'page' => $page, 'perpage' => $perpage);
        $params = self::validate_parameters(self::get_grades_report_parameters(), $params);
        $submissions = $warnings = array();

        $sortallowedvalues = array('lastname', 'firstname', 'submissiontitle', 'submissionmodified', 'submissiongrade',
            'gradinggrade');
        if (!in_array($params['sortby'], $sortallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortby parameter (value: ' . $sortby . '),' .
                'allowed values are: ' . implode(',', $sortallowedvalues));
        }

        $sortdirection = strtoupper($params['sortdirection']);
        $directionallowedvalues = array('ASC', 'DESC');
        if (!in_array($sortdirection, $directionallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortdirection parameter (value: ' . $sortdirection . '),' .
                'allowed values are: ' . implode(',', $directionallowedvalues));
        }

        list($peering, $course, $cm, $context) = self::validate_peering($params['peeringid']);
        require_capability('mod/peering:viewallassessments', $context);

        if (!empty($params['groupid'])) {
            $groupid = $params['groupid'];
            // Determine is the group is visible to user.
            if (!groups_group_visible($groupid, $course, $cm)) {
                throw new moodle_exception('notingroup');
            }
        } else {
            // Check to see if groups are being used here.
            if ($groupmode = groups_get_activity_groupmode($cm)) {
                $groupid = groups_get_activity_group($cm);
                // Determine is the group is visible to user (this is particullary for the group 0 -> all groups).
                if (!groups_group_visible($groupid, $course, $cm)) {
                    throw new moodle_exception('notingroup');
                }
            } else {
                $groupid = 0;
            }
        }

        if ($peering->phase >= peering::PHASE_SUBMISSION) {
            $showauthornames = has_capability('mod/peering:viewauthornames', $context);
            $showreviewernames = has_capability('mod/peering:viewreviewernames', $context);

            if ($peering->phase >= peering::PHASE_EVALUATION) {
                $showsubmissiongrade = true;
                $showgradinggrade = true;
            } else {
                $showsubmissiongrade = false;
                $showgradinggrade = false;
            }

            $data = $peering->prepare_grading_report_data($USER->id, $groupid, $params['page'], $params['perpage'],
                $params['sortby'], $sortdirection);

            if (!empty($data)) {
                // Populate the display options for the submissions report.
                $reportopts                      = new stdclass();
                $reportopts->showauthornames     = $showauthornames;
                $reportopts->showreviewernames   = $showreviewernames;
                $reportopts->sortby              = $params['sortby'];
                $reportopts->sorthow             = $sortdirection;
                $reportopts->showsubmissiongrade = $showsubmissiongrade;
                $reportopts->showgradinggrade    = $showgradinggrade;
                $reportopts->peeringphase       = $peering->phase;

                $report = new peering_grading_report($data, $reportopts);
                return array(
                    'report' => $report->export_data_for_external(),
                    'warnings' => array(),
                );
            }
        }
        throw new moodle_exception('nothingfound', 'peering');
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.4
     */
    public static function get_grades_report_returns() {

        $reviewstructure = new external_single_structure(
            array(
                'userid' => new external_value(PARAM_INT, 'The id of the user (0 when is configured to do not display names).'),
                'assessmentid' => new external_value(PARAM_INT, 'The id of the assessment.'),
                'submissionid' => new external_value(PARAM_INT, 'The id of the submission assessed.'),
                'grade' => new external_value(PARAM_FLOAT, 'The grade for submission.'),
                'gradinggrade' => new external_value(PARAM_FLOAT, 'The grade for assessment.'),
                'gradinggradeover' => new external_value(PARAM_FLOAT, 'The aggregated grade overrided.'),
                'weight' => new external_value(PARAM_INT, 'The weight of the assessment for aggregation.'),
            )
        );

        return new external_single_structure(
            array(
                'report' => new external_single_structure(
                    array(
                        'grades' => new external_multiple_structure(
                            new external_single_structure(
                                array(
                                    'userid' => new external_value(PARAM_INT, 'The id of the user being displayed in the report.'),
                                    'submissionid' => new external_value(PARAM_INT, 'Submission id.'),
                                    'submissiontitle' => new external_value(PARAM_RAW, 'Submission title.'),
                                    'submissionmodified' => new external_value(PARAM_INT, 'Timestamp submission was updated.'),
                                    'submissiongrade' => new external_value(PARAM_FLOAT, 'Aggregated grade for the submission.',
                                        VALUE_OPTIONAL),
                                    'gradinggrade' => new external_value(PARAM_FLOAT, 'Computed grade for the assessment.',
                                        VALUE_OPTIONAL),
                                    'submissiongradeover' => new external_value(PARAM_FLOAT, 'Grade for the assessment overrided
                                        by the teacher.', VALUE_OPTIONAL),
                                    'submissiongradeoverby' => new external_value(PARAM_INT, 'The id of the user who overrided
                                        the grade.', VALUE_OPTIONAL),
                                    'submissionpublished' => new external_value(PARAM_INT, 'Whether is a submission published.',
                                        VALUE_OPTIONAL),
                                    'reviewedby' => new external_multiple_structure($reviewstructure, 'The users who reviewed the
                                        user submission.', VALUE_OPTIONAL),
                                    'reviewerof' => new external_multiple_structure($reviewstructure, 'The assessments the user
                                        reviewed.', VALUE_OPTIONAL),
                                )
                            )
                        ),
                        'totalcount' => new external_value(PARAM_INT, 'Number of total submissions.'),
                    )
                ),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Describes the parameters for view_submission.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function view_submission_parameters() {
        return new external_function_parameters (
            array(
                'submissionid' => new external_value(PARAM_INT, 'Submission id'),
            )
        );
    }

    /**
     * Trigger the submission viewed event.
     *
     * @param int $submissionid submission id
     * @return array of warnings and status result
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function view_submission($submissionid) {
        global $DB;

        $params = self::validate_parameters(self::view_submission_parameters(), array('submissionid' => $submissionid));
        $warnings = array();

        // Get and validate the submission and peering.
        $submission = $DB->get_record('peering_submissions', array('id' => $params['submissionid']), '*', MUST_EXIST);
        list($peering, $course, $cm, $context) = self::validate_peering($submission->peeringid);

        self::validate_submission($submission, $peering);

        $peering->set_submission_viewed($submission);

        $result = array(
            'status' => true,
            'warnings' => $warnings,
        );
        return $result;
    }

    /**
     * Describes the view_submission return value.
     *
     * @return external_single_structure
     * @since Moodle 3.4
     */
    public static function view_submission_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'warnings' => new external_warnings(),
            )
        );
    }

    /**
     * Returns the description of the external function parameters.
     *
     * @return external_function_parameters
     * @since Moodle 3.4
     */
    public static function evaluate_submission_parameters() {
        return new external_function_parameters(
            array(
                'submissionid' => new external_value(PARAM_INT, 'submission id.'),
                'feedbacktext' => new external_value(PARAM_RAW, 'The feedback for the author.', VALUE_DEFAULT, ''),
                'feedbackformat' => new external_value(PARAM_INT, 'The feedback format for text.', VALUE_DEFAULT, FORMAT_MOODLE),
                'published' => new external_value(PARAM_BOOL, 'Publish the submission for others?.', VALUE_DEFAULT, false),
                'gradeover' => new external_value(PARAM_ALPHANUMEXT, 'The new submission grade.', VALUE_DEFAULT, ''),
            )
        );
    }


    /**
     * Evaluates a submission (used by teachers for provide feedback or override the submission grade).
     *
     * @param int $submissionid the submission id
     * @param str $feedbacktext the feedback for the author
     * @param int $feedbackformat the feedback format for the reviewer text
     * @param bool $published whether to publish the submission for other users
     * @param mixed $gradeover the new submission grade (empty for no overriding the grade)
     * @return array containing the status and warnings.
     * @since Moodle 3.4
     * @throws moodle_exception
     */
    public static function evaluate_submission($submissionid, $feedbacktext = '', $feedbackformat = FORMAT_MOODLE, $published = 1,
            $gradeover = '') {
        global $DB;

        $params = self::validate_parameters(
            self::evaluate_submission_parameters(),
            array(
                'submissionid' => $submissionid,
                'feedbacktext' => $feedbacktext,
                'feedbackformat' => $feedbackformat,
                'published' => $published,
                'gradeover' => $gradeover,
            )
        );
        $warnings = array();

        // Get and validate the submission, submission and peering.
        $submission = $DB->get_record('peering_submissions', array('id' => $params['submissionid']), '*', MUST_EXIST);
        list($peering, $course, $cm, $context) = self::validate_peering($submission->peeringid);

        // Check we can evaluate the submission.
        self::validate_submission($submission, $peering);
        $canpublish  = has_capability('mod/peering:publishsubmissions', $context);
        $canoverride = ($peering->phase == peering::PHASE_EVALUATION &&
            has_capability('mod/peering:overridegrades', $context));

        if (!$canpublish && !$canoverride) {
            throw new moodle_exception('nopermissions', 'error', '', 'evaluate submission');
        }

        // Process data.
        $data = new stdClass;
        $data->id = $submission->id;
        $data->feedbackauthor_editor = array(
            'text' => $params['feedbacktext'],
            'format' => $params['feedbackformat'],
        );
        $data->published = $params['published'];
        $data->gradeover = $params['gradeover'];

        $options = array(
            'editable' => true,
            'editablepublished' => $canpublish,
            'overridablegrade' => $canoverride
        );
        $feedbackform = $peering->get_feedbackauthor_form(null, $submission, $options);

        $errors = $feedbackform->validation((array) $data, array());
        // Extra checks for the new grade (if set).
        if (is_numeric($data->gradeover) && $data->gradeover > $peering->grade) {
            $errors['gradeover'] = 'The new grade cannot be higher than the maximum grade for submission.';
        }

        // We can get several errors, return them in warnings.
        if (!empty($errors)) {
            $status = false;
            foreach ($errors as $itemname => $message) {
                $warnings[] = array(
                    'item' => $itemname,
                    'itemid' => 0,
                    'warningcode' => 'fielderror',
                    'message' => s($message)
                );
            }
        } else {
            $peering->evaluate_submission($submission, $data, $canpublish, $canoverride);
            $status = true;
        }

        return array(
            'status' => $status,
            'warnings' => $warnings,
        );
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.4
     */
    public static function evaluate_submission_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'status: true if the submission was evaluated, false otherwise.'),
                'warnings' => new external_warnings()
            )
        );
    }
}
