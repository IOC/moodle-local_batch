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

class batch_type_delete_course extends batch_type_base {

    public function execute($jobid, $categoryid, $params) {
        global $DB;
        $courseid = $DB->get_field('course', 'id', array('shortname' => $params->shortname));
        batch_course::delete_course($courseid);
    }

    public function params_info($params, $jobid) {
        global $PAGE;
        $user = batch_get_user($params->user);
        $batchoutput = $PAGE->get_renderer('local_batch');

        return $batchoutput->print_info_delete_courses(
            array(
                'courseid'  => $params->courseid,
                'shortname' => $params->shortname,
                'user'      => $user
            )
        );
    }

}
