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

namespace core_question;

use core_question\local\bank\question_version_status;

/**
 * This class should provide an API for managing question_references.
 *
 * Unfortunately, question_references were introduced in the DB structure
 * without an nice API. This class is being added later, and is currently
 * terribly incomplete, but hopefully it can be improved in time.
 *
 * @package    core_question
 * @copyright  2023 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_reference_manager {
    /**
     * Return a list of those questions from the list passed in, which are referenced.
     *
     * A question is referenced if either:
     * - There is a question_reference pointing at exactly that version of that question; or
     * - There is an 'always latest' reference, and the question id is the latest non-draft version
     *   of that question_bank_entry.
     *
     * @param array $questionids a list of question ids to check.
     * @return array a list of the question ids from the input array which are referenced.
     */
    public static function questions_with_references(array $questionids): array {
        global $DB;

        if (empty($questionids)) {
            return [];
        }

        [$qidtest, $params] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'outerqid');
        [$lqidtest, $lparams] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'innerqid');

        return $DB->get_fieldset_sql("
            SELECT qv.questionid

              FROM {question_versions} qv

         -- This is a performant to get the latest non-draft version for each
         -- question_bank_entry that relates to one of our questionids.
         LEFT JOIN (
                       SELECT lqv.questionbankentryid,
                              MAX(lv.version) AS latestusableversion
                         FROM {question_versions} lqv
                         JOIN {question_versions} lv ON lv.questionbankentryid = lqv.questionbankentryid
                        WHERE lqv.questionid $lqidtest
                          AND lv.status <> :draft
                     GROUP BY lqv.questionbankentryid
                   ) latestversions ON latestversions.questionbankentryid = qv.questionbankentryid

              JOIN {question_references} qr ON qr.questionbankentryid = qv.questionbankentryid
                       AND (qr.version = qv.version OR qr.version IS NULL AND qv.version = latestversions.latestusableversion)

             WHERE qv.questionid $qidtest
            ", array_merge($params, $lparams, ['draft' => question_version_status::QUESTION_STATUS_DRAFT]));
    }

    /**
     * This will transform set reference filter conditions to use the new filter structure.
     *
     * Previously filterconditions had questioncategoryid, includesubcategories and tags options.
     * These have been replaced by the new category and tags filters. This function convers the old
     * pre-4.3 filter condition structure to the new one.
     *
     * @param array $filtercondition Pre-4.3 filter condition.
     * @param bool $maptags Map tags to tags with the same name, creating them if necessary. If not, just convert existing IDs to
     *     the new structure.
     * @return array Post-4.3 filter condition.
     */
    public static function convert_legacy_set_reference_filter_condition(array $filtercondition, bool $maptags = true): array {
        global $DB;
        if (!isset($filtercondition['filter'])) {
            $filtercondition['filter'] = [];

            // Question category filter.
            if (isset($filtercondition['questioncategoryid'])) {
                $filtercondition['filter']['category'] = [
                    'jointype' => \qbank_managecategories\category_condition::JOINTYPE_DEFAULT,
                    'values' => [$filtercondition['questioncategoryid']],
                    'filteroptions' => ['includesubcategories' => $filtercondition['includingsubcategories']],
                ];
            }

            // Tag filters.
            if (isset($filtercondition['tags'])) {
                // Get the names of the tags in the condition. Find or create corresponding tags,
                // and set their ids in the new condition.
                if ($maptags) {
                    $oldtags = array_map(fn($oldtag) => explode(',', $oldtag)[1], $filtercondition['tags']);
                    $questiontagcollid = \core_tag_area::get_collection('core_question', 'question');
                    $newtags = \core_tag_tag::create_if_missing($questiontagcollid, $oldtags);
                    $newtagids = array_map(fn($newtag) => $newtag->id, $newtags);
                } else {
                    $newtagids = array_map(fn($oldtag) => explode(',', $oldtag)[0], $filtercondition['tags']);
                }

                $filtercondition['filter']['qtagids'] = [
                    'jointype' => \qbank_tagquestion\tag_condition::JOINTYPE_DEFAULT,
                    'values' => array_values($newtagids),
                ];
                unset($filtercondition['tags']);
            }
            // Add additional default properties to the filtercondition.
            if (isset($filtercondition['questioncategoryid'])) {
                $category = $DB->get_record('question_categories', ['id' => $filtercondition['questioncategoryid']]);
                if ($category) {
                    $filtercondition['cat'] = "{$category->id},{$category->contextid}";
                } else {
                    $filtercondition['cat'] = '';
                }
            }
            $filtercondition['tabname'] = 'questions';
            $filtercondition['qpage'] = 0;
            $filtercondition['qperpage'] = 100;
            $filtercondition['jointype'] = \core\output\datafilter::JOINTYPE_ALL;
            unset($filtercondition['questioncategoryid']);
            unset($filtercondition['includingsubcategories']);
        } else if (isset($filtercondition['filter']['category']['includesubcategories'])) {
            $filtercondition['filter']['category']['filteroptions'] =
                ['includesubcategories' => $filtercondition['filter']['category']['includesubcategories']];
            unset($filtercondition['filter']['category']['includesubcategories']);
        }
        return $filtercondition;
    }

    /**
     * Set questionscontextid of set references to the context of the category they use.
     *
     * Restore could leave it on the course context or on the original question bank.
     * Categories not yet moved to a question bank module (e.g. before the transfer task runs) are skipped.
     *
     * @param string $component The component that owns the set references, e.g. 'mod_quiz'.
     * @return int The number of records that were updated.
     */
    public static function fix_set_references_questions_context(string $component): int {
        global $DB;
        $updates = 0;
        // Category id => context id, for categories in a question bank module.
        $categorycontexts = $DB->get_records_sql_menu(
            "SELECT qc.id, qc.contextid
               FROM {question_categories} qc
               JOIN {context} ctx ON ctx.id = qc.contextid
              WHERE ctx.contextlevel = :modulelevel",
            ['modulelevel' => CONTEXT_MODULE],
        );
        $sets = $DB->get_recordset('question_set_references', ['component' => $component]);
        foreach ($sets as $set) {
            $filtercondition = json_decode($set->filtercondition, true);
            $categoryid = $filtercondition['filter']['category']['values'][0] ?? $filtercondition['questioncategoryid'] ?? null;
            $categorycontextid = $categoryid ? ($categorycontexts[$categoryid] ?? null) : null;
            if (!$categorycontextid || $categorycontextid == $set->questionscontextid) {
                continue;
            }
            $set->questionscontextid = $categorycontextid;
            // Legacy filter conditions have no 'cat'; it is built when they are converted.
            if (isset($filtercondition['cat'])) {
                $filtercondition['cat'] = implode(',', [$categoryid, $categorycontextid]);
                $set->filtercondition = json_encode($filtercondition);
            }
            $DB->update_record('question_set_references', $set);
            $updates++;
        }
        $sets->close();
        return $updates;
    }

    /**
     * Ensure consistency of filter 'cat' parameter and questioncontextid in all set references.
     *
     * Some set references may have been moved to a different context, but the filter condition not updated with the context ID.
     * Since the filter condition is JSON-encoded, we have to check each set reference record for inconsistencies.
     *
     * This is used in a CLI script to fix bad data due to MDL-86691.
     *
     * @return int The number of records that were updated.
     * @todo Deprecate in Moodle 6.0 (MDL-87844) for removal in 7.0 (MDL-87845).
     */
    public static function fix_set_references_category_context(): int {
        global $DB;
        $updates = 0;
        $sets = $DB->get_recordset('question_set_references');
        foreach ($sets as $set) {
            $filtercondition = json_decode($set->filtercondition, true);
            [$catid, $catcontext] = explode(',', $filtercondition['cat']);
            if ($catcontext != $set->questionscontextid) {
                $filtercondition['cat'] = implode(',', [$catid, $set->questionscontextid]);
                $set->filtercondition = json_encode($filtercondition);
                $DB->update_record('question_set_references', $set);
                $updates++;
            }
        }
        $sets->close();
        return $updates;
    }
}
