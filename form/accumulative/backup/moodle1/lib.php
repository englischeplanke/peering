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
 * @package    peeringform_accumulative
 * @copyright  2011 David Mudrak <david@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Conversion handler for the accumulative grading strategy data
 */
class moodle1_peeringform_accumulative_handler extends moodle1_peeringform_handler {

    /** @var used by {@link self::migrate_legacy_scales()} */
    private $newscaleids = array();

    /**
     * Converts <ELEMENT> into <peeringform_accumulative_dimension>
     */
    public function process_legacy_element(array $data, array $raw) {
        // prepare a fake record and re-use the upgrade logic
        $fakerecord = (object)$data;
        $newscaleid = $this->get_new_scaleid($data['scale']);
        $converted = (array)peeringform_accumulative_upgrade_element($fakerecord, $newscaleid, 12345678);
        unset($converted['peeringid']);

        $converted['id'] = $data['id'];
        $this->write_xml('peeringform_accumulative_dimension', $converted, array('/peeringform_accumulative_dimension/id'));

        return $converted;
    }

    /**
     * If needed, creates new standard (global) scale to replace the legacy peering one and returns the mapping
     *
     * If the given $oldscaleid represents a scale, returns array $oldscaleid => $newscaleid that
     * can be used as a parameter for {@link peeringform_accumulative_upgrade_element()}. Otherwise
     * this method returns empty array.
     *
     * In peering 1.x, scale field in peering_elements had the following meaning:
     *   0 | 2 point Yes/No scale
     *   1 | 2 point Present/Absent scale
     *   2 | 2 point Correct/Incorrect scale
     *   3 | 3 point Good/Poor scale
     *   4 | 4 point Excellent/Very Poor scale
     *   5 | 5 point Excellent/Very Poor scale
     *   6 | 7 point Excellent/Very Poor scale
     *   7 | Score out of 10
     *   8 | Score out of 20
     *   9 | Score out of 100
     *
     * @see peeringform_accumulative_upgrade_scales()
     * @param int $oldscaleid the value of the 'scale' field in the moodle.xml backup file
     * @return array (int)oldscaleid => (int)newscaleid
     */
    protected function get_new_scaleid($oldscaleid) {

        if ($oldscaleid >= 0 and $oldscaleid <= 6) {
            // we need a new scale id
            if (!isset($this->newscaleids[$oldscaleid])) {
                // this is the first time the legacy scale is used in moodle.xml
                // let us migrate it
                $scale = $this->get_new_scale_definition($oldscaleid);
                // other scales are already stashed - let us append a new artificial record
                $currentscaleids = $this->converter->get_stash_itemids('scales');
                if (empty($currentscaleids)) {
                    $scale['id'] = 1;
                } else {
                    $scale['id'] = max($currentscaleids) + 1;
                }
                $this->converter->set_stash('scales', $scale, $scale['id']);
                $this->newscaleids[$oldscaleid] = $scale['id'];
                // inform the peering instance that it should annotate the new scale
                $inforefman = $this->parenthandler->get_inforef_manager();
                $inforefman->add_ref('scale', $scale['id']);
            }
            return array($oldscaleid => $this->newscaleids[$oldscaleid]);

        } else {
            // not a scale
            return array();
        }
    }

    /**
     * Returns a definition of a legacy peering scale
     *
     * @see peeringform_accumulative_upgrade_scales
     * @param object $oldscaleid
     * @return array
     */
    private function get_new_scale_definition($oldscaleid) {

        $data = array(
            'userid'            => 0,   // restore will remap to the current user
            'courseid'          => 0,   // global scale
            'description'       => '',
            'descriptionformat' => FORMAT_HTML,
        );

        switch ($oldscaleid) {
        case 0:
            $data['name']  = get_string('scalename0', 'peeringform_accumulative');
            $data['scale'] = implode(',', array(get_string('no'), get_string('yes')));
            break;
        case 1:
            $data['name']  = get_string('scalename1', 'peeringform_accumulative');
            $data['scale'] = implode(',', array(get_string('absent', 'peeringform_accumulative'),
                                                get_string('present', 'peeringform_accumulative')));
            break;
        case 2:
            $data['name']  = get_string('scalename2', 'peeringform_accumulative');
            $data['scale'] = implode(',', array(get_string('incorrect', 'peeringform_accumulative'),
                                                get_string('correct', 'peeringform_accumulative')));
            break;
        case 3:
            $data['name']  = get_string('scalename3', 'peeringform_accumulative');
            $data['scale'] = implode(',', array('* ' . get_string('poor', 'peeringform_accumulative'),
                                                '**',
                                                '*** ' . get_string('good', 'peeringform_accumulative')));
            break;
        case 4:
            $data['name']  = get_string('scalename4', 'peeringform_accumulative');
            $data['scale'] = implode(',', array('* ' . get_string('verypoor', 'peeringform_accumulative'),
                                                '**',
                                                '***',
                                                '**** ' . get_string('excellent', 'peeringform_accumulative')));
            break;
        case 5:
            $data['name']  = get_string('scalename5', 'peeringform_accumulative');
            $data['scale'] = implode(',', array('* ' . get_string('verypoor', 'peeringform_accumulative'),
                                                '**',
                                                '***',
                                                '****',
                                                '***** ' . get_string('excellent', 'peeringform_accumulative')));
            break;
        case 6:
            $data['name']  = get_string('scalename6', 'peeringform_accumulative');
            $data['scale'] = implode(',', array('* ' . get_string('verypoor', 'peeringform_accumulative'),
                                                '**',
                                                '***',
                                                '****',
                                                '*****',
                                                '******',
                                                '******* ' . get_string('excellent', 'peeringform_accumulative')));
            break;
        }

        return $data;
    }
}

/**
 * Transforms a given record from peering_elements_old into an object to be saved into peeringform_accumulative
 *
 * @param stdClass $old legacy record from peering_elements_old
 * @param array $newscaleids mapping from old scale types into new standard ones
 * @param int $newpeeringid id of the new peering instance that replaced the previous one
 * @return stdclass to be saved in peeringform_accumulative
 */
function peeringform_accumulative_upgrade_element(stdclass $old, array $newscaleids, $newpeeringid) {
    $new = new stdclass();
    $new->peeringid = $newpeeringid;
    $new->sort = $old->elementno;
    $new->description = $old->description;
    $new->descriptionformat = FORMAT_HTML;
    // calculate new grade/scale of the element
    if ($old->scale >= 0 and $old->scale <= 6 and isset($newscaleids[$old->scale])) {
        $new->grade = -$newscaleids[$old->scale];
    } elseif ($old->scale == 7) {
        $new->grade = 10;
    } elseif ($old->scale == 8) {
        $new->grade = 20;
    } elseif ($old->scale == 9) {
        $new->grade = 100;
    } else {
        $new->grade = 0;    // something is wrong
    }
    // calculate new weight of the element. Negative weights are not supported any more and
    // are replaced with weight = 0. Legacy peering did not store the raw weight but the index
    // in the array of weights (see $peering_EWEIGHTS in peering 1.x)
    // peering 2.0 uses integer weights only (0-16) so all previous weights are multiplied by 4.
    switch ($old->weight) {
        case 8: $new->weight = 1; break;
        case 9: $new->weight = 2; break;
        case 10: $new->weight = 3; break;
        case 11: $new->weight = 4; break;
        case 12: $new->weight = 6; break;
        case 13: $new->weight = 8; break;
        case 14: $new->weight = 16; break;
        default: $new->weight = 0;
    }
    return $new;
}
