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
 * View, create or edit single example submission
 *
 * @package    mod_peering
 * @copyright  2024 Johann Mellin <johann.mellin@tuhh.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');

$cmid       = required_param('cmid', PARAM_INT);            // course module id
$id         = required_param('id', PARAM_INT);              // example submission id, 0 for the new one
$edit       = optional_param('edit', false, PARAM_BOOL);    // open for editing?
$delete     = optional_param('delete', false, PARAM_BOOL);  // example removal requested
$confirm    = optional_param('confirm', false, PARAM_BOOL); // example removal request confirmed
$assess     = optional_param('assess', false, PARAM_BOOL);  // assessment required

$cm         = get_coursemodule_from_id('peering', $cmid, 0, false, MUST_EXIST);
$course     = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

require_login($course, false, $cm);
if (isguestuser()) {
    throw new \moodle_exception('guestsarenotallowed');
}

$peering = $DB->get_record('peering', array('id' => $cm->instance), '*', MUST_EXIST);
$peering = new peering($peering, $cm, $course);

$PAGE->set_url($peering->exsubmission_url($id), array('edit' => $edit));
$PAGE->set_title($peering->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_secondary_active_tab('modulepage');
if ($edit) {
    $PAGE->navbar->add(get_string('exampleediting', 'peering'));
} else {
    $PAGE->navbar->add(get_string('example', 'peering'));
}
$output = $PAGE->get_renderer('mod_peering');

if ($id) { // example is specified
    $example = $peering->get_example_by_id($id);
} else { // no example specified - create new one
    require_capability('mod/peering:manageexamples', $peering->context);
    $example = new stdclass();
    $example->id = null;
    $example->authorid = $USER->id;
    $example->example = 1;
}

$canmanage  = has_capability('mod/peering:manageexamples', $peering->context);
$canassess  = has_capability('mod/peering:peerassess', $peering->context);
$refasid    = $DB->get_field('peering_assessments', 'id', array('submissionid' => $example->id, 'weight' => 1));

if ($example->id and ($canmanage or ($peering->assessing_examples_allowed() and $canassess))) {
    // ok you can go
} elseif (is_null($example->id) and $canmanage) {
    // ok you can go
} else {
    throw new \moodle_exception('nopermissions', 'error', $peering->view_url(), 'view or manage example submission');
}

if ($id and $delete and $confirm and $canmanage) {
    require_sesskey();
    $peering->delete_submission($example);
    redirect($peering->view_url());
}

if ($id and $assess and $canmanage) {
    // reference assessment of an example is the assessment with the weight = 1. There should be just one
    // such assessment
    require_sesskey();
    if (!$refasid) {
        $refasid = $peering->add_allocation($example, $USER->id, 1);
    }
    redirect($peering->exassess_url($refasid));
}

if ($id and $assess and $canassess) {
    // training assessment of an example is the assessment with the weight = 0
    require_sesskey();
    $asid = $DB->get_field('peering_assessments', 'id',
            array('submissionid' => $example->id, 'weight' => 0, 'reviewerid' => $USER->id));
    if (!$asid) {
        $asid = $peering->add_allocation($example, $USER->id, 0);
    }
    if ($asid == peering::ALLOCATION_EXISTS) {
        // the training assessment of the example was not found but the allocation already
        // exists. this probably means that the user is the author of the reference assessment.
        echo $output->header();
        echo $output->box(get_string('assessmentreferenceconflict', 'peering'));
        echo $output->continue_button($peering->view_url());
        echo $output->footer();
        die();
    }
    redirect($peering->exassess_url($asid));
}

if ($edit and $canmanage) {
    require_once(__DIR__.'/submission_form.php');

    $example = file_prepare_standard_editor($example, 'content', $peering->submission_content_options(),
        $peering->context, 'mod_peering', 'submission_content', $example->id);

    $example = file_prepare_standard_filemanager($example, 'attachment', $peering->submission_attachment_options(),
        $peering->context, 'mod_peering', 'submission_attachment', $example->id);

    $mform = new peering_submission_form($PAGE->url, array('current' => $example, 'peering' => $peering,
        'contentopts' => $peering->submission_content_options(), 'attachmentopts' => $peering->submission_attachment_options()));

    if ($mform->is_cancelled()) {
        redirect($peering->view_url());

    } elseif ($canmanage and $formdata = $mform->get_data()) {
        if ($formdata->example == 1) {
            // this was used just for validation, it must be set to one when dealing with example submissions
            unset($formdata->example);
        } else {
            throw new coding_exception('Invalid submission form data value: example');
        }
        $timenow = time();
        if (is_null($example->id)) {
            $formdata->peeringid     = $peering->id;
            $formdata->example        = 1;
            $formdata->authorid       = $USER->id;
            $formdata->timecreated    = $timenow;
            $formdata->feedbackauthorformat = editors_get_preferred_format();
        }
        $formdata->timemodified       = $timenow;
        $formdata->title              = trim($formdata->title);
        $formdata->content            = '';          // updated later
        $formdata->contentformat      = FORMAT_HTML; // updated later
        $formdata->contenttrust       = 0;           // updated later
        if (is_null($example->id)) {
            $example->id = $formdata->id = $DB->insert_record('peering_submissions', $formdata);
        } else {
            if (empty($formdata->id) or empty($example->id) or ($formdata->id != $example->id)) {
                throw new moodle_exception('err_examplesubmissionid', 'peering');
            }
        }

        // Save and relink embedded images and save attachments.
        // To be used when Online text is allowed as a submission type.
        if (!empty($formdata->content_editor)) {
            $formdata = file_postupdate_standard_editor($formdata, 'content', $peering->submission_content_options(),
                $peering->context, 'mod_peering', 'submission_content', $example->id);
            $formdata = file_postupdate_standard_filemanager($formdata, 'attachment', $peering->submission_attachment_options(),
                $peering->context, 'mod_peering', 'submission_attachment', $example->id);
        }

        if (empty($formdata->attachment)) {
            // explicit cast to zero integer
            $formdata->attachment = 0;
        }
        // store the updated values or re-save the new example (re-saving needed because URLs are now rewritten)
        $DB->update_record('peering_submissions', $formdata);
        redirect($peering->exsubmission_url($formdata->id));
    }
}

// Output starts here
echo $output->header();
if (!$PAGE->has_secondary_navigation()) {
    echo $output->heading(format_string($peering->name), 2);
}

// show instructions for submitting as they may contain some list of questions and we need to know them
// while reading the submitted answer
if (trim($peering->instructauthors)) {
    $instructions = file_rewrite_pluginfile_urls($peering->instructauthors, 'pluginfile.php', $PAGE->context->id,
        'mod_peering', 'instructauthors', null, peering::instruction_editors_options($PAGE->context));
    print_collapsible_region_start('', 'peering-viewlet-instructauthors', get_string('instructauthors', 'peering'),
            'peering-viewlet-instructauthors-collapsed');
    echo $output->box(format_text($instructions, $peering->instructauthorsformat, array('overflowdiv'=>true)), array('generalbox', 'instructions'));
    print_collapsible_region_end();
}

// if in edit mode, display the form to edit the example
if ($edit and $canmanage) {
    $mform->display();
    echo $output->footer();
    die();
}

// else display the example...
if ($example->id) {
    if ($canmanage and $delete) {
    echo $output->confirm(get_string('exampledeleteconfirm', 'peering'),
            new moodle_url($PAGE->url, array('delete' => 1, 'confirm' => 1)), $peering->view_url());
    }
    if ($canmanage and !$delete and !$DB->record_exists_select('peering_assessments',
            'grade IS NOT NULL AND weight=1 AND submissionid = ?', array($example->id))) {
        echo $output->confirm(get_string('assessmentreferenceneeded', 'peering'),
                new moodle_url($PAGE->url, array('assess' => 1)), $peering->view_url());
    }
    echo $output->render($peering->prepare_example_submission($example));
}
// ...with an option to edit or remove it
echo $output->container_start('buttonsbar');
if ($canmanage) {
    if (empty($edit) and empty($delete)) {
        $aurl = new moodle_url($peering->exsubmission_url($example->id), array('edit' => 'on'));
        echo $output->single_button($aurl, get_string('exampleedit', 'peering'), 'get');

        $aurl = new moodle_url($peering->exsubmission_url($example->id), array('delete' => 'on'));
        echo $output->single_button($aurl, get_string('exampledelete', 'peering'), 'get');
    }
}
// ...and optionally assess it
if ($canassess or ($canmanage and empty($edit) and empty($delete))) {
    $aurl = new moodle_url($peering->exsubmission_url($example->id), array('assess' => 'on', 'sesskey' => sesskey()));
    echo $output->single_button($aurl, get_string('exampleassess', 'peering'), 'get');
}
echo $output->container_end(); // buttonsbar
// and possibly display the example's review(s) - todo
echo $output->footer();
