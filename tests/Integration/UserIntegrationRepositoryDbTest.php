<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\UserIntegrationRepository;
use App\Repository\UserRepository;

/**
 * Opt-in générique des connecteurs d'export (#70). S'auto-skippe sans base de test
 * joignable.
 */
final class UserIntegrationRepositoryDbTest extends DatabaseTestCase
{
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserRepository($this->pdo()))->create('https://iss.test', 'uid', 'test', 'UI Tester')->id;
    }

    protected function clean(): void
    {
        foreach (['user_integrations', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    public function testEnableDisablePatchAndList(): void
    {
        $repo = new UserIntegrationRepository($this->pdo());

        self::assertNull($repo->get($this->userId, 'energyid'));
        self::assertSame([], $repo->listEnabledUsers('energyid'));

        $repo->enable($this->userId, 'energyid', ['device_id' => 'dev-u' . $this->userId]);
        $state = $repo->get($this->userId, 'energyid');
        self::assertNotNull($state);
        self::assertTrue($state['enabled']);
        self::assertSame('dev-u' . $this->userId, $state['settings']['device_id']);
        self::assertArrayNotHasKey('claimed_at', $state['settings']);

        $enabled = $repo->listEnabledUsers('energyid');
        self::assertCount(1, $enabled);
        self::assertSame($this->userId, $enabled[0]['user_id']);
        self::assertSame('dev-u' . $this->userId, $enabled[0]['settings']['device_id']);

        // patchSettings pose claimed_at sans écraser device_id.
        $repo->patchSettings($this->userId, 'energyid', ['claimed_at' => '2026-07-18 01:15:00']);
        $patched = $repo->get($this->userId, 'energyid');
        self::assertNotNull($patched);
        self::assertSame('2026-07-18 01:15:00', $patched['settings']['claimed_at']);
        self::assertSame('dev-u' . $this->userId, $patched['settings']['device_id']);

        // Ré-enable réapplique le device sans effacer claimed_at (JSON_MERGE_PATCH).
        $repo->enable($this->userId, 'energyid', ['device_id' => 'dev-u' . $this->userId]);
        $reEnabled = $repo->get($this->userId, 'energyid');
        self::assertNotNull($reEnabled);
        self::assertSame('2026-07-18 01:15:00', $reEnabled['settings']['claimed_at']);

        $repo->disable($this->userId, 'energyid');
        $disabled = $repo->get($this->userId, 'energyid');
        self::assertNotNull($disabled);
        self::assertFalse($disabled['enabled']);
        self::assertSame([], $repo->listEnabledUsers('energyid'));
    }
}
