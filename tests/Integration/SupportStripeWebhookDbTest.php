<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\SupportRepository;
use App\Repository\UserRepository;
use App\Service\StripeSupportWebhook;

final class SupportStripeWebhookDbTest extends DatabaseTestCase
{
    private const SECRET = 'whsec_support_integration';
    private const REFERENCE_KEY = 'support_integration_reference_key';

    protected function clean(): void
    {
        foreach (['support_adjustments', 'support_donations', 'support_members'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
        $this->pdo()->exec("DELETE FROM users WHERE oidc_iss = 'https://support-webhook.test'");
    }

    public function testOnlyPaidSessionsGrantPrivateBadgesAndReplayDoesNotRestoreRevokedBadge(): void
    {
        $user = (new UserRepository($this->pdo()))->create('https://support-webhook.test', 'payer', 'test', 'Payer');
        $repo = new SupportRepository($this->pdo());
        $event = $this->paymentEvent('paid', $this->reference($user->id));
        $event['data']['object']['payment_status'] = 'unpaid';
        self::assertTrue($this->deliver($event));
        self::assertSame(0, $repo->publicSummary(2026, 'test')['stripe_cents']);
        self::assertFalse($repo->member($user->id, 'test')['badge']);
        $event['type'] = 'checkout.session.async_payment_succeeded';
        $event['id'] = 'evt_paid_async';
        $event['data']['object']['payment_status'] = 'paid';
        self::assertTrue($this->deliver($event));
        self::assertSame(['badge' => true, 'public' => false], $repo->member($user->id, 'test'));
        self::assertSame([], $repo->publicSummary(2026, 'test')['supporters']);
        $repo->adminSetBadge($user->id, false, 'test');
        self::assertTrue($this->deliver($event));
        self::assertSame(1000, $repo->publicSummary(2026, 'test')['stripe_cents']);
        self::assertFalse($repo->member($user->id, 'test')['badge']);
    }

    public function testForgedDeletedBlockedAndMissingReferencesProduceAnonymousDonations(): void
    {
        $users = new UserRepository($this->pdo());
        $active = $users->create('https://support-webhook.test', 'active', 'test', 'Active');
        $blocked = $users->create('https://support-webhook.test', 'blocked', 'test', 'Blocked');
        $deleted = $users->create('https://support-webhook.test', 'deleted', 'test', 'Deleted');
        $this->pdo()->prepare("UPDATE users SET status = 'blocked' WHERE id = ?")->execute([$blocked->id]);
        $this->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$deleted->id]);
        $references = ['', $active->id . '.' . str_repeat('0', 64), $this->reference($blocked->id), $this->reference($deleted->id)];
        foreach ($references as $index => $reference) {
            self::assertTrue($this->deliver($this->paymentEvent('anonymous-' . $index, $reference)));
        }
        self::assertSame(4, (int) $this->pdo()->query('SELECT COUNT(*) FROM support_donations WHERE user_id IS NULL')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM support_members')->fetchColumn());
    }

    public function testNonEuroDonationsAreRetainedWithoutChangingTheEuroGauge(): void
    {
        $event = $this->paymentEvent('usd');
        $event['data']['object']['currency'] = 'usd';
        self::assertTrue($this->deliver($event));
        self::assertSame(0, (new SupportRepository($this->pdo()))->publicSummary(2026, 'test')['stripe_cents']);
        self::assertSame('usd', $this->pdo()->query('SELECT currency FROM support_donations')->fetchColumn());
    }

    public function testRefundAndClosedDisputeBeforePaymentAreAppliedOnceAndStayClosed(): void
    {
        $event = $this->paymentEvent('adjustments');
        $refund = $event;
        $refund['id'] = 'evt_refund';
        $refund['type'] = 'charge.refunded';
        $refund['data']['object'] = ['id' => 'ch_adjustments', 'payment_intent' => 'pi_adjustments', 'amount_refunded' => 250];
        $dispute = $event;
        $dispute['id'] = 'evt_dispute_won';
        $dispute['type'] = 'charge.dispute.closed';
        $dispute['data']['object'] = ['id' => 'dp_adjustments', 'payment_intent' => 'pi_adjustments', 'amount' => 1000, 'status' => 'won'];
        self::assertTrue($this->deliver($refund));
        self::assertTrue($this->deliver($dispute));
        self::assertTrue($this->deliver($event));
        $dispute['id'] = 'evt_dispute_open';
        $dispute['type'] = 'charge.dispute.created';
        self::assertTrue($this->deliver($dispute));
        self::assertTrue($this->deliver($refund));
        self::assertSame(750, (new SupportRepository($this->pdo()))->publicSummary(2026, 'test')['stripe_cents']);
    }

    public function testUnrelatedPaymentLinkCannotMakePendingAdjustmentsPublic(): void
    {
        $event = $this->paymentEvent('unrelated');
        $refund = $event;
        $refund['id'] = 'evt_unrelated_refund';
        $refund['type'] = 'charge.refunded';
        $refund['data']['object'] = ['id' => 'ch_unrelated', 'payment_intent' => 'pi_unrelated', 'amount_refunded' => 500];
        self::assertTrue($this->deliver($refund));
        $event['data']['object']['payment_link'] = 'plink_other';
        self::assertTrue($this->deliver($event));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM support_donations')->fetchColumn());
        self::assertSame(0, (new SupportRepository($this->pdo()))->publicSummary(2026, 'test')['stripe_cents']);
    }

    private function reference(int $userId): string
    {
        return $userId . '.' . hash_hmac('sha256', (string) $userId, self::REFERENCE_KEY);
    }

    /** @return array<string, mixed> */
    private function paymentEvent(string $name, string $reference = ''): array
    {
        return [
            'id' => 'evt_' . $name, 'type' => 'checkout.session.completed',
            'created' => strtotime('2026-10-01 12:00:00 UTC'), 'livemode' => false,
            'data' => ['object' => [
                'id' => 'cs_' . $name, 'payment_intent' => 'pi_' . $name, 'payment_link' => 'plink_support_test',
                'payment_status' => 'paid', 'amount_total' => 1000, 'currency' => 'eur',
                'client_reference_id' => $reference,
            ]],
        ];
    }

    /** @param array<string, mixed> $event */
    private function deliver(array $event): bool
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);
        return (new StripeSupportWebhook(new SupportRepository($this->pdo()), [
            'stripe_webhook_secret' => self::SECRET, 'stripe_mode' => 'test',
            'stripe_payment_link_id' => 'plink_support_test', 'reference_signing_key' => self::REFERENCE_KEY,
        ]))->handle($body, $signature);
    }
}
