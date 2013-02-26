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

class batch_type_restart_course extends batch_type_base {

    function execute($jobid, $categoryid, $params) {
        global $DB;

        if (!$course = $DB->get_record('course', array('shortname' => $params->shortname))) {
            throw new Exception('nonexistent');
        }

        if (time() - $course->startdate < 30 * 86400) {
            throw new Exception('started recently');
        }

        $old_shortname = $course->shortname . '~';
        $old_fullname = $course->fullname . strftime(' ~ %B %G');

        if ($old_course = $DB->get_record('course', array('shortname' => $old_shortname))) {
            throw new Exception("backup exists");
        }

        $file = batch_course::backup_course($course->id);

        batch_course::hide_course($course->id);
        batch_course::rename_course($course->id, $old_shortname, $old_fullname);
        $params->fullname = $course->fullname;
        $context = context_course::instance($course->id);
        $params->courseid = batch_course::restore_backup($file, $context, $params, $course->category);

        if (!empty($params->roleassignments)) {
            $roleids = explode(',', $params->roleassignments);
            foreach ($roleids as $roleid) {
                $users = get_role_users($roleid, $context);
                foreach ($users as $key => $user) {
                    batch_course::insert_role_assignment($params->courseid, $user->id, $roleid);
                }
            }
        }
        if (isset($params->groups) and $params->groups) {
            //Groups from source
            $groups = groups_get_all_groups($course->id);
            //Participants from destination
            $participants = batch_course::get_user_assignments_by_course($params->courseid);
            $destination_groups = $DB->get_records_menu('groups', array('courseid' => $params->courseid), '', 'id, name');
            foreach ($groups as $group) {
                if ($groupid = array_search($group->name, $destination_groups)) {
                    //Members group from source
                    $members = groups_get_members($group->id);
                    foreach ($members as $member) {
                        if (array_key_exists($member->id, $participants)) {
                            groups_add_member($groupid, $member->id);
                        }
                    }
                }
            }
        } else {
            groups_delete_groupings($params->courseid);
            groups_delete_groups($params->courseid);
        }

        if ($params->category) {
            move_courses(array($course->id), $params->category);
        } else {
            $params->category = $course->category;
        }
    }

    function params_info($params, $jobid) {
        global $PAGE;
        $user = batch_get_user($params->user);
        $batchoutput = $PAGE->get_renderer('local_batch');

        return $batchoutput->print_info_restart_courses(
            array(
                'courseid'   => (isset($params->courseid)?$params->courseid:''),
                'fullname'   => (isset($params->fullname)?$params->fullname:''),
                'shortname'  => $params->shortname,
                'startday'   => $params->startday,
                'startmonth' => $params->startmonth,
                'startyear'  => $params->startyear,
                'user'       => $user
            )
        );
    }
}
