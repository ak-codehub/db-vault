<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Tests\TestCase;

class SchemaTablesTest extends TestCase
{
    public function test_it_returns_the_config_catalog_when_no_introspection_connection_is_set(): void
    {
        config()->set('dbvault.introspection_connection', null);
        config()->set('dbvault.browsable_tables', ['orders', 'customers', 'payments']);

        $developer = $this->makeUser('developer');

        $this->actingAs($developer, 'vault')
            ->getJson('/vault/api/requests/tables')
            ->assertOk()
            ->assertJsonPath('database', config('dbvault.target_database'))
            // Sorted, natural-case order.
            ->assertJsonPath('tables', ['customers', 'orders', 'payments']);
    }

    public function test_it_introspects_a_live_connection_when_configured(): void
    {
        // The test host runs on the in-memory sqlite "testing" connection,
        // where the vault_* tables have been migrated — introspection should
        // surface them (sqlite_% internal tables excluded).
        config()->set('dbvault.introspection_connection', 'testing');

        $developer = $this->makeUser('developer');

        $response = $this->actingAs($developer, 'vault')
            ->getJson('/vault/api/requests/tables')
            ->assertOk();

        $tables = $response->json('tables');

        $this->assertContains('vault_users', $tables);
        $this->assertContains('vault_access_requests', $tables);
        $this->assertNotContains('sqlite_sequence', $tables);
    }

    public function test_restricted_tables_are_hidden_from_live_introspection(): void
    {
        config()->set('dbvault.introspection_connection', 'testing');
        // Hide one exact table and a wildcard family.
        config()->set('dbvault.restricted_tables', ['vault_users', 'vault_audit_*']);

        $developer = $this->makeUser('developer');

        $tables = $this->actingAs($developer, 'vault')
            ->getJson('/vault/api/requests/tables')
            ->assertOk()
            ->json('tables');

        $this->assertNotContains('vault_users', $tables, 'exact denylist entry should be hidden');
        $this->assertNotContains('vault_audit_queries', $tables, 'wildcard denylist entry should be hidden');
        // Unrestricted tables still show.
        $this->assertContains('vault_roles', $tables);
    }

    public function test_restricted_tables_also_apply_to_the_fallback_catalog(): void
    {
        config()->set('dbvault.introspection_connection', null);
        config()->set('dbvault.browsable_tables', ['orders', 'customers', 'secrets']);
        config()->set('dbvault.restricted_tables', ['secrets']);

        $developer = $this->makeUser('developer');

        $this->actingAs($developer, 'vault')
            ->getJson('/vault/api/requests/tables')
            ->assertOk()
            ->assertJsonPath('tables', ['customers', 'orders']);
    }

    public function test_empty_browsable_tables_shows_all_live_tables(): void
    {
        config()->set('dbvault.introspection_connection', 'testing');
        config()->set('dbvault.browsable_tables', []); // empty => no allowlist
        config()->set('dbvault.restricted_tables', []);

        $developer = $this->makeUser('developer');

        $tables = $this->actingAs($developer, 'vault')
            ->getJson('/vault/api/requests/tables')
            ->assertOk()
            ->json('tables');

        // All vault_* tables surface (nothing filtered out).
        $this->assertContains('vault_users', $tables);
        $this->assertContains('vault_roles', $tables);
        $this->assertContains('vault_audit_queries', $tables);
    }

    public function test_browsable_tables_acts_as_an_allowlist_over_live_introspection(): void
    {
        config()->set('dbvault.introspection_connection', 'testing');
        // Only these two families may appear, even though the DB has many
        // more vault_* tables.
        config()->set('dbvault.browsable_tables', ['vault_users', 'vault_role*']);
        config()->set('dbvault.restricted_tables', []);

        $developer = $this->makeUser('developer');

        $tables = $this->actingAs($developer, 'vault')
            ->getJson('/vault/api/requests/tables')
            ->assertOk()
            ->json('tables');

        $this->assertContains('vault_users', $tables);
        $this->assertContains('vault_roles', $tables);       // matches vault_role*
        $this->assertContains('vault_role_user', $tables);   // matches vault_role*
        // Not in the allowlist -> excluded.
        $this->assertNotContains('vault_access_requests', $tables);
        $this->assertNotContains('vault_audit_queries', $tables);
    }

    public function test_wildcard_star_in_browsable_means_all(): void
    {
        config()->set('dbvault.introspection_connection', 'testing');
        config()->set('dbvault.browsable_tables', ['*']);
        config()->set('dbvault.restricted_tables', []);

        $developer = $this->makeUser('developer');

        $tables = $this->actingAs($developer, 'vault')
            ->getJson('/vault/api/requests/tables')
            ->assertOk()
            ->json('tables');

        $this->assertContains('vault_access_requests', $tables);
        $this->assertContains('vault_users', $tables);
    }

    public function test_a_disallowed_database_is_rejected(): void
    {
        $developer = $this->makeUser('developer');

        $this->actingAs($developer, 'vault')
            ->getJson('/vault/api/requests/tables?database=some_other_db')
            ->assertStatus(422);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/vault/api/requests/tables')->assertStatus(401);
    }
}
