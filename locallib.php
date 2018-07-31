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

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->libdir . '/coursecatlib.php');
require_once($CFG->libdir . '/badgeslib.php');
require_once($CFG->dirroot . '/group/lib.php');

define('BATCH_CRON_TIME', 600);
define('BATCH_TODELETE_AGE', 365 * 86400);

class batch_job {
    public $id;
    public $user;
    public $category;
    public $type;
    public $params;
    public $timecreated;
    public $timestarted;
    public $timeended;
    public $error;

    public function __construct($record) {
        $this->id = $record->id;
        $this->user = $record->user;
        $this->category = $record->category;
        $this->type = $record->type;
        $this->params = json_decode($record->params);
        $this->timecreated = $record->timecreated ?
            $record->timecreated : false;
        $this->timestarted = $record->timestarted ?
            $record->timestarted : false;
        $this->timeended = $record->timeended ? $record->timeended : false;
        $this->priority = $record->priority;
        $this->error = $record->error;
    }

    public function can_start() {
        $type = batch_type($this->type);
        return $type->can_start($this->params);
    }

    public function execute() {
        global $DB;

        $type = batch_type($this->type);
        $this->timestarted = time();
        $this->save();
        $transaction = $DB->start_delegated_transaction();
        try {
            $type->execute($this->id, $this->category, $this->params);
        } catch (moodle_exception $e) {
            $this->error = $e->getMessage() . ' in file: ' .
                           $e->getFile() . ' line: ' . $e->getLine() . ' ' . $e->getTraceAsString();

        }
        $transaction->allow_commit();
        $this->timeended = time();
        $this->save();
    }

    public function record() {
        return (object) array('id' => $this->id,
                              'user' => $this->user,
                              'category' => $this->category,
                              'type' => $this->type,
                              'params' => json_encode($this->params),
                              'timecreated' => $this->timecreated,
                              'timestarted' => $this->timestarted,
                              'timeended' => $this->timeended,
                              'error' => $this->error);
    }

    public function save() {
        global $DB;
        $DB->update_record('local_batch_jobs', $this->record());
    }

    public function delete() {
        global $DB;
        $DB->delete_records('local_batch_jobs', array('id' => $this->id));
        if ($DB->record_exists('course_categories', array('id' => $this->category))) {
            $fs = get_file_storage();
            $context = context_coursecat::instance($this->category);
            $fs->delete_area_files($context->id, 'local_batch', 'job', $this->id);
        }
    }

}

class batch_queue {

    const FILTER_ALL         = 0;
    const FILTER_PENDING     = 1;
    const FILTER_FINISHED    = 2;
    const FILTER_ERRORS      = 3;
    const FILTER_ABORTED     = 4;
    const FILTER_TODELETE    = 5;
    const FILTER_PRIORITIZED = 6;

    public static function add_job($userid, $category, $type, $params=false, $priority=false) {
        global $DB;
        $record = (object) array('user' => $userid,
                                 'category' => $category,
                                 'type' => $type,
                                 'params' => json_encode($params),
                                 'timecreated' => time(),
                                 'timestarted' => 0,
                                 'timeended' => 0,
                                 'priority' => $priority,
                                 'error' => '');
        $record->id = $DB->insert_record('local_batch_jobs', $record);
        return new batch_job($record);
    }

    public static function cancel_job($id) {
        global $DB;
        if ($job = self::get_job($id)) {
            $context = context_coursecat::instance($job->category);
            if (has_capability('moodle/category:manage', $context) and $job->timestarted == 0) {
                $DB->delete_records('local_batch_jobs', array('id' => $job->id));
                $fs = get_file_storage();
                $fs->delete_area_files($context->id, 'local_batch', 'job', $job->id);
            }
        }
    }

    public static function count_jobs($filter, $category = 0, $owner = false) {
        global $DB;
        list($select, $params) = self::filter_select($filter, $category, $owner);
        return $DB->count_records_select('local_batch_jobs', $select, $params);
    }

