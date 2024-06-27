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
 * The main peering configuration form
 *
 * The UI mockup has been proposed in MDL-18688
 * It uses the standard core Moodle formslib. For more info about them, please
 * visit: https://moodledev.io/docs/apis/subsystems/form
 *
 * @package    mod_peering
 * @copyright  2024 Johann Mellin <johann.mellin@tuhh.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->libdir . '/filelib.php');

use core_grades\component_gradeitems;
/**
 * Module settings form for peering instances
 */
class mod_peering_mod_form extends moodleform_mod {

    /** @var object the course this instance is part of */
    protected $course = null;

    /**
     * Constructor
     */
    public function __construct($current, $section, $cm, $course) {
        $this->course = $course;
        parent::__construct($current, $section, $cm, $course);
    }

    /**
     * Defines the peering instance configuration form
     *
     * @return void
     */
    public function definition() {
        global $CFG, $PAGE;

        $peeringconfig = get_config('peering');
        $mform = $this->_form;

        // General --------------------------------------------------------------------
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // peering name
        $label = get_string('peeringname', 'peering');
        $mform->addElement('text', 'name', $label, array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Introduction
        $this->standard_intro_elements(get_string('introduction', 'peering'));

        // Autonomous group settings -----------------------------------------------------------

        $mform->addElement('header', 'autonomousgroupssettings', get_string('autonomousgroupssettings', 'peering'));

        $label = get_string('autonomousgroups', 'peering');
        $text = get_string('autonomousgroups_desc', 'peering');
        $mform->addElement('checkbox', 'autonomousgroups', $label, $text);
        $mform->addHelpButton('autonomousgroups', 'autonomousgroups', 'peering');

        
        // Grading settings -----------------------------------------------------------
        $mform->addElement('header', 'gradingsettings', get_string('gradingsettings', 'peering'));
        $mform->setExpanded('gradingsettings');

        $label = get_string('strategy', 'peering');
        $mform->addElement('select', 'strategy', $label, peering::available_strategies_list());
        $mform->setDefault('strategy', $peeringconfig->strategy);
        $mform->addHelpButton('strategy', 'strategy', 'peering');

        $grades = peering::available_maxgrades_list();
        $gradecategories = grade_get_categories_menu($this->course->id);

        $label = get_string('submissiongrade', 'peering');
        $mform->addGroup(array(
            $mform->createElement('select', 'grade', '', $grades),
            $mform->createElement('select', 'gradecategory', '', $gradecategories),
            ), 'submissiongradegroup', $label, ' ', false);
        $mform->setDefault('grade', $peeringconfig->grade);
        $mform->addHelpButton('submissiongradegroup', 'submissiongrade', 'peering');

        $mform->addElement('float', 'submissiongradepass', get_string('gradetopasssubmission', 'peering'));
        $mform->addHelpButton('submissiongradepass', 'gradepass', 'grades');
        $mform->setDefault('submissiongradepass', '');

        $label = get_string('gradinggrade', 'peering');
        $mform->addGroup(array(
            $mform->createElement('select', 'gradinggrade', '', $grades),
            $mform->createElement('select', 'gradinggradecategory', '', $gradecategories),
            ), 'gradinggradegroup', $label, ' ', false);
        $mform->setDefault('gradinggrade', $peeringconfig->gradinggrade);
        $mform->addHelpButton('gradinggradegroup', 'gradinggrade', 'peering');

        $mform->addElement('float', 'gradinggradepass', get_string('gradetopassgrading', 'peering'));
        $mform->addHelpButton('gradinggradepass', 'gradepass', 'grades');
        $mform->setDefault('gradinggradepass', '');

        $options = array();
        for ($i = 5; $i >= 0; $i--) {
            $options[$i] = $i;
        }
        $label = get_string('gradedecimals', 'peering');
        $mform->addElement('select', 'gradedecimals', $label, $options);
        $mform->setDefault('gradedecimals', $peeringconfig->gradedecimals);

        // Submission settings --------------------------------------------------------
        $mform->addElement('header', 'submissionsettings', get_string('submissionsettings', 'peering'));

        $label = get_string('instructauthors', 'peering');
        $mform->addElement('editor', 'instructauthorseditor', $label, null,
                            peering::instruction_editors_options($this->context));

        $typeelements = [];
        foreach (['submissiontypetext', 'submissiontypefile'] as $type) {
            $available = $type . 'available';
            $required = $type . 'required';
            $availablelabel = get_string($available, 'peering');
            $requiredlabel = get_string($required, 'peering');
            $typeelements[] = $mform->createElement('advcheckbox', $available, '', $availablelabel);
            $typeelements[] = $mform->createElement('advcheckbox', $required, '', $requiredlabel);
            $mform->setDefault($available, 1);
        }
        // We can't use <br> as the separator as it does not work well in this case with the Boost theme.
        // Instead, separate both tuples with a full-width empty div.
        $mform->addGroup($typeelements, 'submissiontypes', get_string('submissiontypes', 'peering'),
            array(' ', '<div style="width:100%"></div>'), false);

        $options = array();
        for ($i = 7; $i >= 1; $i--) {
            $options[$i] = $i;
        }
        $label = get_string('nattachments', 'peering');
        $mform->addElement('select', 'nattachments', $label, $options);
        $mform->setDefault('nattachments', 1);
        $mform->hideIf('nattachments', 'submissiontypefileavailable');

        $label = get_string('allowedfiletypesforsubmission', 'peering');
        $mform->addElement('filetypes', 'submissionfiletypes', $label);
        $mform->addHelpButton('submissionfiletypes', 'allowedfiletypesforsubmission', 'peering');
        $mform->hideIf('submissionfiletypes', 'submissiontypefileavailable');

        $options = get_max_upload_sizes($CFG->maxbytes, $this->course->maxbytes, 0, $peeringconfig->maxbytes);
        $mform->addElement('select', 'maxbytes', get_string('maxbytes', 'peering'), $options);
        $mform->setDefault('maxbytes', $peeringconfig->maxbytes);
        $mform->hideIf('maxbytes', 'submissiontypefileavailable');

        $label = get_string('latesubmissions', 'peering');
        $text = get_string('latesubmissions_desc', 'peering');
        $mform->addElement('checkbox', 'latesubmissions', $label, $text);
        $mform->addHelpButton('latesubmissions', 'latesubmissions', 'peering');

        // Assessment settings --------------------------------------------------------
        $mform->addElement('header', 'assessmentsettings', get_string('assessmentsettings', 'peering'));

        $label = get_string('instructreviewers', 'peering');
        $mform->addElement('editor', 'instructreviewerseditor', $label, null,
                            peering::instruction_editors_options($this->context));

        $label = get_string('useselfassessment', 'peering');
        $text = get_string('useselfassessment_desc', 'peering');
        $mform->addElement('checkbox', 'useselfassessment', $label, $text);
        $mform->addHelpButton('useselfassessment', 'useselfassessment', 'peering');

        // Feedback -------------------------------------------------------------------
        $mform->addElement('header', 'feedbacksettings', get_string('feedbacksettings', 'peering'));

        $mform->addElement('select', 'overallfeedbackmode', get_string('overallfeedbackmode', 'mod_peering'), array(
            0 => get_string('overallfeedbackmode_0', 'mod_peering'),
            1 => get_string('overallfeedbackmode_1', 'mod_peering'),
            2 => get_string('overallfeedbackmode_2', 'mod_peering')));
        $mform->addHelpButton('overallfeedbackmode', 'overallfeedbackmode', 'mod_peering');
        $mform->setDefault('overallfeedbackmode', 1);

        $options = array();
        for ($i = 7; $i >= 0; $i--) {
            $options[$i] = $i;
        }
        $mform->addElement('select', 'overallfeedbackfiles', get_string('overallfeedbackfiles', 'peering'), $options);
        $mform->setDefault('overallfeedbackfiles', 0);
        $mform->hideIf('overallfeedbackfiles', 'overallfeedbackmode', 'eq', 0);

        $label = get_string('allowedfiletypesforoverallfeedback', 'peering');
        $mform->addElement('filetypes', 'overallfeedbackfiletypes', $label);
        $mform->addHelpButton('overallfeedbackfiletypes', 'allowedfiletypesforoverallfeedback', 'peering');
        $mform->hideIf('overallfeedbackfiletypes', 'overallfeedbackfiles', 'eq', 0);

        $options = get_max_upload_sizes($CFG->maxbytes, $this->course->maxbytes);
        $mform->addElement('select', 'overallfeedbackmaxbytes', get_string('overallfeedbackmaxbytes', 'peering'), $options);
        $mform->setDefault('overallfeedbackmaxbytes', $peeringconfig->maxbytes);
        $mform->hideIf('overallfeedbackmaxbytes', 'overallfeedbackmode', 'eq', 0);
        $mform->hideIf('overallfeedbackmaxbytes', 'overallfeedbackfiles', 'eq', 0);

        $label = get_string('conclusion', 'peering');
        $mform->addElement('editor', 'conclusioneditor', $label, null,
                            peering::instruction_editors_options($this->context));
        $mform->addHelpButton('conclusioneditor', 'conclusion', 'peering');

        // Example submissions --------------------------------------------------------
        $mform->addElement('header', 'examplesubmissionssettings', get_string('examplesubmissions', 'peering'));

        $label = get_string('useexamples', 'peering');
        $text = get_string('useexamples_desc', 'peering');
        $mform->addElement('checkbox', 'useexamples', $label, $text);
        $mform->addHelpButton('useexamples', 'useexamples', 'peering');

        $label = get_string('examplesmode', 'peering');
        $options = peering::available_example_modes_list();
        $mform->addElement('select', 'examplesmode', $label, $options);
        $mform->setDefault('examplesmode', $peeringconfig->examplesmode);
        $mform->hideIf('examplesmode', 'useexamples');

        // Availability ---------------------------------------------------------------
        $mform->addElement('header', 'accesscontrol', get_string('availability', 'core'));

        $label = get_string('submissionstart', 'peering');
        $mform->addElement('date_time_selector', 'submissionstart', $label, array('optional' => true));

        $label = get_string('submissionend', 'peering');
        $mform->addElement('date_time_selector', 'submissionend', $label, array('optional' => true));

        $label = get_string('submissionendswitch', 'mod_peering');
        $mform->addElement('checkbox', 'phaseswitchassessment', $label);
        $mform->hideIf('phaseswitchassessment', 'submissionend[enabled]');
        $mform->addHelpButton('phaseswitchassessment', 'submissionendswitch', 'mod_peering');

        $label = get_string('assessmentstart', 'peering');
        $mform->addElement('date_time_selector', 'assessmentstart', $label, array('optional' => true));

        $label = get_string('assessmentend', 'peering');
        $mform->addElement('date_time_selector', 'assessmentend', $label, array('optional' => true));

        $coursecontext = context_course::instance($this->course->id);
        // To be removed (deprecated) with MDL-67526.
        plagiarism_get_form_elements_module($mform, $coursecontext, 'mod_peering');

        // Common module settings, Restrict availability, Activity completion etc. ----
        $features = array('groups' => true, 'groupings' => true,
                'outcomes' => true, 'gradecat' => false, 'idnumber' => false);

        $this->standard_coursemodule_elements();

        // Standard buttons, common to all modules ------------------------------------
        $this->add_action_buttons();

        $PAGE->requires->js_call_amd('mod_peering/modform', 'init');
    }

    /**
     * Prepares the form before data are set
     *
     * Additional wysiwyg editor are prepared here, the introeditor is prepared automatically by core.
     * Grade items are set here because the core modedit supports single grade item only.
     *
     * @param array $data to be set
     * @return void
     */
    public function data_preprocessing(&$data) {
        if ($this->current->instance) {
            // editing an existing peering - let us prepare the added editor elements (intro done automatically)
            $draftitemid = file_get_submitted_draft_itemid('instructauthors');
            $data['instructauthorseditor']['text'] = file_prepare_draft_area($draftitemid, $this->context->id,
                                'mod_peering', 'instructauthors', 0,
                                peering::instruction_editors_options($this->context),
                                $data['instructauthors']);
            $data['instructauthorseditor']['format'] = $data['instructauthorsformat'];
            $data['instructauthorseditor']['itemid'] = $draftitemid;

            $draftitemid = file_get_submitted_draft_itemid('instructreviewers');
            $data['instructreviewerseditor']['text'] = file_prepare_draft_area($draftitemid, $this->context->id,
                                'mod_peering', 'instructreviewers', 0,
                                peering::instruction_editors_options($this->context),
                                $data['instructreviewers']);
            $data['instructreviewerseditor']['format'] = $data['instructreviewersformat'];
            $data['instructreviewerseditor']['itemid'] = $draftitemid;

            $draftitemid = file_get_submitted_draft_itemid('conclusion');
            $data['conclusioneditor']['text'] = file_prepare_draft_area($draftitemid, $this->context->id,
                                'mod_peering', 'conclusion', 0,
                                peering::instruction_editors_options($this->context),
                                $data['conclusion']);
            $data['conclusioneditor']['format'] = $data['conclusionformat'];
            $data['conclusioneditor']['itemid'] = $draftitemid;
            // Set submission type checkboxes.
            foreach (['submissiontypetext', 'submissiontypefile'] as $type) {
                $data[$type . 'available'] = 1;
                $data[$type . 'required'] = 0;
                if ($data[$type] == peering_SUBMISSION_TYPE_DISABLED) {
                    $data[$type . 'available'] = 0;
                } else if ($data[$type] == peering_SUBMISSION_TYPE_REQUIRED) {
                    $data[$type . 'required'] = 1;
                }
            }
        } else {
            // adding a new peering instance
            $draftitemid = file_get_submitted_draft_itemid('instructauthors');
            file_prepare_draft_area($draftitemid, null, 'mod_peering', 'instructauthors', 0);    // no context yet, itemid not used
            $data['instructauthorseditor'] = array('text' => '', 'format' => editors_get_preferred_format(), 'itemid' => $draftitemid);

            $draftitemid = file_get_submitted_draft_itemid('instructreviewers');
            file_prepare_draft_area($draftitemid, null, 'mod_peering', 'instructreviewers', 0);    // no context yet, itemid not used
            $data['instructreviewerseditor'] = array('text' => '', 'format' => editors_get_preferred_format(), 'itemid' => $draftitemid);

            $draftitemid = file_get_submitted_draft_itemid('conclusion');
            file_prepare_draft_area($draftitemid, null, 'mod_peering', 'conclusion', 0);    // no context yet, itemid not used
            $data['conclusioneditor'] = array('text' => '', 'format' => editors_get_preferred_format(), 'itemid' => $draftitemid);
        }
    }

    /**
     * Combine submission type checkboxes into integer values for the database.
     *
     * @param stdClass $data The submitted form data.
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);

        foreach (['text', 'file'] as $type) {
            $field = 'submissiontype' . $type;
            $available = $field . 'available';
            $required = $field . 'required';
            if ($data->$required) {
                $data->$field = peering_SUBMISSION_TYPE_REQUIRED;
            } else if ($data->$available) {
                $data->$field = peering_SUBMISSION_TYPE_AVAILABLE;
            } else {
                $data->$field = peering_SUBMISSION_TYPE_DISABLED;
            }
            unset($data->$available);
            unset($data->$required);
        }
    }

    /**
     * Set the grade item categories when editing an instance
     */
    public function definition_after_data() {

        $mform =& $this->_form;

        if ($id = $mform->getElementValue('update')) {
            $instance   = $mform->getElementValue('instance');

            $gradeitems = grade_item::fetch_all(array(
                'itemtype'      => 'mod',
                'itemmodule'    => 'peering',
                'iteminstance'  => $instance,
                'courseid'      => $this->course->id));

            if (!empty($gradeitems)) {
                foreach ($gradeitems as $gradeitem) {
                    // here comes really crappy way how to set the value of the fields
                    // gradecategory and gradinggradecategory - grrr QuickForms
                    $decimalpoints = $gradeitem->get_decimals();
                    if ($gradeitem->itemnumber == 0) {
                        $mform->setDefault('submissiongradepass', format_float($gradeitem->gradepass, $decimalpoints));
                        $group = $mform->getElement('submissiongradegroup');
                        $elements = $group->getElements();
                        foreach ($elements as $element) {
                            if ($element->getName() == 'gradecategory') {
                                $element->setValue($gradeitem->categoryid);
                            }
                        }
                    } else if ($gradeitem->itemnumber == 1) {
                        $mform->setDefault('gradinggradepass', format_float($gradeitem->gradepass, $decimalpoints));
                        $group = $mform->getElement('gradinggradegroup');
                        $elements = $group->getElements();
                        foreach ($elements as $element) {
                            if ($element->getName() == 'gradinggradecategory') {
                                $element->setValue($gradeitem->categoryid);
                            }
                        }
                    }
                }
            }
        }
        $typevalues = $mform->getElementValue('submissiontypes');
        foreach (['submissiontypetext', 'submissiontypefile'] as $type) {
            // Don't leave a disabled "required" checkbox checked.
            if (!$typevalues[$type . 'available']) {
                $mform->setDefault($type . 'required', 0);
            }
        }

        parent::definition_after_data();
    }

    /**
     * Validates the form input
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array eventual errors indexed by the field name
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // check the phases borders are valid
        if ($data['submissionstart'] > 0 and $data['submissionend'] > 0 and $data['submissionstart'] >= $data['submissionend']) {
            $errors['submissionend'] = get_string('submissionendbeforestart', 'mod_peering');
        }
        if ($data['assessmentstart'] > 0 and $data['assessmentend'] > 0 and $data['assessmentstart'] >= $data['assessmentend']) {
            $errors['assessmentend'] = get_string('assessmentendbeforestart', 'mod_peering');
        }

        // check the phases do not overlap
        if (max($data['submissionstart'], $data['submissionend']) > 0 and max($data['assessmentstart'], $data['assessmentend']) > 0) {
            $phasesubmissionend = max($data['submissionstart'], $data['submissionend']);
            $phaseassessmentstart = min($data['assessmentstart'], $data['assessmentend']);
            if ($phaseassessmentstart == 0) {
                $phaseassessmentstart = max($data['assessmentstart'], $data['assessmentend']);
            }
            if ($phasesubmissionend > 0 and $phaseassessmentstart > 0 and $phaseassessmentstart < $phasesubmissionend) {
                foreach (array('submissionend', 'submissionstart', 'assessmentstart', 'assessmentend') as $f) {
                    if ($data[$f] > 0) {
                        $errors[$f] = get_string('phasesoverlap', 'mod_peering');
                        break;
                    }
                }
            }
        }

        // Check that the submission grade pass is a valid number.
        if (!empty($data['submissiongradepass'])) {
            $submissiongradefloat = unformat_float($data['submissiongradepass'], true);
            if ($submissiongradefloat === false) {
                $errors['submissiongradepass'] = get_string('err_numeric', 'form');
            } else {
                if ($submissiongradefloat > $data['grade']) {
                    $errors['submissiongradepass'] = get_string('gradepassgreaterthangrade', 'grades', $data['grade']);
                }
            }
        }

        // Check that the grade pass is a valid number.
        if (!empty($data['gradinggradepass'])) {
            $gradepassfloat = unformat_float($data['gradinggradepass'], true);
            if ($gradepassfloat === false) {
                $errors['gradinggradepass'] = get_string('err_numeric', 'form');
            } else {
                if ($gradepassfloat > $data['gradinggrade']) {
                    $errors['gradinggradepass'] = get_string('gradepassgreaterthangrade', 'grades', $data['gradinggrade']);
                }
            }
        }

        // We need to do a custom completion validation because peering grade items identifiers divert from standard.
        // Refer to validation defined in moodleform_mod.php.
        if (isset($data['completionpassgrade']) && $data['completionpassgrade'] &&
            isset($data['completiongradeitemnumber'])) {
            $itemnames = component_gradeitems::get_itemname_mapping_for_component('mod_peering');
            $gradepassfield = $itemnames[(int) $data['completiongradeitemnumber']] . 'gradepass';
            // We need to make all the validations related with $gradepassfield
            // with them being correct floats, keeping the originals unmodified for
            // later validations / showing the form back...
            // TODO: Note that once MDL-73994 is fixed we'll have to re-visit this and
            // adapt the code below to the new values arriving here, without forgetting
            // the special case of empties and nulls.
            $gradepass = isset($data[$gradepassfield]) ? unformat_float($data[$gradepassfield]) : null;
            if (is_null($gradepass) || $gradepass == 0) {
                $errors['completionpassgrade'] = get_string(
                    'activitygradetopassnotset',
                    'completion'
                );
            } else {
                // We have validated grade pass. Unset any errors.
                unset($errors['completionpassgrade']);
            }
        }

        if (!$data['submissiontypetextavailable'] && !$data['submissiontypefileavailable']) {
            // One submission type must be available.
            $errors['submissiontypes'] = get_string('nosubmissiontype', 'peering');
        }

        return $errors;
    }
}
