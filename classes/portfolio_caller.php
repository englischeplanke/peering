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
 * Provides the {@link mod_peering_portfolio_caller} class.
 *
 * @package   mod_peering
 * @category  portfolio
 * @copyright Loc Nguyen <ndloc1905@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/portfolio/caller.php');

/**
 * peering portfolio caller class to integrate with portfolio API.
 *
 * @package   mod_peering
 * @copyright Loc Nguyen <ndloc1905@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_peering_portfolio_caller extends portfolio_module_caller_base {

    /** @var peering The peering instance where the export is happening. */
    protected $peering;

    /** @var int ID if the exported submission, set via the constructor. */
    protected $submissionid;

    /** @var object The submission being exported. */
    protected $submission;

    /** @var array of objects List of assessments of the exported submission. */
    protected $assessments = [];

    /**
     * Explicit constructor to set the properties declared by the parent class.
     *
     * Firstly we call the parent's constructor to set the $this->id property
     * from the passed argument. Then we populate the $this->cm so that the
     * default parent class methods work well.
     *
     * @param array $callbackargs
     */
    public function __construct($callbackargs) {

        // Let the parent class set the $this->id property.
        parent::__construct($callbackargs);
        // Populate the $this->cm property.
        $this->cm = get_coursemodule_from_id('peering', $this->id, 0, false, MUST_EXIST);
    }

    /**
     * Return array of expected callback arguments and whether they are required or not.
     *
     * The 'id' argument is supposed to be our course module id (cmid) - see
     * the parent class' properties.
     *
     * @return array of (string)callbackname => (bool)required
     */
    public static function expected_callbackargs() {
        return [
            'id' => true,
            'submissionid' => true,
        ];
    }

    /**
     * Load data required for the export.
     */
    public function load_data() {
        global $DB, $USER;

        // Note that require_login() is normally called later as a part of
        // portfolio_export_pagesetup() in the portfolio/add.php file. But we
        // load various data depending of capabilities so it makes sense to
        // call it explicitly here, too.
        require_login($this->get('course'), false, $this->cm, false, true);

        if (isguestuser()) {
            throw new portfolio_caller_exception('guestsarenotallowed', 'core_error');
        }

        $peeringrecord = $DB->get_record('peering', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $this->peering = new peering($peeringrecord, $this->cm, $this->get('course'));

        $this->submission = $this->peering->get_submission_by_id($this->submissionid);

        // Is the user exporting her/his own submission?
        $ownsubmission = $this->submission->authorid == $USER->id;

        // Does the user have permission to see all submissions (aka is it a teacher)?
        $canviewallsubmissions = has_capability('mod/peering:viewallsubmissions', $this->peering->context);
        $canviewallsubmissions = $canviewallsubmissions && $this->peering->check_group_membership($this->submission->authorid);

        // Is the user exporting a submission that she/he has peer-assessed?
        $userassessment = $this->peering->get_assessment_of_submission_by_user($this->submission->id, $USER->id);
        if ($userassessment) {
            $this->assessments[$userassessment->id] = $userassessment;
            $isreviewer = true;
        }

        if (!$ownsubmission and !$canviewallsubmissions and !$isreviewer) {
            throw new portfolio_caller_exception('nopermissions', 'core_error');
        }

        // Does the user have permission to see all assessments (aka is it a teacher)?
        $canviewallassessments = has_capability('mod/peering:viewallassessments', $this->peering->context);

        // Load other assessments eventually if the user can see them.
        if ($canviewallassessments or ($ownsubmission and $this->peering->assessments_available())) {
            foreach ($this->peering->get_assessments_of_submission($this->submission->id) as $assessment) {
                if ($assessment->reviewerid == $USER->id) {
                    // User's own assessment is already loaded.
                    continue;
                }
                if (is_null($assessment->grade) and !$canviewallassessments) {
                    // Students do not see peer-assessment that are not graded.
                    continue;
                }
                $this->assessments[$assessment->id] = $assessment;
            }
        }

        // Prepare embedded and attached files for the export.
        $this->multifiles = [];

        $this->add_area_files('submission_content', $this->submission->id);
        $this->add_area_files('submission_attachment', $this->submission->id);

        foreach ($this->assessments as $assessment) {
            $this->add_area_files('overallfeedback_content', $assessment->id);
            $this->add_area_files('overallfeedback_attachment', $assessment->id);
        }

        $this->add_area_files('instructauthors', 0);

        // If there are no files to be exported, we can offer plain HTML file export.
        if (empty($this->multifiles)) {
            $this->add_format(PORTFOLIO_FORMAT_PLAINHTML);
        }
    }

    /**
     * Prepare the package ready to be passed off to the portfolio plugin.
     */
    public function prepare_package() {

        $canviewauthornames = has_capability('mod/peering:viewauthornames', $this->peering->context, $this->get('user'));

        // Prepare the submission record for rendering.
        $peeringsubmission = $this->peering->prepare_submission($this->submission, $canviewauthornames);

        // Set up the LEAP2A writer if we need it.
        $writingleap = false;

        if ($this->exporter->get('formatclass') == PORTFOLIO_FORMAT_LEAP2A) {
            $leapwriter = $this->exporter->get('format')->leap2a_writer();
            $writingleap = true;
        }

        // If writing to HTML file, accumulate the exported hypertext here.
        $html = '';

        // If writing LEAP2A, keep track of all entry ids so we can add a selection element.
        $leapids = [];

        $html .= $this->export_header($peeringsubmission);
        $content = $this->export_content($peeringsubmission);
        // Get rid of the JS relics left by moodleforms.
        $content = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $content);
        $html .= $content;

        if ($writingleap) {
            $leapids[] = $this->export_content_leap2a($leapwriter, $peeringsubmission, $content);
        }

        // Export the files.
        foreach ($this->multifiles as $file) {
            $this->exporter->copy_existing_file($file);
        }

        if ($writingleap) {
            // Add an extra LEAP2A selection entry. In Mahara, this maps to a journal.
            $selection = new portfolio_format_leap2a_entry('peering'.$this->peering->id,
                get_string('pluginname', 'mod_peering').': '.s($this->peering->name), 'selection');
            $leapwriter->add_entry($selection);
            $leapwriter->make_selection($selection, $leapids, 'Grouping');
            $leapxml = $leapwriter->to_xml();
            $name = $this->exporter->get('format')->manifest_name();
            $this->exporter->write_new_file($leapxml, $name, true);

        } else {
            $this->exporter->write_new_file($html, 'submission.html', true);
        }
    }

    /**
     * Helper method to add all files from the given location to $this->multifiles
     *
     * @param string $filearea
     * @param int $itemid
     */
    protected function add_area_files($filearea, $itemid) {

        $fs = get_file_storage();
        $areafiles = $fs->get_area_files($this->peering->context->id, 'mod_peering', $filearea, $itemid, null, false);
        if ($areafiles) {
            $this->multifiles = array_merge($this->multifiles, array_values($areafiles));
        }
    }

    /**
     * Render the header of the exported content.
     *
     * This is mainly used for the HTML output format. In case of LEAP2A
     * export, this is not used as the information is stored in metadata and
     * displayed as a part of the journal and entry title in Mahara.
     *
     * @param peering_submission $peeringsubmission
     * @return string HTML
     */
    protected function export_header(peering_submission $peeringsubmission) {

        $output = '';
        $output .= html_writer::tag('h2', get_string('pluginname', 'mod_peering').': '.s($this->peering->name));
        $output .= html_writer::tag('h3', s($peeringsubmission->title));

        $created = get_string('userdatecreated', 'peering', userdate($peeringsubmission->timecreated));
        $created = html_writer::tag('span', $created);

        if ($peeringsubmission->timemodified > $peeringsubmission->timecreated) {
            $modified = get_string('userdatemodified', 'peering', userdate($peeringsubmission->timemodified));
            $modified = ' | ' . html_writer::tag('span', $modified);
        } else {
            $modified = '';
        }

        $output .= html_writer::div($created.$modified);
        $output .= html_writer::empty_tag('br');

        return $output;
    }

    /**
     * Render the content of the submission.
     *
     * @param peering_submission $peeringsubmission
     * @return string
     */
    protected function export_content(peering_submission $peeringsubmission) {

        $output = '';

        if (!$peeringsubmission->is_anonymous()) {
            $author = username_load_fields_from_object((object)[], $peeringsubmission, 'author');
            $output .= html_writer::div(get_string('byfullnamewithoutlink', 'mod_peering', fullname($author)));
        }

        $content = $this->format_exported_text($peeringsubmission->content, $peeringsubmission->contentformat);
        $content = portfolio_rewrite_pluginfile_urls($content, $this->peering->context->id, 'mod_peering',
            'submission_content', $peeringsubmission->id, $this->exporter->get('format'));
        $output .= html_writer::div($content);

        $output .= $this->export_files_list('submission_attachment');

        $strategy = $this->peering->grading_strategy_instance();

        $canviewauthornames = has_capability('mod/peering:viewauthornames', $this->peering->context, $this->get('user'));
        $canviewreviewernames = has_capability('mod/peering:viewreviewernames', $this->peering->context, $this->get('user'));

        foreach ($this->assessments as $assessment) {
            $mform = $strategy->get_assessment_form(null, 'assessment', $assessment, false);
            $options = [
                'showreviewer' => $canviewreviewernames,
                'showauthor' => $canviewauthornames,
                'showform' => true,
                'showweight' => true,
            ];
            if ($assessment->reviewerid == $this->get('user')->id) {
                $options['showreviewer'] = true;
            }

            $peeringassessment = $this->peering->prepare_assessment($assessment, $mform, $options);

            if ($assessment->reviewerid == $this->get('user')->id) {
                $peeringassessment->title = get_string('assessmentbyyourself', 'mod_peering');
            } else {
                $peeringassessment->title = get_string('assessment', 'mod_peering');
            }

            $output .= html_writer::empty_tag('hr');
            $output .= $this->export_assessment($peeringassessment);
        }

        if (trim($this->peering->instructauthors)) {
            $output .= html_writer::tag('h3', get_string('instructauthors', 'mod_peering'));
            $content = $this->format_exported_text($this->peering->instructauthors, $this->peering->instructauthorsformat);
            $content = portfolio_rewrite_pluginfile_urls($content, $this->peering->context->id, 'mod_peering',
                'instructauthors', 0, $this->exporter->get('format'));
            $output .= $content;
        }

        return html_writer::div($output);
    }

    /**
     * Render the content of an assessment.
     *
     * @param peering_assessment $assessment
     * @return string HTML
     */
    protected function export_assessment(peering_assessment $assessment) {

        $output = '';

        if (empty($assessment->title)) {
            $title = get_string('assessment', 'peering');
        } else {
            $title = s($assessment->title);
        }

        $output .= html_writer::tag('h3', $title);

        if ($assessment->reviewer) {
            $output .= html_writer::div(get_string('byfullnamewithoutlink', 'mod_peering', fullname($assessment->reviewer)));
            $output .= html_writer::empty_tag('br');
        }

        if ($this->peering->overallfeedbackmode) {
            if ($assessment->feedbackauthorattachment || trim($assessment->feedbackauthor ?? '') !== '') {
                $output .= html_writer::tag('h3', get_string('overallfeedback', 'mod_peering'));
                $content = $this->format_exported_text($assessment->feedbackauthor, $assessment->feedbackauthorformat);
                $content = portfolio_rewrite_pluginfile_urls($content, $this->peering->context->id, 'mod_peering',
                    'overallfeedback_content', $assessment->id , $this->exporter->get('format'));
                $output .= $content;

                $output .= $this->export_files_list('overallfeedback_attachment');
            }
        }

        if ($assessment->form) {
            $output .= $assessment->form->render();
        }

        return $output;
    }

    /**
     * Export the files in the given file area in a list.
     *
     * @param string $filearea
     * @return string HTML
     */
    protected function export_files_list($filearea) {

        $output = '';
        $files = [];

        foreach ($this->multifiles as $file) {
            if ($file->is_directory()) {
                continue;
            }
            if ($file->get_filearea() !== $filearea) {
                continue;
            }
            if ($file->is_valid_image()) {
                // Not optimal but looks better than original images.
                $files[] = html_writer::tag('li', $this->exporter->get('format')->file_output($file,
                    ['attributes' => ['style' => 'max-height:24px; max-width:24px']]).' '.s($file->get_filename()));
            } else {
                $files[] = html_writer::tag('li', $this->exporter->get('format')->file_output($file));
            }
        }

        if ($files) {
            $output .= html_writer::tag('ul', implode('', $files));
        }

        return $output;
    }

    /**
     * Helper function to call {@link format_text()} on exported text.
     *
     * We need to call {@link format_text()} to convert the text into HTML, but
     * we have to keep the original @@PLUGINFILE@@ placeholder there without a
     * warning so that {@link portfolio_rewrite_pluginfile_urls()} can do its work.
     *
     * @param string $text
     * @param int $format
     * @return string HTML
     */
    protected function format_exported_text($text, $format) {

        $text = str_replace('@@PLUGINFILE@@', '@@ORIGINALPLUGINFILE@@', $text);
        $html = format_text($text, $format, portfolio_format_text_options());
        $html = str_replace('@@ORIGINALPLUGINFILE@@', '@@PLUGINFILE@@', $html);

        return $html;
    }

    /**
     * Add a LEAP2A entry element that corresponds to a submission including attachments.
     *
     * @param portfolio_format_leap2a_writer $leapwriter Writer object to add entries to.
     * @param peering_submission $peeringsubmission
     * @param string $html The exported HTML content of the submission
     * @return int id of new entry
     */
    protected function export_content_leap2a(portfolio_format_leap2a_writer $leapwriter,
            peering_submission $peeringsubmission, $html) {

        $entry = new portfolio_format_leap2a_entry('peeringsubmission'.$peeringsubmission->id,  s($peeringsubmission->title),
            'resource', $html);
        $entry->published = $peeringsubmission->timecreated;
        $entry->updated = $peeringsubmission->timemodified;
        $entry->author = (object)[
            'id' => $peeringsubmission->authorid,
            'email' => $peeringsubmission->authoremail
        ];
        username_load_fields_from_object($entry->author, $peeringsubmission);

        $leapwriter->link_files($entry, $this->multifiles);
        $entry->add_category('web', 'resource_type');
        $leapwriter->add_entry($entry);

        return $entry->id;
    }

    /**
     * Return URL for redirecting the user back to where the export started.
     *
     * @return string
     */
    public function get_return_url() {

        $returnurl = new moodle_url('/mod/peering/submission.php', ['cmid' => $this->cm->id, 'id' => $this->submissionid]);
        return $returnurl->out();
    }

    /**
     * Get navigation that logically follows from the place the user was before.
     *
     * @return array
     */
    public function get_navigation() {

        $navlinks = [
            ['name' => s($this->submission->title)],
        ];

        return [$navlinks, $this->cm];
    }

    /**
     * How long might we expect this export to take.
     *
     * @return string such as PORTFOLIO_TIME_LOW
     */
    public function expected_time() {
        return $this->expected_time_file();
    }

    /**
     * Make sure that the current user is allowed to do the export.
     *
     * @return boolean
     */
    public function check_permissions() {
        return has_capability('mod/peering:exportsubmissions', context_module::instance($this->cm->id));
    }

    /**
     * Return the SHA1 hash of the exported content.
     *
     * @return string
     */
    public function get_sha1() {

        $identifier = 'submission:'.$this->submission->id.'@'.$this->submission->timemodified;

        if ($this->assessments) {
            $ids = array_keys($this->assessments);
            sort($ids);
            $identifier .= '/assessments:'.implode(',', $ids);
        }

        if ($this->multifiles) {
            $identifier .= '/files:'.$this->get_sha1_file();
        }

        return sha1($identifier);
    }

    /**
     * Return a nice name to be displayed about this export location.
     *
     * @return string
     */
    public static function display_name() {
        return get_string('pluginname', 'mod_peering');
    }

    /**
     * What export formats the peering generally supports.
     *
     * If there are no files embedded/attached, the plain HTML format is added
     * in {@link self::load_data()}.
     *
     * @return array
     */
    public static function base_supported_formats() {
        return [
            PORTFOLIO_FORMAT_RICHHTML,
            PORTFOLIO_FORMAT_LEAP2A,
        ];
    }
}
