<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Notifications\Services\EncryptionKeyEventMailBuilder;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Security\Repositories\SealedIbanRepository;
use App\Modules\Security\Services\EncryptionKeyService;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;
use Tests\Feature\DatabaseTestCase;

/**
 * A key swap leaves the panel by mail (ADR-0036).
 *
 * The gap: registering and activating a key is audited, and nothing reads the
 * audit log. The dashboard banner only speaks for a key that is missing or
 * expiring, so one activated today with 365 days on it is silent — and it names
 * the key by `key_identifier`, which whoever registered it typed in. An admin
 * who did not perform the action had no way to find out.
 *
 * Uses the real AdminNotifier rather than a double: what is being checked is
 * that a row actually lands in the outbox with the right kind and subject,
 * which a mock would assert about itself.
 */
class EncryptionKeyEventNoticeTest extends DatabaseTestCase
{
    private EncryptionKeyService $service;
    private EncryptionKeysRepository $keys;
    private AdminUsersRepository $admins;
    private EncryptionKeyEventMailBuilder $builder;
    private array $createdKeyIds = [];
    private string $adminId;
    private string $adminEmail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keys = new EncryptionKeysRepository($this->db, $this->logger);
        $admins = $this->admins = new AdminUsersRepository($this->db, $this->logger);

