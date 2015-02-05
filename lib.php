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

function local_batch_execute_jobs($jobs) {
    $starttime = time();
    foreach ($jobs as $job) {
        if (time() - $starttime >= BATCH_CRON_TIME) {
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
        if (empty($job->error)) {
            $job->error = get_string('aborted', 'local_batch');
        }
        $job->save();
    }

    $starthour = isset($CFG->local_batch_start_hour) ? (int) $CFG->local_batch_start_hour : 0;
    $stophour = isset($CFG->local_batch_stop_hour) ? (int) $CFG->local_batch_stop_hour : 0;
    $date = getdate();
    $hour = $date['hours'];
    if ($starthour < $stophour) {
        $execute = ($hour >= $starthour and $hour < $stophour);
    } else {
        $execute = ($hour >= $starthour or $hour < $stophour);
    }
    if (!$execute) {
        $jobs = batch_queue::get_jobs(batch_queue::FILTER_NO_WAIT);
        if (!empty($jobs)) {
            mtrace("batch: executing no wait tasks");
            flush();
            local_batch_execute_jobs($jobs);
        }
        mtrace("batch: execution will start at $starthour");
        flush();
        return;
    }

    $jobs = batch_queue::get_jobs(batch_queue::FILTER_PENDING);
    if (!$jobs) {
        mtrace("batch: no pending jobs");
        flush();
        return;
    }

    local_batch_execute_jobs($jobs);
}
