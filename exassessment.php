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
 * Assess an example submission
 *
 * @package    mod_peering
 * @copyright  2024 Johann Mellin <johann.mellin@tuhh.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');

$asid       = required_param('asid', PARAM_INT);  // assessment id
$assessment = $DB->get_record('peering_assessments', array('id' => $asid), '*', MUST_EXIST);
$example    = $DB->get_record('peering_submissions', array('id' => $assessment->submissionid, 'example' => 1), '*', MUST_EXIST);
$peering   = $DB->get_record('peering', array('id' => $example->peeringid), '*', MUST_EXIST);
$course     = $DB->get_record('course', array('id' => $peering->course), '*', MUST_EXIST);
$cm         = get_coursemodule_from_instance('peering', $peering->id, $course->id, false, MUST_EXIST);

require_login($course, false, $cm);
if (isguestuser()) {
    throw new \moodle_exception('guestsarenotallowed');
}
$peering = new peering($peering, $cm, $course);

$PAGE->set_url($peering->exassess_url($assessment->id));
$PAGE->set_title($peering->name);
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('assessingexample', 'peering'));
$PAGE->set_secondary_active_tab('modulepage');
$currenttab = 'assessment';

$canmanage  = has_capability('mod/peering:manageexamples', $peering->context);
$isreviewer = ($USER->id == $assessment->reviewerid);

if ($isreviewer or $canmanage) {
    // such a user can continue
} else {
    throw new \moodle_exception('nopermissions', 'error', $peering->view_url(), 'assess example submission');
}

// only the reviewer is allowed to modify the assessment
if (($canmanage and $assessment->weight == 1) or ($isreviewer and $peering->assessing_examples_allowed())) {
    $assessmenteditable = true;
} else {
    $assessmenteditable = false;
}

// load the grading strategy logic
$strategy = $peering->grading_strategy_instance();

// load the assessment form and process the submitted data eventually
$mform = $strategy->get_assessment_form($PAGE->url, 'assessment', $assessment, $assessmenteditable);

// Set data managed by the peering core, subplugins set their own data themselves.
$currentdata = (object)array(
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

    // Let the grading strategy subplugin save its data.
    $rawgrade = $strategy->save_assessment($assessment, $data);

    // Store the data managed by the peering core.
    $coredata = (object)array('id' => $assessment->id);
    if (isset($data->feedbackauthor_editor)) {
        $coredata->feedbackauthor_editor = $data->feedbackauthor_editor;
        $coredata = file_postupdate_standard_editor($coredata, 'feedbackauthor', $peering->overall_feedback_content_options(),
            $peering->context, 'mod_peering', 'overallfeedback_content', $assessment->id);
        unset($coredata->feedbackauthor_editor);
    }
    if (isset($data->feedbackauthorattachment_filemanager)) {
        $coredata->feedbackauthorattachment_filemanager = $data->feedbackauthorattachment_filemanager;
        $coredata = file_postupdate_standard_filemanager($coredata, 'feedbackauthorattachment',
            $peering->overall_feedback_attachment_options(), $peering->context, 'mod_peering', 'overallfeedback_attachment',
            $assessment->id);
        unset($coredata->feedbackauthorattachment_filemanager);
        if (empty($coredata->feedbackauthorattachment)) {
            $coredata->feedbackauthorattachment = 0;
        }
    }
    if ($canmanage) {
        // Remember the last one who edited the reference assessment.
        $coredata->reviewerid = $USER->id;
    }
    // Update the assessment data if there is something other than just the 'id'.
    if (count((array)$coredata) > 1 ) {
        $DB->update_record('peering_assessments', $coredata);
    }

    if (!is_null($rawgrade) and isset($data->saveandclose)) {
        if ($canmanage) {
            redirect($peering->view_url());
        } else {
            redirect($peering->excompare_url($example->id, $assessment->id));
        }
    } else {
        // either it is not possible to calculate the $rawgrade
        // or the reviewer has chosen "Save and continue"
        redirect($PAGE->url);
    }
}

// output starts here
$output = $PAGE->get_renderer('mod_peering');      // peering renderer
echo $output->header();
if (!$PAGE->has_secondary_navigation()) {
    echo $output->heading(format_string($peering->name));
}
echo $output->heading(get_string('assessedexample', 'peering'), 3);

$example = $peering->get_example_by_id($example->id);     // reload so can be passed to the renderer
echo $output->render($peering->prepare_example_submission(($example)));

// show instructions for assessing as thay may contain important information
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

if ($canmanage and $assessment->weight == 1) {
    $options = array(
        'showreviewer'  => false,
        'showauthor'    => false,
        'showform'      => true,
    );
    $assessment = $peering->prepare_example_reference_assessment($assessment, $mform, $options);
    $assessment->title = get_string('assessmentreference', 'peering');
    echo $output->render($assessment);

} else if ($isreviewer) {
    $options = array(
        'showreviewer'  => true,
        'showauthor'    => false,
        'showform'      => true,
    );
    $assessment = $peering->prepare_example_assessment($assessment, $mform, $options);
    $assessment->title = get_string('assessmentbyyourself', 'peering');
    echo $output->render($assessment);

} else if ($canmanage) {
    $options = array(
        'showreviewer'  => true,
        'showauthor'    => false,
        'showform'      => true,
        'showweight'    => false,
    );
    $assessment = $peering->prepare_example_assessment($assessment, $mform, $options);
    echo $output->render($assessment);
}

echo $output->footer();
