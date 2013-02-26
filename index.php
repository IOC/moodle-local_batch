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

require_once('../../config.php');
require_once('lib.php');

require_login();

$cancel_job = optional_param('cancel_job', 0, PARAM_INT);
$category   = optional_param('category', 0, PARAM_INT);
$filter     = optional_param('filter', batch_queue::FILTER_ALL, PARAM_INT);
$page       = optional_param('page', 0, PARAM_INT);
$retry_job  = optional_param('retry_job', 0, PARAM_INT);
$view       = optional_param('view', 'job_queue', PARAM_ALPHAEXT);

$context = context_system::instance();

if ($category) {
    $PAGE->set_url('/local/batch/index.php', array('category' => $category));
    if (!$DB->record_exists('course_categories', array('id' => $category))) {
        print_error('unknowcategory');
    }
    $context = context_coursecat::instance($category);
} elseif (has_capability('moodle/category:manage', $context)) {
    $category = 0;
    $PAGE->set_url('/local/batch/index.php', array('category' => $category));
}

require_capability('moodle/category:manage', $context);

$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title("$SITE->shortname: ".get_string('pluginname', 'local_batch'));
$PAGE->set_heading($SITE->fullname);
$PAGE->requires->css('/local/batch/styles.css');
$PAGE->requires->js('/local/batch/batch.js');
form_init_date_js();
$batchoutput = $PAGE->get_renderer('local_batch');

