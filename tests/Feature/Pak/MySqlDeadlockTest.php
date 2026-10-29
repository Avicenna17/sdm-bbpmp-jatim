<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Services\SkpCommitRetryPolicy;
use App\Support\Database\MySqlDeadlock;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MySqlDeadlockTest extends TestCase
{
    public static function codes(): array
    {
        return [
            [['40001', 1213], true], [['40001', '1213'], true],
            [['40001', 1062], false], [['40001', 1205], false],
            [['HY000', 1213], false], [['HY000', 1205], false],
            [['23000', 1062], false], [[40001, 1213], false],
            [['40001'], false], [[], false], [null, false],
        ];
    }

    #[DataProvider('codes')]
    public function test_exact_predicate_and_only_approved_wrappers(?array $info, bool $expected): void
    {
        $pdo = new \PDOException('deadlock text is not authority');
        $pdo->errorInfo = $info;
        $error = new QueryException('testing', 'synthetic', [], $pdo);
        foreach ([$error, new DeadlockException('wrapper', 0, $error),
            new DeadlockException('wrapper', 0, new DeadlockException('inner', 0, $error))] as $wrapped) {
            $this->assertSame($expected, MySqlDeadlock::allows($wrapped));
            $this->assertSame($expected, (new SkpCommitRetryPolicy)->allowsDeadlock($wrapped));
        }
        $this->assertFalse(MySqlDeadlock::allows(new RuntimeException('unrelated', 0, $error)));
    }

    public function test_message_or_class_alone_is_not_a_deadlock(): void
    {
        $this->assertFalse(MySqlDeadlock::allows(new RuntimeException('deadlock 40001 1213')));
        $this->assertFalse(MySqlDeadlock::allows(new DeadlockException('deadlock')));
    }
}
