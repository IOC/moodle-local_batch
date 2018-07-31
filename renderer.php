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

require_once($CFG->libdir . '/form/filepicker.php');
require_once($CFG->libdir . '/form/filemanager.php');

class local_batch_renderer extends plugin_renderer_base {

    private $views = array(
        'job_queue',
        'create_courses',
        'delete_courses',
        'restart_courses',
        'import_courses',
        'config_courses'
    );

    public function print_header($currentview, $category) {
        $tabrow = array();
        foreach ($this->views as $view) {
            $url = $this->url($view, array('category' => $category));
            $tabrow[] = new tabobject($view, $url->out(),
                                      get_string("view_$view", 'local_batch'));
        }
        return print_tabs(array($tabrow), $currentview, null, null, true);
    }

    public function url($view=false, $params=array()) {
        if ($view) {
            $params['view'] = $view;
        }
        return new moodle_url('/local/batch/index.php', $params);
    }

    public function print_filter_select($filter) {
        $options = array(
            batch_queue::FILTER_ALL => get_string('filter_all', 'local_batch'),
            batch_queue::FILTER_PENDING => get_string('filter_pending', 'local_batch'),
            batch_queue::FILTER_FINISHED => get_string('filter_finished', 'local_batch'),
            batch_queue::FILTER_ERRORS => get_string('filter_errors', 'local_batch'),
            batch_queue::FILTER_PRIORITIZED => get_string('filter_prioritized', 'local_batch'),
        );
        return html_writer::select($options, 'filter', $filter, '', array('id' => 'local_batch_filter'));
    }

    public function print_table($jobs, $count, $page, $filter, $category) {
        $content = html_writer::start_div('queue-jobs');
        $url = $this->url('job_queue', array('filter' => $filter,
                                                'category' => $category));
        $pagingbar = new paging_bar($count, $page, LOCAL_BATCH_PERPAGE, $url);
        $content .= $this->output->render($pagingbar);
        $table = new html_table();
        $table->id = 'queue-table';
        $table->attributes = array('class' => 'generaltable');
        $table->head = array('timestarted' => get_string('column_timestarted', 'local_batch'),
                         'type' => get_string('column_type', 'local_batch'),
                         'params' => get_string('column_params', 'local_batch'),
                         'state' => get_string('column_state', 'local_batch'),
                         'actions' => get_string('column_action', 'local_batch'));

        foreach ($jobs as $job) {
            $action = '';
            $strtype = get_string('type_' . $job->type, 'local_batch');
            $type = batch_type($job->type);
            $job->params->user = $job->user;
            $strparams = $type->params_info($job->params, $job->id);

            $timestarted = $this->strtime($job->timestarted);

            if (!$job->timestarted) {
                $state = get_string('state_waiting', 'local_batch');
            } else if (!$job->timeended) {
                $state = get_string('state_executing', 'local_batch');
            } else if ($job->error) {
                if (strlen($job->error) < 30) {
                    $state = get_string('state_error', 'local_batch', $job->error);
                } else {
                    $state = html_writer::start_div('batch_error');
                    $state .= html_writer::tag('span', '', array('class' => 'batch_error_switcher'));
                    $state .= html_writer::start_tag('span', array('class' => 'batch_error_message'));
                    $state .= get_string('state_error', 'local_batch', $job->error);
                    $state .= html_writer::end_tag('span');
                    $state .= html_writer::end_div();
                }
            } else {
                $seconds = $job->timeended - $job->timestarted;
                $duration = ($seconds > 60 ? round((float) $seconds / 60) . 'm' : $seconds . 's');
                $state = get_string('state_finished', 'local_batch', $duration);
            }

            $row = new html_table_row();
            if ($job->timestarted == 0) {
                $url = $this->url(false, array('cancel_job' => $job->id,
                                                    'filter' => $filter,
                                                    'page' => $page,
                                                    'sesskey' => sesskey(),
                                                    'category' => $category));
                $action = html_writer::link($url, get_string('cancel', 'local_batch'), array('title' => get_string('cancel', 'local_batch')));
                if (has_capability('moodle/site:config', context_system::instance())) {
                    $customaction = 'prioritize';
                    if ($job->priority) {
                        $customaction = 'desprioritize';
                    }
                    $url = $this->url(false, array($customaction . '_job' => $job->id,
                                                    'filter' => $filter,
                                                    'page' => $page,
                                                    'sesskey' => sesskey(),
                                                    'category' => $category));
                    $action .= html_writer::link($url, get_string($customaction, 'local_batch'), array('title' => get_string($customaction, 'local_batch')));
                }
                if ($job->priority) {
                    $row->attributes = array('class' => 'priority');
                }
            } else if ($job->timeended > 0 and $job->error) {
                $url = $this->url(false, array('retry_job' => $job->id,
                                                    'filter' => $filter,
                                                    'page' => $page,
                                                    'sesskey' => sesskey(),
                                                    'category' => $category));
                $action = html_writer::link($url, get_string('retry', 'local_batch'), array('title' => get_string('retry', 'local_batch')));
                $row->attributes = array('class' => 'ko');
            } else {
                $row->attributes = array('class' => 'ok');
            }
            $row->cells = array($timestarted, $strtype, $strparams, $state, $action);
            $table->data[] = $row;
        }
        if ($table->data) {
            $content .= html_writer::table($table);
        } else {
            $content .= $this->output->heading(get_string('nothingtodisplay'));
        }
        $url = $this->url('job_queue', array('filter' => $filter,
                                                'category' => $category));
        $pagingbar = new paging_bar($count, $page, LOCAL_BATCH_PERPAGE, $url);
        $content .= $this->output->render($pagingbar);
        $content .= html_writer::end_div();
        return $content;
    }

