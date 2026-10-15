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

use advanced_testcase;
use context_system;
use core_question\local\bank\question_version_status;
use core_question_generator;

/**
 * Unit tests for the {@see question_reference_manager} class.
 *
 * @package   core_question
 * @category  test
 * @copyright 2011 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \core_question\question_reference_manager
 */
final class question_reference_manager_test extends advanced_testcase {

    public function test_questions_with_references(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $systemcontext = context_system::instance();

        // Create three questions, each with three versions.
        // In each case, the third version is draft.
        $cat = $questiongenerator->create_question_category();
        $q1v1 = $questiongenerator->create_question('truefalse', null, ['name' => 'Q1V1', 'category' => $cat->id]);
        $q1v2 = $questiongenerator->update_question($q1v1, null, ['name' => 'Q1V2']);
        $q1v3 = $questiongenerator->update_question($q1v2, null,
                ['name' => 'Q1V3', 'status' => question_version_status::QUESTION_STATUS_DRAFT]);
        $q2v1 = $questiongenerator->create_question('truefalse', null, ['name' => 'Q2V1', 'category' => $cat->id]);
        $q2v2 = $questiongenerator->update_question($q2v1, null, ['name' => 'Q2V2']);
        $q2v3 = $questiongenerator->update_question($q2v2, null,
                ['name' => 'Q2V3', 'status' => question_version_status::QUESTION_STATUS_DRAFT]);
        $q3v1 = $questiongenerator->create_question('truefalse', null, ['name' => 'Q3V1', 'category' => $cat->id]);
        $q3v2 = $questiongenerator->update_question($q3v1, null, ['name' => 'Q3V2']);
        $q3v3 = $questiongenerator->update_question($q3v2, null,
                ['name' => 'Q3V3', 'status' => question_version_status::QUESTION_STATUS_DRAFT]);

        // Create specific references to Q2V1 and Q2V3.
        $DB->insert_record('question_references', ['usingcontextid' => $systemcontext->id,
                'component' => 'core_question', 'questionarea' => 'test', 'itemid' => 0,
                'questionbankentryid' => $q2v1->questionbankentryid, 'version' => 1]);
        $DB->insert_record('question_references', ['usingcontextid' => $systemcontext->id,
                'component' => 'core_question', 'questionarea' => 'test', 'itemid' => 1,
                'questionbankentryid' => $q2v1->questionbankentryid, 'version' => 3]);

        // Create an always-latest reference to Q3.
        $DB->insert_record('question_references', ['usingcontextid' => $systemcontext->id,
                'component' => 'core_question', 'questionarea' => 'test', 'itemid' => 2,
                'questionbankentryid' => $q3v1->questionbankentryid, 'version' => null]);

        // Verify which versions of Q1 are used.
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q1v1->id]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q1v2->id]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q1v3->id]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q1v1->id, $q1v2->id, $q1v3->id]));

        // Verify which versions of Q2 are used.
        $this->assertEqualsCanonicalizing([$q2v1->id],
                question_reference_manager::questions_with_references([$q2v1->id]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q2v2->id]));
        $this->assertEqualsCanonicalizing([$q2v3->id],
                question_reference_manager::questions_with_references([$q2v3->id]));
        $this->assertEqualsCanonicalizing([$q2v1->id, $q2v3->id],
                question_reference_manager::questions_with_references([$q2v1->id, $q2v2->id, $q2v3->id]));

        // Verify which versions of Q1 are used.
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q3v1->id]));
        $this->assertEqualsCanonicalizing([$q3v2->id],
                question_reference_manager::questions_with_references([$q3v2->id]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([$q3v3->id]));
        $this->assertEqualsCanonicalizing([$q3v2->id],
                question_reference_manager::questions_with_references([$q3v1->id, $q3v2->id, $q3v3->id]));

        // Do some combined queries.
        $this->assertEqualsCanonicalizing([$q2v1->id, $q2v3->id, $q3v2->id],
                question_reference_manager::questions_with_references([
                        $q1v1->id, $q1v2->id, $q1v3->id,
                        $q2v1->id, $q2v2->id, $q2v3->id,
                        $q3v1->id, $q3v2->id, $q3v3->id]));
        $this->assertEqualsCanonicalizing([$q2v1->id, $q2v3->id, $q3v2->id],
                question_reference_manager::questions_with_references([$q2v1->id, $q2v3->id, $q3v2->id]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([
                        $q1v1->id, $q1v2->id, $q1v3->id,
                        $q2v2->id,
                        $q3v1->id, $q3v3->id]));

        // Test some edge cases.
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([]));
        $this->assertEqualsCanonicalizing([],
                question_reference_manager::questions_with_references([-1]));

    }

    /**
     * Set references should be pointed at the question bank module of their category.
     */
    public function test_fix_set_references_questions_context(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        /** @var core_question_generator $questiongenerator */
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $course = $generator->create_course();
        $coursecontext = \context_course::instance($course->id);
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        $otherqbank = $generator->create_module('qbank', ['course' => $course->id]);
        $otherqbankcontext = \context_module::instance($otherqbank->cmid);
        $category = $questiongenerator->create_question_category(['contextid' => $qbankcontext->id]);
        // A course bank category the transfer task has not moved yet.
        $coursecategory = (object) [
            'name' => 'Not transferred yet',
            'contextid' => $coursecontext->id,
            'info' => '',
            'infoformat' => FORMAT_HTML,
            'stamp' => make_unique_id_code(),
            'parent' => 0,
            'sortorder' => 999,
        ];
        $coursecategory->id = $DB->insert_record('question_categories', $coursecategory);

        $filtercondition = fn(int $categoryid, int $contextid) => json_encode([
            'filter' => [
                'category' => [
                    'jointype' => 1,
                    'values' => [$categoryid],
                    'filteroptions' => ['includesubcategories' => false],
                ],
            ],
            'cat' => "{$categoryid},{$contextid}",
        ]);
        $reference = fn(int $itemid, int $questionscontextid, string $filtercondition, string $component = 'mod_quiz') => (object) [
            'usingcontextid' => 1,
            'component' => $component,
            'questionarea' => 'slot',
            'itemid' => $itemid,
            'questionscontextid' => $questionscontextid,
            'filtercondition' => $filtercondition,
        ];

        $correct = $reference(1, $qbankcontext->id, $filtercondition($category->id, $qbankcontext->id));
        // Legacy filter condition on the course context, e.g. restored from a course bank in a 4.x backup.
        $brokenlegacy = $reference(
            2,
            $coursecontext->id,
            json_encode(['questioncategoryid' => (string) $category->id, 'includingsubcategories' => '0']),
        );
        // Another question bank, e.g. the original bank after restoring a quiz before its bank.
        $brokenotherbank = $reference(3, $otherqbankcontext->id, $filtercondition($category->id, $otherqbankcontext->id));
        $pending = $reference(4, $coursecontext->id, $filtercondition($coursecategory->id, $coursecontext->id));
        $othercomponent = $reference(5, $coursecontext->id, $filtercondition($category->id, $coursecontext->id), 'core_question');
        foreach ([$correct, $brokenlegacy, $brokenotherbank, $pending, $othercomponent] as $record) {
            $record->id = $DB->insert_record('question_set_references', $record);
        }

        $this->assertEquals(2, question_reference_manager::fix_set_references_questions_context('mod_quiz'));

        $this->assertEquals($correct, $DB->get_record('question_set_references', ['id' => $correct->id]));
        $this->assertEquals($pending, $DB->get_record('question_set_references', ['id' => $pending->id]));
        $this->assertEquals($othercomponent, $DB->get_record('question_set_references', ['id' => $othercomponent->id]));

        $fixedlegacy = $DB->get_record('question_set_references', ['id' => $brokenlegacy->id]);
        $this->assertEquals($qbankcontext->id, $fixedlegacy->questionscontextid);
        $this->assertEquals($brokenlegacy->filtercondition, $fixedlegacy->filtercondition);

        $fixedotherbank = $DB->get_record('question_set_references', ['id' => $brokenotherbank->id]);
        $this->assertEquals($qbankcontext->id, $fixedotherbank->questionscontextid);
        $this->assertEquals("{$category->id},{$qbankcontext->id}", json_decode($fixedotherbank->filtercondition, true)['cat']);
    }

    /**
     * Any question set references where questioncategoryid does not match filtercondition['cat'] should have the cat updated.
     *
     * @todo Deprecate in Moodle 6.0 (MDL-87844) for removal in 7.0 (MDL-87845).
     */
    public function test_fix_set_references_category_context(): void {
        global $DB;
        $this->resetAfterTest();

        $correctreference = (object) [
            'usingcontextid' => 1,
            'component' => 'core_question',
            'questionarea' => 'test',
            'itemid' => 1,
            'questionscontextid' => 2,
            'filtercondition' => json_encode(
                [
                    'filter' => [
                        'category' => [
                            'name' => 'category',
                            'jointype' => 1,
                            'values' => [1],
                            'filteroptions' => [
                                'includesubcategories' => 0,
                            ],
                        ],
                    ],
                    'cmid' => 1,
                    'courseid' => 1,
                    'cat' => '1,2',
                ],
            ),
        ];
        $correctreference->id = $DB->insert_record('question_set_references', $correctreference);

        $incorrectreference = (object) [
            'usingcontextid' => 1,
            'component' => 'core_question',
            'questionarea' => 'test',
            'itemid' => 2,
            'questionscontextid' => 4,
            'filtercondition' => json_encode(
                [
                    'filter' => [
                        'category' => [
                            'name' => 'category',
                            'jointype' => 1,
                            'values' => [6],
                            'filteroptions' => [
                                'includesubcategories' => 0,
                            ],
                        ],
                    ],
                    'cmid' => 1,
                    'courseid' => 1,
                    'cat' => '6,3',
                ],
            ),
        ];
        $incorrectreference->id = $DB->insert_record('question_set_references', $incorrectreference);

        $fixedcount = question_reference_manager::fix_set_references_category_context();

        $this->assertEquals(1, $fixedcount);

        $updatedcorrectrefrence = $DB->get_record('question_set_references', ['id' => $correctreference->id]);
        $this->assertEquals($correctreference, $updatedcorrectrefrence);

        $updatedincorrectrefrence = $DB->get_record('question_set_references', ['id' => $incorrectreference->id]);
        $this->assertEquals($incorrectreference->usingcontextid, $updatedincorrectrefrence->usingcontextid);
        $this->assertEquals($incorrectreference->questionscontextid, $updatedincorrectrefrence->questionscontextid);
        $filtercondition = json_decode($updatedincorrectrefrence->filtercondition, true);
        $this->assertEquals(
            implode(',', [$filtercondition['filter']['category']['values'][0], $updatedincorrectrefrence->questionscontextid]),
            $filtercondition['cat'],
        );
    }
}
