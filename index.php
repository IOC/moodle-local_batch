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

require_once('../../config.php');
require_once('lib.php');

require_login();

$canceljob         = optional_param('cancel_job', 0, PARAM_INT);
$category          = optional_param('category', 0, PARAM_INT);
$filter            = optional_param('filter', batch_queue::FILTER_ALL, PARAM_INT);
$page              = optional_param('page', 0, PARAM_INT);
$retryjob          = optional_param('retry_job', 0, PARAM_INT);
$view              = optional_param('view', 'job_queue', PARAM_ALPHAEXT);
$prioritizejob     = optional_param('prioritize_job', 0, PARAM_INT);
$desprioritizejob  = optional_param('desprioritize_job', 0, PARAM_INT);

$context = context_system::instance();

if ($category) {
    $PAGE->set_url('/local/batch/index.php', array('category' => $category));
    if (!$DB->record_exists('course_categories', array('id' => $category))) {
        print_error('unknowcategory');
    }
    $context = context_coursecat::instance($category);
    $categoryname = coursecat::get($category)->get_formatted_name();
    $PAGE->navbar->add(get_string('pluginname', 'local_batch'), new moodle_url('/local/batch/index.php', array('category' => $category)));
    $PAGE->navbar->add($categoryname);
} else if (has_capability('moodle/category:manage', $context)) {
    $category = 0;
    $PAGE->set_url('/local/batch/index.php', array('category' => $category));
    $PAGE->navbar->add(get_string('pluginname', 'local_batch'), '/local/batch/index.php');
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
$params = array(
    'category' => $category,
    'view' => $view,
);
$PAGE->navbar->add(get_string('view_' . $view, 'local_batch'), new moodle_url('/local/batch/index.php', $params));

if ($view == 'job_queue') {

    if ($canceljob) {
        require_sesskey();
        batch_queue::cancel_job($canceljob);
        $params = array(
            'view' => 'job_queue',
            'filter' => $filter,
            'page' => $page,
            'category' => $category
        );
        redirect(new moodle_url('/local/batch/index.php', $params));
    }

    if ($retryjob) {
        require_sesskey();
        batch_queue::retry_job($retryjob);
        $params = array(
            'view' => 'job_queue',
            'filter' => $filter,
            'page' => $page,
            'category' => $category
        );
        redirect(new moodle_url('/local/batch/index.php', $params));
    }

    if ($prioritizejob) {
        require_sesskey();
        batch_queue::prioritize_job($prioritizejob, true);
        $params = array(
            'view' => 'job_queue',
            'filter' => $filter,
            'page' => $page,
            'category' => $category
        );
        redirect(new moodle_url('/local/batch/index.php', $params));
    }

    if ($desprioritizejob) {
        require_sesskey();
        batch_queue::prioritize_job($desprioritizejob, false);
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

    $mypending = 0;
    $totalpending = batch_queue::count_jobs(batch_queue::FILTER_PENDING, 0);
    if ($totalpending) {
        $mypending = batch_queue::count_jobs(batch_queue::FILTER_PENDING, 0, $USER->id);
    }

    echo $OUTPUT->header();
    echo $batchoutput->print_header($view, $category);
    echo $batchoutput->print_job_queue($jobs, $count, $page, $filter, $category, $totalpending, $mypending);
} else if ($view == 'create_courses') {
    list($csvfile, $data) = batch_create_courses_get_data();
    if (!$csvfile and $data) {
        $draftareaid = file_get_submitted_draft_itemid('choose-backup');
        foreach ($data['courses'] as $params) {
            $params->startday = $data['startday'];
            $params->startmonth = $data['startmonth'];
            $params->startyear = $data['startyear'];
            $job = batch_queue::add_job($USER->id, $params->category, 'create_course', (object) $params, true);
            $context = context_coursecat::instance($params->category);
            file_prepare_draft_area($draftareaid, $context->id, 'local_batch', 'job', $job->id);
            file_save_draft_area_files($draftareaid, $context->id, 'local_batch', 'job', $job->id);
        }
        redirect(new moodle_url('/local/batch/index.php', array('category' => $category)));
    } else if (!$csvfile) {
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
    } else if ($csvfile) {
        $data['draftareaid'] = file_get_submitted_draft_itemid('choose-backup');
    }
    $data['category'] = $category;
    echo $OUTPUT->header();
    echo $batchoutput->print_header($view, $category);
    echo $batchoutput->print_create_courses($data);
} else if ($view == 'delete_courses') {
    if ($data = batch_data_submitted()) {
        foreach ($data as $name => $value) {
            if (preg_match("/^course-/", $name)) {
                preg_match('/[^\d]*(\d+)$/', $name, $id);
                $params = array(
                    'shortname' => stripslashes($value),
                    'courseid'  => isset($id[1]) ? $id[1] : 0
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
} else if ($view == 'restart_courses') {
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
        $materials = !empty($data['materials']);

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
                        'materials'       => $materials,
                    );
                    preg_match('/[^\d]*(\d+)$/', $name, $id);
                    $courseid = isset($id[1]) ? $id[1] : 0;
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
} else if ($view == 'import_courses') {
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
            $context = context_user::instance($USER->id);
            if (!empty($data['choose-backup'])) {
                $params = array(
                    'startday'      => $startday,
                    'startmonth'    => $startmonth,
                    'startyear'     => $startyear,
                    'category'      => $categorydest,
                    'coursedisplay' => $coursedisplay
                );
                $context = context_coursecat::instance($categorydest);
                $files = optional_param_array('choose-backup', '', PARAM_PATH);
                foreach ($files as $file) {
                    $params['file'] = $file;
                    $job = batch_queue::add_job($USER->id, $categorydest, 'import_course', (object) $params);
                }
                redirect(new moodle_url('/local/batch/index.php', array('category' => $category)));
            }
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
    echo $batchoutput->print_import_courses($data);
} else if ($view == 'config_courses') {
    if ($data = batch_data_submitted()) {
        $errors = array();
        $courses = false;
        foreach ($data as $name => $value) {
            if (preg_match("/^course-(\d+)$/", $name, $match)) {
                $courseid = (int) $match[1];
                $data['prefix'] = preg_replace('/[\[\]]*/', '', $data['prefix']);
                if (!empty($data['remove_prefix'])) {
                    batch_course::change_prefix($courseid, false);
                } else if (trim($data['prefix'])) {
                    batch_course::change_prefix($courseid, $data['prefix']);
                }
                if ($data['suffix']) {
                    if (!batch_course::change_suffix($courseid, $data['suffix'])) {
                        $errors[] = $courseid;
                    }
                }
                if ($data['visible'] == 'yes') {
                    batch_course::show_course($courseid);
                } else if ($data['visible'] == 'no') {
                    batch_course::hide_course($courseid);
                }
                if (!empty($data['default_theme'])) {
                    batch_course::set_theme($courseid, '');
                } else if (!empty($data['theme'])) {
                    batch_course::set_theme($courseid, $data['theme']);
                }
                $courses = true;
            }
        }
        $url = new moodle_url('/local/batch/index.php', array('category' => $category, 'view' => 'config_courses'));
        if ($errors) {
            $message = html_writer::tag('p', get_string('config_courses_error', 'local_batch'));
            $message .= html_writer::start_tag('ul');
            foreach ($errors as $courseid) {
                $message .= html_writer::tag('li', $DB->get_field('course', 'fullname', array('id' => $courseid)));
            }
            $message .= html_writer::end_tag('ul');
        } else if ($courses) {
            $message = get_string('config_courses_ok', 'local_batch');
        } else {
            redirect($url);
        }
        echo $OUTPUT->header();
        echo $OUTPUT->box($message);
        echo $OUTPUT->continue_button($url);
    } else {
        echo $OUTPUT->header();
        echo $batchoutput->print_header($view, $category);
        $courses = batch_get_category_and_subcategories_info($category);
        $themes = core_component::get_plugin_list('theme');
        $themes = array_combine(array_keys($themes), array_keys($themes));
        echo $batchoutput->print_config_courses($courses, $themes);
    }
}
echo $OUTPUT->footer();