    public static function filter_select($filter, $category, $owner = false) {
        $params = array();
        $timetodelete = time() - BATCH_TODELETE_AGE;
        $select = "timecreated > :timetodelete";
        $params['timetodelete'] = $timetodelete;
        if ($filter == self::FILTER_PENDING) {
            $select .= " AND timeended = :timeended";
            $params['timeended'] = 0;
        } else if ($filter == self::FILTER_FINISHED) {
            $select .= " AND timeended > :timeended AND error = :error";
            $params['timeended'] = 0;
            $params['error'] = '';
        } else if ($filter == self::FILTER_ERRORS) {
            $select .= " AND timeended > :timeended AND error != :error";
            $params['timeended'] = 0;
            $params['error'] = '';
        } else if ($filter == self::FILTER_ABORTED) {
            $select .= " AND timestarted > :timestarted AND timeended = :timeended";
            $params['timestarted'] = 0;
            $params['timeended'] = 0;
        } else if ($filter == self::FILTER_TODELETE) {
            $select = "timecreated <= :timetodelete";
            $params['timetodelete'] = $timetodelete;
        } else if ($filter == self::FILTER_PRIORITIZED) {
            $select .= " AND priority = :priority AND timeended = :timeended";
            $params['priority'] = true;
            $params['timeended'] = 0;
        }
        if ($category) {
            $cat = batch_get_category($category);
            $categories = coursecat::make_categories_list('moodle/category:manage', $cat);
            $cats = array_keys($categories);
            $select .= ' AND category IN(' . implode(',', $cats) . ')';
        }
        if ($owner) {
            $select .= ' AND user = :owner';
            $params['owner'] = $owner;
        }
        return array($select, $params);
    }

    public static function get_job($id) {
        global $DB;

        $record = $DB->get_record('local_batch_jobs', array('id' => $id));
        return new batch_job($record);
    }

    public static function get_jobs($filter, $category = 0, $start=0, $count=0) {
        global $DB;
        $jobs = array();

        $sort = ($filter == self::FILTER_PENDING || $filter == self::FILTER_PRIORITIZED) ? 'priority DESC, timecreated, id ASC' : 'timecreated DESC, id DESC';
        list($select, $params) = self::filter_select($filter, $category);
        $records = $DB->get_records_select('local_batch_jobs', $select, $params,
                                      $sort, '*', $start, $count);
        if ($records) {
            foreach ($records as $record) {
                $jobs[] = new batch_job($record);
            }
        }

        return $jobs;
    }

    public static function retry_job($id) {
        if ($job = self::get_job($id)) {
            $context = context_coursecat::instance($job->category);
            if (has_capability('moodle/category:manage', $context) and $job->error) {
                $newjob = self::add_job($job->user, $job->category, $job->type, $job->params);
                $fs = get_file_storage();
                $af = $fs->get_area_files($context->id, 'local_batch', 'job', $job->id, 'filename', false);
                if ($af) {
                    $newfile = array(
                        'component' => 'local_batch',
                        'filearea'  => 'job',
                        'contextid' => $context->id,
                        'itemid'    => $newjob->id
                    );
                    $file = array_shift($af);
                    $fs->create_file_from_storedfile((object) $newfile, $file->get_id());
                }
            }
        }
    }

    public static function prioritize_job($id, $value) {
        global $DB;
        if (has_capability('moodle/site:config', context_system::instance())) {
            if ($job = self::get_job($id)) {
                $context = context_coursecat::instance($job->category);
                if (has_capability('moodle/category:manage', $context) and $job->timestarted == 0) {
                    $DB->set_field('local_batch_jobs', 'priority', $value, array('id' => $job->id));
                }
            }
        }
    }
}

function batch_type($name) {
    global $CFG;
    require_once("{$CFG->dirroot}/local/batch/types/$name.php");
    $class = "batch_type_$name";
    return new $class;
}

function batch_data_submitted() {
    if ($data = data_submitted()) {
        if (!confirm_sesskey()) {
            print_error('invalidsesskey');
        }
        return (array) $data;
    }
}

