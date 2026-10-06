<?php

declare(strict_types=1);

namespace Pds\Tests\Integration;

use PDOException;
use Pds\Tests\Support\DatabaseTestCase;

final class PdsQueryTest extends DatabaseTestCase
{
    public function testSchemaCreatesAllTablesAndCoreConstraints(): void
    {
        $tables = $this->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertEqualsCanonicalizing(
            ['staff_users', 'login_throttles', 'pds_records', 'pds_children', 'pds_education', 'audit_log'],
            $tables,
        );

        $this->expectException(PDOException::class);
        $this->pdo()->exec("INSERT INTO pds_education (pds_id, level) VALUES (999999, 'college')");
    }

    public function testAgencyNumberAndEducationLevelAreUnique(): void
    {
        $staffId = $this->createStaffUser();
        $insert = $this->pdo()->prepare(
            'INSERT INTO pds_records (surname, first_name, birth_date, agency_employee_number, created_by, updated_by)
             VALUES (:surname, :first_name, :birth_date, :agency_employee_number, :created_by, :updated_by)',
        );
        $first = [
            'surname' => 'Lovelace',
            'first_name' => 'Ada',
            'birth_date' => '1815-12-10',
            'agency_employee_number' => 'AGENCY-001',
            'created_by' => $staffId,
            'updated_by' => $staffId,
        ];
        $insert->execute($first);
        $pdsId = (int) $this->pdo()->lastInsertId();

        $education = $this->pdo()->prepare('INSERT INTO pds_education (pds_id, level) VALUES (?, ?)');
        $education->execute([$pdsId, 'college']);
        try {
            $education->execute([$pdsId, 'college']);
            self::fail('Duplicate education level was accepted.');
        } catch (PDOException $exception) {
            self::assertSame('23000', $exception->getCode());
            self::assertSame(1062, $exception->errorInfo[1]);
        }

        try {
            $insert->execute(array_replace($first, ['surname' => 'Hopper', 'first_name' => 'Grace']));
            self::fail('Duplicate agency employee number was accepted.');
        } catch (PDOException $exception) {
            self::assertSame('23000', $exception->getCode());
            self::assertSame(1062, $exception->errorInfo[1]);
        }
    }
}
