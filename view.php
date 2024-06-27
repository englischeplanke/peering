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
 * Prints a particular instance of peering
 *
 * You can have a rather longer description of the file as well,
 * if you like, and it can span multiple lines.
 *
 * @package    mod_peering
 * @copyright  2024 Johann Mellin <johann.mellin@tuhh.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');

$id         = optional_param('id', 0, PARAM_INT); // course_module ID, or
$w          = optional_param('w', 0, PARAM_INT);  // peering instance ID
$editmode   = optional_param('editmode', null, PARAM_BOOL);
$page       = optional_param('page', 0, PARAM_INT);
$perpage    = optional_param('perpage', null, PARAM_INT);
$sortby     = optional_param('sortby', 'lastname', PARAM_ALPHA);
$sorthow    = optional_param('sorthow', 'ASC', PARAM_ALPHA);
$eval       = optional_param('eval', null, PARAM_PLUGIN);

if ($id) {
    $cm             = get_coursemodule_from_id('peering', $id, 0, false, MUST_EXIST);
    $course         = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $peeringrecord = $DB->get_record('peering', array('id' => $cm->instance), '*', MUST_EXIST);
} else {
    $peeringrecord = $DB->get_record('peering', array('id' => $w), '*', MUST_EXIST);
    $course         = $DB->get_record('course', array('id' => $peeringrecord->course), '*', MUST_EXIST);
    $cm             = get_coursemodule_from_instance('peering', $peeringrecord->id, $course->id, false, MUST_EXIST);
}

require_login($course, true, $cm);
require_capability('mod/peering:view', $PAGE->context);

$peering = new peering($peeringrecord, $cm, $course);

$PAGE->set_url($peering->view_url());

// Mark viewed.
$peering->set_module_viewed();

// If the phase is to be switched, do it asap. This just has to happen after triggering
// the event so that the scheduled allocator had a chance to allocate submissions.
if ($peering->phase == peering::PHASE_SUBMISSION and $peering->phaseswitchassessment
        and $peering->submissionend > 0 and $peering->submissionend < time()) {
    $peering->switch_phase(peering::PHASE_ASSESSMENT);
    // Disable the automatic switching now so that it is not executed again by accident
    // if the teacher changes the phase back to the submission one.
    $DB->set_field('peering', 'phaseswitchassessment', 0, array('id' => $peering->id));
    $peering->phaseswitchassessment = 0;
}

if (!is_null($editmode) && $PAGE->user_allowed_editing()) {
    $USER->editing = $editmode;
}
$peering->init_initial_bar();
$userplan = new peering_user_plan($peering, $USER->id);

foreach ($userplan->phases as $phase) {
    if ($phase->active) {
        $currentphasetitle = $phase->title;
    }
}

$PAGE->set_title($peering->name . " (" . $currentphasetitle . ")");
$PAGE->set_heading($course->fullname);


if ($perpage and $perpage > 0 and $perpage <= 1000) {
    require_sesskey();
    set_user_preference('peering_perpage', $perpage);
    redirect($PAGE->url);
}

if ($eval) {
    require_sesskey();
    require_capability('mod/peering:overridegrades', $peering->context);
    $peering->set_grading_evaluation_method($eval);
    redirect($PAGE->url);
}

$heading = $OUTPUT->heading_with_help(format_string($peering->name), 'userplan', 'peering');
$heading = preg_replace('/<h2[^>]*>([.\s\S]*)<\/h2>/', '$1', $heading);
$PAGE->activityheader->set_attrs([
    'title' => $PAGE->activityheader->is_title_allowed() ? $heading : "",
    'description' => ''
]);

$output = $PAGE->get_renderer('mod_peering');

/// Output starts here

echo $output->header();

// Output action buttons here.
switch ($peering->phase) {
    case peering::PHASE_SUBMISSION:
        // Does the user have to assess examples before submitting their own work?
        $examplesmust = ($peering->useexamples and $peering->examplesmode == peering::EXAMPLES_BEFORE_SUBMISSION);

        // Is the assessment of example submissions considered finished?
        $examplesdone = has_capability('mod/peering:manageexamples', $peering->context);

        if ($peering->assessing_examples_allowed() && has_capability('mod/peering:submit', $peering->context) &&
                !has_capability('mod/peering:manageexamples', $peering->context)) {
            $examples = $userplan->get_examples();
            $left = 0;
            // Make sure the current user has all examples allocated.
            foreach ($examples as $exampleid => $example) {
                if (is_null($example->grade)) {
                    $left++;
                    break;
                }
            }
            if ($left > 0 and $peering->examplesmode != peering::EXAMPLES_VOLUNTARY) {
                $examplesdone = false;
            } else {
                $examplesdone = true;
            }
        }

        if (has_capability('mod/peering:submit', $PAGE->context) and (!$examplesmust or $examplesdone)) {
            if (!$peering->get_submission_by_author($USER->id)) {
                $btnurl = new moodle_url($peering->submission_url(), ['edit' => 'on']);
                $btntxt = get_string('createsubmission', 'peering');
                echo $output->single_button($btnurl, $btntxt, 'get', ['primary' => true]);
            }
        }
        break;

    case peering::PHASE_ASSESSMENT:
        if (has_capability('mod/peering:submit', $PAGE->context)) {
            if (!$peering->get_submission_by_author($USER->id)) {
                if ($peering->creating_submission_allowed($USER->id)) {
                    $btnurl = new moodle_url($peering->submission_url(), array('edit' => 'on'));
                    $btntxt = get_string('createsubmission', 'peering');
                    echo $output->single_button($btnurl, $btntxt, 'get', ['primary' => true]);
                }
            }
        }
}

