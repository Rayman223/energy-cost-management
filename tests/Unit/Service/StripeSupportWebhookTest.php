<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Repository\SupportRepository;
use App\Service\StripeSupportWebhook;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class StripeSupportWebhookTest extends TestCase
{
    private const SECRET = 'whsec_unit_test_secret';

    public function testRejectsInvalidAndExpiredSignatures(): void
    {
        $service = $this->service();
        $body = '{"id":"evt_1","type":"unknown","livemode":false,"data":{"object":{}}}';
        self::assertFalse($service->handle($body, 't=' . time() . ',v1=' . str_repeat('0', 64)));
        self::assertFalse($service->handle($body, $this->signature($body, time() - 301)));
    }

    public function testRejectsEventsFromTheOtherStripeMode(): void
    {
        $body = '{"id":"evt_1","type":"unknown","livemode":true,"data":{"object":{}}}';
        self::assertFalse($this->service()->handle($body, $this->signature($body)));
    }

    public function testIgnoresSessionsFromOtherPaymentLinks(): void
    {
        $body = json_encode([
            'id' => 'evt_1', 'type' => 'checkout.session.completed', 'created' => time(), 'livemode' => false,
            'data' => ['object' => [
                'id' => 'cs_1', 'payment_intent' => 'pi_1', 'payment_link' => 'plink_other',
                'payment_status' => 'paid', 'amount_total' => 500, 'currency' => 'eur',
            ]],
        ], JSON_THROW_ON_ERROR);
        self::assertTrue($this->service()->handle($body, $this->signature($body)));
    }

    private function service(): StripeSupportWebhook
    {
        /** Aucun appel repository n'est attendu dans ces tests de rejet/filtrage. */
        $repository = (new ReflectionClass(SupportRepository::class))->newInstanceWithoutConstructor();
        return new StripeSupportWebhook($repository, [
            'stripe_webhook_secret' => self::SECRET,
            'stripe_mode' => 'test',
            'stripe_payment_link_id' => 'plink_expected',
        ]);
    }

    private function signature(string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);
    }
}
