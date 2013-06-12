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

require_once('base.php');
require_once $CFG->dirroot . '/enrol/manual/lib.php';

class batch_type_import_course extends batch_type_base {

    function execute($jobid, $categoryid, $params) {
        global $DB, $CFG;

        $context = context_coursecat::instance($categoryid);
        $slash = '/';
        if (preg_match('/\/$/', $CFG->local_batch_path_backups)) {
            $slash = '';
        }
        $filepath = $CFG->dataroot . '/' . $CFG->local_batch_path_backups . $slash . $params->file;
        $params->courseid = batch_course::restore_backup($filepath, $context, $params, null, true);
        batch_course::assignmentupgrade($params->courseid);
        $enrol = new enrol_manual_plugin();
        $enrol->add_instance((object) array('id' => $params->courseid));
        if ($params->coursedisplay) {
            $conditions = array(
                'courseid' => $params->courseid,
                'name'     => 'coursedisplay',
            );
            $DB->set_field('course_format_options', 'value', 1, $conditions);
        }
    }

    function params_info($params, $jobid) {
        global $DB, $PAGE;

        $context = context_coursecat::instance($params->category);
        $categoryname = $DB->get_field('course_categories', 'name' , array('id' => $params->category));
        $user = batch_get_user($params->user);
        $url = new moodle_url('/course/category.php', array('id' => $params->category));
        $batchoutput = $PAGE->get_renderer('local_batch');

        return $batchoutput->print_info_import_courses(
            array(
                'courseid'      => (isset($params->courseid)?$params->courseid:''),
                'categoryname'  => $categoryname,
                'coursedisplay' => $params->coursedisplay,
                'filename'      => basename($params->file),
                'fullname'      => (isset($params->fullname)?$params->fullname:''),
                'startday'      => $params->startday,
                'startmonth'    => $params->startmonth,
                'startyear'     => $params->startyear,
                'url'           => $url,
                'user'          => $user
            )
        );
    }
}
