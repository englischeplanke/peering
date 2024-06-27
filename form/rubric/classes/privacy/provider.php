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
 * Provides the class {@link peeringform_rubric\privacy\provider}
 *
 * @package     peeringform_rubric
 * @category    privacy
 * @copyright   2018 David Mudrák <david@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace peeringform_rubric\privacy;

use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy API implementation for the Rubric strategy.
 *
 * @copyright 2018 David Mudrák <david@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\null_provider, \mod_peering\privacy\peeringform_provider {

    /**
     * Explain that this plugin stores no personal data.
     *
     * @return string
     */
    public static function get_reason() : string {
        return 'privacy:metadata';
    }

    /**
     * Return details of the filled assessment form.
     *
     * @param stdClass $user User we are exporting data for
     * @param context $context The peering activity context
     * @param array $subcontext Subcontext within the context to export to
     * @param int $assessmentid ID of the assessment
     */
    public static function export_assessment_form(\stdClass $user, \context $context, array $subcontext, int $assessmentid) {
        global $DB;

        if ($context->contextlevel != CONTEXT_MODULE) {
            throw new \coding_exception('Unexpected context provided');
        }

        $sql = "SELECT r.id, r.peeringid, r.description, r.descriptionformat,
                       rl.id AS levelid, rl.grade AS levelgrade, rl.definition, rl.definitionformat,
                       wg.grade
                  FROM {course_modules} cm
                  JOIN {context} ctx ON ctx.contextlevel = :contextlevel AND ctx.instanceid = cm.id
                  JOIN {peering} w ON cm.instance = w.id
                  JOIN {peeringform_rubric} r ON r.peeringid = w.id
                  JOIN {peeringform_rubric_levels} rl ON rl.dimensionid = r.id
             LEFT JOIN {peering_grades} wg ON wg.strategy = :strategy AND wg.dimensionid = r.id AND wg.assessmentid = :assessmentid
                 WHERE ctx.id = :contextid
              ORDER BY r.sort, rl.grade DESC";

        $params = [
            'strategy' => 'rubric',
            'contextlevel' => CONTEXT_MODULE,
            'contextid' => $context->id,
            'assessmentid' => $assessmentid,
        ];

        $writer = \core_privacy\local\request\writer::with_context($context);
        $criteria = [];
        $peeringid = null;
        $hasdata = false;

        $rs = $DB->get_recordset_sql($sql, $params);

        foreach ($rs as $record) {
            if (empty($criteria[$record->id])) {
                $criteria[$record->id] = (object) [
                    'description' => $writer->rewrite_pluginfile_urls($subcontext, 'peeringform_rubric', 'description',
                        $record->id, $record->description),
                    'descriptionformat' => $record->descriptionformat,
                    'grade' => $record->grade,
                    'levels' => [],
                ];
                $peeringid = $record->peeringid;
            }
            $criteria[$record->id]->levels[] = (object) [
                'grade' => $record->levelgrade,
                'definition' => $record->definition,
                'definitionformat' => $record->definitionformat,
            ];
            if ($record->grade !== null) {
                $hasdata = true;
            }
        }

        $rs->close();

        if ($hasdata) {
            $data = (object) [
                'criteria' => array_values($criteria),
            ];
            $layout = $DB->get_field('peeringform_rubric_config', 'layout', ['peeringid' => $peeringid]);

            foreach (array_keys($criteria) as $dimensionid) {
                $writer->export_area_files($subcontext, 'peeringform_rubric', 'description', $dimensionid);
            }

            $writer->export_data($subcontext, $data);
            $writer->export_metadata($subcontext, 'layout', $layout, get_string('layout', 'peeringform_rubric'));
        }
    }
}
