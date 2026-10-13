<?php

declare(strict_types=1);

namespace App\Repository\Contract;

interface ReleaseAnnouncementRepositoryInterface
{
    /** @return list<string> */
    public function sentVersions(): array;

    /** @param list<string> $versions */
    public function markSent(array $versions): void;

    /**
     * Serialize publishers, including the network request and its DB receipt.
     * @param callable(): array<string, mixed> $publish
     * @return array<string, mixed>
     */
    public function withPublishLock(callable $publish): array;
}
