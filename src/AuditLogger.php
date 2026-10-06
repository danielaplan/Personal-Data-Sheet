<?php

declare(strict_types=1);

namespace Pds;

use InvalidArgumentException;
use PDO;

final class AuditLogger
{
    private const ACTIONS = ['login_success', 'login_failure', 'create', 'update', 'archive', 'restore'];
    private const SENSITIVE = '/password|token|csrf|umid|pagibig|philhealth|philsys|tin|payload|username|source[_-]?address/i';

    public function __construct(private readonly PDO $pdo) {}

    public function record(?int $staffId, ?int $pdsId, string $action, array $metadata = []): void
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Unknown audit action.');
        }
        $this->rejectSensitiveKeys($metadata);
        if (str_starts_with($action, 'login_') && $metadata !== []) {
            throw new InvalidArgumentException('Login audit metadata must be empty.');
        }
        foreach ($metadata as $key => $value) {
            if (!in_array($key, ['from_version', 'to_version', 'version'], true) || !is_int($value) || $value < 1) {
                throw new InvalidArgumentException('Audit metadata accepts positive version numbers only.');
            }
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (staff_user_id, pds_id, action, metadata) VALUES (?, ?, ?, ?)',
        );
        $statement->execute([
            $staffId, $pdsId, $action,
            $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }

    private function rejectSensitiveKeys(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (preg_match(self::SENSITIVE, (string) $key)) {
                throw new InvalidArgumentException('Sensitive audit metadata is prohibited.');
            }
            if (is_array($value)) {
                $this->rejectSensitiveKeys($value);
            }
        }
    }
}
