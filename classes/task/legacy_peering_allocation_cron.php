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
 * Legacy peering allocation plugins cron.
 *
 * @package     mod_peering
 * @copyright   2018 Simey Lameze <simey@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_peering\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Legacy peering allocation plugins cron.
 *
 * @package     mod_peering
 * @copyright   2018 Simey Lameze <simey@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_peering_allocation_cron extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('legacyallocationplugincron', 'mod_peering');
    }

    /**
     * Execute all peering allocation methods plugins cron tasks.
     */
    public function execute() {
        cron_execute_plugin_type('peeringallocation', 'peering allocation methods');
    }
}
