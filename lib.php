<?php

// Local batch plugin for Moodle
// Copyright © 2012,2013 Institut Obert de Catalunya
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//
// Ths program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.

require_once('locallib.php');

const LOCAL_BATCH_PERPAGE = 10;

function local_batch_extends_settings_navigation($nav, $context) {
    if (has_capability('moodle/site:config', context_system::instance())) {
        $node = navigation_node::create(get_string('pluginname', 'local_batch'),
                        new moodle_url('/local/batch/index.php',
                        array('category' => 0)),
                        navigation_node::TYPE_ROOTNODE,
                        'local_batch',
                        'local_batch',
                        new pix_icon('icon', '', 'local_batch'));
        if ($settings = $nav->get('root')) {
            $settings->children->add($node);
        }
    }
    if ($context and has_capability('moodle/category:manage', $context) and $context->contextlevel == CONTEXT_COURSECAT) {
        $node = navigation_node::create(get_string('pluginname', 'local_batch'),
                        new moodle_url('/local/batch/index.php',
                        array('category' => $context->instanceid)),
                        navigation_node::TYPE_SETTING,
                        null,
                        null,
                        new pix_icon('icon', '', 'local_batch'));
        $settings = $nav->get('categorysettings');
        $settings->children->add($node);
    }
}

function local_batch_pluginfile($course, $cm, $context, $filearea, $args,
                               $forcedownload, array $options=array()) {
    // Check context

    if (!has_capability('moodle/category:manage', $context)) {
        return false;
    }

    // Check job

    $jobid = (int) array_shift($args);
    $job = batch_queue::get_job($jobid);
    if ($filearea != 'job' or !$job) {
        return false;
    }

    // Fetch file info

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/local_batch/$filearea/$jobid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, true, $options);
}

function local_batch_cron() {
    global $CFG;

    $jobs = batch_queue::get_jobs(batch_queue::FILTER_TODELETE);
    foreach ($jobs as $job) {
        mtrace("batch: job {$job->id} deleted");
        $job->delete();
    }

    $jobs = batch_queue::get_jobs(batch_queue::FILTER_ABORTED);
    foreach ($jobs as $job) {
        mtrace("batch: job {$job->id} aborted");
        $job->timeended = time();
        $job->error = 'aborted';
        $job->save();
    }

    $start_hour = isset($CFG->local_batch_start_hour) ? (int) $CFG->local_batch_start_hour : 0;
    $stop_hour = isset($CFG->local_batch_stop_hour) ? (int) $CFG->local_batch_stop_hour : 0;
    $date = getdate();
    $hour = $date['hours'];
    if ($start_hour < $stop_hour) {
        $execute = ($hour >= $start_hour and $hour < $stop_hour);
    } else {
        $execute = ($hour >= $start_hour or $hour < $stop_hour);
    }
    if (!$execute) {
        mtrace("batch: execution will start at $start_hour");
        flush();
        return;
    }

    $jobs = batch_queue::get_jobs(batch_queue::FILTER_PENDING);
    if (!$jobs) {
        mtrace("batch: no pending jobs");
        flush();
        return;
    }

    $start_time = time();
    foreach ($jobs as $job) {
        if (time() - $start_time >= BATCH_CRON_TIME) {
            return;
        }
        if ($job->can_start()) {
            mtrace("batch: executing job {$job->id}... ", "");
            flush();
            $job->execute();
            mtrace($job->error ? "ERROR" : "OK");
            flush();
        }
    }
}
