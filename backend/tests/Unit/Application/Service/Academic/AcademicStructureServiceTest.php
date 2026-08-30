<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service\Academic;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\Academic\AcademicTermService;
use QRIVO\Application\Service\Academic\AcademicYearService;
use QRIVO\Application\Service\Academic\ClassService;
use QRIVO\Application\Service\Academic\CourseService;
use QRIVO\Application\Service\Academic\DepartmentService;
use QRIVO\Application\Service\Academic\FacultyService;
use QRIVO\Application\Service\Academic\ProgramService;
use QRIVO\Application\Service\Academic\RoomService;
use QRIVO\Application\Service\Academic\SchoolService;
use QRIVO\Application\Service\Academic\StudentService;
use QRIVO\Application\Service\Academic\TeacherService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Exception\ConflictException;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Domain\Exception\ValidationException;
use QRIVO\Infrastructure\Repository\Academic\AcademicTermRepository;
use QRIVO\Infrastructure\Repository\Academic\AcademicYearRepository;
use QRIVO\Infrastructure\Repository\Academic\ClassRepository;
use QRIVO\Infrastructure\Repository\Academic\CourseRepository;
use QRIVO\Infrastructure\Repository\Academic\DepartmentRepository;
use QRIVO\Infrastructure\Repository\Academic\FacultyRepository;
use QRIVO\Infrastructure\Repository\Academic\ProgramRepository;
use QRIVO\Infrastructure\Repository\Academic\RoomRepository;
use QRIVO\Infrastructure\Repository\Academic\SchoolRepository;
use QRIVO\Infrastructure\Repository\Academic\StudentRepository;
use QRIVO\Infrastructure\Repository\Academic\TeacherRepository;
use QRIVO\Infrastructure\Repository\ReferenceRepository;
use QRIVO\Tests\Support\AcademicSchemaTrait;

final class AcademicStructureServiceTest extends TestCase
{
    use AcademicSchemaTrait;

    private \QRIVO\Infrastructure\Database\Connection $db;

    protected function setUp(): void
    {
        $this->pdo = $this->buildAcademicDb();
        $this->db  = $this->buildConnection();
    }

