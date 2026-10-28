<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\DTOs\ConversionPreview;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ConversionService
{
    public const PERMISSIONS = ['pak.conversion.calculate', 'pak.conversion.view', 'pak.case.view', 'pak.case.update', 'pak.skp.view', 'pak.employee.view', 'pak.rule-set.view'];

    public function preview(User $actor, ConversionPreviewRequest $request): ConversionPreview
    {
        $connection = DB::connection();
        $this->requireTopLevel($connection);
        foreach (self::PERMISSIONS as $permission) {
            Access::authorize($actor, $permission);
        }
        $this->requireTopLevel($connection);
        $this->requireSnapshotIsolation($connection);

        // All semantic reads use the default connection and its owned top-level snapshot.
        return $connection->transaction(fn () => app(ConversionOrchestrator::class)->build($request, app(ConversionPreviewDependencies::class)));
    }

    private function requireTopLevel(Connection $connection): void
    {
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Conversion preview requires ownership of a top-level transaction.');
        }
    }

    private function requireSnapshotIsolation(Connection $connection): void
    {
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new LogicException('Unsupported conversion preview snapshot connection.');
        }
        // getPdo() is the WRITE PDO used by beginTransaction, never a read replica.
        $pdo = $connection->getPdo();
        try {
            $isolation = $pdo->query('SELECT @@session.transaction_isolation')->fetchColumn();
        } catch (\PDOException $e) {
            // MariaDB 10.5 exposes tx_isolation; fall back only for unknown variable.
            if (($e->errorInfo[1] ?? null) !== 1193) {
                throw $e;
            }
            $isolation = $pdo->query('SELECT @@session.tx_isolation')->fetchColumn();
        }
        if (! is_string($isolation) || strtoupper(str_replace('-', ' ', trim($isolation))) !== 'REPEATABLE READ') {
            throw new LogicException('Conversion preview requires a repeatable-read connection.');
        }
    }
}
