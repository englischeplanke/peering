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
 * Provides support for the conversion of moodle1 backup to the moodle2 format
 *
 * @package    peeringform_comments
 * @copyright  2011 David Mudrak <david@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Conversion handler for the comments grading strategy data
 */
class moodle1_peeringform_comments_handler extends moodle1_peeringform_handler {

    /**
     * Converts <ELEMENT> into <peeringform_comments_dimension>
     *
     * @param array $data legacy element data
     * @param array $raw raw element data
     *
     * @return array converted
     */
    public function process_legacy_element(array $data, array $raw) {
        // prepare a fake record and re-use the upgrade logic
        $fakerecord = (object)$data;
        $converted = (array)peeringform_comments_upgrade_element($fakerecord, 12345678);
        unset($converted['peeringid']);

        $converted['id'] = $data['id'];
        $this->write_xml('peeringform_comments_dimension', $converted, array('/peeringform_comments_dimension/id'));

        return $converted;
    }
}

/**
 * Transforms a given record from peering_elements_old into an object to be saved into peeringform_comments
 *
 * @param stdClass $old legacy record from peering_elements_old
 * @param int $newpeeringid id of the new peering instance that replaced the previous one
 * @return stdclass to be saved in peeringform_comments
 */
function peeringform_comments_upgrade_element(stdclass $old, $newpeeringid) {
    $new                    = new stdclass();
    $new->peeringid        = $newpeeringid;
    $new->sort              = $old->elementno;
    $new->description       = $old->description;
    $new->descriptionformat = FORMAT_HTML;
    return $new;
}