    public function print_job_queue($jobs, $count, $page, $filter, $category, $totalpending, $mypending) {
        $url = $this->url('job_queue')->out();
        $content = html_writer::start_tag('form', array('id' => 'queue-filter', 'action' => $url));
        $content .= $this->print_filter_select($filter);
        $content .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'category', 'value' => $category));
        if ($totalpending) {
            $content .= html_writer::start_tag('span', array('class' => 'batch_pending alert alert-info'));
            $content .= get_string('total_pending', 'local_batch', $totalpending) . ' ';
            if ($mypending) {
                $content .= get_string('my_pending', 'local_batch', $mypending);
            } else {
                $content .= get_string('no_my_pending', 'local_batch');
            }
            $content .= html_writer::end_tag('span');
        }
        $starthour = isset($CFG->local_batch_start_hour) ? (int) $CFG->local_batch_start_hour : 0;
        $stophour = isset($CFG->local_batch_stop_hour) ? (int) $CFG->local_batch_stop_hour : 0;
        if ($starthour != $stophour) {
            $content .= html_writer::tag('span', get_string('start_hour', 'local_batch', $starthour), array('class' => 'batch_starthour alert alert-info'));
        }
        $content .= html_writer::start_tag('noscript');
        $content .= html_writer::empty_tag('input', array(
                'type' => 'submit',
                'value' => get_string('show'),
        ));
        $content .= html_writer::end_tag('noscript');
        $content .= html_writer::end_tag('form');
        $content .= $this->print_table($jobs, $count, $page, $filter, $category);
        return $content;
    }

    public function print_create_courses($info) {
        global $SITE;

        $content = $this->output->container_start('batch_create_courses');
        $content .= html_writer::start_tag('form', array('id' => 'form', 'method' => 'post'));
        $params = array(
            'id' => 'sesskey',
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey()
        );
        $content .= html_writer::empty_tag('input', $params);
        $params = array(
            'id' => 'category',
            'type' => 'hidden',
            'name' => 'category',
            'value' => $info['category']
        );
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_start('section');
        $content .= $this->output->heading(get_string('backup'), 3);
        $params = array(
            'type' => 'hidden',
            'name' => 'course',
            'value' => $SITE->id
        );
        $content .= html_writer::empty_tag('input', $params);
        $options = array(
            'accepted_types' => '.mbz',
            'maxfiles' => 1
        );
        $df = new MoodleQuickForm_filemanager('choose-backup', '', array('id' => 'choose-backup'), $options);
        if (isset($info['draftareaid'])) {
            $df->setValue($info['draftareaid']);
        }
        $content .= $df->toHtml();
        $content .= $this->output->container_end('section');
        $content .= $this->output->container_start('section');
        $content .= $this->output->heading(get_string('courses'), 3);
        $params = array(
            'type' => 'hidden',
            'name' => 'lastindex',
            'value' => $info['lastindex']
        );
        $content .= html_writer::empty_tag('input', $params);
        $table = new html_table();
        $table->id = 'course-list';
        $table->head = array(
            get_string('shortname'),
            get_string('fullname'),
            get_string('category'),
            get_string('action')
        );
        foreach ($info['courses'] as $i => $course) {
            $params = array(
                'type' => 'text',
                'size' => '16',
                'name' => 'shortname-' . $i,
                'value' => s($course->shortname)
            );
            $cella = html_writer::empty_tag('input', $params);
            $params = array(
                'type' => 'text',
                'size' => '48',
                'name' => 'fullname-' . $i,
                'value' => s($course->fullname)
            );
            $cellb = html_writer::empty_tag('input', $params);
            $cellc = $this->print_category_menu('category-' . $i, $info['category'], $course->category);
            $celld = html_writer::link('#', get_string('delete'), array('class' => 'js-only delete-course'));
            $table->data[] = array($cella, $cellb, $cellc, $celld);
        }
        $content .= html_writer::table($table);

        $content .= $this->output->container_start('actions js-only');
        $content .= html_writer::link('#', get_string('add'), array('id' => 'add-course'));
        $content .= $this->output->container_end('actions js-only');

        $content .= $this->output->container_start('actions js-only');
        $content .= $this->output->heading(get_string('import_from_csv_file', 'local_batch'), 4);

        $df = new MoodleQuickForm_filepicker('csvfile', '', array('id' => 'import-csv-file'), array('accepted_types' => '.csv'));
        $content .= $df->toHtml();

        $content .= $this->output->container_end('actions js-only');
        $content .= $this->output->container_end('section');
        $content .= $this->output->container_start('section', 'calendar-panel');
        $content .= $this->output->heading(get_string('start_date', 'local_batch'), 3);
        $params = array(
            'id' => 'startdate',
            'type' => 'text',
            'name' => 'startdate',
            'value' => $info['startday'] . '/' . $info['startmonth'] . '/' . $info['startyear']
        );
        $content .= html_writer::empty_tag('input', $params);
        $url = $this->output->image_url('i/calendar', 'core');
        $datepicker = html_writer::empty_tag('img', array('id' => 'batch_toggle_datepicker', 'class' => 'batch_toggle_datepicker', 'src' => $url, 'alt' => 'calendar'));
        $content .= html_writer::link('#', $datepicker);
        $content .= $this->output->container_end('section');
        $params = array(
            'type' => 'submit',
            'name' => 'restart',
            'value' => get_string('add_jobs', 'local_batch')
        );
        $content .= $this->output->container_start('section');
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_end('section');
        $content .= html_writer::end_tag('form');
        $content .= $this->output->container_end('batch_create_courses');

        return $content;
    }

    public function print_info_create_courses($params) {
        $info = '';
        if (!is_null($params['attach'])) {
            $iconimage = $this->output->pix_icon(file_file_icon($params['attach']), get_mimetype_description($params['attach']),
                                        'moodle', array('class' => 'icon'));
            $info .= html_writer::start_tag('div')
                . html_writer::tag('span', get_string('backup'), array('class' => 'batch_param'))
                . html_writer::start_tag('span', array('class' => 'batch_value'))
                . html_writer::link($params['fileurl'], $iconimage) . html_writer::link($params['fileurl'], $params['filename'])
                . html_writer::end_tag('span')
                . html_writer::end_tag('div');
        }
        if (is_int($params['courseid'])) {
            $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('shortname'), array('class' => 'batch_param'))
            . html_writer::link(new moodle_url('/course/view.php', array('id' => $params['courseid'])), $params['shortname'], array('class' => 'batch_value'))
            . html_writer::end_tag('div');
            $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('fullname'), array('class' => 'batch_param'))
            . html_writer::link(new moodle_url('/course/view.php', array('id' => $params['courseid'])), $params['fullname'], array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        } else {
            $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('shortname'), array('class' => 'batch_param'))
            . html_writer::tag('span', $params['shortname'], array('class' => 'batch_value'))
            . html_writer::end_tag('div');
            $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('fullname'), array('class' => 'batch_param'))
            . html_writer::tag('span', $params['fullname'], array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        }
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('category'), array('class' => 'batch_param'))
            . html_writer::link($params['url'], $params['categoryname'])
            . html_writer::end_tag('div');
        $value = $params['startday'] . '/'. $params['startmonth'] . '/' . $params['startyear'];
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('start_date', 'local_batch'), array('class' => 'batch_param'))
            . html_writer::tag('span', $value, array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('creator', 'local_batch'), array('class' => 'batch_param'))
            . html_writer::tag('span', fullname($params['user']), array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        return $info;
    }

    public function print_delete_courses($courses, $category) {
        $content = $this->output->container_start('batch_delete_courses');
        $content .= html_writer::start_tag('form', array('id' => 'form', 'method' => 'post'));
        $params = array(
            'id' => 'sesskey',
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey()
        );
        $content .= html_writer::empty_tag('input', $params);
        $params = array(
            'id' => 'category',
            'type' => 'hidden',
            'name' => 'category',
            'value' => $category
        );
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_start('section');
        $content .= $this->output->heading(get_string('courses'), 3);
        $content .= $this->output->container_start('', 'course-tree');
        $content .= $this->print_course_menu($courses);
        $content .= $this->output->container_end();// close course-tree
        $content .= $this->output->container_end();// close section
        $params = array(
            'type' => 'submit',
            'name' => 'restart',
            'value' => get_string('add_jobs', 'local_batch')
        );
        $content .= $this->output->container_start('section');
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_end();// close section
        $content .= html_writer::end_tag('form');
        $content .= $this->output->container_end();// close batch_delete_courses

        return $content;
    }

    public function print_info_delete_courses($params) {
        $info = html_writer::start_tag('div')
            . html_writer::tag('span', get_string('shortname'), array('class' => 'batch_param'))
            . html_writer::link(new moodle_url('/course/view.php', array('id' => $params['courseid'])), $params['shortname'], array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('creator', 'local_batch'), array('class' => 'batch_param'))
            . html_writer::tag('span', fullname($params['user']), array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        return $info;
    }

    public function print_restart_courses($courses, $info) {
        $content = $this->output->container_start('batch_restart_courses');
        $content .= html_writer::start_tag('form', array('id' => 'form', 'method' => 'post'));
        $params = array(
            'id' => 'sesskey',
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey()
        );
        $content .= html_writer::empty_tag('input', $params);
        $params = array(
            'id' => 'category',
            'type' => 'hidden',
            'name' => 'category',
            'value' => $info['category']
        );
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_start('section');
        $content .= $this->output->heading(get_string('courses'), 3);
        $content .= $this->output->container_start('', 'course-tree');
        $content .= $this->print_course_menu($courses);
        $content .= $this->output->container_end();// close course-tree
        $content .= $this->output->container_end();// close section
        $content .= $this->output->container_start('section');
        $content .= $this->output->heading(get_string('parameters', 'local_batch'), 3);
        $content .= $this->output->container_start('', 'calendar-panel');
        $content .= html_writer::label(get_string('start_date', 'local_batch'), 'startdate');
        $params = array(
            'id' => 'startdate',
            'type' => 'text',
            'name' => 'startdate',
            'size' => '10',
            'value' => $info['startday'] . '/' . $info['startmonth'] . '/' . $info['startyear']
        );
        $content .= html_writer::empty_tag('input', $params);
        $url = $this->output->image_url('i/calendar', 'core');
        $datepicker = html_writer::empty_tag('img', array('id' => 'batch_toggle_datepicker', 'class' => 'batch_toggle_datepicker', 'src' => $url, 'alt' => 'calendar'));
        $content .= html_writer::link('#', $datepicker);
        $content .= $this->output->container_end();// close startdate
        $content .= $this->output->container_start('batch_category', 'category');
        $content .= html_writer::label(get_string('backup_category', 'local_batch'), 'category');
        $content .= $this->print_category_menu('categorydest', $info['category']);
        $content .= $this->output->container_end();// close category
        $content .= $this->output->container_start('roles');
        $content .= html_writer::label(get_string('roleassignments', 'role'), 'roleassignments');
        $context = context_system::instance();
        $roles = role_get_names($context);
        $content .= html_writer::start_tag('ul', array('class' => 'batch_assign_roles'));
        foreach ($roles as $key => $role) {
            $content .= html_writer::start_tag('li');
            $content .= html_writer::empty_tag('input', array(
                                    'id' => 'role['.$role->id.']',
                                    'type' => 'checkbox',
                                    'name' => 'role[]',
                                    'value' => $role->id
                                    )
                        );
            $content .= html_writer::label($role->localname, 'role['.$role->id.']');
            $content .= html_writer::end_tag('li');
        }
        $content .= html_writer::end_tag('ul');
        $content .= $this->output->container_end();// close roles
        $content .= $this->output->container_start('groups');
        $content .= html_writer::label(get_string('groupsgroupings', 'group'), 'groups');
        $params = array(
            'id' => 'groups',
            'type' => 'checkbox',
            'name' => 'groups',
            'checked' => 'checked'
        );
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_end();// close groups
        $content .= $this->output->container_start('materials');
        $content .= html_writer::label(get_string('materials', 'local_batch'), 'materials');
        $params = array(
            'id' => 'materials',
            'type' => 'checkbox',
            'name' => 'materials',
        );
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_end();// close materials configuration
        $content .= $this->output->container_end();// close section

        $params = array(
            'type' => 'submit',
            'name' => 'restart',
            'value' => get_string('add_jobs', 'local_batch')
        );
        $content .= $this->output->container_start('section');
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_end();// close section
        $content .= html_writer::end_tag('form');
        $content .= $this->output->container_end();// close batch_restart_courses

        return $content;
    }

    public function print_info_restart_courses($params) {
        $info = html_writer::start_tag('div')
            . html_writer::tag('span', get_string('course'), array('class' => 'batch_param'))
            . html_writer::tag('span', $params['shortname'], array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        $value = $params['startday'] . '/'. $params['startmonth'] . '/' . $params['startyear'];
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('start_date', 'local_batch'), array('class' => 'batch_param'))
            . html_writer::tag('span', $value, array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        if (is_int($params['courseid'])) {
            $info .= html_writer::start_tag('div')
                . html_writer::tag('span', get_string('course_reset', 'local_batch'), array('class' => 'batch_param'))
                . html_writer::link(new moodle_url('/course/view.php', array('id' => $params['courseid'])), $params['fullname'])
                . html_writer::end_tag('div');
        }
        $value = ($params['roleassignments'] ? $params['roleassignments'] : get_string('no'));
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('roles'), array('class' => 'batch_param'))
            . html_writer::tag('span', $value, array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        $value = ($params['groups'] ? get_string('yes') : get_string('no'));
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('groups'), array('class' => 'batch_param'))
            . html_writer::tag('span', $value, array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        $value = ($params['materials'] ? get_string('yes') : get_string('no'));
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('materials_short', 'local_batch'), array('class' => 'batch_param'))
            . html_writer::tag('span', $value, array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        $value = fullname($params['user']);
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('creator', 'local_batch'), array('class' => 'batch_param'))
            . html_writer::tag('span', $value, array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        return $info;
    }

    public function print_import_courses($info) {
        global $CFG, $SITE;

        $content = $this->output->container_start('batch_create_courses');
        $content .= html_writer::start_tag('form', array('id' => 'form', 'method' => 'post'));
        $params = array(
            'id' => 'sesskey',
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey()
        );
        $content .= html_writer::empty_tag('input', $params);
        $params = array(
            'id' => 'category',
            'type' => 'hidden',
            'name' => 'category',
            'value' => $info['category']
        );
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_start('section');
        $content .= $this->output->heading(get_string('backup'), 3);
        $params = array(
            'type' => 'hidden',
            'name' => 'course',
            'value' => $SITE->id
        );
        $content .= html_writer::empty_tag('input', $params);
        if (!empty($CFG->local_batch_path_backups)) {
            $files = get_directory_list($CFG->dataroot . '/' . $CFG->local_batch_path_backups);

            $filter = function($value) {
                return preg_match('/\.zip$/', $value);
            };

            $files = array_filter($files, $filter);

            $content .= html_writer::start_tag('ul', array('class' => 'batch_assign_roles'));
            foreach ($files as $key => $file) {
                $params = array(
                    'id' => 'choose-backup[' . $key .']',
                    'name' => 'choose-backup[]',
                    'type' => 'checkbox',
                    'value' => $file
                );
                $content .= html_writer::start_tag('li');
                $content .= html_writer::empty_tag('input', $params);
                $content .= html_writer::label(basename($file), 'choose-backup[' . $key . ']');
                $content .= html_writer::end_tag('li');
            }
            $content .= html_writer::end_tag('ul');
        } else {
            $content .= html_writer::tag('div', get_string('nobackupfolder', 'local_batch'));
        }

        $content .= $this->output->container_end('section');// close backup files section
        $content .= $this->output->container_start('section');
        $content .= $this->output->heading(get_string('parameters', 'local_batch'), 3);
        $content .= $this->output->container_start('', 'calendar-panel');
        $content .= html_writer::label(get_string('start_date', 'local_batch'), 'startdate');
        $params = array(
            'id' => 'startdate',
            'type' => 'text',
            'name' => 'startdate',
            'size' => '10',
            'value' => $info['startday'] . '/' . $info['startmonth'] . '/' . $info['startyear']
        );
        $content .= html_writer::empty_tag('input', $params);
        $url = $this->output->image_url('i/calendar', 'core');
        $datepicker = html_writer::empty_tag('img', array('id' => 'batch_toggle_datepicker', 'class' => 'batch_toggle_datepicker', 'src' => $url, 'alt' => 'calendar'));
        $content .= html_writer::link('#', $datepicker);
        $content .= $this->output->container_end();// close startdate
        $content .= $this->output->container_start('batch_category', 'category');
        $content .= html_writer::label(get_string('backup_category', 'local_batch'), 'category');
        $content .= $this->print_category_menu('categorydest', $info['category']);
        $content .= $this->output->container_end();// close category
        $content .= $this->output->container_start();
        $content .= html_writer::label(get_string('course_display', 'local_batch'), 'coursedisplay');
        $params = array(
            'id' => 'coursedisplay',
            'type' => 'checkbox',
            'name' => 'coursedisplay'
        );
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_end();
        $content .= $this->output->container_end('section');// close parameters
        $params = array(
            'type' => 'submit',
            'name' => 'restart',
            'value' => get_string('add_jobs', 'local_batch')
        );
        $content .= $this->output->container_start('section');
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_end();// close section

        $content .= html_writer::end_tag('form');
        $content .= $this->output->container_end();// close batch_restore_courses

        return $content;
    }

    public function print_info_import_courses($params) {
        $info = '';
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('backup'), array('class' => 'batch_param'))
            . html_writer::tag('span', $params['filename'], array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        if (is_int($params['courseid'])) {
            $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('fullname'), array('class' => 'batch_param'))
            . html_writer::link(new moodle_url('/course/view.php', array('id' => $params['courseid'])), $params['fullname'], array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        }
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('category'), array('class' => 'batch_param'))
            . html_writer::link($params['url'], $params['categoryname'], array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        $value = $params['startday'] . '/'. $params['startmonth'] . '/' . $params['startyear'];
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('start_date', 'local_batch'), array('class' => 'batch_param'))
            . html_writer::tag('span', $value, array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        $value = ($params['coursedisplay'] ? get_string('yes') : get_string('no'));
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('course_display', 'local_batch'), array('class' => 'batch_param'))
            . html_writer::tag('span', $value, array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('creator', 'local_batch'), array('class' => 'batch_param'))
            . html_writer::tag('span', fullname($params['user']), array('class' => 'batch_value'))
            . html_writer::end_tag('div');
        return $info;
    }

    public function print_config_courses($courses, $themes) {
        global $CFG;
        $content = $this->output->container_start('batch_config_courses');
        $content .= html_writer::start_tag('form', array('id' => 'form', 'method' => 'post'));
        $params = array(
            'id' => 'sesskey',
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey()
        );
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_start('section');
        $content .= $this->output->heading(get_string('courses'), 3);
        $content .= $this->output->container_start('', 'course-tree');
        $content .= $this->print_course_menu($courses);
        $content .= $this->output->container_end();// close course-tree
        $content .= $this->output->container_end();// close section
        $content .= $this->output->container_start('section');
        $content .= $this->output->heading(get_string('parameters', 'local_batch'), 3);
        $content .= $this->output->container_start('course-prefix');
        $content .= html_writer::label(get_string('prefix', 'local_batch'), 'prefix');
        $params = array(
            'id'   => 'prefix',
            'type' => 'text',
            'name' => 'prefix'
        );
        $content .= html_writer::tag('span', '[', array('class' => 'batch_delimiter'));
        $content .= html_writer::empty_tag('input', $params);
        $content .= html_writer::tag('span', ']', array('class' => 'batch_delimiter'));
        $params = array(
            'id'   => 'remove_prefix',
            'type' => 'checkbox',
            'name' => 'remove_prefix'
        );
        $content .= html_writer::empty_tag('input', $params);
        $content .= html_writer::label(get_string('remove', 'local_batch'), 'remove_prefix');
        $content .= $this->output->container_end();// close course-prefix
        $content .= $this->output->container_start('course-suffix');
        $content .= html_writer::label(get_string('suffix', 'local_batch'), 'suffix');
        $options = array(
            'none'      => get_string('suffix_none', 'local_batch'),
            'restarted' => get_string('suffix_restarted', 'local_batch'),
            'imported'  => get_string('suffix_imported', 'local_batch')
        );
        $content .= html_writer::select($options, 'suffix', '', array('' => ''), array('id' => 'suffix'));
        $content .= $this->output->container_end();// close course-suffix
        $content .= $this->output->container_start('course-visible');
        $content .= html_writer::label(get_string('visible'), 'visible');
        $options = array(
            'yes' => get_string('yes'),
            'no'  => get_string('no')
        );
        $content .= html_writer::select($options, 'visible', '', array('' => ''), array('id' => 'visible'));
        $content .= $this->output->container_end();// close course-visible
        if (!empty($CFG->allowcoursethemes)) {
            $content .= $this->output->container_start('course-theme');
            $content .= html_writer::label(get_string('theme'), 'visible');
            $content .= html_writer::select($themes, 'theme', '', array('' => ''), array('id' => 'theme', 'class' => 'batch_theme'));
            $params = array(
                'id'   => 'default_theme',
                'type' => 'checkbox',
                'name' => 'default_theme'
            );
            $content .= html_writer::empty_tag('input', $params);
            $content .= html_writer::label(get_string('default_theme', 'local_batch'), 'default_theme');
            $content .= $this->output->container_end();// close course-theme
        }
        $content .= $this->output->container_start('section');
        $params = array(
            'type' => 'submit',
            'name' => 'config',
            'value' => get_string('configure', 'local_batch')
        );
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_end();// close section
        $content .= $this->output->container_end();// close parameters
        $content .= html_writer::end_tag('form');
        $content .= $this->output->container_end();// close section
        return $content;
    }

    public function strtime($time) {
        return $time ? strftime("%e %B, %R", $time) : '';
    }

    public function print_category_menu($name="categorydest", $category=0, $selected=0) {
        $cat = batch_get_category($category);
        $categories = coursecat::make_categories_list('moodle/category:manage', $cat);
        foreach ($categories as $key => $value) {
            if ($aux = coursecat::get($key)->get_parents()) {
                if ($indent = count($aux)) {
                    $categories[$key] = str_repeat('&nbsp;', $indent) . $categories[$key];
                }
            }
        }
        return html_writer::select($categories, $name, $selected, array('' => ''), array('id' => $name));
    }

    public function print_course_menu($structure) {
        // Generate an id
        $id = html_writer::random_id('course_category_tree');

        // Start content generation
        $content = html_writer::start_tag('div', array('class' => 'course_category_tree', 'id' => $id));
        $content .= html_writer::start_tag('ul', array('class' => 'categories'));
        $categories = coursecat::make_categories_list('moodle/category:manage');
        foreach ($structure as $category) {
            $content .= $this->make_tree_categories($category, $categories);
        }
        $content .= html_writer::end_tag('ul');
        $content .= html_writer::start_tag('div', array('class' => 'controls'));
        $content .= html_writer::tag('div', get_string('collapseall'), array('class' => 'addtoall expandall'));
        $content .= html_writer::tag('div', get_string('expandall'), array('class' => 'removefromall collapseall'));
        $content .= html_writer::end_tag('div');
        $content .= html_writer::end_tag('div');

        // Return the course category tree HTML
        return $content;
    }

    protected function make_tree_categories($category, $categories) {
        if (!array_key_exists($category->id, $categories)) {
            return;
        }
        $hassubcategories = (isset($category->categories) && count($category->categories) > 0);
        $hascourses = (isset($category->courses) && count($category->courses) > 0);
        $classes = array('category');
        if ($hassubcategories || $hascourses) {
            $classes[] = 'category_group';
        }
        $content = html_writer::start_tag('li', array('class' => join(' ', $classes)));
        $content .= html_writer::tag('span', $category->name);
        if ($hassubcategories) {
            $content .= html_writer::start_tag('ul', array('class' => 'subcategories'));
            foreach ($category->categories as $cat) {
                $content .= $this->make_tree_categories($cat, $categories);
            }
            $content .= html_writer::end_tag('ul');
        }
        if ($hascourses) {
            $url = $this->output->image_url('t/unblock', 'core');
            $params = array (
                'id' => 'batch_toggle_category_' . $category->id,
                'class' => 'batch_toggle_category batch_hidden_toggle',
                'src' => $url, 'alt' => 'toggle',
            );
            $content .= html_writer::empty_tag('img', $params);
            $content .= html_writer::start_tag('ul', array('class' => 'courses'));
            foreach ($category->courses as $course) {
                $content .= html_writer::start_tag('li', array('class' => 'course'));
                $params = array(
                    'id' => 'course-' . $course->id,
                    'name' => 'course-' . $course->id,
                    'type' => 'checkbox',
                    'value' => $course->shortname
                );
                $content .= html_writer::empty_tag('input', $params);
                $content .= html_writer::label($course->fullname, 'course-' . $course->id);
                $content .= html_writer::end_tag('li');
            }
            $content .= html_writer::end_tag('ul');
        }
        $content .= html_writer::end_tag('li');
        return $content;
    }
}
