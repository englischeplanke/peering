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

namespace mod_peering\event;

/**
 * This event is triggered when a phase is automatically switched, usually from cron_task.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int previouspeeringphase: Previous peering phase.
 *      - int targetpeeringphase: Target peering phase.
 * }
 *
 * @package    mod_peering
 * @copyright  2020 Universitat Jaume I <https://www.uji.es/>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class phase_automatically_switched extends \core\event\base {

    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'peering';
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The phase of the peering with course module id " .
            "'$this->contextinstanceid' has been automatically switched from " .
            "'{$this->other['previouspeeringphase']} to '{$this->other['currentpeeringphase']}'.";
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventphaseautomaticallyswitched', 'mod_peering');
    }

    /**
     * Get URL related to the action.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/peering/view.php', array('id' => $this->contextinstanceid));
    }

    /**
     * Custom validation.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->other['previouspeeringphase'])) {
            throw new \coding_exception('The \'previouspeeringphase\' value must be set in other.');
        }
        if (!isset($this->other['targetpeeringphase'])) {
            throw new \coding_exception('The \'targetpeeringphase\' value must be set in other.');
        }
    }

    /**
     * Map the objectid information in order to restore the event accurately. In this event
     * objectid is the peering id.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return array('db' => 'peering', 'restore' => 'peering');
    }

    /**
     * No need to map the 'other' field as it only stores phases and they don't need to be mapped.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
