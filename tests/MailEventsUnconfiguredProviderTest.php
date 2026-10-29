<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Application\Application;
use EzPhp\Contracts\RouterInterface;
use EzPhp\MailEvents\MailEventsServiceProvider;
use EzPhp\MailEvents\Mailgun\MailgunSignature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\MailEvents\ApplicationTestCase;

/**
 * Class MailEventsUnconfiguredProviderTest
 *
 * No keys (or an empty path): no routes, and the verifier fails closed.
 *
 * @package Tests
 */
#[CoversClass(MailEventsServiceProvider::class)]
#[UsesClass(MailgunSignature::class)]
final class MailEventsUnconfiguredProviderTest extends ApplicationTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function mailEventsConfig(): array
    {
        return [
            'tolerance' => 'invalid',
            'mailgun' => ['signing_key' => 'key', 'path' => ''],
            'sendgrid' => ['public_key' => ''],
        ];
    }

    /**
     * @param Application $app
     *
     * @return void
     */
    protected function configureApplication(Application $app): void
    {
        $app->register(MailEventsServiceProvider::class);
    }

    public function test_registers_no_route_without_key_or_path(): void
    {
        self::assertSame([], $this->app()->make(RouterInterface::class)->toCache());
    }

    public function test_falls_back_to_the_default_tolerance(): void
    {
        $verifier = $this->app()->make(MailgunSignature::class);
        $stale = (string) (time() - 301);
        $fresh = (string) (time() - 200);

        self::assertFalse($verifier->verify($stale, 't', hash_hmac('sha256', $stale . 't', 'key')));
        self::assertTrue($verifier->verify($fresh, 't', hash_hmac('sha256', $fresh . 't', 'key')));
    }
}
