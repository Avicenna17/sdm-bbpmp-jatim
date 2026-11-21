<?php

namespace App\Domain\Pak\Authorization;

use App\Models\PakCase;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class PakEvidenceAuthorization
{
    public static function allows(?User $actor, bool $upload = false): bool
    {
        foreach ($upload ? ['pak.case.view', 'pak.skp.view', 'pak.case.update', 'pak.skp.manage'] : ['pak.case.view', 'pak.skp.view'] as $permission) {
            if (! PakEmployeeAuthorization::allows($actor, $permission)) {
                return false;
            }
        }

        return true;
    }

    public static function authorize(?User $actor, bool $upload = false): void
    {
        abort_unless(self::allows($actor, $upload), 403);
    }

    public static function requireDraft(PakCase $case): void
    {
        if ($case->status !== 'DRAFT' || $case->finalized_at !== null) {
            throw ValidationException::withMessages(['evidence' => 'Dokumen hanya dapat ditambahkan pada draf kasus.']);
        }
    }
}
