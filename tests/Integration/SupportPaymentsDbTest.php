<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\SupportRepository;
use App\Repository\UserRepository;

/** Persistance et rapprochement des paiements liés à la collecte annuelle. */
final class SupportPaymentsDbTest extends DatabaseTestCase
{
    protected function clean(): void
    {
        $this->pdo()->exec('DELETE FROM support_adjustments');
        $this->pdo()->exec('DELETE FROM support_donations');
        $this->pdo()->exec('DELETE FROM support_members');
        $this->pdo()->exec("DELETE FROM users WHERE oidc_iss = 'https://support.test'");
    }

    public function testOutOfOrderRefundIsIdempotentAndManualRevocationSurvivesPaymentReplay(): void
    {
        $repo = new SupportRepository($this->pdo());
        $year = (int) gmdate('Y');
        $user = (new UserRepository($this->pdo()))->create('https://support.test', uniqid('support-', true), 'test', 'Support test');

        // L'événement de remboursement peut atteindre Stripe avant la session payée.
        $repo->recordAdjustment('evt_refund', time(), 'pi_support', 'refund:ch_support', -250, 'refunded');
        $repo->recordPayment('cs_support', 'pi_support', $user->id, 1000, 'eur', $year);
        $repo->recordAdjustment('evt_refund', time(), 'pi_support', 'refund:ch_support', -250, 'refunded');
        self::assertSame(750, $repo->publicSummary($year)['stripe_cents']);
        self::assertTrue($repo->member($user->id)['badge']);

        $repo->adminSetBadge($user->id, false);
        $repo->recordPayment('cs_support', 'pi_support', $user->id, 1000, 'eur', $year);
        self::assertFalse($repo->member($user->id)['badge']);
        self::assertSame(750, $repo->publicSummary($year)['stripe_cents']);
    }

    public function testDeletingAccountAnonymizesDonationAndRemovesPreferences(): void
    {
        $repo = new SupportRepository($this->pdo());
        $user = (new UserRepository($this->pdo()))->create('https://support.test', uniqid('support-', true), 'test', 'Support test');
        $repo->recordPayment('cs_erase', 'pi_erase', $user->id, 500, 'eur', (int) gmdate('Y'));
        $repo->setVisibility($user->id, true);
        $this->pdo()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $user->id]);

        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM support_members')->fetchColumn());
        $statement = $this->pdo()->prepare('SELECT user_id FROM support_donations WHERE payment_intent = :intent');
        $statement->execute(['intent' => 'pi_erase']);
        self::assertNull($statement->fetchColumn());
    }
}