function batch_create_courses_get_data() {
    global $USER;

    if (!$data = batch_data_submitted()) {
        return array(false, false);
    }

    $info = array();

    $info['lastindex'] = (int) $data['lastindex'];

    $info['courses'] = array();
    for ($i = 0; $i <= $info['lastindex']; $i++) {
        if (isset($data["shortname-$i"])) {
            $shortname = stripslashes($data["shortname-$i"]);
            $fullname = stripslashes($data["fullname-$i"]);
            $category = (int) $data["category-$i"];
            if ($shortname and $fullname and $category) {
                $info['courses'][$i] = (object)  array(
                    'shortname' => $shortname,
                    'fullname' => $fullname,
                    'category' => $category,
                );
            }
        }
    }
    $fs = get_file_storage();
    $context = context_user::instance($USER->id);
    if ($files = $fs->get_area_files($context->id, 'user', 'draft', $data['csvfile'], 'id DESC', false)) {
        $csvfile = reset($files);
        if ($content = trim($csvfile->get_content())) {
            foreach (explode("\n", $content) as $line) {
                $fields = explode(",", $line);
                if (count($fields) == 3) {
                    $info['lastindex']++;
                    $info['courses'][$info['lastindex']] = (object)  array(
                        'shortname' => trim($fields[0]),
                        'fullname' => trim($fields[1]),
                        'category' => trim($fields[2]),
                    );
                }
            }
        }
    } else {
        $csvfile = false;
    }
    if (preg_match("/([0-9]+)\/([0-9]+)\/([0-9]+)/", $data['startdate'], $match)) {
        $info['startday'] = (int) $match[1];
        $info['startmonth'] = (int) $match[2];
        $info['startyear'] = (int) $match[3];
        $info['data'] = ($data['choose-backup'] and $info['courses']);
    } else {
        $info['data'] = false;
    }

    return array($csvfile, $info);
}

function batch_get_category($category) {
    global $DB;
    return $DB->get_record('course_categories', array('id' => $category));
}

function batch_get_course_category($course) {
    global $DB;
    return $DB->get_field('course', 'category', array('id' => $course));
}

function batch_get_course_category_tree($tree, $category, &$result) {
    if ($tree->id == $category) {
        $result = $tree;
    } else if (!empty($tree->categories)) {
        foreach ($tree->categories as $branch) {
            if (empty($result)) {
                batch_get_course_category_tree($branch, $category, $result);
            }
        }
    }
}

function batch_get_user($userid) {
    global $DB;
    $conditions = array('id' => $userid);
    return $DB->get_record('user', $conditions, '*', MUST_EXIST);
}

function batch_get_roles($roleids) {
    global $DB;
    $sql = "SELECT name"
         . " FROM {role}"
         . " WHERE id IN (" . $roleids . ")";
    if ($records = $DB->get_fieldset_sql($sql)) {
        return implode(',', $records);
    }
    return false;
}

function batch_get_category_and_subcategories_info($category) {
    global $DB;

    cache_helper::purge_by_definition('core', 'coursecat');
    $records = $DB->get_records_sql("SELECT id , name, path"
                        . " FROM mdl_course_categories"
                        . " ORDER BY sortorder"
    );
    $courses = batch_get_courses($category);
    $tree = array();
    foreach ($records as $record) {
        batch_get_categories(explode('/', preg_replace('/^\//', '', $record->path)), $tree, $records, $courses);
    }

    if ($category === 0) {
        return $tree;
    }
    $result = array();
    foreach ($tree as $branch) {
        if (empty($result)) {
            batch_get_course_category_tree($branch, $category, $result);
        }
    }
    return array($result);
}

function batch_get_categories($cat, &$tree, $categories, $courses) {
    if (empty($cat)) {
        return 0;
    }
    $index = $cat[0];
    if (!array_key_exists($index, $tree)) {
        $obj = new stdClass();
        $obj->id = $index;
        $obj->name = $categories[$index]->name;
        $obj->categories = array();
        $obj->courses = (isset($courses[$index]) ? $courses[$index] : array());
        $tree[$index] = $obj;
    }
    array_shift($cat);
    batch_get_categories($cat, $tree[$index]->categories, $categories, $courses);
}

