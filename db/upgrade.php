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
 * @package    local_batch
 * @copyright  Institut Obert de Catalunya
 * @author     Marc Català <mcatala@ioc.cat>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function xmldb_local_batch_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2016071403) {

        $dbman = $DB->get_manager(); // Loads ddl manager and xmldb classes.

        // Define field priority to be added to local_batch_jobs.
        $table = new xmldb_table('local_batch_jobs');
        $field = new xmldb_field('priority', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timeended');

        // Conditionally launch add field priority.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2016071403, 'local', 'batch');
    }

    return true;
}
