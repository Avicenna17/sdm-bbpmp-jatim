<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\DTOs\ConversionAssessment;
use App\Domain\Pak\DTOs\ConversionAssessmentRequest;
use App\Models\PakCase;
use App\Models\PakConversionRun;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** OD-06 read only: no lifecycle mutation, saveability gate, retry or audit writer. */
final class ConversionAssessmentService
{
    public function assess(User $actor, ConversionAssessmentRequest $request): ConversionAssessment
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new LogicException('Conversion assessment requires ownership of a top-level snapshot.');
        }
        foreach (ConversionService::PERMISSIONS as $permission) {
            Access::authorize($actor, $permission);
        }
        $this->requireSnapshotIsolation($connection);

        // Target, case and every semantic read belong to this one snapshot, never public preview().
        return $connection->transaction(function () use ($request): ConversionAssessment {
            $run = PakConversionRun::where('pak_case_id', $request->pak_case_id)->find($request->run_id);
            $case = PakCase::find($request->pak_case_id);
            if ($run === null || $case === null) {
                throw ValidationException::withMessages(['run' => 'Invalid conversion assessment target.']);
            }

            return app(ConversionAssessmentBuilder::class)->build($case, $run, app(ConversionPreviewDependencies::class));
        });
    }

    private function requireSnapshotIsolation(Connection $connection): void
    {
        // Same read-snapshot requirement as accepted preview, without refactoring that frozen flow.
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new LogicException('Unsupported conversion assessment snapshot connection.');
        }
        $pdo = $connection->getPdo();
        try {
            $isolation = $pdo->query('SELECT @@session.transaction_isolation')->fetchColumn();
        } catch (\PDOException $error) {
            if (($error->errorInfo[1] ?? null) !== 1193) {
                throw $error;
            }
            $isolation = $pdo->query('SELECT @@session.tx_isolation')->fetchColumn();
        }
        if (! is_string($isolation) || strtoupper(str_replace('-', ' ', trim($isolation))) !== 'REPEATABLE READ') {
            throw new LogicException('Conversion assessment requires a repeatable-read connection.');
        }
    }
}
