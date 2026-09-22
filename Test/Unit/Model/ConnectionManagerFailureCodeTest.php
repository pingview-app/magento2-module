<?php
declare(strict_types=1);

namespace PingView\Monitoring\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use PingView\Monitoring\Model\ConnectionManager;

final class ConnectionManagerFailureCodeTest extends TestCase
{
    /**
     * The two codes that mean "this merchant already exists upstream": the
     * setup screen routes those to the API key form. Every other failure keeps
     * code 0, because retrying the same form is still the right move there.
     */
    public function testOnlyAnExistingAccountIsRoutedToTheApiKeyForm(): void
    {
        $expected = [
            'USER_EXISTS' => ConnectionManager::ACCOUNT_EXISTS,
            'DUPLICATE_MONITOR' => ConnectionManager::ACCOUNT_EXISTS,
            'VALIDATION_ERROR' => 0,
            'RATE_LIMIT_EXCEEDED' => 0,
            'INTERNAL_SERVER_ERROR' => 0,
            '' => 0,
        ];

        foreach ($expected as $code => $failureCode) {
            self::assertSame($failureCode, ConnectionManager::failureCode(['code' => $code]), $code);
        }
    }

    public function testMissingCodeIsNotTreatedAsAnExistingAccount(): void
    {
        // A transport failure never reaches the envelope, so there is no code.
        self::assertSame(0, ConnectionManager::failureCode([]));
    }
}
