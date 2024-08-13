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
 * Assess a submission or view the single assessment
 *
 * Assessment id parameter must be passed. The script displays the submission and
 * the assessment form. If the current user is the reviewer and the assessing is
 * allowed, new assessment can be saved.
 * If the assessing is not allowed (for example, the assessment period is over
 * or the current user is eg a teacher), the assessment form is opened
 * in a non-editable mode.
 * The capability 'mod/peering:peerassess' is intentionally not checked here.
 * The user is considered as a reviewer if the corresponding assessment record
 * has been prepared for him/her (during the allocation). So even a user without the
 * peerassess capability (like a 'teacher', for example) can become a reviewer.
 *
 * @package    mod_peering
 * @copyright  2024 Johann Mellin <johann.mellin@tuhh.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');

$asid       = required_param('asid', PARAM_INT);  // assessment id
$assessment = $DB->get_record('peering_assessments', array('id' => $asid), '*', MUST_EXIST);
$submission = $DB->get_record('peering_submissions', array('id' => $assessment->submissionid, 'example' => 0), '*', MUST_EXIST);
$peering   = $DB->get_record('peering', array('id' => $submission->peeringid), '*', MUST_EXIST);
$course     = $DB->get_record('course', array('id' => $peering->course), '*', MUST_EXIST);
$cm         = get_coursemodule_from_instance('peering', $peering->id, $course->id, false, MUST_EXIST);

require_login($course, false, $cm);
if (isguestuser()) {
    throw new \moodle_exception('guestsarenotallowed');
}
$peering = new peering($peering, $cm, $course);

$PAGE->set_url($peering->assess_url($assessment->id));
$PAGE->set_title($peering->name);
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->set_attrs([
    "hidecompletion" => true,
    "description" => ""
]);

$PAGE->navbar->add(get_string('assessingsubmission', 'peering'));
$PAGE->set_secondary_active_tab('modulepage');

$cansetassessmentweight = has_capability('mod/peering:allocate', $peering->context);
if($peering->autonomousgroups){
    $canoverridegrades = true;
}else{
    $canoverridegrades = has_capability('mod/peering:overridegrades', $peering->context);
}

$isreviewer             = ($USER->id == $assessment->reviewerid);

$peering->check_view_assessment($assessment, $submission);

// only the reviewer is allowed to modify the assessment
if ($isreviewer and $peering->assessing_allowed($USER->id)) {
    $assessmenteditable = true;
} else {
    $assessmenteditable = false;
}

// check that all required examples have been assessed by the user
if ($assessmenteditable) {

    list($assessed, $notice) = $peering->check_examples_assessed_before_assessment($assessment->reviewerid);
    if (!$assessed) {
        echo $output->header();
        notice(get_string($notice, 'peering'), new moodle_url('/mod/peering/view.php', array('id' => $cm->id)));
        echo $output->footer();
        exit;
    }
}


// load the grading strategy logic
$strategy = $peering->grading_strategy_instance();

