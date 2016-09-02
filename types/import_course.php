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

require_once('base.php');
require_once($CFG->dirroot . '/enrol/manual/lib.php');

class batch_type_import_course extends batch_type_base {

    public function execute($jobid, $categoryid, $params) {
        global $DB, $CFG;

        $context = context_coursecat::instance($categoryid);
        $slash = '/';
        if (preg_match('/\/$/', $CFG->local_batch_path_backups)) {
            $slash = '';
        }
        $filepath = $CFG->dataroot . '/' . $CFG->local_batch_path_backups . $slash . $params->file;
        $options = array('import' => true);
        $params->courseid = batch_course::restore_backup($filepath, $context, $params, $options);
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

    public function params_info($params, $jobid) {
        global $DB, $PAGE;

        $context = context_coursecat::instance($params->category);
        $categoryname = $DB->get_field('course_categories', 'name' , array('id' => $params->category));
        $user = batch_get_user($params->user);
        $url = new moodle_url('/course/index.php', array('categoryid' => $params->category));
        $batchoutput = $PAGE->get_renderer('local_batch');

        return $batchoutput->print_info_import_courses(
            array(
                'courseid'      => (isset($params->courseid) ? $params->courseid : ''),
                'categoryname'  => $categoryname,
                'coursedisplay' => $params->coursedisplay,
                'filename'      => basename($params->file),
                'fullname'      => (isset($params->fullname) ? $params->fullname : ''),
                'startday'      => $params->startday,
                'startmonth'    => $params->startmonth,
                'startyear'     => $params->startyear,
                'url'           => $url,
                'user'          => $user
            )
        );
    }
}
