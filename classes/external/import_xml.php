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

namespace local_questions_importer_ws\external;
defined('MOODLE_INTERNAL') || die();

require_once("{$CFG->libdir}/externallib.php");
require_once("{$CFG->libdir}/questionlib.php");
require_once("{$CFG->dirroot}/question/format/xml/format.php");
require_once("{$CFG->dirroot}/question/editlib.php");
require_once("{$CFG->libdir}/testing/generator/lib.php");

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_course;
use qformat_xml;
use moodle_exception;
use question_bank;

/**
 * Question XML Import External API
 * @package    local_questions_importer_ws
 * @copyright  2026 Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_xml extends external_api {
    /**
     * Defines the parameters for the import_xml function
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'course id where to import questions'),
            'draftitemid' => new external_value(PARAM_INT, 'ID area draft containing the XML file'),
        ]);
    }

    /**
     * Execute the function to import questions from XML
     * @param int $courseid
     * @param int $draftitemid
     * @return array
     * @throws moodle_exception
     */
    public static function execute(int $courseid, int $draftitemid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'draftitemid' => $draftitemid,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/questions_importer_ws:import', $context);

        $datagenerator = new \testing_data_generator();
        $qgenerator = $datagenerator->get_plugin_generator('core_question');

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $params['draftitemid'], 'id DESC', false);

        if (empty($files)) {
            throw new moodle_exception('filenotfound', 'local_questions_importer_ws');
        }

        $xmlcontent = reset($files)->get_content();
        $qformat = new qformat_xml();
        $questions = $qformat->readquestions(explode("\n", $xmlcontent));

        $defaultcategory = question_get_default_category($context->id);
        $currentcategoryid = $defaultcategory->id;

        $transaction = $DB->start_delegated_transaction();
        $count = 0;

        try {
            foreach ($questions as $qdata) {
                if (isset($qdata->qtype) && $qdata->qtype === 'category') {
                    $currentcategoryid = self::get_or_create_category($qdata->category, $context->id, $qgenerator);
                    continue;
                }

                if (isset($qdata->qtype)) {
                    $qdata->category = $currentcategoryid;
                    $qdata->contextid = $context->id;
                    $qdata->createdby = $USER->id;

                    question_bank::get_qtype($qdata->qtype)->save_question($qdata, $qdata);
                    $count++;
                }
            }
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw new moodle_exception('errorimporting', 'local_questions_importer_ws', '', $e->getMessage());
        }

        return [
            'status' => true,
            'message' => "Successfully imported $count questions from XML.",
        ];
    }

    /**
     * Creates or retrieves question categories based on the provided path.
     * @param string $categorypath
     * @param int $contextid
     * @param \core_question_generator $qgenerator
     * @return int category id
     */
    private static function get_or_create_category($categorypath, $contextid, $qgenerator) {
        global $DB;
        $categorypath = str_replace('$course$', '', $categorypath);
        $categories = explode('/', trim($categorypath, '/'));
        $defaultcategory = question_get_default_category($contextid);
        $parentid = $defaultcategory->id;
        foreach ($categories as $catname) {
            $catname = trim($catname);
            if (empty($catname)) {
                continue;
            }

            $existing = $DB->get_record('question_categories', [
                'contextid' => $contextid,
                'name' => $catname,
                'parent' => $parentid,
            ]);
            if ($existing) {
                $parentid = $existing->id;
            } else {
                $newcat = $qgenerator->create_question_category([
                    'name' => $catname,
                    'contextid' => $contextid,
                    'parent' => $parentid,
                ]);
                $parentid = $newcat->id;
            }
        }
        return $parentid;
    }

    /**
     * Returns the structure of the return value.
     * */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Success'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
        ]);
    }
}
