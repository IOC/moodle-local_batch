<?php

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