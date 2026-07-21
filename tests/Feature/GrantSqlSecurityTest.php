<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Enums\Privilege;
use DbVault\Models\RequestGrant;
use DbVault\Services\ProvisionerService;
use DbVault\Tests\TestCase;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * buildGrantSql() assembles GRANT statements that are executed on the
 * privileged admin connection. It must NEVER emit SQL for:
 *   - a forbidden privilege (DROP/TRIGGER),
 *   - a privilege outside the allowed set,
 *   - a database/table/column name that is not a plain SQL identifier.
 * The last guards against second-order SQL injection via a crafted request.
 */
class GrantSqlSecurityTest extends TestCase
{
    private function provisioner(): ProvisionerService
    {
        return $this->app->make(ProvisionerService::class);
    }

    private function grant(array $attributes): RequestGrant
    {
        return new RequestGrant(array_merge([
            'table_name' => 'orders',
            'column_name' => null,
            'privilege' => Privilege::Select->value,
        ], $attributes));
    }

    public function test_valid_grant_builds_create_user_and_scoped_grant(): void
    {
        $sql = $this->provisioner()->buildGrantSql(
            new Collection([$this->grant(['privilege' => Privilege::Select->value])]),
            'appdb',
            'dbv_user_req1'
        );

        $this->assertStringContainsString("CREATE USER 'dbv_user_req1'@'%'", $sql[0]);
        $this->assertStringContainsString('GRANT SELECT ON `appdb`.`orders`', $sql[1]);
    }

    public function test_column_scoped_grant_escapes_the_column(): void
    {
        $sql = $this->provisioner()->buildGrantSql(
            new Collection([$this->grant(['column_name' => 'ssn'])]),
            'appdb',
            'dbv_user_req1'
        );

        $this->assertStringContainsString('ON `appdb`.`orders` (`ssn`)', $sql[1]);
    }

    #[DataProvider('maliciousIdentifiers')]
    public function test_malicious_table_name_is_refused(string $table): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->provisioner()->buildGrantSql(
            new Collection([$this->grant(['table_name' => $table])]),
            'appdb',
            'dbv_user_req1'
        );
    }

    #[DataProvider('maliciousColumnIdentifiers')]
    public function test_malicious_column_name_is_refused(string $column): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->provisioner()->buildGrantSql(
            new Collection([$this->grant(['column_name' => $column])]),
            'appdb',
            'dbv_user_req1'
        );
    }

    #[DataProvider('maliciousIdentifiers')]
    public function test_malicious_database_name_is_refused(string $database): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->provisioner()->buildGrantSql(
            new Collection([$this->grant([])]),
            $database,
            'dbv_user_req1'
        );
    }

    public static function maliciousIdentifiers(): array
    {
        return [
            'grant-all breakout' => ["orders` ON *.* TO 'x'@'%'; -- "],
            'backtick + drop user' => ['orders`; DROP USER `x'],
            'stacked statement' => ['orders; GRANT ALL ON *.* TO x'],
            'space' => ['bad name'],
            'quote' => ["o'rders"],
            'dot qualified' => ['db.orders'],
            'empty' => [''],
        ];
    }

    /**
     * Same as maliciousIdentifiers minus the empty case: an empty/null column
     * legitimately means "table-level grant, no column scope", so it is not an
     * injection vector and is not rejected.
     */
    public static function maliciousColumnIdentifiers(): array
    {
        $cases = self::maliciousIdentifiers();
        unset($cases['empty']);

        return $cases;
    }

    public function test_forbidden_privilege_is_structurally_refused(): void
    {
        // Defence in depth: the Privilege enum cast rejects "DROP" at the model
        // boundary (ValueError), and buildGrantSql() independently refuses any
        // forbidden privilege that reaches it. Either way, no SQL is produced.
        $this->expectException(\Throwable::class);

        $grants = new Collection([$this->grant(['privilege' => 'DROP'])]);

        $this->provisioner()->buildGrantSql($grants, 'appdb', 'dbv_user_req1');
    }

}
