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
 * The peering module configuration variables
 *
 * The values defined here are often used as defaults for all module instances.
 *
 * @package    mod_peering
 * @copyright  2024 Johann Mellin <johann.mellin@tuhh.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot.'/mod/peering/locallib.php');

    $grades = peering::available_maxgrades_list();

    $settings->add(new admin_setting_configselect('peering/grade', get_string('submissiongrade', 'peering'),
                        get_string('configgrade', 'peering'), 80, $grades));

    $settings->add(new admin_setting_configselect('peering/gradinggrade', get_string('gradinggrade', 'peering'),
                        get_string('configgradinggrade', 'peering'), 20, $grades));

    $options = array();
    for ($i = 5; $i >= 0; $i--) {
        $options[$i] = $i;
    }
    $settings->add(new admin_setting_configselect('peering/gradedecimals', get_string('gradedecimals', 'peering'),
                        get_string('configgradedecimals', 'peering'), 0, $options));

    if (isset($CFG->maxbytes)) {
        $maxbytes = get_config('peering', 'maxbytes');
        $options = get_max_upload_sizes($CFG->maxbytes, 0, 0, $maxbytes);
        $settings->add(new admin_setting_configselect('peering/maxbytes', get_string('maxbytes', 'peering'),
                            get_string('configmaxbytes', 'peering'), 0, $options));
    }

    $settings->add(new admin_setting_configselect('peering/strategy', get_string('strategy', 'peering'),
                        get_string('configstrategy', 'peering'), 'accumulative', peering::available_strategies_list()));

    $options = peering::available_example_modes_list();
    $settings->add(new admin_setting_configselect('peering/examplesmode', get_string('examplesmode', 'peering'),
                        get_string('configexamplesmode', 'peering'), peering::EXAMPLES_VOLUNTARY, $options));

    // include the settings of allocation subplugins
    $allocators = core_component::get_plugin_list('peeringallocation');
    foreach ($allocators as $allocator => $path) {
        if (file_exists($settingsfile = $path . '/settings.php')) {
            $settings->add(new admin_setting_heading('peeringallocationsetting'.$allocator,
                    get_string('allocation', 'peering') . ' - ' . get_string('pluginname', 'peeringallocation_' . $allocator), ''));
            include($settingsfile);
        }
    }

    // include the settings of grading strategy subplugins
    $strategies = core_component::get_plugin_list('peeringform');
    foreach ($strategies as $strategy => $path) {
        if (file_exists($settingsfile = $path . '/settings.php')) {
            $settings->add(new admin_setting_heading('peeringformsetting'.$strategy,
                    get_string('strategy', 'peering') . ' - ' . get_string('pluginname', 'peeringform_' . $strategy), ''));
            include($settingsfile);
        }
    }

    // include the settings of grading evaluation subplugins
    $evaluations = core_component::get_plugin_list('peeringeval');
    foreach ($evaluations as $evaluation => $path) {
        if (file_exists($settingsfile = $path . '/settings.php')) {
            $settings->add(new admin_setting_heading('peeringevalsetting'.$evaluation,
                    get_string('evaluation', 'peering') . ' - ' . get_string('pluginname', 'peeringeval_' . $evaluation), ''));
            include($settingsfile);
        }
    }

}
