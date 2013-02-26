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

class batch_type_delete_course extends batch_type_base {

    function execute($jobid, $categoryid, $params) {
        global $DB;
        $courseid = $DB->get_field('course', 'id', array('shortname' => $params->shortname));
        batch_course::delete_course($courseid);
    }

    function params_info($params, $jobid) {
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
