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
 * A scheduled task.
 *
 * @package    local_batch
 * @copyright  2015 Institut Obert de Catalunya
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_batch\task;

require_once($CFG->dirroot . '/local/batch/lib.php');

/**
 * Simple task to run the autosave cleanup task.
 */
class cron_task extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('crontask', 'local_batch');
    }

    /**
     * Do the job.
     * Throw exceptions on errors (the job will be retried).
     */
    public function execute() {
        global $CFG;

        $jobs = \batch_queue::get_jobs(\batch_queue::FILTER_TODELETE);
        foreach ($jobs as $job) {
            mtrace("batch: job {$job->id} deleted");
            $job->delete();
        }

        $jobs = \batch_queue::get_jobs(\batch_queue::FILTER_ABORTED);
        foreach ($jobs as $job) {
            mtrace("batch: job {$job->id} aborted");
            $job->timeended = time();
            if (empty($job->error)) {
                $job->error = get_string('aborted', 'local_batch');
            }
            $job->save();
        }

        $starthour = isset($CFG->local_batch_start_hour) ? (int) $CFG->local_batch_start_hour : 0;
        $stophour = isset($CFG->local_batch_stop_hour) ? (int) $CFG->local_batch_stop_hour : 0;
        $date = getdate();
        $hour = $date['hours'];
        if ($starthour < $stophour) {
            $execute = ($hour >= $starthour and $hour < $stophour);
        } else {
            $execute = ($hour >= $starthour or $hour < $stophour);
        }

        $jobs = \batch_queue::get_jobs(\batch_queue::FILTER_PRIORITIZED);
        if (!empty($jobs)) {
            mtrace("batch: executing prioritized tasks");
            flush();
            $this->local_batch_execute_jobs($jobs);
        }

        if (!$execute) {
            mtrace("batch: execution will start at $starthour");
            flush();
            return;
        }

        $jobs = \batch_queue::get_jobs(\batch_queue::FILTER_PENDING);
        if (!$jobs) {
            mtrace("batch: no pending jobs");
            flush();
            return;
        }

        mtrace("batch: executing batch jobs");
        flush();

        $this->local_batch_execute_jobs($jobs);
    }


    private function local_batch_execute_jobs($jobs) {
        $starttime = time();
        foreach ($jobs as $job) {
            if (time() - $starttime >= BATCH_CRON_TIME) {
                return;
            }
            if ($job->can_start()) {
                mtrace("batch: executing job {$job->id}... ", "");
                flush();
                $job->execute();
                mtrace($job->error ? "ERROR" : "OK");
                flush();
            }
        }
    }
}
