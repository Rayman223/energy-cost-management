<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SupportRepository;
use App\Support\SupportReference;

/** Valide puis normalise les événements Stripe liés au Payment Link de soutien. */
final class StripeSupportWebhook
{
    /** @param array<string, mixed> $supportConfig */
    public function __construct(private readonly SupportRepository $repository, private readonly array $supportConfig) {}

    public function handle(string $body, string $signatureHeader): bool
    {
        if (strlen($body) > 262144 || !$this->validSignature($body, $signatureHeader)) return false;
        $event = json_decode($body, true);
        if (!is_array($event) || !is_array($event['data']['object'] ?? null)) return false;
        $mode = $this->supportConfig['stripe_mode'] ?? 'test';
        $paymentLinkId = (string) ($this->supportConfig['stripe_payment_link_id'] ?? '');
        if (!in_array($mode, ['test', 'live'], true) || !str_starts_with($paymentLinkId, 'plink_')) return false;
        $expectedLive = $mode === 'live';
        if (($event['livemode'] ?? null) !== $expectedLive) return false;
        $type = (string) ($event['type'] ?? '');
        $eventCreated = (int) ($event['created'] ?? 0);
        $object = $event['data']['object'];
        $eventId = (string) ($event['id'] ?? '');
        if ($eventId === '' || $eventCreated <= 0) return false;

        if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            if (($object['payment_status'] ?? '') !== 'paid'
                || (string) ($object['payment_link'] ?? '') !== $paymentLinkId) return true;
            $sessionId = (string) ($object['id'] ?? '');
            $paymentIntent = (string) ($object['payment_intent'] ?? '');
            if ($sessionId === '' || $paymentIntent === '' || (int) ($object['amount_total'] ?? 0) <= 0
                || preg_match('/^[a-z]{3}$/i', (string) ($object['currency'] ?? '')) !== 1) return true;
            $userId = SupportReference::userId((string) ($object['client_reference_id'] ?? ''), (string) ($this->supportConfig['reference_signing_key'] ?? ''));
            $this->repository->recordPayment($sessionId, $paymentIntent, $userId,
                (int) ($object['amount_total'] ?? 0), (string) ($object['currency'] ?? ''),
                (int) gmdate('Y', $eventCreated), $mode);
            return true;
        }

        if ($type === 'charge.refunded' || $type === 'charge.dispute.created' || $type === 'charge.dispute.closed') {
            $intent = $object['payment_intent'] ?? null;
            if (!is_string($intent) || $intent === '') return true;
            $amount = (int) ($object['amount'] ?? 0);
            if ($type === 'charge.refunded') {
                $amount = -(int) ($object['amount_refunded'] ?? 0);
                $ref = 'refund:' . (string) ($object['id'] ?? '');
                $state = 'refunded';
            } else {
                $status = $type === 'charge.dispute.created' ? 'open' : (string) ($object['status'] ?? 'lost');
                $ref = 'dispute:' . (string) ($object['id'] ?? '');
                $amount = in_array($status, ['won', 'warning_closed'], true) ? 0 : -$amount;
                $state = $status;
            }
            if ($ref !== 'refund:' && $ref !== 'dispute:') $this->repository->recordAdjustment($eventId, $eventCreated, $intent, $ref, $amount, $state, $mode);
        }
        return true;
    }

    private function validSignature(string $body, string $header): bool
    {
        $secret = trim((string) ($this->supportConfig['stripe_webhook_secret'] ?? ''));
        if ($secret === '' || strlen($header) > 4096) return false;
        $timestamp = null; $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) $timestamp = (int) $value;
            if ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/i', $value) === 1) $signatures[] = $value;
        }
        if ($timestamp === null || abs(time() - $timestamp) > 300 || $signatures === []) return false;
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        foreach ($signatures as $signature) if (hash_equals($expected, $signature)) return true;
        return false;
    }
}
