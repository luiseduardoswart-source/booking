<?php

namespace Tests\Unit;

use App\Enums\IdentityStatus;
use App\Services\StripeIdentityVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\Identity\VerificationSession;
use Tests\TestCase;

/**
 * The Stripe Identity status translated to our status. A rejected
 * document must not stay on "pending".
 */
class StripeIdentityVerifierTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, IdentityStatus}> */
    public static function sessions(): array
    {
        return [
            'successful' => [['status' => 'verified', 'last_error' => null], IdentityStatus::Verified],
            'rejected document' => [
                ['status' => 'requires_input', 'last_error' => ['code' => 'document_unverified_other']],
                IdentityStatus::Failed,
            ],
            'not yet completed' => [['status' => 'requires_input', 'last_error' => null], IdentityStatus::Pending],
            'being processed' => [['status' => 'processing', 'last_error' => null], IdentityStatus::Pending],
            'cancelled' => [['status' => 'canceled', 'last_error' => null], IdentityStatus::Failed],
        ];
    }

    #[DataProvider('sessions')]
    public function test_status_of_stripe(array $session, IdentityStatus $expected): void
    {
        $this->assertSame(
            $expected,
            (new StripeIdentityVerifier)->statusOf(VerificationSession::constructFrom($session)),
        );
    }
}
