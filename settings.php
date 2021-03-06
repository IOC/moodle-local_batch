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
 * @package    local
 * @subpackage batch
 * @copyright  2014 Institut Obert de Catalunya
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_batch', get_string('pluginname', 'local_batch'));

     $settings->add(
        new admin_setting_configselect('local_batch_start_hour',
                                       get_string('batch_start_hour', 'local_batch'), '',
                                       '0', range(0, 23))
    );

    $settings->add(
        new admin_setting_configselect('local_batch_stop_hour',
                                       get_string('batch_stop_hour', 'local_batch'), '',
                                       '0', range(0, 23))
    );

    $settings->add(
        new admin_setting_configtext('local_batch_path_backups',
                                       get_string('batch_path_backups', 'local_batch'), get_string('batch_path_backups_desc', 'local_batch', $CFG->dataroot . '/'),
                                       '', PARAM_URL, 50)
    );
    $ADMIN->add('localplugins', $settings);
}