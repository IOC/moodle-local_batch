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

include_once($CFG->libdir . '/form/filepicker.php');
include_once($CFG->libdir . '/form/filemanager.php');

class local_batch_renderer extends plugin_renderer_base {

    var $views = array('job_queue',
        'create_courses',
        'delete_courses',
        'restart_courses',
        'import_courses');

    function print_header($current_view, $category) {
        $tabrow = array();
        foreach ($this->views as $view) {
            $url = $this->url($view, array('category' => $category));
            $tabrow[] = new tabobject($view, $url->out(),
                                      get_string("view_$view", 'local_batch'));
        }
        return print_tabs(array($tabrow), $current_view, null, null, true);
    }

    function url($view=false, $params=array()) {
        if ($view) {
            $params['view'] = $view;
        }
        return new moodle_url('/local/batch/index.php', $params);
    }

    function print_filter_select($filter) {
        $options = array(
            batch_queue::FILTER_ALL => get_string('filter_all', 'local_batch'),
            batch_queue::FILTER_PENDING => get_string('filter_pending', 'local_batch'),
            batch_queue::FILTER_FINISHED => get_string('filter_finished', 'local_batch'),
            batch_queue::FILTER_ERRORS => get_string('filter_errors', 'local_batch'),
        );
        return html_writer::select($options, 'filter', $filter, '', array('id' => 'local_batch_filter'));
    }

    function print_table($jobs, $count, $page, $filter, $category) {
        $content = '';
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
            } elseif (!$job->timeended) {
                $state = get_string('state_executing', 'local_batch');
            } elseif ($job->error) {
                $state = get_string('state_error', 'local_batch', $job->error);
            } else {
                $seconds = $job->timeended - $job->timestarted;
                $duration = ($seconds > 60 ? round((float) $seconds / 60) . 'm'
                             : $seconds . 's');
                $state = get_string('state_finished', 'local_batch', $duration);
            }

