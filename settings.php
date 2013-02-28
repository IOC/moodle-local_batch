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

    $ADMIN->add('localplugins', $settings);
}