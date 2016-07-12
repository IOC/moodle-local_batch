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

class batch_type_restart_course extends batch_type_base {

    public function execute($jobid, $categoryid, $params) {
        global $DB;

        @set_time_limit(0);
        if (!$course = $DB->get_record('course', array('shortname' => $params->shortname))) {
            throw new moodle_exception('error:coursenotexist', 'local_batch', '', $params->shortname);
        }

        if (time() - $course->startdate < 30 * 86400) {
            throw new moodle_exception('error:courserestartedrecently', 'local_batch');
        }

        $oldshortname = $course->shortname . '~';
        $oldfullname = $course->fullname . strftime(' ~ %B %G');

        if ($oldcourse = $DB->get_record('course', array('shortname' => $oldshortname))) {
            throw new moodle_exception('error:oldcourseexists', 'local_batch');
        }

        list($file, $backupid) = batch_course::backup_course($course->id);

        batch_course::hide_course($course->id);
        batch_course::rename_course($course->id, $oldshortname, $oldfullname);
        $params->fullname = $course->fullname;
        $context = context_course::instance($course->id);// old course
        $options = array(
            'category' => $course->category,
            'mode' => backup::MODE_SAMESITE,
            'restart' => true,
            'backupid' => $backupid,
        );
        $params->courseid = batch_course::restore_backup($file, $context, $params, $options);
        $context = context_course::instance($params->courseid);// new course

        if (!empty($params->roleassignments)) {
            $participants = batch_course::get_user_assignments_by_course($params->courseid);
            $roleids = explode(',', $params->roleassignments);
            $listusers = array();
            foreach ($roleids as $roleid) {
                $users = get_role_users($roleid, $context);
                foreach ($users as $user) {
                    $listusers[$user->id] = true;
                }
            }
            $plugin = enrol_get_plugin('manual');
            $conditions = array('enrol' => 'manual', 'courseid' => $params->courseid);
            $enrol = $DB->get_record('enrol', $conditions, '*', MUST_EXIST);
            $userstodelete = array_diff_key($participants, $listusers);
            foreach ($userstodelete as $user) {
                $plugin->unenrol_user($enrol, $user->id);
            }
        } else {
            $plugin = enrol_get_plugin('manual');
            $conditions = array('enrol' => 'manual', 'courseid' => $params->courseid);
            $enrol = $DB->get_record('enrol', $conditions, '*', MUST_EXIST);
            $participants = batch_course::get_user_assignments_by_course($params->courseid);
            foreach ($participants as $user) {
                $plugin->unenrol_user($enrol, $user->id);
            }
        }
        if (isset($params->groups) and $params->groups) {
            // Groups from source
            $groups = groups_get_all_groups($course->id);
            // Participants from destination
            if (!isset($participants) or empty($participants)) {
                $participants = batch_course::get_user_assignments_by_course($params->courseid);
            }
            $destinationgroups = $DB->get_records_menu('groups', array('courseid' => $params->courseid), '', 'id, name');
            foreach ($groups as $group) {
                if ($groupid = array_search($group->name, $destinationgroups)) {
                    // Members group from source
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

        if (isset($params->materials) and $params->materials) {
            // Copy configutarion from local_materials
            batch_course::copy_config_materials($course->id, $params->courseid);
        }

        // Remove mdl_grade_grades_history
        batch_course::remove_grade_history_data($course->id);
    }

    public function params_info($params, $jobid) {
        global $PAGE;
        $user = batch_get_user($params->user);
        $batchoutput = $PAGE->get_renderer('local_batch');

        return $batchoutput->print_info_restart_courses(
            array(
                'courseid'   => (isset($params->courseid) ? $params->courseid : ''),
                'fullname'   => (isset($params->fullname) ? $params->fullname : ''),
                'shortname'  => $params->shortname,
                'startday'   => $params->startday,
                'startmonth' => $params->startmonth,
                'startyear'  => $params->startyear,
                'user'       => $user,
                'groups'     => (isset($params->groups) and $params->groups),
                'materials'  => (isset($params->materials) and $params->materials),
                'roleassignments' => !empty($params->roleassignments) ? batch_get_roles($params->roleassignments) : '',
            )
        );
    }
}