    private function schools(): SchoolService
    {
        return new SchoolService($this->logger(), new SchoolRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db));
    }
    private function faculties(): FacultyService
    {
        return new FacultyService($this->logger(), new FacultyRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db));
    }
    private function departments(): DepartmentService
    {
        return new DepartmentService($this->logger(), new DepartmentRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db));
    }
    private function programs(): ProgramService
    {
        return new ProgramService($this->logger(), new ProgramRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db));
    }
    private function rooms(): RoomService
    {
        return new RoomService($this->logger(), new RoomRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db));
    }
    private function courses(): CourseService
    {
        return new CourseService($this->logger(), new CourseRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db));
    }
    private function years(): AcademicYearService
    {
        return new AcademicYearService($this->logger(), new AcademicYearRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db));
    }
    private function terms(): AcademicTermService
    {
        return new AcademicTermService($this->logger(), new AcademicTermRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db));
    }
    private function classes(): ClassService
    {
        return new ClassService($this->logger(), new ClassRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db));
    }
    private function teachers(): TeacherService
    {
        return new TeacherService($this->logger(), new TeacherRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db));
    }
    private function students(): StudentService
    {
        return new StudentService($this->logger(), new StudentRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db));
    }

    private function logger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    private function auditCount(?string $type = null): int
    {
        if ($type === null) {
            return (int) $this->pdo->query('SELECT COUNT(*) c FROM audit_logs')->fetch()['c'];
        }
        $s = $this->pdo->prepare('SELECT COUNT(*) c FROM audit_logs WHERE event_type = ?');
        $s->execute([$type]);
        return (int) $s->fetch()['c'];
    }

    // ─── CRUD ─────────────────────────────────────────────────────────────────

    public function test_school_full_crud_cycle(): void
    {
        $svc   = $this->schools();
        $actor = $this->actor();

        $created = $svc->create(['name' => 'Main Campus', 'code' => 'main'], $actor);
        $this->assertSame('MAIN', $created['code']);
        $this->assertArrayNotHasKey('deleted_at', $created);
        $id = $created['id'];

        $this->assertSame('Main Campus', $svc->get($id, $actor)['name']);

        $updated = $svc->update($id, ['name' => 'Renamed'], $actor);
        $this->assertSame('Renamed', $updated['name']);

        $list = $svc->list([], $actor);
        $this->assertSame(1, $list['meta']['total']);

        $svc->delete($id, $actor);
        $this->expectException(NotFoundException::class);
        $svc->get($id, $actor);
    }

    public function test_soft_deleted_row_is_hidden_but_kept(): void
    {
        $svc = $this->schools();
        $id  = $svc->create(['name' => 'S', 'code' => 'S1'], $this->actor())['id'];
        $svc->delete($id, $this->actor());

        $this->assertSame(0, $svc->list([], $this->actor())['meta']['total']);
        $this->assertNotFalse($this->pdo->query("SELECT 1 FROM schools WHERE id = {$id} AND deleted_at IS NOT NULL")->fetch());
    }

    public function test_writes_are_audited(): void
    {
        $svc = $this->schools();
        $id  = $svc->create(['name' => 'S', 'code' => 'S1'], $this->actor())['id'];
        $svc->update($id, ['name' => 'S2'], $this->actor());
        $svc->delete($id, $this->actor());

        $this->assertSame(1, $this->auditCount('SCHOOL_CREATED'));
        $this->assertSame(1, $this->auditCount('SCHOOL_UPDATED'));
        $this->assertSame(1, $this->auditCount('SCHOOL_DELETED'));
    }

    // ─── Validation ───────────────────────────────────────────────────────────

    public function test_create_rejects_missing_required_fields(): void
    {
        $this->expectException(ValidationException::class);
        $this->schools()->create(['name' => 'x'], $this->actor());
    }

    public function test_update_rejects_empty_body(): void
    {
        $id = $this->schools()->create(['name' => 'S', 'code' => 'S1'], $this->actor())['id'];
        $this->expectException(ValidationException::class);
        $this->schools()->update($id, [], $this->actor());
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $this->schools()->create(['name' => 'A', 'code' => 'DUP'], $this->actor());
        $this->expectException(ValidationException::class);
        $this->schools()->create(['name' => 'B', 'code' => 'DUP'], $this->actor());
    }

    public function test_program_duration_out_of_range_rejected(): void
    {
        $ids = $this->seedHierarchy();
        $this->expectException(ValidationException::class);
        $this->programs()->create([
            'department_id' => $ids['departmentId'], 'name' => 'X', 'code' => 'X', 'duration_years' => 99,
        ], $this->actor());
    }

    public function test_academic_year_end_before_start_rejected(): void
    {
        $ids = $this->seedHierarchy();
        $this->expectException(ValidationException::class);
        $this->years()->create([
            'school_id' => $ids['schoolId'], 'name' => '2099-2098',
            'start_date' => '2099-09-01', 'end_date' => '2099-08-01',
        ], $this->actor());
    }

    // ─── Relationship enforcement (application layer) ──────────────────────────

    public function test_child_create_rejects_nonexistent_parent(): void
    {
        try {
            $this->faculties()->create(['school_id' => 999, 'name' => 'F', 'code' => 'F'], $this->actor());
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('school_id', $e->getErrors());
        }
    }

    public function test_child_create_rejects_soft_deleted_parent(): void
    {
        $schoolId = $this->schools()->create(['name' => 'S', 'code' => 'S1'], $this->actor())['id'];
        $this->schools()->delete($schoolId, $this->actor());

        $this->expectException(ValidationException::class);
        $this->faculties()->create(['school_id' => $schoolId, 'name' => 'F', 'code' => 'F'], $this->actor());
    }

    public function test_full_hierarchy_can_be_built(): void
    {
        $actor    = $this->actor();
        $schoolId = $this->schools()->create(['name' => 'S', 'code' => 'S'], $actor)['id'];
        $facId    = $this->faculties()->create(['school_id' => $schoolId, 'name' => 'F', 'code' => 'F'], $actor)['id'];
        $depId    = $this->departments()->create(['faculty_id' => $facId, 'name' => 'D', 'code' => 'D'], $actor)['id'];
        $progId   = $this->programs()->create(['department_id' => $depId, 'name' => 'P', 'code' => 'P', 'duration_years' => 4], $actor)['id'];
        $yearId   = $this->years()->create(['school_id' => $schoolId, 'name' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30'], $actor)['id'];
        $termId   = $this->terms()->create(['academic_year_id' => $yearId, 'name' => 'Fall', 'term_number' => 1, 'start_date' => '2025-09-01', 'end_date' => '2026-01-15'], $actor)['id'];
        $classId  = $this->classes()->create(['program_id' => $progId, 'academic_term_id' => $termId, 'name' => 'CE-1A', 'grade_level' => 1], $actor)['id'];
        $this->courses()->create(['department_id' => $depId, 'name' => 'DS', 'code' => 'DS101'], $actor);
        $this->rooms()->create(['school_id' => $schoolId, 'name' => 'Hall A', 'code' => 'A1', 'capacity' => 120], $actor);

        $this->assertGreaterThan(0, $classId);
    }

    // ─── Delete guards ────────────────────────────────────────────────────────

    public function test_cannot_delete_school_with_faculty(): void
    {
        $actor    = $this->actor();
        $schoolId = $this->schools()->create(['name' => 'S', 'code' => 'S'], $actor)['id'];
        $this->faculties()->create(['school_id' => $schoolId, 'name' => 'F', 'code' => 'F'], $actor);

        $this->expectException(ConflictException::class);
        $this->schools()->delete($schoolId, $actor);
    }

    public function test_can_delete_school_after_children_removed(): void
    {
        $actor    = $this->actor();
        $schoolId = $this->schools()->create(['name' => 'S', 'code' => 'S'], $actor)['id'];
        $facId    = $this->faculties()->create(['school_id' => $schoolId, 'name' => 'F', 'code' => 'F'], $actor)['id'];
        $this->faculties()->delete($facId, $actor);

        $this->schools()->delete($schoolId, $actor);
        $this->assertSame(0, $this->schools()->list([], $actor)['meta']['total']);
    }

    public function test_cannot_delete_academic_year_with_term_db_restrict(): void
    {
        $ids = $this->seedHierarchy();
        // academic_years/terms are hard-deleted; the FK RESTRICT is the guard,
        // surfaced by the service's blockingChildren check first.
        $this->expectException(ConflictException::class);
        $this->years()->delete($ids['yearId'], $this->actor());
    }

    // ─── Teachers & students ──────────────────────────────────────────────────

    public function test_teacher_profile_links_user_and_attaches_role(): void
    {
        $ids    = $this->seedHierarchy();
        $userId = $this->makeUser('teacher@x.test');

        $teacher = $this->teachers()->create([
            'user_id' => $userId, 'department_id' => $ids['departmentId'], 'employee_number' => 'E-100',
        ], $this->actor());

        $this->assertSame($userId, $teacher['user_id']);
        $role = $this->pdo->query("SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = {$userId}")->fetch();
        $this->assertSame('TEACHER', $role['name']);
        $this->assertSame(1, $this->auditCount('USER_ROLE_ATTACHED'));
    }

    public function test_teacher_rejects_unknown_or_inactive_user(): void
    {
        $ids = $this->seedHierarchy();
        try {
            $this->teachers()->create(['user_id' => 999, 'department_id' => $ids['departmentId'], 'employee_number' => 'E1'], $this->actor());
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user_id', $e->getErrors());
        }

        $inactive = $this->makeUser('inactive@x.test');
        $this->pdo->exec("UPDATE users SET is_active = 0 WHERE id = {$inactive}");
        $this->expectException(ValidationException::class);
        $this->teachers()->create(['user_id' => $inactive, 'department_id' => $ids['departmentId'], 'employee_number' => 'E2'], $this->actor());
    }

    public function test_one_teacher_profile_per_user(): void
    {
        $ids    = $this->seedHierarchy();
        $userId = $this->makeUser('t@x.test');
        $this->teachers()->create(['user_id' => $userId, 'department_id' => $ids['departmentId'], 'employee_number' => 'E1'], $this->actor());

        $this->expectException(ValidationException::class);
        $this->teachers()->create(['user_id' => $userId, 'department_id' => $ids['departmentId'], 'employee_number' => 'E2'], $this->actor());
    }

    public function test_duplicate_employee_number_rejected(): void
    {
        $ids = $this->seedHierarchy();
        $u1  = $this->makeUser('t1@x.test');
        $u2  = $this->makeUser('t2@x.test');
        $this->teachers()->create(['user_id' => $u1, 'department_id' => $ids['departmentId'], 'employee_number' => 'SAME'], $this->actor());

        $this->expectException(ValidationException::class);
        $this->teachers()->create(['user_id' => $u2, 'department_id' => $ids['departmentId'], 'employee_number' => 'SAME'], $this->actor());
    }

    public function test_student_profile_links_user_and_attaches_role(): void
    {
        $ids    = $this->seedHierarchy();
        $userId = $this->makeUser('student@x.test');

        $student = $this->students()->create([
            'user_id' => $userId, 'program_id' => $ids['programId'],
            'student_number' => 'S-1', 'enrollment_year' => 2025,
        ], $this->actor());

        $this->assertSame(2025, $student['enrollment_year']);
        $role = $this->pdo->query("SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = {$userId}")->fetch();
        $this->assertSame('STUDENT', $role['name']);
    }

    public function test_student_enrollment_year_range_enforced(): void
    {
        $ids    = $this->seedHierarchy();
        $userId = $this->makeUser('s@x.test');
        $this->expectException(ValidationException::class);
        $this->students()->create([
            'user_id' => $userId, 'program_id' => $ids['programId'],
            'student_number' => 'S1', 'enrollment_year' => 12,
        ], $this->actor());
    }

    // ─── Pagination & filters ─────────────────────────────────────────────────

    public function test_list_paginates_and_filters(): void
    {
        $actor    = $this->actor();
        $schoolId = $this->schools()->create(['name' => 'S', 'code' => 'S'], $actor)['id'];
        $other    = $this->schools()->create(['name' => 'O', 'code' => 'O'], $actor)['id'];
        for ($i = 1; $i <= 7; $i++) {
            $this->faculties()->create(['school_id' => $schoolId, 'name' => "F{$i}", 'code' => "F{$i}"], $actor);
        }
        $this->faculties()->create(['school_id' => $other, 'name' => 'ZZ', 'code' => 'ZZ'], $actor);

        $page1 = $this->faculties()->list(['school_id' => $schoolId, 'per_page' => 5, 'page' => 1], $actor);
        $this->assertCount(5, $page1['data']);
        $this->assertSame(7, $page1['meta']['total']);
        $this->assertSame(2, $page1['meta']['total_pages']);

        $filtered = $this->faculties()->list(['school_id' => $other], $actor);
        $this->assertSame(1, $filtered['meta']['total']);

        $searched = $this->faculties()->list(['search' => 'F3'], $actor);
        $this->assertSame(1, $searched['meta']['total']);
    }
}