            if ($job->timestarted == 0) {
                $url = $this->url(false, array('cancel_job' => $job->id,
                                                    'filter' => $filter,
                                                    'page' => $page,
                                                    'sesskey' => sesskey(),
                                                    'category' => $category));
                $action = html_writer::link($url, get_string('cancel', 'local_batch'), array('title' => get_string('cancel', 'local_batch')));
            } elseif ($job->timeended > 0 and $job->error) {
                $url = $this->url(false, array('retry_job' => $job->id,
                                                    'filter' => $filter,
                                                    'page' => $page,
                                                    'sesskey' => sesskey(),
                                                    'category' => $category));
                $action = html_writer::link($url, get_string('retry', 'local_batch'), array('title' => get_string('retry', 'local_batch')));
            }
            $table->data[] = array($timestarted, $strtype, $strparams, $state, $action);
        }
        if ($table->data) {
            $content .= html_writer::table($table);
        } else {
            $content .= $this->output->heading(get_string('nothingtodisplay'));
        }
        $url = $this->url('job_queue', array('filter' => $filter));
        $pagingbar = new paging_bar($count, $page, LOCAL_BATCH_PERPAGE, $url);
        $content .= $this->output->render($pagingbar);
        return $content;
    }

     function print_job_queue($jobs, $count, $page, $filter, $category) {
        $url = $this->url('job_queue')->out();
        $content = html_writer::start_tag('form', array('id' => 'queue-filter', 'action' => $url));
        $content .= $this->print_filter_select($filter);
        $content .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'category', 'value' => $category));
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

    function print_create_courses($info) {
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
            'type' => 'text',
            'name' => 'startdate',
            'value' => $info['startday'] . '/' . $info['startmonth'] . '/' . $info['startyear']
        );
        $content .= html_writer::empty_tag('input', $params);
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

    function print_info_create_courses($params) {
        $info = '';
        if (!is_null($params['attach'])) {
            $iconimage = $this->output->pix_icon(file_file_icon($params['attach']), get_mimetype_description($params['attach']), 
                                        'moodle', array('class' => 'icon'));
            $info .= html_writer::start_tag('div')
                . html_writer::tag('span',get_string('backup'), array('class' => 'batch_param'))
                . html_writer::link($params['fileurl'], $iconimage) . html_writer::link($params['fileurl'], $params['filename'])
                . html_writer::end_tag('div');
        }
        if (is_int($params['courseid'])) {
            $info .= html_writer::start_tag('div')
            . html_writer::tag('span',get_string('shortname'), array('class' => 'batch_param'))
            . html_writer::link(new moodle_url('/course/view.php', array('id' => $params['courseid'])), $params['shortname'])
            . html_writer::end_tag('div');
            $info .= html_writer::start_tag('div')
            . html_writer::tag('span',get_string('fullname'), array('class' => 'batch_param'))
            . html_writer::link(new moodle_url('/course/view.php', array('id' => $params['courseid'])), $params['fullname'])
            . html_writer::end_tag('div');
        } else {
            $info .= html_writer::start_tag('div')
            . html_writer::tag('span',get_string('shortname'), array('class' => 'batch_param'))
            . $params['shortname']
            . html_writer::end_tag('div');
            $info .= html_writer::start_tag('div')
            . html_writer::tag('span',get_string('fullname'), array('class' => 'batch_param'))
            . $params['fullname']
            . html_writer::end_tag('div');
        }
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('category'), array('class' => 'batch_param'))
            . html_writer::link($params['url'], $params['categoryname'])
            . html_writer::end_tag('div');
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('start_date', 'local_batch'), array('class' => 'batch_param'))
            . $params['startday'] . '/'. $params['startmonth'] . '/' . $params['startyear']
            . html_writer::end_tag('div');
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('creator', 'local_batch'), array('class' => 'batch_param'))
            . fullname($params['user'])
            . html_writer::end_tag('div');
        return $info;
    }

    function print_delete_courses($courses, $category) {
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
        $content .= $this->output->container_end();//close course-tree
        $content .= $this->output->container_end();//close section
        $params = array(
            'type' => 'submit',
            'name' => 'restart',
            'value' => get_string('add_jobs', 'local_batch')
        );
        $content .= $this->output->container_start('section');
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_end();//close section
        $content .= html_writer::end_tag('form');
        $content .= $this->output->container_end();//close batch_delete_courses

        return $content;
    }

    function print_info_delete_courses($params) {
        $info = html_writer::start_tag('div')
            . html_writer::tag('span', get_string('shortname'), array('class' => 'batch_param'))
            . html_writer::link(new moodle_url('/course/view.php', array('id' => $params['courseid'])), $params['shortname'])
            . html_writer::end_tag('div');
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('creator', 'local_batch'), array('class' => 'batch_param'))
            . fullname($params['user'])
            . html_writer::end_tag('div');
        return $info;
    }

    function print_restart_courses($courses, $info) {
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
        $content .= $this->output->container_end();//close course-tree
        $content .= $this->output->container_end();//close section
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
        $content .= $this->output->container_end();//close startdate
        $content .= $this->output->container_start('', 'category');
        $content .= html_writer::label(get_string('backup_category', 'local_batch'), 'category');
        $content .= $this->print_category_menu('categorydest', $info['category']);
        $content .= $this->output->container_end();//close category
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
            $content .= html_writer::label($role->name, 'role['.$role->id.']');
            $content .= html_writer::end_tag('li');
        }
        $content .= html_writer::end_tag('ul');
        $content .= $this->output->container_end();//close roles
        $content .= $this->output->container_start('groups');
        $content .= html_writer::label(get_string('groupsgroupings', 'group'), 'groups');
        $params = array(
            'id' => 'groups',
            'type' => 'checkbox',
            'name' => 'groups',
            'checked' => 'checked'
        );
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_end();//close groups
        $content .= $this->output->container_end();//close section

        $params = array(
            'type' => 'submit',
            'name' => 'restart',
            'value' => get_string('add_jobs', 'local_batch')
        );
        $content .= $this->output->container_start('section');
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_end();//close section
        $content .= html_writer::end_tag('form');
        $content .= $this->output->container_end();//close batch_restart_courses

        return $content;
    }

    function print_info_restart_courses($params) {
        $info = html_writer::start_tag('div')
            . html_writer::tag('span', get_string('course'), array('class' => 'batch_param'))
            . $params['shortname']
            . html_writer::end_tag('div');
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('start_date', 'local_batch'), array('class' => 'batch_param'))
            . $params['startday'] . '/'. $params['startmonth'] . '/' . $params['startyear']
            . html_writer::end_tag('div');
        if (is_int($params['courseid'])) {
            $info .= html_writer::start_tag('div')
                . html_writer::tag('span', get_string('course_reset', 'local_batch'), array('class' => 'batch_param'))
                . html_writer::link(new moodle_url('/course/view.php', array('id' => $params['courseid'])), $params['fullname'])
                . html_writer::end_tag('div');
        }
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('creator', 'local_batch'), array('class' => 'batch_param'))
            . fullname($params['user'])
            . html_writer::end_tag('div');
        return $info;
    }

    function print_import_courses($info) {
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
            'accepted_types' => '.zip'
        );
        $df = new MoodleQuickForm_filemanager('choose-backup', '', array('id' => 'choose-backup'), $options);
        if ($info['draftareaid']) {
            $df->setValue($info['draftareaid']);
        }
        $content .= $df->toHtml();
        $content .= $this->output->container_end('section');//close backup files section
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
        $content .= $this->output->container_end();//close startdate
        $content .= $this->output->container_start('', 'category');
        $content .= html_writer::label(get_string('backup_category', 'local_batch'), 'category');
        $content .= $this->print_category_menu('categorydest', $info['category']);
        $content .= $this->output->container_end();//close category
        $content .= $this->output->container_start();
        $content .= html_writer::label(get_string('course_display', 'local_batch'), 'coursedisplay');
        $params = array(
            'id' => 'coursedisplay',
            'type' => 'checkbox',
            'name' => 'coursedisplay'
        );
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_end();
        $content .= $this->output->container_end('section');//close parameters
        $params = array(
            'type' => 'submit',
            'name' => 'restart',
            'value' => get_string('add_jobs', 'local_batch')
        );
        $content .= $this->output->container_start('section');
        $content .= html_writer::empty_tag('input', $params);
        $content .= $this->output->container_end();//close section

        $content .= html_writer::end_tag('form');
        $content .= $this->output->container_end();//close batch_restore_courses

        return $content;
    }

    function print_info_import_courses($params) {
        $info = '';
        if (!is_null($params['attach'])) {
            $iconimage = $this->output->pix_icon(file_file_icon($params['attach']), get_mimetype_description($params['attach']), 
                                        'moodle', array('class' => 'icon'));
            $info .= html_writer::start_tag('div')
                . html_writer::tag('span',get_string('backup'), array('class' => 'batch_param'))
                . html_writer::link($params['fileurl'], $iconimage) . html_writer::link($params['fileurl'], $params['filename'])
                . html_writer::end_tag('div');
        }
        if (is_int($params['courseid'])) {
            $info .= html_writer::start_tag('div')
            . html_writer::tag('span',get_string('fullname'), array('class' => 'batch_param'))
            . html_writer::link(new moodle_url('/course/view.php', array('id' => $params['courseid'])), $params['fullname'])
            . html_writer::end_tag('div');
        }
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('category'), array('class' => 'batch_param'))
            . html_writer::link($params['url'], $params['categoryname'])
            . html_writer::end_tag('div');
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('start_date', 'local_batch'), array('class' => 'batch_param'))
            . $params['startday'] . '/'. $params['startmonth'] . '/' . $params['startyear']
            . html_writer::end_tag('div');
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span',get_string('course_display', 'local_batch'), array('class' => 'batch_param'))
            . ($params['coursedisplay']?get_string('yes'):get_string('no'))
            . html_writer::end_tag('div');
        $info .= html_writer::start_tag('div')
            . html_writer::tag('span', get_string('creator', 'local_batch'), array('class' => 'batch_param'))
            . fullname($params['user'])
            . html_writer::end_tag('div');
        return $info;
    }

    function strtime($time) {
        return $time ? strftime("%e %B, %R", $time) : '';
    }

    function print_category_menu($name="categorydest", $category=0, $selected=0) {
        $cat = batch_get_category($category);
        make_categories_list($categories, $parents, 'moodle/category:manage', 0, $cat);
        foreach ($categories as $key => $value) {
            if (array_key_exists($key,$parents)) {
                if ($indent = count($parents[$key])) {
                    for ($i = 0; $i < $indent; $i++) {
                        $categories[$key] = '&nbsp;'.$categories[$key];
                    }
                }
            }
        }
        return html_writer::select($categories, $name, $selected, array('' => ''), array('id' => $name));
    }

    function print_course_menu($structure) {
        // Generate an id
        $id = html_writer::random_id('course_category_tree');

        // Start content generation
        $content = html_writer::start_tag('div', array('class'=>'course_category_tree', 'id'=>$id));
        $content .= html_writer::start_tag('ul', array('class' => 'categories'));
        make_categories_list($categories, $parents, 'moodle/category:manage');
        foreach ($structure as $category) {
            $content .= $this->make_tree_categories($category, $categories);
        }
        $content .= html_writer::end_tag('ul');
        $content .= html_writer::start_tag('div', array('class'=>'controls'));
        $content .= html_writer::tag('div', get_string('collapseall'), array('class'=>'addtoall expandall'));
        $content .= html_writer::tag('div', get_string('expandall'), array('class'=>'removefromall collapseall'));
        $content .= html_writer::end_tag('div');
        $content .= html_writer::end_tag('div');

        // Return the course category tree HTML
        return $content;
    }

    protected function make_tree_categories($category, $categories) {
        if (!array_key_exists($category->id, $categories)){
            return;
        }
        $hassubcategories = (isset($category->categories) && count($category->categories)>0);
        $hascourses = (isset($category->courses) && count($category->courses)>0);
        $classes = array('category');
        if ($hassubcategories || $hascourses) {
            $classes[] = 'category_group';
        }
        $content = html_writer::start_tag('li', array('class' => join(' ', $classes)));
        $content .= $category->name;
        if ($hassubcategories) {
            $content .= html_writer::start_tag('ul', array('class' => 'subcategories'));
            foreach ($category->categories as $cat) {
                $content .= $this->make_tree_categories($cat, $categories);
            }
            $content .= html_writer::end_tag('ul');
        }
        if ($hascourses) {
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
