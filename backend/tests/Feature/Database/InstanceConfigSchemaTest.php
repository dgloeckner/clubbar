<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

/**
 * The instance_id column backing ADR-0035 (terminal-backend pairing). A
 * terminal must be able to tell "the backend I'm talking to" apart from "a
 * backend with the same URL but a different, discontinuous history" — that
 * requires a value that already exists on any freshly-migrated schema and
 * is never silently rewritten by the ordinary config-update path.
 */
class InstanceConfigSchemaTest extends SchemaTestCase
{
    public function test_the_singleton_row_already_has_an_instance_id(): void
    {
        $row = $this->fetchInstanceConfig();

        $this->assertNotNull($row['instance_id']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $row['instance_id'],
        );
    }

    public function test_updating_instance_name_does_not_change_instance_id(): void
    {
        $original = $this->fetchInstanceConfig()['instance_name'];
        $before = $this->fetchInstanceConfig()['instance_id'];

        try {
            $stmt = $this->db->prepare('UPDATE instance_config SET instance_name = ? WHERE id = 1');
            $stmt->execute(['A New Club Name']);

            $after = $this->fetchInstanceConfig()['instance_id'];
            $this->assertSame($before, $after, 'instance_id must survive an ordinary config update untouched');
        } finally {
            $stmt = $this->db->prepare('UPDATE instance_config SET instance_name = ? WHERE id = 1');
            $stmt->execute([$original]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchInstanceConfig(): array
    {
        $stmt = $this->db->query('SELECT * FROM instance_config WHERE id = 1');
        return $stmt->fetch();
    }
}
