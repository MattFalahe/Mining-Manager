<?php

namespace MiningManager\Tests\Unit;

use MiningManager\Services\Configuration\SettingsCredentials;
use PHPUnit\Framework\TestCase;

class SettingsCredentialsTest extends TestCase
{
    private const PASSPHRASE = 'correct horse battery';

    private const CREDENTIALS = [
        'settings' => [
            ['key' => 'janice_api_key', 'value' => 'janice-test-key-0123', 'type' => 'string', 'corporation_id' => null],
        ],
        'webhooks' => [
            ['name' => 'Moon pings', 'corporation_id' => 98000001, 'webhook_url' => 'https://discord.example/api/webhooks/1/test-token', 'custom_headers' => ['Authorization' => 'Bearer test'], 'custom_payload_template' => null],
        ],
    ];

    public function test_the_passphrase_gives_back_exactly_what_was_locked(): void
    {
        $sealed = SettingsCredentials::seal(self::CREDENTIALS, self::PASSPHRASE);

        $this->assertSame(self::CREDENTIALS, SettingsCredentials::open($sealed, self::PASSPHRASE));
    }

    public function test_the_block_survives_the_trip_through_a_json_file(): void
    {
        $sealed = json_decode(json_encode(SettingsCredentials::seal(self::CREDENTIALS, self::PASSPHRASE)), true);

        $this->assertSame(self::CREDENTIALS, SettingsCredentials::open($sealed, self::PASSPHRASE));
    }

    public function test_a_wrong_passphrase_opens_nothing(): void
    {
        $sealed = SettingsCredentials::seal(self::CREDENTIALS, self::PASSPHRASE);

        $this->assertNull(SettingsCredentials::open($sealed, 'correct horse batterY'));
        $this->assertNull(SettingsCredentials::open($sealed, ''));
    }

    public function test_nothing_locked_can_be_read_in_the_file(): void
    {
        $file = json_encode(SettingsCredentials::seal(self::CREDENTIALS, self::PASSPHRASE));

        foreach (['janice-test-key-0123', 'discord.example', 'test-token', 'Bearer', 'janice_api_key', 'Moon pings'] as $secret) {
            $this->assertStringNotContainsString($secret, $file, $secret);
        }
    }

    public function test_a_changed_block_opens_nothing(): void
    {
        $sealed = SettingsCredentials::seal(self::CREDENTIALS, self::PASSPHRASE);
        $data = base64_decode($sealed['data']);
        $data[0] = chr(ord($data[0]) ^ 1);
        $sealed['data'] = base64_encode($data);

        $this->assertNull(SettingsCredentials::open($sealed, self::PASSPHRASE));
    }

    public function test_every_export_locks_with_a_salt_of_its_own(): void
    {
        $first = SettingsCredentials::seal(self::CREDENTIALS, self::PASSPHRASE);
        $second = SettingsCredentials::seal(self::CREDENTIALS, self::PASSPHRASE);

        $this->assertNotSame($first['salt'], $second['salt']);
        $this->assertNotSame($first['data'], $second['data']);
        $this->assertSame(SettingsCredentials::ROUNDS, $first['rounds']);
    }

    public function test_a_block_asking_for_too_many_rounds_is_refused_unread(): void
    {
        $sealed = SettingsCredentials::seal(self::CREDENTIALS, self::PASSPHRASE);
        $sealed['rounds'] = SettingsCredentials::MAX_ROUNDS + 1;

        $this->assertSame('damaged', $this->outcome($sealed));
    }

    public function test_a_block_that_is_not_one_seal_wrote_is_refused(): void
    {
        $sealed = SettingsCredentials::seal(self::CREDENTIALS, self::PASSPHRASE);

        $this->assertSame('damaged', $this->outcome([]));
        $this->assertSame('damaged', $this->outcome(['data' => 'not base64 at all!'] + $sealed));
        $this->assertSame('damaged', $this->outcome(['cipher' => 'aes-128-cbc'] + $sealed));
        $this->assertSame('damaged', $this->outcome(['rounds' => '600000'] + $sealed));
        $this->assertSame('damaged', $this->outcome(['iv' => base64_encode('short')] + $sealed));
    }

    private function outcome(array $sealed): string
    {
        try {
            return SettingsCredentials::open($sealed, self::PASSPHRASE) === null ? 'not opened' : 'opened';
        } catch (\InvalidArgumentException $e) {
            return 'damaged';
        }
    }
}
