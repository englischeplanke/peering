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
 * The configuration variables for "Number of errors" grading strategy
 *
 * The values defined here are often used as defaults for all module instances.
 *
 * @package    peeringform_numerrors
 * @copyright  2024 Johann Mellin <johann.mellin@tuhh.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$settings->add(new admin_setting_configtext('peeringform_numerrors/grade0', get_string('grade0', 'peeringform_numerrors'),
                    get_string('configgrade0', 'peeringform_numerrors'),
                    get_string('grade0default', 'peeringform_numerrors'), $paramtype=PARAM_TEXT, $size=15));

$settings->add(new admin_setting_configtext('peeringform_numerrors/grade1', get_string('grade1', 'peeringform_numerrors'),
                    get_string('configgrade1', 'peeringform_numerrors'),
                    get_string('grade1default', 'peeringform_numerrors'), $paramtype=PARAM_TEXT, $size=15));