if (is_null($assessment->grade) and !$assessmenteditable) {
   
    $mform = null;
} else {
 
    // Are there any other pending assessments to do but this one?
    if ($assessmenteditable) {
        $pending = $peering->get_pending_assessments_by_reviewer($assessment->reviewerid, $assessment->id);
    } else {
        $pending = array();
    }
    // load the assessment form and process the submitted data eventually
    $mform = $strategy->get_assessment_form($PAGE->url, 'assessment', $assessment, $assessmenteditable,
                                        array('editableweight' => $cansetassessmentweight, 'pending' => !empty($pending)));

    // Set data managed by the peering core, subplugins set their own data themselves.
    $currentdata = (object)array(
        'weight' => $assessment->weight,
        'feedbackauthor' => $assessment->feedbackauthor,
        'feedbackauthorformat' => $assessment->feedbackauthorformat,
    );
    if ($assessmenteditable and $peering->overallfeedbackmode) {
        $currentdata = file_prepare_standard_editor($currentdata, 'feedbackauthor', $peering->overall_feedback_content_options(),
            $peering->context, 'mod_peering', 'overallfeedback_content', $assessment->id);
        if ($peering->overallfeedbackfiles) {
            $currentdata = file_prepare_standard_filemanager($currentdata, 'feedbackauthorattachment',
                $peering->overall_feedback_attachment_options(), $peering->context, 'mod_peering', 'overallfeedback_attachment',
                $assessment->id);
        }
    }
    $mform->set_data($currentdata);

    if ($mform->is_cancelled()) {
        redirect($peering->view_url());
    } elseif ($assessmenteditable and ($data = $mform->get_data())) {

        // Add or update assessment.
        $rawgrade = $peering->edit_assessment($assessment, $submission, $data, $strategy);

        // And finally redirect the user's browser.
        if (!is_null($rawgrade) and isset($data->saveandclose)) {
            redirect($peering->view_url());
        } else if (!is_null($rawgrade) and isset($data->saveandshownext)) {
            $next = reset($pending);
            if (!empty($next)) {
                redirect($peering->assess_url($next->id));
            } else {
                redirect($PAGE->url); // This should never happen but just in case...
            }
        } else {
            // either it is not possible to calculate the $rawgrade
            // or the reviewer has chosen "Save and continue"
            redirect($PAGE->url);
        }
    }
}

// load the form to override gradinggrade and/or set weight and process the submitted data eventually
if ($canoverridegrades or $cansetassessmentweight) {
    $options = array(
        'editable' => true,
        'editableweight' => $cansetassessmentweight,
        'overridablegradinggrade' => $canoverridegrades);
    $feedbackform = $peering->get_feedbackreviewer_form($PAGE->url, $assessment, $options);
    if ($data = $feedbackform->get_data()) {
        $peering->evaluate_assessment($assessment, $data, $cansetassessmentweight, $canoverridegrades);
        $peering->aggregate_grading_grades();
        redirect($peering->view_url());
    }
}

// output starts here
$output = $PAGE->get_renderer('mod_peering');      // peering renderer

echo $output->header();
echo $output->heading(get_string('assessedsubmission', 'peering'), 3);

$submission = $peering->get_submission_by_id($submission->id);     // reload so can be passed to the renderer
echo $output->render($peering->prepare_submission($submission, has_capability('mod/peering:viewauthornames', $peering->context)));

// show instructions for assessing as they may contain important information
// for evaluating the assessment
if (trim($peering->instructreviewers)) {
    $instructions = file_rewrite_pluginfile_urls($peering->instructreviewers, 'pluginfile.php', $PAGE->context->id,
        'mod_peering', 'instructreviewers', null, peering::instruction_editors_options($PAGE->context));
    print_collapsible_region_start('', 'peering-viewlet-instructreviewers', get_string('instructreviewers', 'peering'),
            'peering-viewlet-instructreviewers-collapsed');
    echo $output->box(format_text($instructions, $peering->instructreviewersformat, array('overflowdiv'=>true)), array('generalbox', 'instructions'));
    print_collapsible_region_end();
}

// extend the current assessment record with user details
$assessment = $peering->get_assessment_by_id($assessment->id);

if ($isreviewer) {

    $options    = array(
        'showreviewer'  => true,
        'showauthor'    => has_capability('mod/peering:viewauthornames', $peering->context),
        'showform'      => $assessmenteditable or !is_null($assessment->grade),
        'showweight'    => true,
    );

    $assessment = $peering->prepare_assessment($assessment, $mform, $options);
    $assessment->title = get_string('assessmentbyyourself', 'peering');
    echo $output->render($assessment);

} else {

    $options    = array(
        'showreviewer'  => has_capability('mod/peering:viewreviewernames', $peering->context),
        'showauthor'    => has_capability('mod/peering:viewauthornames', $peering->context),
        'showform'      => $assessmenteditable or !is_null($assessment->grade),
        'showweight'    => true,
    );
    $assessment = $peering->prepare_assessment($assessment, $mform, $options);
    echo $output->render($assessment);
}


if (!$assessmenteditable and $canoverridegrades) {

    $feedbackform->display();
}

echo $output->footer();
