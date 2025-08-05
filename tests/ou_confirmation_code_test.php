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

namespace quiz_gradingstudents;
use advanced_testcase;
use mod_quiz\quiz_settings;
use mod_quiz\quiz_attempt;
use quiz_gradingstudents\report_display_options;
use quiz_gradingstudents\ou_confirmation_code;

/**
 * Unit tests for quiz_gradingstudents ou_confirmation_code
 *
 * @package   quiz_gradingstudents
 * @copyright 2013 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers :: ou_confirmation_code
 */
final class ou_confirmation_code_test extends advanced_testcase {
    /**
     * Data provider for test_quiz_can_have_confirmation_code.
     * @return array
     */
    public static function quiz_can_have_confirmation_code_cases(): array {
        return [
            ['sk121-13r.eca30', 'eca30'],
            ['sk121-13j.exm01', 'exm01'],
            ['mu123-14b.icma42', null],
            ['practicequiz', null],
            ['B747-20B.icme30', 'icme30'],
            ['abc123-20j.prj01', 'prj01'],
        ];
    }

    /**
     * Verify whether quiz ca have confirmation code
     *
     * @dataProvider quiz_can_have_confirmation_code_cases
     *
     * @param string $idnumber
     * @param string|null $expectedresult
     * @return void
     */
    public function test_quiz_can_have_confirmation_code(string $idnumber, ?string $expectedresult = null): void {
        $this->assertSame($expectedresult,
                ou_confirmation_code::quiz_can_have_confirmation_code($idnumber));
    }

    /**
     * Test calculate hash
     *
     * @return void
     */
    public function test_calculate_hash(): void {
        $this->assertEquals('PYWF', ou_confirmation_code::calculate_hash(
                'R335671X L120 1 12P TMA30'));

        // Example from #7168.
        $this->assertEquals('DZSD', ou_confirmation_code::calculate_hash(
                'B7435280 SK121 1 13R ECA30'));
    }

    /**
     * Test calculate confirmation code
     *
     * @return void
     */
    public function test_calculate_confirmation_code(): void {
        $this->assertEquals('PYWF', ou_confirmation_code::calculate_confirmation_code(
                'R335671X', 'L120', '12P', 'TMA30', 1));

        // Example from #7168.
        $this->assertEquals('DZSD', ou_confirmation_code::calculate_confirmation_code(
                'B7435280', 'SK121', '13R', 'ECA30', 1));
    }

    /**
     * Data provider for {@see get_confirmation_code_cases}.
     *
     * @return array
     */
    public static function get_confirmation_code_cases(): array {
        return [
            ['sk121-13r.eca30', 'B7435280', 'DZSD'], // From issue #7168.
            ['sk121-13j.exm01', 'B7435280', 'VZVG'], // From issue #7168.
            ['sk121-13r.exm01', 'B7435280', 'VGWM'], // To verify the value used in the more comple test below.
            ['sdk121-13j.exm01', 'B7435281', 'CSGS'], // To verify the value used in the more comple test below.
            ['abc123-20j.prj01', 'A1234567', 'DJFM'], // From issue #480515.
            ['mu123-14b.icma42', 'B7435280', null], // Not ECA, EXM or PRJ.
            ['mu123/14b.exm01', 'B7435280', null], // No '-' in th course-pres code.
            ['exm01', 'B7435280', null], // Completely the wrong form.
            ['frog', 'B7435280', null], // Completely the wrong form.
        ];
    }

    /**
     * Test get_confirmation_code.
     *
     * @dataProvider get_confirmation_code_cases
     * @param string $quizidnumber
     * @param string $useridnumber
     * @param string|null $expectedcode
     * @return void
     */
    public function test_get_confirmation_code(string $quizidnumber, string $useridnumber,
                                               ?string $expectedcode): void {
        $code = ou_confirmation_code::get_confirmation_code(
                        (object) ['id' => 12, 'course' => 23, 'idnumber' => $quizidnumber],
                        (object) ['id' => 123, 'idnumber' => $useridnumber]);

        if ($expectedcode === null) {
            $this->assertNull($code);
        } else {
            $this->assertEquals($expectedcode, $code);
        }
    }

    /**
     * Test with variant groups
     *
     * @return void
     * @throws coding_exception
     */
    public function test_with_variant_groups(): void {
        global $DB;

        if (!class_exists('\local_oudataload\util')) {
            $this->markTestSkipped('This test verifies behaviour related to other OU-specific plugins.');
        }

        $this->resetAfterTest();

        // Create a course and some test users.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'oustudyplan',
                'shortname' => 'SK121-13J', ['createsections' => false]]);
        $student1 = $generator->create_user(['idnumber' => 'B7435280']);
        $student2 = $generator->create_user(['idnumber' => 'B7435281']);
        $generator->enrol_user($student1->id, $course->id);
        $generator->enrol_user($student2->id, $course->id);

        // Create variant groups with teacher and student in it.
        $grouping = $generator->create_grouping(['courseid' => $course->id, 'name' => 'Variant groups (SK121-13J)']);
        $group = $generator->create_group(['courseid' => $course->id, 'name' => 'SK121-13R variant group']);
        groups_assign_grouping($grouping->id, $group->id);
        groups_add_member($group, $student1);
        $group = $generator->create_group(['courseid' => $course->id, 'name' => 'SDK121-13J variant group']);
        groups_assign_grouping($grouping->id, $group->id);
        groups_add_member($group, $student2);

        // CVP entries.
        $cvp = \local_oudataload\util::table('vl_v_crs_version_pres');
        $recentpast = date('Y-m-d', strtotime('-19 days'));
        $DB->execute("
                INSERT INTO $cvp
                    (course_code, pres_code, vle_course_short_name, pres_finish_date, vle_course_page_in_stud_home)
                VALUES
                    ('SK121', '13J', 'SK121-13J', ?, 'Y'),
                    ('SK121', '13R', 'SK121-13J', ?, 'Y'),
                    ('SDK121', '13J', 'SK121-13J', ?, 'Y')
                ", [$recentpast, $recentpast, $recentpast]);

        $fakecm = (object) ['id' => 12, 'course' => $course->id, 'idnumber' => 'sk121-13j.exm01'];

        // Test.
        $this->assertEquals('VGWM', ou_confirmation_code::get_confirmation_code(
                $fakecm, $student1));
        $this->assertEquals('CSGS', ou_confirmation_code::get_confirmation_code(
                $fakecm, $student2));
    }
}