echo $output->heading(format_string($currentphasetitle), 3, null, 'mod_peering-userplanheading');
echo $output->render($userplan);

switch ($peering->phase) {
    case peering::PHASE_SETUP:
        if (trim($peering->intro)) {
            print_collapsible_region_start('', 'peering-viewlet-intro', get_string('introduction', 'peering'),
                'peering-viewlet-intro-collapsed');
            echo $output->box(format_module_intro('peering', $peering, $peering->cm->id), 'generalbox');
            print_collapsible_region_end();
        }
        if ($peering->useexamples && has_capability('mod/peering:manageexamples', $PAGE->context)) {
            print_collapsible_region_start('', 'peering-viewlet-allexamples', get_string('examplesubmissions', 'peering'),
                'peering-viewlet-allexamples-collapsed');
            echo $output->box_start('generalbox examples');
            if ($peering->grading_strategy_instance()->form_ready()) {
                if (!$examples = $peering->get_examples_for_manager()) {
                    echo $output->container(get_string('noexamples', 'peering'), 'noexamples');
                }
                foreach ($examples as $example) {
                    $summary = $peering->prepare_example_summary($example);
                    $summary->editable = true;
                    echo $output->render($summary);
                }
                $aurl = new moodle_url($peering->exsubmission_url(0), array('edit' => 'on'));
                echo $output->single_button($aurl, get_string('exampleadd', 'peering'), 'get');
            } else {
                echo $output->container(get_string('noexamplesformready', 'peering'));
            }
            echo $output->box_end();
            print_collapsible_region_end();
        }
        break;
    case peering::PHASE_SUBMISSION:

        if (trim($peering->instructauthors)) {

            $instructions = file_rewrite_pluginfile_urls($peering->instructauthors, 'pluginfile.php', $PAGE->context->id,
                'mod_peering', 'instructauthors', null, peering::instruction_editors_options($PAGE->context));
            print_collapsible_region_start('', 'peering-viewlet-instructauthors', get_string('instructauthors', 'peering'),
                'peering-viewlet-instructauthors-collapsed');
            echo $output->box(format_text($instructions, $peering->instructauthorsformat, array('overflowdiv' => true)),
                array('generalbox', 'instructions'));
            print_collapsible_region_end();
        }

        if ($peering->assessing_examples_allowed()
            && has_capability('mod/peering:submit', $peering->context)
            && !has_capability('mod/peering:manageexamples', $peering->context)) {
            $examples = $userplan->get_examples();
            echo "huhn1";
            $total = count($examples);
            print_collapsible_region_start('', 'peering-viewlet-examples', get_string('exampleassessments', 'peering'),
                'peering-viewlet-examples-collapsed', $examplesdone);
            echo $output->box_start('generalbox exampleassessments');
            if ($total == 0) {
                echo $output->heading(get_string('noexamples', 'peering'), 3);
            } else {
                foreach ($examples as $example) {
                    $summary = $peering->prepare_example_summary($example);
                    echo $output->render($summary);
                }
            }
            echo $output->box_end();
            print_collapsible_region_end();
        }

        if (has_capability('mod/peering:submit', $PAGE->context) && (!$examplesmust || $examplesdone)) {
          
            print_collapsible_region_start('', 'peering-viewlet-ownsubmission', get_string('yoursubmission', 'peering'),
                'peering-viewlet-ownsubmission-collapsed');
            echo $output->box_start('generalbox ownsubmission');
            if ($submission = $peering->get_submission_by_author($USER->id)) {
                echo $output->render($peering->prepare_submission_summary($submission, true));
            } else {
                echo $output->container(get_string('noyoursubmission', 'peering'));
            }

            echo $output->box_end();
            print_collapsible_region_end();
        }

        if (has_capability('mod/peering:viewallsubmissions', $PAGE->context)) {
            
            $groupmode = groups_get_activity_groupmode($peering->cm);
            $groupid = groups_get_activity_group($peering->cm, true);

            if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $peering->context)) {
                $allowedgroups = groups_get_activity_allowed_groups($peering->cm);
                if (empty($allowedgroups)) {
                    echo $output->container(get_string('groupnoallowed', 'mod_peering'), 'groupwidget error');
                    break;
                }
                if (!in_array($groupid, array_keys($allowedgroups))) {
                    echo $output->container(get_string('groupnotamember', 'core_group'), 'groupwidget error');
                    break;
                }
            }

            print_collapsible_region_start('', 'peering-viewlet-allsubmissions', get_string('submissionsreport', 'peering'),
                'peering-viewlet-allsubmissions-collapsed');

            $perpage = get_user_preferences('peering_perpage', 10);
            $data = $peering->prepare_grading_report_data($USER->id, $groupid, $page, $perpage, $sortby, $sorthow);
            if ($data) {
                $countparticipants = $peering->count_participants();
                $countsubmissions = $peering->count_submissions(array_keys($data->grades), $groupid);
                $a = new stdClass();
                $a->submitted = $countsubmissions;
                $a->notsubmitted = $data->totalcount - $countsubmissions;

                echo html_writer::tag('div', get_string('submittednotsubmitted', 'peering', $a));

                echo $output->container(groups_print_activity_menu($peering->cm, $PAGE->url, true), 'groupwidget');

                // Prepare the paging bar.
                $baseurl = new moodle_url($PAGE->url, array('sortby' => $sortby, 'sorthow' => $sorthow));
                $pagingbar = new paging_bar($data->totalcount, $page, $perpage, $baseurl, 'page');

                // Populate the display options for the submissions report.
                $reportopts = new stdclass();
                $reportopts->showauthornames = has_capability('mod/peering:viewauthornames', $peering->context);
                $reportopts->showreviewernames = has_capability('mod/peering:viewreviewernames', $peering->context);
                $reportopts->sortby = $sortby;
                $reportopts->sorthow = $sorthow;
                $reportopts->showsubmissiongrade = false;
                $reportopts->showgradinggrade = false;
                $reportopts->peeringphase = $peering->phase;
                echo $output->initials_bars($peering, $baseurl);
                echo $output->render($pagingbar);
                echo $output->render(new peering_grading_report($data, $reportopts));
                echo $output->render($pagingbar);
                echo $output->perpage_selector($perpage);
            } else {
                echo html_writer::tag('div', get_string('nothingfound', 'peering'), array('class' => 'nothingfound'));
            }
            
            print_collapsible_region_end();
        }
        /*Einschub Anfang*/

        // Does the user have to assess examples before assessing other's work?
        $examplesmust = ($peering->useexamples && $peering->examplesmode == peering::EXAMPLES_BEFORE_ASSESSMENT);

        // Is the assessment of example submissions considered finished?
        $examplesdone = has_capability('mod/peering:manageexamples', $peering->context);

        // Can the examples be assessed?
        $examplesavailable = true;

        if (!$examplesdone && $examplesmust && ($ownsubmissionexists === false)) {
            print_collapsible_region_start('', 'peering-viewlet-examplesfail', get_string('exampleassessments', 'peering'),
                'peering-viewlet-examplesfail-collapsed');
            echo $output->box(get_string('exampleneedsubmission', 'peering'));
            print_collapsible_region_end();
            $examplesavailable = false;
        }

        if ($peering->assessing_examples_allowed()
            && has_capability('mod/peering:submit', $peering->context)
            && !has_capability('mod/peering:manageexamples', $peering->context)
            && $examplesavailable) {
            $examples = $userplan->get_examples();
            $total = count($examples);
            $left = 0;
            // Make sure the current user has all examples allocated.
            foreach ($examples as $exampleid => $example) {
                if (is_null($example->assessmentid)) {
                    $examples[$exampleid]->assessmentid = $peering->add_allocation($example, $USER->id, 0);
                }
                if (is_null($example->grade)) {
                    $left++;
                }
            }
            if ($left > 0 && $peering->examplesmode != peering::EXAMPLES_VOLUNTARY) {
                $examplesdone = false;
            } else {
                $examplesdone = true;
            }
            print_collapsible_region_start('', 'peering-viewlet-examples', get_string('exampleassessments', 'peering'),
                'peering-viewlet-examples-collapsed', $examplesdone);
            echo $output->box_start('generalbox exampleassessments');
            if ($total == 0) {
                echo $output->heading(get_string('noexamples', 'peering'), 3);
            } else {
                foreach ($examples as $example) {
                    $summary = $peering->prepare_example_summary($example);
                    echo $output->render($summary);
                }
            }
            echo $output->box_end();
            print_collapsible_region_end();
        }
        if (!$examplesmust || $examplesdone) {
            
            print_collapsible_region_start('', 'peering-viewlet-assignedassessments',
                get_string('assignedassessments', 'peering'),
                'peering-viewlet-assignedassessments-collapsed');
            if (!$assessments = $peering->get_assessments_by_reviewer($USER->id)) {
                echo $output->box_start('generalbox assessment-none');
                echo $output->notification(get_string('assignedassessmentsnone', 'peering'));
                echo $output->box_end();
            } else {
               
                $shownames = has_capability('mod/peering:viewauthornames', $PAGE->context);
                     
                foreach ($assessments as $assessment) {
                    $submission = new stdClass();
                    $submission->id = $assessment->submissionid;
                    $submission->title = $assessment->submissiontitle;
                    $submission->timecreated = $assessment->submissioncreated;
                    $submission->timemodified = $assessment->submissionmodified;
                    $userpicturefields = explode(',', implode(',', \core_user\fields::get_picture_fields()));
                    foreach ($userpicturefields as $userpicturefield) {
                        $prefixedusernamefield = 'author' . $userpicturefield;
                        $submission->$prefixedusernamefield = $assessment->$prefixedusernamefield;
                    }
                    // Transform the submission object into renderable component.
                    $submission = $peering->prepare_submission_summary($submission, $shownames);

                    if (is_null($assessment->grade)) {
                        $submission->status = 'notgraded';
                        $class = ' notgraded';
                        $buttontext = get_string('assess', 'peering');
                    } else {
                        $submission->status = 'graded';
                        $class = ' graded';
                        $buttontext = get_string('reassess', 'peering');
                    }
                    echo $output->box_start('generalbox assessment-summary' . $class);
                    echo $output->render($submission);
                    $aurl = $peering->assess_url($assessment->id);
                    echo $output->single_button($aurl, $buttontext, 'get');
                    echo $output->box_end();
                    }

                } 
                
            }
        /*Einschub Ende*/
        break;

    case peering::PHASE_ASSESSMENT:

        $ownsubmissionexists = null;
        if (has_capability('mod/peering:submit', $PAGE->context)) {
            if ($ownsubmission = $peering->get_submission_by_author($USER->id)) {
                print_collapsible_region_start('', 'peering-viewlet-ownsubmission', get_string('yoursubmission', 'peering'),
                    'peering-viewlet-ownsubmission-collapsed', true);
                echo $output->box_start('generalbox ownsubmission');
                echo $output->render($peering->prepare_submission_summary($ownsubmission, true));
                $ownsubmissionexists = true;
            } else {
                print_collapsible_region_start('', 'peering-viewlet-ownsubmission', get_string('yoursubmission', 'peering'),
                    'peering-viewlet-ownsubmission-collapsed');
                echo $output->box_start('generalbox ownsubmission');
                echo $output->container(get_string('noyoursubmission', 'peering'));
                $ownsubmissionexists = false;
            }

            echo $output->box_end();
            print_collapsible_region_end();
        }

        if (has_capability('mod/peering:viewallassessments', $PAGE->context)) {
            $perpage = get_user_preferences('peering_perpage', 10);
            $groupid = groups_get_activity_group($peering->cm, true);
            $data = $peering->prepare_grading_report_data($USER->id, $groupid, $page, $perpage, $sortby, $sorthow);
            if ($data) {
                $showauthornames = has_capability('mod/peering:viewauthornames', $peering->context);
                $showreviewernames = has_capability('mod/peering:viewreviewernames', $peering->context);

                // Prepare paging bar.
                $baseurl = new moodle_url($PAGE->url, array('sortby' => $sortby, 'sorthow' => $sorthow));
                $pagingbar = new paging_bar($data->totalcount, $page, $perpage, $baseurl, 'page');

                // Grading report display options.
                $reportopts = new stdclass();
                $reportopts->showauthornames = $showauthornames;
                $reportopts->showreviewernames = $showreviewernames;
                $reportopts->sortby = $sortby;
                $reportopts->sorthow = $sorthow;
                $reportopts->showsubmissiongrade = false;
                $reportopts->showgradinggrade = false;
                $reportopts->peeringphase = $peering->phase;

                print_collapsible_region_start('', 'peering-viewlet-gradereport', get_string('gradesreport', 'peering'),
                    'peering-viewlet-gradereport-collapsed');
                echo $output->box_start('generalbox gradesreport');
                echo $output->container(groups_print_activity_menu($peering->cm, $PAGE->url, true), 'groupwidget');
                echo $output->initials_bars($peering, $baseurl);
                echo $output->render($pagingbar);
                echo $output->render(new peering_grading_report($data, $reportopts));
                echo $output->render($pagingbar);
                echo $output->perpage_selector($perpage);
                echo $output->box_end();
                print_collapsible_region_end();
            }
        }
        if (trim($peering->instructreviewers)) {
            $instructions = file_rewrite_pluginfile_urls($peering->instructreviewers, 'pluginfile.php', $PAGE->context->id,
                'mod_peering', 'instructreviewers', null, peering::instruction_editors_options($PAGE->context));
            print_collapsible_region_start('', 'peering-viewlet-instructreviewers', get_string('instructreviewers', 'peering'),
                'peering-viewlet-instructreviewers-collapsed');
            echo $output->box(format_text($instructions, $peering->instructreviewersformat, array('overflowdiv' => true)),
                array('generalbox', 'instructions'));
            print_collapsible_region_end();
        }

        // Does the user have to assess examples before assessing other's work?
        $examplesmust = ($peering->useexamples && $peering->examplesmode == peering::EXAMPLES_BEFORE_ASSESSMENT);

        // Is the assessment of example submissions considered finished?
        $examplesdone = has_capability('mod/peering:manageexamples', $peering->context);

        // Can the examples be assessed?
        $examplesavailable = true;

        if (!$examplesdone && $examplesmust && ($ownsubmissionexists === false)) {
            print_collapsible_region_start('', 'peering-viewlet-examplesfail', get_string('exampleassessments', 'peering'),
                'peering-viewlet-examplesfail-collapsed');
            echo $output->box(get_string('exampleneedsubmission', 'peering'));
            print_collapsible_region_end();
            $examplesavailable = false;
        }

        if ($peering->assessing_examples_allowed()
            && has_capability('mod/peering:submit', $peering->context)
            && !has_capability('mod/peering:manageexamples', $peering->context)
            && $examplesavailable) {
            $examples = $userplan->get_examples();
            $total = count($examples);
            $left = 0;
            // Make sure the current user has all examples allocated.
            foreach ($examples as $exampleid => $example) {
                if (is_null($example->assessmentid)) {
                    $examples[$exampleid]->assessmentid = $peering->add_allocation($example, $USER->id, 0);
                }
                if (is_null($example->grade)) {
                    $left++;
                }
            }
            if ($left > 0 && $peering->examplesmode != peering::EXAMPLES_VOLUNTARY) {
                $examplesdone = false;
            } else {
                $examplesdone = true;
            }
            print_collapsible_region_start('', 'peering-viewlet-examples', get_string('exampleassessments', 'peering'),
                'peering-viewlet-examples-collapsed', $examplesdone);
            echo $output->box_start('generalbox exampleassessments');
            if ($total == 0) {
                echo $output->heading(get_string('noexamples', 'peering'), 3);
            } else {
                foreach ($examples as $example) {
                    $summary = $peering->prepare_example_summary($example);
                    echo $output->render($summary);
                }
            }
            echo $output->box_end();
            print_collapsible_region_end();
        }
        if (!$examplesmust || $examplesdone) {
            
            print_collapsible_region_start('', 'peering-viewlet-assignedassessments',
                get_string('assignedassessments', 'peering'),
                'peering-viewlet-assignedassessments-collapsed');
            if (!$assessments = $peering->get_assessments_by_reviewer($USER->id)) {
                echo $output->box_start('generalbox assessment-none');
                echo $output->notification(get_string('assignedassessmentsnone', 'peering'));
                echo $output->box_end();
            } else {
                $shownames = has_capability('mod/peering:viewauthornames', $PAGE->context);
                foreach ($assessments as $assessment) {
                    $submission = new stdClass();
                    $submission->id = $assessment->submissionid;
                    $submission->title = $assessment->submissiontitle;
                    $submission->timecreated = $assessment->submissioncreated;
                    $submission->timemodified = $assessment->submissionmodified;
                    $userpicturefields = explode(',', implode(',', \core_user\fields::get_picture_fields()));
                    foreach ($userpicturefields as $userpicturefield) {
                        $prefixedusernamefield = 'author' . $userpicturefield;
                        $submission->$prefixedusernamefield = $assessment->$prefixedusernamefield;
                    }

                    // Transform the submission object into renderable component.
                    $submission = $peering->prepare_submission_summary($submission, $shownames);

                    if (is_null($assessment->grade)) {
                        $submission->status = 'notgraded';
                        $class = ' notgraded';
                        $buttontext = get_string('assess', 'peering');
                    } else {
                        $submission->status = 'graded';
                        $class = ' graded';
                        $buttontext = get_string('reassess', 'peering');
                    }

                    echo $output->box_start('generalbox assessment-summary' . $class);
                    echo $output->render($submission);
                    $aurl = $peering->assess_url($assessment->id);
                    echo $output->single_button($aurl, $buttontext, 'get');
                    echo $output->box_end();
                    
                }
            }
            print_collapsible_region_end();
        }
        break;
    case peering::PHASE_EVALUATION:
           
        if (has_capability('mod/peering:viewallassessments', $PAGE->context)) {
            $perpage = get_user_preferences('peering_perpage', 10);
            $groupid = groups_get_activity_group($peering->cm, true);
            $data = $peering->prepare_grading_report_data($USER->id, $groupid, $page, $perpage, $sortby, $sorthow);

            if ($data) {
                
                $showauthornames = has_capability('mod/peering:viewauthornames', $peering->context);
                $showreviewernames = has_capability('mod/peering:viewreviewernames', $peering->context);

                if (has_capability('mod/peering:overridegrades', $PAGE->context)) {
                    // Print a drop-down selector to change the current evaluation method.
                    $selector = new single_select($PAGE->url, 'eval', peering::available_evaluators_list(),
                        $peering->evaluation, false, 'evaluationmethodchooser');
                    $selector->set_label(get_string('evaluationmethod', 'mod_peering'));
                    $selector->set_help_icon('evaluationmethod', 'mod_peering');
                    $selector->method = 'post';
                    echo $output->render($selector);
                    // Load the grading evaluator.
                    $evaluator = $peering->grading_evaluation_instance();
                    $form = $evaluator->get_settings_form(new moodle_url($peering->aggregate_url(),
                        compact('sortby', 'sorthow', 'page')));
                    $form->display();
                }
                if (has_capability('mod/peering:overridegrades', $PAGE->context)) {
                    // Print a drop-down selector to change the current evaluation method.
                    /*
                    $selector = new single_select($PAGE->url, 'eval', peering::available_evaluators_list(),
                        $peering->evaluation, false, 'evaluationmethodchooser');
                    $selector->set_label(get_string('evaluationmethod', 'mod_peering'));
                    $selector->set_help_icon('evaluationmethod', 'mod_peering');
                    $selector->method = 'post';
                    echo $output->render($selector);
                    // Load the grading evaluator.
                    $evaluator = $peering->grading_evaluation_instance();
                    $form = $evaluator->get_settings_form(new moodle_url($peering->aggregate_url(),
                        compact('sortby', 'sorthow', 'page')));


                        **************************************
                        https://moodle.org/mod/forum/discuss.php?d=160377
                        function moodleform($action=null, $customdata=null, $method='post', $target='', $attributes=null, $editable=true)
This means that when we make a new form, and we instantiate it, we can call a different php page as the action, eg if a Moodle form my_form extends moodleform, then we can call it by:

$mform = new my_form('path/to/actionpage.php')
which will then direct to 'actionpage.php' to process the form as you wish lächelnd

Hope it makes sense...
                        **************************************
                    */

                    // function moodleform($action=null, $customdata=null, $method='post', $target='', $attributes=null, $editable=true)

              
                    $mform = new publish_grades_form($peering->publishgrades_url());

                    
                        
                    

                    //publish_grades_form
                    // $peering->aggregate_url() = https://localhost/moodle/mod/peering/aggregate.php?cmid=3
                    //$form = 
                    $mform->display();
                }
                // Prepare paging bar.
                $baseurl = new moodle_url($PAGE->url, array('sortby' => $sortby, 'sorthow' => $sorthow));
                $pagingbar = new paging_bar($data->totalcount, $page, $perpage, $baseurl, 'page');

                // Grading report display options.
                $reportopts = new stdclass();
                $reportopts->showauthornames = $showauthornames;
                $reportopts->showreviewernames = $showreviewernames;
                $reportopts->sortby = $sortby;
                $reportopts->sorthow = $sorthow;
                $reportopts->showsubmissiongrade = true;
                $reportopts->showgradinggrade = true;
                $reportopts->peeringphase = $peering->phase;

                print_collapsible_region_start('', 'peering-viewlet-gradereport', get_string('gradesreport', 'peering'),
                    'peering-viewlet-gradereport-collapsed');
                    
                echo $output->box_start('generalbox gradesreport');
                echo $output->container(groups_print_activity_menu($peering->cm, $PAGE->url, true), 'groupwidget');
                echo $output->initials_bars($peering, $baseurl);
                echo $output->render($pagingbar);
                echo $output->render(new peering_grading_report($data, $reportopts));
                echo $output->render($pagingbar);
                echo $output->perpage_selector($perpage);
                echo $output->box_end();
                print_collapsible_region_end();
                
            }
            
        }
     
        if (has_capability('mod/peering:overridegrades', $peering->context)) {
            print_collapsible_region_start('', 'peering-viewlet-cleargrades', get_string('toolbox', 'peering'),
                'peering-viewlet-cleargrades-collapsed', true);
            echo $output->box_start('generalbox toolbox');

            // Clear aggregated grades.
            $url = new moodle_url($peering->toolbox_url('clearaggregatedgrades'));
            $btn = new single_button($url, get_string('clearaggregatedgrades', 'peering'), 'post');
            $btn->add_confirm_action(get_string('clearaggregatedgradesconfirm', 'peering'));
            echo $output->container_start('toolboxaction');
            echo $output->render($btn);
            echo $output->help_icon('clearaggregatedgrades', 'peering');
            echo $output->container_end();
            // Clear assessments.
            $url = new moodle_url($peering->toolbox_url('clearassessments'));
            $btn = new single_button($url, get_string('clearassessments', 'peering'), 'post');
            $btn->add_confirm_action(get_string('clearassessmentsconfirm', 'peering'));
            echo $output->container_start('toolboxaction');
            echo $output->render($btn);
            echo $output->help_icon('clearassessments', 'peering');

            echo $OUTPUT->pix_icon('i/risk_dataloss', get_string('riskdatalossshort', 'admin'));
            echo $output->container_end();

            echo $output->box_end();
            print_collapsible_region_end();
        }
        if (has_capability('mod/peering:submit', $PAGE->context)) {
            print_collapsible_region_start('', 'peering-viewlet-ownsubmission', get_string('yoursubmission', 'peering'),
                'peering-viewlet-ownsubmission-collapsed');
            echo $output->box_start('generalbox ownsubmission');
            if ($submission = $peering->get_submission_by_author($USER->id)) {
                echo $output->render($peering->prepare_submission_summary($submission, true));
            } else {
                echo $output->container(get_string('noyoursubmission', 'peering'));
            }
            echo $output->box_end();
            print_collapsible_region_end();
        }
        if ($assessments = $peering->get_assessments_by_reviewer($USER->id)) {
            print_collapsible_region_start('', 'peering-viewlet-assignedassessments',
                get_string('assignedassessments', 'peering'),
                'peering-viewlet-assignedassessments-collapsed');
            $shownames = has_capability('mod/peering:viewauthornames', $PAGE->context);
            foreach ($assessments as $assessment) {
                $submission = new stdclass();
                $submission->id = $assessment->submissionid;
                $submission->title = $assessment->submissiontitle;
                $submission->timecreated = $assessment->submissioncreated;
                $submission->timemodified = $assessment->submissionmodified;
                $userpicturefields = explode(',', implode(',', \core_user\fields::get_picture_fields()));
                foreach ($userpicturefields as $userpicturefield) {
                    $prefixedusernamefield = 'author' . $userpicturefield;
                    $submission->$prefixedusernamefield = $assessment->$prefixedusernamefield;
                }

                if (is_null($assessment->grade)) {
                    $class = ' notgraded';
                    $submission->status = 'notgraded';
                    $buttontext = get_string('assess', 'peering');
                } else {
                    $class = ' graded';
                    $submission->status = 'graded';
                    $buttontext = get_string('reassess', 'peering');
                }
                echo $output->box_start('generalbox assessment-summary' . $class);
                echo $output->render($peering->prepare_submission_summary($submission, $shownames));
                echo $output->box_end();
            }
            print_collapsible_region_end();
            
        }
        
        break;
    case peering::PHASE_CLOSED:
        if (trim($peering->conclusion)) {
            $conclusion = file_rewrite_pluginfile_urls($peering->conclusion, 'pluginfile.php', $peering->context->id,
                'mod_peering', 'conclusion', null, peering::instruction_editors_options($peering->context));
            print_collapsible_region_start('', 'peering-viewlet-conclusion', get_string('conclusion', 'peering'),
                'peering-viewlet-conclusion-collapsed');
            echo $output->box(format_text($conclusion, $peering->conclusionformat, array('overflowdiv' => true)),
                array('generalbox', 'conclusion'));
            print_collapsible_region_end();
        }
        $finalgrades = $peering->get_gradebook_grades($USER->id);
        if (!empty($finalgrades)) {
            print_collapsible_region_start('', 'peering-viewlet-yourgrades', get_string('yourgrades', 'peering'),
                'peering-viewlet-yourgrades-collapsed');
            echo $output->box_start('generalbox grades-yourgrades');
            echo $output->render($finalgrades);
            echo $output->box_end();
            print_collapsible_region_end();
        }
        if (has_capability('mod/peering:viewallassessments', $PAGE->context)) {
            $perpage = get_user_preferences('peering_perpage', 10);
            $groupid = groups_get_activity_group($peering->cm, true);
            $data = $peering->prepare_grading_report_data($USER->id, $groupid, $page, $perpage, $sortby, $sorthow);
            if ($data) {
                $showauthornames = has_capability('mod/peering:viewauthornames', $peering->context);
                $showreviewernames = has_capability('mod/peering:viewreviewernames', $peering->context);

                // Prepare paging bar.
                $baseurl = new moodle_url($PAGE->url, array('sortby' => $sortby, 'sorthow' => $sorthow));
                $pagingbar = new paging_bar($data->totalcount, $page, $perpage, $baseurl, 'page');

                // Grading report display options.
                $reportopts = new stdclass();
                $reportopts->showauthornames = $showauthornames;
                $reportopts->showreviewernames = $showreviewernames;
                $reportopts->sortby = $sortby;
                $reportopts->sorthow = $sorthow;
                $reportopts->showsubmissiongrade = true;
                $reportopts->showgradinggrade = true;
                $reportopts->peeringphase = $peering->phase;

                print_collapsible_region_start('', 'peering-viewlet-gradereport', get_string('gradesreport', 'peering'),
                    'peering-viewlet-gradereport-collapsed');
                echo $output->box_start('generalbox gradesreport');
                echo $output->container(groups_print_activity_menu($peering->cm, $PAGE->url, true), 'groupwidget');
                echo $output->initials_bars($peering, $baseurl);
                echo $output->render($pagingbar);
                echo $output->render(new peering_grading_report($data, $reportopts));
                echo $output->render($pagingbar);
                echo $output->perpage_selector($perpage);
                echo $output->box_end();
                print_collapsible_region_end();
            }
        }
        if (has_capability('mod/peering:submit', $PAGE->context)) {
            print_collapsible_region_start('', 'peering-viewlet-ownsubmission',
                get_string('yoursubmissionwithassessments', 'peering'), 'peering-viewlet-ownsubmission-collapsed');
            echo $output->box_start('generalbox ownsubmission');
            if ($submission = $peering->get_submission_by_author($USER->id)) {
                echo $output->render($peering->prepare_submission_summary($submission, true));
            } else {
                echo $output->container(get_string('noyoursubmission', 'peering'));
            }
            echo $output->box_end();

            if (!empty($submission->gradeoverby) && strlen(trim($submission->feedbackauthor)) > 0) {
                echo $output->render(new peering_feedback_author($submission));
            }

            print_collapsible_region_end();
        }
        if (has_capability('mod/peering:viewpublishedsubmissions', $peering->context)) {
            $shownames = has_capability('mod/peering:viewauthorpublished', $peering->context);
            if ($submissions = $peering->get_published_submissions()) {
                print_collapsible_region_start('', 'peering-viewlet-publicsubmissions',
                    get_string('publishedsubmissions', 'peering'),
                    'peering-viewlet-publicsubmissions-collapsed');
                foreach ($submissions as $submission) {
                    echo $output->box_start('generalbox submission-summary');
                    echo $output->render($peering->prepare_submission_summary($submission, $shownames));
                    echo $output->box_end();
                }
                print_collapsible_region_end();
            }
        }
        if ($assessments = $peering->get_assessments_by_reviewer($USER->id)) {
            print_collapsible_region_start('', 'peering-viewlet-assignedassessments',
                get_string('assignedassessments', 'peering'),
                'peering-viewlet-assignedassessments-collapsed');
            $shownames = has_capability('mod/peering:viewauthornames', $PAGE->context);
            foreach ($assessments as $assessment) {
                $submission = new stdclass();
                $submission->id = $assessment->submissionid;
                $submission->title = $assessment->submissiontitle;
                $submission->timecreated = $assessment->submissioncreated;
                $submission->timemodified = $assessment->submissionmodified;
                $userpicturefields = explode(',', implode(',', \core_user\fields::get_picture_fields()));
                foreach ($userpicturefields as $userpicturefield) {
                    $prefixedusernamefield = 'author' . $userpicturefield;
                    $submission->$prefixedusernamefield = $assessment->$prefixedusernamefield;
                }

                if (is_null($assessment->grade)) {
                    $class = ' notgraded';
                    $submission->status = 'notgraded';
                    $buttontext = get_string('assess', 'peering');
                } else {
                    $class = ' graded';
                    $submission->status = 'graded';
                    $buttontext = get_string('reassess', 'peering');
                }
                echo $output->box_start('generalbox assessment-summary' . $class);
                echo $output->render($peering->prepare_submission_summary($submission, $shownames));
                echo $output->box_end();

                if (!empty($assessment->feedbackreviewer) && strlen(trim($assessment->feedbackreviewer)) > 0) {
                    echo $output->render(new peering_feedback_reviewer($assessment));
                }
            }
            print_collapsible_region_end();
        }
        break;
    default:
}
$PAGE->requires->js_call_amd('mod_peering/peeringview', 'init');
echo $output->footer();
