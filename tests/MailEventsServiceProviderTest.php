<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Application\Application;
use EzPhp\Contracts\RouterInterface;
use EzPhp\Events\EventDispatcher;
use EzPhp\Events\EventInterface;
use EzPhp\Events\EventServiceProvider;
use EzPhp\Http\Request;
use EzPhp\MailEvents\MailEvent;
use EzPhp\MailEvents\MailEventsServiceProvider;
use EzPhp\MailEvents\Mailgun\MailgunEventParser;
use EzPhp\MailEvents\Mailgun\MailgunSignature;
use EzPhp\MailEvents\Mailgun\MailgunWebhookController;
use EzPhp\MailEvents\Payload;
use EzPhp\MailEvents\Postmark\PostmarkAuthenticator;
use EzPhp\MailEvents\Postmark\PostmarkEventParser;
use EzPhp\MailEvents\Postmark\PostmarkWebhookController;
use EzPhp\MailEvents\SendGrid\SendGridEventParser;
use EzPhp\MailEvents\SendGrid\SendGridSignature;
use EzPhp\MailEvents\SendGrid\SendGridWebhookController;
use EzPhp\MailEvents\Ses\SesEventParser;
use EzPhp\MailEvents\Ses\SesWebhookController;
use EzPhp\MailEvents\Ses\SnsMessageVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\MailEvents\ApplicationTestCase;
use Tests\SendGrid\SendGridKeyPair;

/**
 * Class MailEventsServiceProviderTest
 *
 * Both providers configured: routes registered, CSRF-exempt, end to end.
 *
 * @package Tests
 */
#[CoversClass(MailEventsServiceProvider::class)]
#[UsesClass(MailgunSignature::class)]
#[UsesClass(MailgunEventParser::class)]
#[UsesClass(MailgunWebhookController::class)]
#[UsesClass(SendGridSignature::class)]
#[UsesClass(SendGridEventParser::class)]
#[UsesClass(SendGridWebhookController::class)]
#[UsesClass(MailEvent::class)]
#[UsesClass(Payload::class)]
#[UsesClass(PostmarkAuthenticator::class)]
#[UsesClass(PostmarkEventParser::class)]
#[UsesClass(PostmarkWebhookController::class)]
#[UsesClass(SnsMessageVerifier::class)]
#[UsesClass(SesEventParser::class)]
#[UsesClass(SesWebhookController::class)]
final class MailEventsServiceProviderTest extends ApplicationTestCase
{
    private const string MAILGUN_KEY = 'mg-signing-key';

    private static ?SendGridKeyPair $keys = null;

    /**
     * @return array<string, mixed>
     */
    protected function mailEventsConfig(): array
    {
        return [
            'tolerance' => 120,
            'mailgun' => ['signing_key' => self::MAILGUN_KEY, 'path' => '/hooks/mailgun'],
            'sendgrid' => ['public_key' => self::keys()->publicBase64(), 'path' => '/hooks/sendgrid'],
            'postmark' => ['username' => 'hook', 'password' => 's3cret', 'path' => '/hooks/postmark'],
            'ses' => ['topic_arn' => 'arn:aws:sns:eu-central-1:123456789012:ses-events', 'path' => '/hooks/ses'],
        ];
    }

    /**
     * @param Application $app
     *
     * @return void
     */
    protected function configureApplication(Application $app): void
    {
        $app->register(EventServiceProvider::class);
        $app->register(MailEventsServiceProvider::class);
    }

    public function test_registers_csrf_exempt_post_routes_for_configured_providers(): void
    {
        $routes = array_map(
            fn (array $route): array => [$route['method'], $route['path'], $route['handler'][0], $route['csrfExempt']],
            $this->app()->make(RouterInterface::class)->toCache(),
        );

        self::assertContains(['POST', '/hooks/mailgun', MailgunWebhookController::class, true], $routes);
        self::assertContains(['POST', '/hooks/sendgrid', SendGridWebhookController::class, true], $routes);
        self::assertContains(['POST', '/hooks/postmark', PostmarkWebhookController::class, true], $routes);
        self::assertContains(['POST', '/hooks/ses', SesWebhookController::class, true], $routes);
    }

    public function test_a_signed_mailgun_webhook_reaches_listeners_through_the_app(): void
    {
        $seen = [];
        $this->app()->make(EventDispatcher::class)->listen(MailEvent::class, function (EventInterface $event) use (&$seen): void {
            self::assertInstanceOf(MailEvent::class, $event);
            $seen[] = $event->recipient;
        });
        $timestamp = (string) time();
        $body = (string) json_encode([
            'signature' => ['timestamp' => $timestamp, 'token' => 't', 'signature' => hash_hmac('sha256', $timestamp . 't', self::MAILGUN_KEY)],
            'event-data' => ['event' => 'delivered', 'recipient' => 'a@example.com', 'timestamp' => 1],
        ]);

        $response = $this->app()->handle(new Request('POST', '/hooks/mailgun', rawBody: $body));

        self::assertSame(200, $response->status());
        self::assertSame(['a@example.com'], $seen);
    }

    public function test_an_authenticated_postmark_webhook_reaches_listeners_through_the_app(): void
    {
        $seen = [];
        $this->app()->make(EventDispatcher::class)->listen(MailEvent::class, function (EventInterface $event) use (&$seen): void {
            self::assertInstanceOf(MailEvent::class, $event);
            $seen[] = $event->recipient;
        });
        $body = '{"RecordType":"Open","Recipient":"c@example.com","ReceivedAt":"2026-10-30T10:00:00Z"}';

        $response = $this->app()->handle(new Request('POST', '/hooks/postmark', headers: [
            'Authorization' => 'Basic ' . base64_encode('hook:s3cret'),
        ], rawBody: $body));

        self::assertSame(200, $response->status());
        self::assertSame(['c@example.com'], $seen);
    }

    public function test_an_unsigned_ses_message_is_rejected_through_the_app(): void
    {
        $body = (string) json_encode(['Type' => 'Notification', 'TopicArn' => 'arn:aws:sns:eu-central-1:123456789012:ses-events', 'Message' => '{}']);

        self::assertSame(403, $this->app()->handle(new Request('POST', '/hooks/ses', rawBody: $body))->status());
    }

    public function test_a_signed_sendgrid_webhook_reaches_listeners_through_the_app(): void
    {
        $seen = [];
        $this->app()->make(EventDispatcher::class)->listen(MailEvent::class, function (EventInterface $event) use (&$seen): void {
            self::assertInstanceOf(MailEvent::class, $event);
            $seen[] = $event->recipient;
        });
        $timestamp = (string) time();
        $body = '[{"email":"b@example.com","timestamp":1,"event":"open"}]';

        $response = $this->app()->handle(new Request('POST', '/hooks/sendgrid', headers: [
            SendGridSignature::TIMESTAMP_HEADER => $timestamp,
            SendGridSignature::SIGNATURE_HEADER => self::keys()->sign($timestamp, $body),
        ], rawBody: $body));

        self::assertSame(200, $response->status());
        self::assertSame(['b@example.com'], $seen);
    }

    /**
     * One key pair per class: the config is written before bootstrap, the tests sign afterwards.
     *
     * @return SendGridKeyPair
     */
    private static function keys(): SendGridKeyPair
    {
        return self::$keys ??= SendGridKeyPair::generate();
    }
}
