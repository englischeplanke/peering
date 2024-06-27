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
 * View a single (usually the own) submission, submit own work.
 *
 * @package    mod_peering
 * @copyright  2024 Johann Mellin <johann.mellin@tuhh.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');

$cmid = required_param('cmid', PARAM_INT); // Course module id.
$id = optional_param('id', 0, PARAM_INT); // Submission id.
$edit = optional_param('edit', false, PARAM_BOOL); // Open the page for editing?
$assess = optional_param('assess', false, PARAM_BOOL); // Instant assessment required.
$delete = optional_param('delete', false, PARAM_BOOL); // Submission removal requested.
$confirm = optional_param('confirm', false, PARAM_BOOL); // Submission removal request confirmed.

$cm = get_coursemodule_from_id('peering', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

require_login($course, false, $cm);
if (isguestuser()) {
    throw new \moodle_exception('guestsarenotallowed');
}

$peeringrecord = $DB->get_record('peering', array('id' => $cm->instance), '*', MUST_EXIST);
$peering = new peering($peeringrecord, $cm, $course);

$PAGE->set_url($peering->submission_url(), array('cmid' => $cmid, 'id' => $id));

$PAGE->set_secondary_active_tab("modulepage");

if ($edit) {
    $PAGE->url->param('edit', $edit);
}

if ($id) { // submission is specified
    $submission = $peering->get_submission_by_id($id);

} else { // no submission specified
    if (!$submission = $peering->get_submission_by_author($USER->id)) {
        $submission = new stdclass();
        $submission->id = null;
        $submission->authorid = $USER->id;
        $submission->example = 0;
        $submission->grade = null;
        $submission->gradeover = null;
        $submission->published = null;
        $submission->feedbackauthor = null;
        $submission->feedbackauthorformat = editors_get_preferred_format();
    }
}

$ownsubmission  = $submission->authorid == $USER->id;
$canviewall     = has_capability('mod/peering:viewallsubmissions', $peering->context);
$cansubmit      = has_capability('mod/peering:submit', $peering->context);
$canallocate    = has_capability('mod/peering:allocate', $peering->context);
$canpublish     = has_capability('mod/peering:publishsubmissions', $peering->context);
$canoverride    = (($peering->phase == peering::PHASE_EVALUATION) and has_capability('mod/peering:overridegrades', $peering->context));
$candeleteall   = has_capability('mod/peering:deletesubmissions', $peering->context);
$userassessment = $peering->get_assessment_of_submission_by_user($submission->id, $USER->id);
$isreviewer     = !empty($userassessment);
$editable       = ($cansubmit and $ownsubmission);
$deletable      = $candeleteall;
$ispublished    = ($peering->phase == peering::PHASE_CLOSED
                    and $submission->published == 1
                    and has_capability('mod/peering:viewpublishedsubmissions', $peering->context));

if (empty($submission->id) and !$peering->creating_submission_allowed($USER->id)) {
    $editable = false;
}
if ($submission->id and !$peering->modifying_submission_allowed($USER->id)) {
    $editable = false;
}

$canviewall = $canviewall && $peering->check_group_membership($submission->authorid);

$editable = ($editable && $peering->check_examples_assessed_before_submission($USER->id));
$edit = ($editable and $edit);

if (!$candeleteall and $ownsubmission and $editable) {
    // Only allow the student to delete their own submission if it's still editable and hasn't been assessed.
    if (count($peering->get_assessments_of_submission($submission->id)) > 0) {
        $deletable = false;
    } else {
        $deletable = true;
    }
}

if ($submission->id and $delete and $confirm and $deletable) {
    require_sesskey();
    $peering->delete_submission($submission);

    redirect($peering->view_url());
}

$seenaspublished = false; // is the submission seen as a published submission?

if ($submission->id and ($ownsubmission or $canviewall or $isreviewer)) {
    // ok you can go
} elseif ($submission->id and $ispublished) {
    // ok you can go
    $seenaspublished = true;
} elseif (is_null($submission->id) and $cansubmit) {
    // ok you can go
} else {
    throw new \moodle_exception('nopermissions', 'error', $peering->view_url(), 'view or create submission');
}

if ($submission->id) {
    // Trigger submission viewed event.
    $peering->set_submission_viewed($submission);
}

if ($assess and $submission->id and !$isreviewer and $canallocate and $peering->assessing_allowed($USER->id)) {
    require_sesskey();
    $assessmentid = $peering->add_allocation($submission, $USER->id);
    redirect($peering->assess_url($assessmentid));
}

if ($edit) {
    require_once(__DIR__.'/submission_form.php');

    $submission = file_prepare_standard_editor($submission, 'content', $peering->submission_content_options(),
        $peering->context, 'mod_peering', 'submission_content', $submission->id);

    $submission = file_prepare_standard_filemanager($submission, 'attachment', $peering->submission_attachment_options(),
        $peering->context, 'mod_peering', 'submission_attachment', $submission->id);

    $mform = new peering_submission_form($PAGE->url, array('current' => $submission, 'peering' => $peering,
        'contentopts' => $peering->submission_content_options(), 'attachmentopts' => $peering->submission_attachment_options()));

    if ($mform->is_cancelled()) {
        redirect($peering->view_url());

    } elseif ($cansubmit and $formdata = $mform->get_data()) {

        $formdata->id = $submission->id;
        // Creates or updates submission.
        $submission->id = $peering->edit_submission($formdata);

        redirect($peering->submission_url($submission->id));
    }
}

// load the form to override grade and/or publish the submission and process the submitted data eventually
if (!$edit and ($canoverride or $canpublish)) {
    $options = array(
        'editable' => true,
        'editablepublished' => $canpublish,
        'overridablegrade' => $canoverride);
    $feedbackform = $peering->get_feedbackauthor_form($PAGE->url, $submission, $options);
    if ($data = $feedbackform->get_data()) {
        $peering->evaluate_submission($submission, $data, $canpublish, $canoverride);
        redirect($peering->view_url());
    }
}

$PAGE->set_title($peering->name);
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->set_attrs([
    'hidecompletion' => true,
    'description' => ''
]);
if ($edit) {
    $PAGE->navbar->add(get_string('mysubmission', 'peering'), $peering->submission_url(), navigation_node::TYPE_CUSTOM);
    $PAGE->navbar->add(get_string('editingsubmission', 'peering'));
} elseif ($ownsubmission) {
    $PAGE->navbar->add(get_string('mysubmission', 'peering'));
} else {
    $PAGE->navbar->add(get_string('submission', 'peering'));
}

// Output starts here
$output = $PAGE->get_renderer('mod_peering');
echo $output->header();
echo $output->heading(get_string('mysubmission', 'peering'), 3);

// show instructions for submitting as thay may contain some list of questions and we need to know them
// while reading the submitted answer
if (trim($peering->instructauthors)) {
    $instructions = file_rewrite_pluginfile_urls($peering->instructauthors, 'pluginfile.php', $PAGE->context->id,
        'mod_peering', 'instructauthors', null, peering::instruction_editors_options($PAGE->context));
    print_collapsible_region_start('', 'peering-viewlet-instructauthors', get_string('instructauthors', 'peering'),
            'peering-viewlet-instructauthors-collapsed');
    echo $output->box(format_text($instructions, $peering->instructauthorsformat, array('overflowdiv'=>true)), array('generalbox', 'instructions'));
    print_collapsible_region_end();
}

// if in edit mode, display the form to edit the submission

if ($edit) {
    if (!empty($CFG->enableplagiarism)) {
        require_once($CFG->libdir.'/plagiarismlib.php');
        echo plagiarism_print_disclosure($cm->id);
    }
    $mform->display();
    echo $output->footer();
    die();
}

// Confirm deletion (if requested).
if ($deletable and $delete) {
    $prompt = get_string('submissiondeleteconfirm', 'peering');
    if ($candeleteall) {
        $count = count($peering->get_assessments_of_submission($submission->id));
        if ($count > 0) {
            $prompt = get_string('submissiondeleteconfirmassess', 'peering', ['count' => $count]);
        }
    }
    echo $output->confirm($prompt, new moodle_url($PAGE->url, ['delete' => 1, 'confirm' => 1]), $peering->view_url());
}

// else display the submission

if ($submission->id) {
    if ($seenaspublished) {
        $showauthor = has_capability('mod/peering:viewauthorpublished', $peering->context);
    } else {
        $showauthor = has_capability('mod/peering:viewauthornames', $peering->context);
    }
    echo $output->render($peering->prepare_submission($submission, $showauthor));
} else {
    echo $output->box(get_string('noyoursubmission', 'peering'));
}

// If not at removal confirmation screen, some action buttons can be displayed.
if (!$delete) {
    // Display create/edit button.
    if ($editable) {
        if ($submission->id) {
            $btnurl = new moodle_url($PAGE->url, array('edit' => 'on', 'id' => $submission->id));
            $btntxt = get_string('editsubmission', 'peering');
        } else {
            $btnurl = new moodle_url($PAGE->url, array('edit' => 'on'));
            $btntxt = get_string('createsubmission', 'peering');
        }
        echo $output->box($output->single_button($btnurl, $btntxt, 'get'), 'mr-1 inline');
    }

    // Display delete button.
    if ($submission->id and $deletable) {
        $url = new moodle_url($PAGE->url, array('delete' => 1));
        echo $output->box($output->single_button($url, get_string('deletesubmission', 'peering'), 'get'), 'mr-1 inline');
    }

    // Display assess button.
    if ($submission->id and !$edit and !$isreviewer and $canallocate and $peering->assessing_allowed($USER->id)) {
        $url = new moodle_url($PAGE->url, array('assess' => 1));
        echo $output->box($output->single_button($url, get_string('assess', 'peering'), 'post'), 'mr-1 inline');
    }
}

if (($peering->phase == peering::PHASE_CLOSED) and ($ownsubmission or $canviewall)) {
    if (!empty($submission->gradeoverby) and strlen(trim($submission->feedbackauthor)) > 0) {
        echo $output->render(new peering_feedback_author($submission));
    }
}

// and possibly display the submission's review(s)

if ($isreviewer) {
    // user's own assessment
    $strategy   = $peering->grading_strategy_instance();
    $mform      = $strategy->get_assessment_form($PAGE->url, 'assessment', $userassessment, false);
    $options    = array(
        'showreviewer'  => true,
        'showauthor'    => $showauthor,
        'showform'      => !is_null($userassessment->grade),
        'showweight'    => true,
    );
    $assessment = $peering->prepare_assessment($userassessment, $mform, $options);
    $assessment->title = get_string('assessmentbyyourself', 'peering');

    if ($peering->assessing_allowed($USER->id)) {
        if (is_null($userassessment->grade)) {
            $assessment->add_action($peering->assess_url($assessment->id), get_string('assess', 'peering'));
        } else {
            $assessment->add_action($peering->assess_url($assessment->id), get_string('reassess', 'peering'));
        }
    }
    if ($canoverride) {
        $assessment->add_action($peering->assess_url($assessment->id), get_string('assessmentsettings', 'peering'));
    }

    echo $output->render($assessment);

    if ($peering->phase == peering::PHASE_CLOSED) {
        if (strlen(trim($userassessment->feedbackreviewer)) > 0) {
            echo $output->render(new peering_feedback_reviewer($userassessment));
        }
    }
}

if (has_capability('mod/peering:viewallassessments', $peering->context) or ($ownsubmission and $peering->assessments_available())) {
    // other assessments
    $strategy       = $peering->grading_strategy_instance();
    $assessments    = $peering->get_assessments_of_submission($submission->id);
    $showreviewer   = has_capability('mod/peering:viewreviewernames', $peering->context);
    foreach ($assessments as $assessment) {
        if ($assessment->reviewerid == $USER->id) {
            // own assessment has been displayed already
            continue;
        }
        if (is_null($assessment->grade) and !has_capability('mod/peering:viewallassessments', $peering->context)) {
            // students do not see peer-assessment that are not graded yet
            continue;
        }
        $mform      = $strategy->get_assessment_form($PAGE->url, 'assessment', $assessment, false);
        $options    = array(
            'showreviewer'  => $showreviewer,
            'showauthor'    => $showauthor,
            'showform'      => !is_null($assessment->grade),
            'showweight'    => true,
        );
        $displayassessment = $peering->prepare_assessment($assessment, $mform, $options);
        if ($canoverride) {
            $displayassessment->add_action($peering->assess_url($assessment->id), get_string('assessmentsettings', 'peering'));
        }
        echo $output->render($displayassessment);

        if ($peering->phase == peering::PHASE_CLOSED and has_capability('mod/peering:viewallassessments', $peering->context)) {
            if (strlen(trim($assessment->feedbackreviewer)) > 0) {
                echo $output->render(new peering_feedback_reviewer($assessment));
            }
        }
    }
}

if (!$edit and $canoverride) {
    // display a form to override the submission grade
    $feedbackform->display();
}

// If portfolios are enabled and we are not on the edit/removal confirmation screen, display a button to export this page.
// The export is not offered if the submission is seen as a published one (it has no relation to the current user.
if (!empty($CFG->enableportfolios)) {
    if (!$delete and !$edit and !$seenaspublished and $submission->id and ($ownsubmission or $canviewall or $isreviewer)) {
        if (has_capability('mod/peering:exportsubmissions', $peering->context)) {
            require_once($CFG->libdir.'/portfoliolib.php');

            $button = new portfolio_add_button();
            $button->set_callback_options('mod_peering_portfolio_caller', array(
                'id' => $peering->cm->id,
                'submissionid' => $submission->id,
            ), 'mod_peering');
            $button->set_formats(PORTFOLIO_FORMAT_RICHHTML);
            echo html_writer::start_tag('div', array('class' => 'singlebutton'));
            echo $button->to_html(PORTFOLIO_ADD_FULL_FORM, get_string('exportsubmission', 'peering'));
            echo html_writer::end_tag('div');
        }
    }
}

echo $output->footer();