function batch_get_courses($category) {
    global $DB;

    $categoryids = array();

    if ($courses = coursecat::get($category)->get_courses(array('recursive' => true))) {
        foreach ($courses as $course) {
            if ($course->id == SITEID) {
                continue;
            }
            if (!empty($course->visible) || has_capability('moodle/course:viewhiddencourses', context_course::instance($course->id))) {
                if (!isset($categoryids[$course->category])) {
                    $categoryids[$course->category] = array();
                }
                $categoryids[$course->category][$course->id] = $course;
            }
        }
    }
    return $categoryids;
}

class batch_course {

    public static function backup_course($courseid, $mode = backup::MODE_SAMESITE) {
        global $DB, $USER;

        $bc = new backup_controller(backup::TYPE_1COURSE, $courseid, backup::FORMAT_MOODLE,
                        backup::INTERACTIVE_NO, $mode, $USER->id);
        // Set own properties.
        $bc->get_plan()->get_setting('filename')->set_value(backup_plan_dbops::get_default_backup_filename(backup::FORMAT_MOODLE, backup::TYPE_1COURSE, $courseid, false, false));
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->get_plan()->get_setting('calendarevents')->set_value(true);
        $bc->get_plan()->get_setting('grade_histories')->set_value(false);
        $bc->get_plan()->get_setting('comments')->set_value(false);
        $bc->get_plan()->get_setting('userscompletion')->set_value(false);
        $bc->get_plan()->get_setting('logs')->set_value(false);

        if ($allmods = $DB->get_records_menu('modules', null, '', 'id, name')) {
            if ($modules = $DB->get_records('course_modules', array('course' => $courseid), '', 'id, module')) {
                foreach ($modules as $mod) {
                    $name = $allmods[$mod->module] . '_' . $mod->id . '_userinfo';
                    if ($bc->get_plan()->setting_exists($name)) {
                        $bc->get_plan()->get_setting($name)->set_value(false);
                    }
                }
            }
        }

        $bc->set_status(backup::STATUS_AWAITING);

        $backupid = $bc->get_backupid();
        $backupbasepath = $bc->get_plan()->get_basepath();

        // Execute backup.
        $bc->execute_plan();

        $results = $bc->get_results();
        $file = $results['backup_destination']; // may be empty if file already moved to target location

        $bc->destroy();

        return array($file, $backupid);
    }

    public static function delete_course($courseid) {
        global $CFG, $DB;

        if (!$DB->record_exists('course', array('id' => $courseid))) {
            throw new Exception('delete_course: nonexistent_course ' . $courseid);
        }

        if (!delete_course($courseid, false)) {
            throw new Exception('delete_course ' . $courseid);
        }
    }

    public static function hide_course($courseid) {
        global $DB;

        if (!$DB->set_field('course', 'visible', 0, array('id' => $courseid))) {
            throw new Exception('hide_course');
        }
    }

    public static function show_course($courseid) {
        global $DB;

        if (!$DB->set_field('course', 'visible', 1, array('id' => $courseid))) {
            throw new Exception('show course');
        }
    }

    public static function set_theme($courseid, $theme) {
        global $DB;
        $themes = array_keys(core_component::get_plugin_list('theme'));
        if (empty($theme) || in_array($theme, $themes)) {
            if (!$DB->set_field('course', 'theme', $theme, array('id' => $courseid))) {
                throw new Exception('set theme');
            }
        }
    }

    public static function rename_course($courseid, $shortname, $fullname) {
        global $DB;

        if (!$DB->set_field('course', 'shortname', $shortname, array('id' => $courseid))) {
            throw new Exception('rename_course: shortname');
        }

        if (!$DB->set_field('course', 'fullname', $fullname, array('id' => $courseid))) {
            throw new Exception('rename_course: fullname');
        }
    }