if ($view == 'job_queue') {

    if ($cancel_job) {
        require_sesskey();
        batch_queue::cancel_job($cancel_job, $context);
        $params = array(
            'view' => 'job_queue',
            'filter' => $filter,
            'page' => $page,
            'category' => $category
        );
        redirect(new moodle_url('/local/batch/index.php', $params));
    }

    if ($retry_job) {
        require_sesskey();
        batch_queue::retry_job($retry_job);
        $params = array(
            'view' => 'job_queue',
            'filter' => $filter,
            'page' => $page,
            'category' => $category
        );
        redirect(new moodle_url('/local/batch/index.php', $params));
    }
    $count = batch_queue::count_jobs($filter, $category);
    $jobs = batch_queue::get_jobs($filter, $category, $page * LOCAL_BATCH_PERPAGE, LOCAL_BATCH_PERPAGE);

    echo $OUTPUT->header();
    echo $batchoutput->print_header($view, $category);
    echo $batchoutput->print_job_queue($jobs, $count, $page, $filter, $category);
} elseif ($view == 'create_courses') {
    list($csvfile, $data) = batch_create_courses_get_data();
    if (!$csvfile and $data) {
        $draftareaid = file_get_submitted_draft_itemid('choose-backup');
        foreach ($data['courses'] as $params) {
            $params->startday = $data['startday'];
            $params->startmonth = $data['startmonth'];
            $params->startyear = $data['startyear'];
            $job = batch_queue::add_job($USER->id, $params->category, 'create_course', (object) $params);
            $context = context_coursecat::instance($params->category);
            file_prepare_draft_area($draftareaid, $context->id, 'local_batch', 'job', $job->id);
            file_save_draft_area_files($draftareaid, $context->id, 'local_batch', 'job', $job->id);
        }
        redirect(new moodle_url('/local/batch/index.php', array('category' => $category)));
    } elseif (!$csvfile) {
        $data = array();
        $date = getdate();
        $data['lastindex']  = 0;
        $data['startyear']  = $date['year'];
        $data['startmonth'] = $date['mon'];
        $data['startday']   = $date['mday'];
        $data['courses']    = array();
        $data['courses'][0] = (object) array(
            'shortname' => '',
            'fullname'  => '',
            'category'  => 0
        );
    } elseif($csvfile) {
        $data['draftareaid'] = file_get_submitted_draft_itemid('choose-backup');
    }
    $data['category'] = $category;
    echo $OUTPUT->header();
    echo $batchoutput->print_header($view, $category);
    echo $batchoutput->print_create_courses($data);
} elseif ($view == 'delete_courses') {
    if ($data = batch_data_submitted()) {
        foreach ($data as $name => $value) {
            if (preg_match("/^course-/", $name)) {
                preg_match('/[^\d]*(\d+)$/', $name, $id);
                $params = array(
                    'shortname' => stripslashes($value),
                    'courseid'  => isset($id[1])?$id[1]:0
                );
                $catid = batch_get_course_category($params['courseid']);
                batch_queue::add_job($USER->id, $catid, 'delete_course', (object) $params);
            }
        }
        redirect(new moodle_url('/local/batch/index.php', array('category' => $category)));
    }
    echo $OUTPUT->header();
    echo $batchoutput->print_header($view, $category);
    $courses = batch_get_category_and_subcategories_info($category);
    echo $batchoutput->print_delete_courses($courses, $category);
} elseif ($view == 'restart_courses') {
    if ($data = batch_data_submitted()) {
        if (preg_match("/([0-9]{1,2})\/([0-9]{1,2})\/([0-9]{4})/", $data['startdate'], $match)) {
            $startday = (int) $match[1];
            $startmonth = (int) $match[2];
            $startyear = (int) $match[3];
        } else {
            $date = getdate();
            $startday = $date['mday'];
            $startyear = $date['year'];
            $startmonth = $date['mon'];
        }

        $categorydest = (int) $data['categorydest'];
        if (isset($data['role'])) {
            $roleassignments = implode(',', $data['role']);
        } else {
            $roleassignments = '';
        }
        $groups = !empty($data['groups']);

        if ($match and checkdate($startmonth, $startday, $startyear)) {
            foreach ($data as $name => $value) {
                if (preg_match("/^course-/", $name)) {
                    $params = array(
                        'shortname'       => stripslashes($value),
                        'startyear'       => $startyear,
                        'startmonth'      => $startmonth,
                        'startday'        => $startday,
                        'category'        => $categorydest,
                        'roleassignments' => $roleassignments,
                        'groups'          => $groups,
                    );
                    preg_match('/[^\d]*(\d+)$/', $name, $id);
                    $courseid = isset($id[1])?$id[1]:0;
                    $catid = batch_get_course_category($courseid);
                    batch_queue::add_job($USER->id, $catid, 'restart_course', (object) $params);
                }
            }
        }
        redirect(new moodle_url('/local/batch/index.php', array('category' => $category)));
    }
    echo $OUTPUT->header();
    echo $batchoutput->print_header($view, $category);
    $courses = batch_get_category_and_subcategories_info($category);
    $data = array();
    $date = getdate();
    $data['startyear']  = $date['year'];
    $data['startmonth'] = $date['mon'];
    $data['startday']   = $date['mday'];
    $data['category']   = $category;
    echo $batchoutput->print_restart_courses($courses, $data);
} elseif ($view == 'import_courses') {
    if ($data = batch_data_submitted()) {
        if (!empty($data['categorydest'])) {
            if (preg_match("/([0-9]{1,2})\/([0-9]{1,2})\/([0-9]{4})/", $data['startdate'], $match)) {
                $startday   = (int) $match[1];
                $startmonth = (int) $match[2];
                $startyear  = (int) $match[3];
            } else {
                $date       = getdate();
                $startday   = $date['mday'];
                $startyear  = $date['year'];
                $startmonth = $date['mon'];
            }
            $categorydest  = (int) $data['categorydest'];
            $coursedisplay = !empty($data['coursedisplay']);
            $fs = get_file_storage();
            $context = context_user::instance($USER->id);
            if ($files = $fs->get_area_files($context->id, 'user', 'draft', $data['choose-backup'], 'id DESC', false)) {
                $params = array(
                    'startday'      => $startday,
                    'startmonth'    => $startmonth,
                    'startyear'     => $startyear,
                    'category'      => $categorydest,
                    'coursedisplay' => $coursedisplay
                );
                $context = context_coursecat::instance($categorydest);
                $newfile = array(
                    'component' => 'local_batch',
                    'filearea'  => 'job',
                    'contextid' => $context->id,
                );
                foreach ($files as $file) {
                    $job = batch_queue::add_job($USER->id, $categorydest, 'import_course', (object) $params);
                    $newfile['itemid'] = $job->id;
                    $fs->create_file_from_storedfile((object) $newfile, $file->get_id());
                }
                redirect(new moodle_url('/local/batch/index.php', array('category' => $category)));
            }
        } else {
            $draftareaid = file_get_submitted_draft_itemid('choose-backup');
        }
    }
    echo $OUTPUT->header();
    echo $batchoutput->print_header($view, $category);
    $data = array();
    $date = getdate();
    $data['category']    = $category;
    $data['startyear']   = $date['year'];
    $data['startmonth']  = $date['mon'];
    $data['startday']    = $date['mday'];
    $data['draftareaid'] = isset($draftareaid)?$draftareaid:0;
    echo $batchoutput->print_import_courses($data);
}
echo $OUTPUT->footer();