        // This test owns a recipient of its own rather than trusting whatever
        // the environment seeded. CI applies migrations without `seed.sql`, so
        // `findActiveRecipients()` there returned nobody with an address and
        // every "a notice was queued" assertion failed while the count
        // assertion passed against an expected zero — the shape of a test that
        // reports success for having found nothing.
        $this->adminId = $this->generateUuid();
        $this->adminEmail = 'key-notice-' . substr($this->adminId, 0, 8) . '@example.test';
        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
        )->execute([
            $this->adminId,
            $this->adminEmail,
            password_hash('not-used-' . $this->adminId, PASSWORD_DEFAULT),
            'Key Notice Recipient',
            'de',
        ]);
        $outbox = new MailOutboxRepository($this->db, $this->logger);

        $this->service = new EncryptionKeyService(
            $this->keys,
            new SealedIbanRepository($this->db),
            new IbanSealedBox(str_repeat('aa', 32), 'test'),
            $this->createMock(AuditService::class),
            new AdminNotifier($outbox, $admins, $this->createMock(AuditService::class)),
            $this->logger,
        );

        $this->builder = new EncryptionKeyEventMailBuilder(
            $this->keys,
            $admins,
            $this->mailConfigService(),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->createdKeyIds as $id) {
            $this->db->prepare('DELETE FROM mail_outbox WHERE subject_id = ?')->execute([$id]);
        }
        $this->cleanupTestData('encryption_keys', $this->createdKeyIds);
        $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$this->adminId]);

        parent::tearDown();
    }

    /** @return array{id: string, secret: string, public: string} */
    private function registerKey(): array
    {
        $keypair = sodium_crypto_box_keypair();
        $public = sodium_crypto_box_publickey($keypair);

        $dto = $this->service->register(
            base64_encode($public),
            'notice-key-' . $this->generateUuid(),
            null,
        );
        $this->createdKeyIds[] = $dto->id;

        return [
            'id' => $dto->id,
            'secret' => sodium_crypto_box_secretkey($keypair),
            'public' => $public,
        ];
    }

    /** @return array<int, array<string,mixed>> */
    private function outboxRowsFor(string $keyId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mail_outbox WHERE subject_id = ? ORDER BY queued_at, id');
        $stmt->execute([$keyId]);

        return $stmt->fetchAll();
    }

    public function test_registering_a_key_queues_a_notice(): void
    {
        $key = $this->registerKey();

        $rows = $this->outboxRowsFor($key['id']);
        $this->assertNotEmpty($rows, 'registering a key must tell the admins');

        foreach ($rows as $row) {
            $this->assertSame(MailKind::ENCRYPTION_KEY_REGISTERED->value, $row['kind']);
            $this->assertNotEmpty($row['recipient']);
        }

        $this->assertContains(
            $this->adminEmail,
            array_column($rows, 'recipient'),
            'the admin this test created must be among the recipients',
        );
    }

    public function test_activating_a_key_queues_a_separate_notice(): void
    {
        $key = $this->registerKey();
        $this->service->activate($key['id'], base64_encode($key['secret']), null);

        $kinds = array_column($this->outboxRowsFor($key['id']), 'kind');

        $this->assertContains(MailKind::ENCRYPTION_KEY_REGISTERED->value, $kinds);
        $this->assertContains(
            MailKind::ENCRYPTION_KEY_ACTIVATED->value,
            $kinds,
            'activation is the moment the key starts sealing IBANs and is its own message',
        );
    }

    /**
     * A refused activation must stay silent: announcing a swap that did not
     * happen would train admins to ignore the message that matters.
     */
    public function test_a_refused_activation_announces_nothing(): void
    {
        $key = $this->registerKey();
        $stranger = sodium_crypto_box_secretkey(sodium_crypto_box_keypair());

        try {
            $this->service->activate($key['id'], base64_encode($stranger), null);
            $this->fail('a foreign private key must not activate the key');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $kinds = array_column($this->outboxRowsFor($key['id']), 'kind');
        $this->assertNotContains(MailKind::ENCRYPTION_KEY_ACTIVATED->value, $kinds);
    }

    public function test_one_notice_per_admin_with_an_address(): void
    {
        $expected = 0;
        foreach ($this->admins->findActiveRecipients() as $admin) {
            if (trim((string) ($admin['email'] ?? '')) !== '') {
                $expected++;
            }
        }

        // setUp created one, so this can never be the vacuous "0 == 0" that
        // let this assertion pass in CI while the rest of the class failed.
        $this->assertGreaterThan(0, $expected);

        $key = $this->registerKey();

        $this->assertCount(
            $expected,
            $this->outboxRowsFor($key['id']),
            'the point of the message is reaching the admin who did not act, so every one gets a copy',
        );
    }

    /**
     * The fingerprint is the whole payload: comparing it against what the
     * offline generator printed is the only check that separates the club's own
     * key from somebody else's, and `key_identifier` cannot do that job because
     * whoever registered the key chose it.
     */
    public function test_the_message_carries_the_full_fingerprint_and_no_key_material(): void
    {
        $key = $this->registerKey();

        // This test's own recipient, not "whichever row came back first": the
        // set depends on how many admins the environment has.
        $row = null;
        foreach ($this->outboxRowsFor($key['id']) as $candidate) {
            if ($candidate['recipient'] === $this->adminEmail) {
                $row = $candidate;
                break;
            }
        }
        $this->assertNotNull($row, 'expected a queued notice to render');

        $message = $this->builder->build($row);
        $fingerprint = $this->keys->findById($key['id'])['fingerprint_sha256'];

        foreach ([$message->html, $message->text] as $body) {
            $this->assertStringContainsString($fingerprint, $body, 'the full fingerprint must be readable');
            $this->assertStringNotContainsString(base64_encode($key['secret']), $body);
            $this->assertStringNotContainsString(base64_encode($key['public']), $body);
        }
    }

    /**
     * The actor (migration 043) is a fact about who ran `register()`, stored
     * durably at enqueue time — distinct from the recipient, which is every
     * active admin in turn, including the actor's own copy.
     */
    public function test_the_registering_admin_is_recorded_as_actor_on_every_recipients_row(): void
    {
        $keypair = sodium_crypto_box_keypair();
        $dto = $this->service->register(
            base64_encode(sodium_crypto_box_publickey($keypair)),
            'notice-key-' . $this->generateUuid(),
            $this->adminId,
        );
        $this->createdKeyIds[] = $dto->id;

        $rows = $this->outboxRowsFor($dto->id);
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(
                $this->adminId,
                $row['actor_admin_user_id'],
                'every recipient row must name the same acting admin, including the recipient who acted',
            );
        }
    }

    /** The rendered message names the actor by display name and login when the row carries one. */
    public function test_the_message_names_the_actor_when_known(): void
    {
        $keypair = sodium_crypto_box_keypair();
        $dto = $this->service->register(
            base64_encode(sodium_crypto_box_publickey($keypair)),
            'notice-key-' . $this->generateUuid(),
            $this->adminId,
        );
        $this->createdKeyIds[] = $dto->id;

        $row = null;
        foreach ($this->outboxRowsFor($dto->id) as $candidate) {
            if ($candidate['recipient'] === $this->adminEmail) {
                $row = $candidate;
                break;
            }
        }
        $this->assertNotNull($row);

        $message = $this->builder->build($row);

        foreach ([$message->html, $message->text] as $body) {
            $this->assertStringContainsString('Key Notice Recipient', $body);
            $this->assertStringContainsString($this->adminEmail, $body);
        }
    }

    /**
     * A key event with no human actor — an action taken with `$adminId = null`
     * — must still queue and render; the actor line is simply absent, exactly
     * as a pre-migration row behaves.
     */
    public function test_a_notice_with_no_actor_still_renders(): void
    {
        $keypair = sodium_crypto_box_keypair();
        $dto = $this->service->register(
            base64_encode(sodium_crypto_box_publickey($keypair)),
            'notice-key-' . $this->generateUuid(),
            null,
        );
        $this->createdKeyIds[] = $dto->id;

        $row = null;
        foreach ($this->outboxRowsFor($dto->id) as $candidate) {
            if ($candidate['recipient'] === $this->adminEmail) {
                $row = $candidate;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertNull($row['actor_admin_user_id']);

        $message = $this->builder->build($row);
        $this->assertStringNotContainsString('Ausgeführt von', $message->text);
    }

    public function test_the_builder_claims_the_lifecycle_kinds_and_not_the_expiry_warning(): void
    {
        $this->assertTrue($this->builder->supports(MailKind::ENCRYPTION_KEY_REGISTERED));
        $this->assertTrue($this->builder->supports(MailKind::ENCRYPTION_KEY_ACTIVATED));
        $this->assertTrue($this->builder->supports(MailKind::ENCRYPTION_KEY_REVOKED));

        // Same subject type, different builder — CredentialExpiryMailBuilder
        // claims this one by naming it.
        $this->assertFalse($this->builder->supports(MailKind::KEY_EXPIRY_WARNING));
    }
}