    public static function restore_backup($file, $context, $params, $options = array()) {
        global $CFG, $DB, $USER;

        $import = isset($options['import']);
        $mode = isset($options['mode']) ? $options['mode'] : backup::MODE_GENERAL;
        $restart = isset($options['restart']);
        $backupid = isset($options['backupid']) ? $options['backupid'] : false;
        $fileisunzip = false;
        $tmpdir = $CFG->tempdir . '/backup';

        if (isset($options['category'])) {
            $catid = $options['category'];
        } else {
            $catid = $params->category;
        }
        if (!isset($params->fullname) or !isset($params->shortname)) {
            list($params->fullname, $params->shortname) = restore_dbops::calculate_course_names(0,
                                                            get_string('restoringcourse', 'backup'),
                                                            get_string('restoringcourseshortname', 'backup'));
        }
        $courseid = restore_dbops::create_new_course($params->fullname, $params->shortname, $catid);

        if ($backupid) {
            $fileisunzip = file_exists("$tmpdir/$backupid/moodle_backup.xml");
        }

        if ($restart and $fileisunzip) {
            $pathname = $CFG->tempdir . '/backup/' . $backupid;
        } else {
            if ($import) {
                $pathname = $file;
            } else {
                if ($file->is_external_file()) {
                    $reference = preg_replace('/^\//', '' , $file->get_reference());
                    $repository = repository::get_repository_by_id($file->get_repository_id(), SYSCONTEXTID);
                    $pathname = $repository->root_path . $reference;
                } else {
                    $pathname = "$tmpdir/" . basename($file->copy_content_to_temp('/backup'));
                }
            }
            $backupid = restore_controller::get_tempdir_name($context->id, $USER->id);
            $fb = get_file_packer('application/vnd.moodle.backup');
            $files = $fb->extract_to_pathname($pathname, "$tmpdir/$backupid/");
        }
        $rc = new restore_controller($backupid, $courseid, backup::INTERACTIVE_NO,
                        $mode, $USER->id, backup::TARGET_NEW_COURSE);
        if ($rc->get_status() == backup::STATUS_REQUIRE_CONV) {
            $rc->convert();
            if ($import) {
                $params->fullname = $rc->get_info()->original_course_fullname;
                $params->shortname = $rc->get_info()->original_course_shortname . '*';
            }
        } else if ($import) {
            $params->fullname = $rc->get_info()->original_course_fullname;
            $params->shortname = $rc->get_info()->original_course_shortname;
        }
        $rc->execute_precheck();

        // Set own properties.
        if (!empty($params->shortname)) {
            $rc->get_plan()->get_setting('course_shortname')->set_value($params->shortname);
        }
        if (!empty($params->fullname)) {
            $rc->get_plan()->get_setting('course_fullname')->set_value($params->fullname);
        }

        $startdate = make_timestamp($params->startyear, $params->startmonth, $params->startday);
        $rc->get_plan()->get_setting('course_startdate')->set_value($startdate);
        $rc->get_plan()->get_setting('grade_histories')->set_value(false);

        // Execute restore.
        $rc->execute_plan();

        $rc->destroy();

        // Remove temp backup.
        if (is_dir("$tmpdir/$backupid")) {
            fulldelete("$tmpdir/$backupid/");
        }
        if (isset($files) and !$fileisunzip and !$import and !$file->is_external_file()) {
            fulldelete($pathname);
        }

        return $courseid;
    }

    public static function remove_grade_history_data($courseid) {
        global $DB;

        $DB->execute('DELETE gg.* ' .
                     'FROM {grade_grades_history} gg ' .
                     'JOIN {grade_items_history} gi ON gi.oldid = gg.itemid ' .
                     'WHERE gi.courseid = :courseid',
                     array('courseid' => $courseid));
        $DB->delete_records('grade_items_history', array('courseid' => $courseid));
        $DB->delete_records('grade_categories_history', array('courseid' => $courseid));
        $DB->delete_records('grade_outcomes_history', array('courseid' => $courseid));
        $DB->delete_records('scale_history', array('courseid' => $courseid));
    }

    public static function get_user_assignments_by_course($courseid) {
         global $CFG, $DB;

         $sql = 'SELECT u.id'
             . ' FROM {context} ct, {enrol} e, {role} r, {role_assignments} ra,'
             . '      {user} u, {user_enrolments} ue'
             . ' WHERE ct.contextlevel = :contextlevel'
             . ' AND ct.instanceid = :courseid'
             . ' AND e.courseid = ct.instanceid'
             . ' AND e.enrol = :enrol'
             . ' AND ra.component = :component'
             . ' AND ra.contextid = ct.id'
             . ' AND ra.itemid = :itemid'
             . ' AND ra.roleid = r.id'
             . ' AND ra.userid = u.id'
             . ' AND ra.userid = ue.userid'
             . ' AND u.mnethostid = :mnethostid'
             . ' AND ue.enrolid = e.id'
             . ' AND ue.userid = u.id';

         return $DB->get_records_sql($sql, array(
             'component' => '',
             'contextlevel' => CONTEXT_COURSE,
             'courseid' => $courseid,
             'enrol' => 'manual',
             'itemid' => 0,
             'mnethostid' => $CFG->mnet_localhost_id,
         ));
    }

    public static function assignmentupgrade($courseid) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/admin/tool/assignmentupgrade/locallib.php');
        // first find all the unique assignment types
        $sql = 'SELECT plugin AS assignmenttype, value AS version'
            . ' FROM {config_plugins}'
            . ' WHERE name = :version'
            . ' AND plugin LIKE :assignment';
        $types = $DB->get_records_sql($sql, array(
            'version' => 'version',
            'assignment' => 'assignment_%'
        ));

        $upgradabletypes = array();

        foreach ($types as $assignment) {
            $shorttype = substr($assignment->assignmenttype, strlen('assignment_'));
            if (assign::can_upgrade_assignment($shorttype, $assignment->version)) {
                $upgradabletypes[] = $shorttype;
            }
        }

        list($sql, $params) = $DB->get_in_or_equal($upgradabletypes, SQL_PARAMS_NAMED);
        $sql .= ' AND course = :courseid';
        $params['courseid'] = $courseid;
        $records = $DB->get_records_sql(
                  'SELECT id '
                . ' FROM {assignment}'
                . ' WHERE assignmenttype ' . $sql, $params);
        foreach ($records as $record) {
            tool_assignmentupgrade_upgrade_assignment($record->id);
        }
    }

    public static function change_prefix($courseid, $prefix) {
        global $DB;
        $course = $DB->get_record('course', array('id' => $courseid));

        if (preg_match('/^\[.*?\](.*)$/', $course->fullname, $match)) {
            $course->fullname = trim($match[1]);
        }

        if ($prefix) {
            $course->fullname = "[$prefix] {$course->fullname}";
        }

        return $DB->update_record('course', $course);
    }


    public static function change_suffix($courseid, $suffix) {
        global $DB;
        $course = $DB->get_record('course', array('id' => $courseid));

        if (preg_match('/^(.*)([~\*])$/', $course->shortname, $match)) {
            $course->shortname = $match[1];
            if ($match[2] == '~') {
                if (preg_match('/(.*) ~ .*?$/', $course->fullname, $match)) {
                    $course->fullname = $match[1];
                }
            }
        }

        if ($suffix == 'restarted') {
            $course->shortname .= '~';
            $course->fullname .= strftime(' ~ %B %G');
        } else if ($suffix == 'imported') {
            $course->shortname .= '*';
        }

        $id = $DB->get_field('course', 'id', array('shortname' => $course->shortname));
        if (!$id or $id == $courseid) {
            return $DB->update_record('course', $course);
        }
    }

    public static function copy_config_materials($oldcourseid, $newcourseid) {
        global $DB;

        if ($record = $DB->get_record('local_materials', array('courseid' => $oldcourseid))) {
            $materialid = $record->id;
            unset($record->id);
            $record->courseid = $newcourseid;
            $newid = $DB->insert_record('local_materials', $record);
            $context = context_system::instance();
            $fs = get_file_storage();
            $oldfiles = $fs->get_area_files($context->id, 'local_materials', 'attachment', $materialid, 'id', false);
            foreach ($oldfiles as $oldfile) {
                $filerecord = new stdClass();
                $filerecord->contextid = $context->id;
                $filerecord->itemid = $newid;
                $fs->create_file_from_storedfile($filerecord, $oldfile);
            }
        }
    }
}
