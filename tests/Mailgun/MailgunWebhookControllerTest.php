<?php

declare(strict_types=1);

namespace Tests\Mailgun;

use EzPhp\Events\EventDispatcher;
use EzPhp\Events\EventInterface;
use EzPhp\Http\Request;
use EzPhp\MailEvents\MailEvent;
use EzPhp\MailEvents\MailEventType;
use EzPhp\MailEvents\Mailgun\MailgunEventParser;
use EzPhp\MailEvents\Mailgun\MailgunSignature;
use EzPhp\MailEvents\Mailgun\MailgunWebhookController;
use EzPhp\MailEvents\Payload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class MailgunWebhookControllerTest
 *
 * @package Tests\Mailgun
 */
#[CoversClass(MailgunWebhookController::class)]
#[UsesClass(MailgunSignature::class)]
#[UsesClass(MailgunEventParser::class)]
#[UsesClass(MailEvent::class)]
#[CoversClass(Payload::class)]
final class MailgunWebhookControllerTest extends TestCase
{
    private const string KEY = 'signing-key';

    /**
     * @var list<MailEvent>
     */
    private array $dispatched = [];

    public function test_dispatches_a_verified_event(): void
    {
        $response = $this->controller()(self::request(self::body(['event' => 'delivered', 'recipient' => 'a@example.com', 'timestamp' => 1])));

        self::assertSame(200, $response->status());
        self::assertCount(1, $this->dispatched);
        self::assertSame(MailEventType::Delivered, $this->dispatched[0]->type);
    }

    public function test_rejects_a_bad_signature_without_dispatching(): void
    {
        $body = self::body(['event' => 'delivered', 'recipient' => 'a@example.com', 'timestamp' => 1]);
        $body['signature']['token'] = 'tampered';

        $response = $this->controller()(self::request($body));

        self::assertSame(401, $response->status());
        self::assertSame([], $this->dispatched);
    }

    public function test_missing_signature_is_rejected(): void
    {
        self::assertSame(401, $this->controller()(new Request('POST', '/', rawBody: '{"event-data":{}}'))->status());
    }

    public function test_answers_406_for_unparseable_bodies(): void
    {
        $incomplete = self::body(['event' => 'delivered']);

        self::assertSame(406, $this->controller()(new Request('POST', '/', rawBody: 'not json'))->status());
        self::assertSame(406, $this->controller()(self::request($incomplete))->status());
        self::assertSame([], $this->dispatched);
    }

    /**
     * @return MailgunWebhookController
     */
    private function controller(): MailgunWebhookController
    {
        $events = new EventDispatcher();
        $events->listen(MailEvent::class, function (EventInterface $event): void {
            self::assertInstanceOf(MailEvent::class, $event);
            $this->dispatched[] = $event;
        });

        return new MailgunWebhookController(new MailgunSignature(self::KEY), new MailgunEventParser(), $events);
    }

    /**
     * @param array<string, mixed> $eventData
     *
     * @return array{signature: array{timestamp: string, token: string, signature: string}, event-data: array<string, mixed>}
     */
    private static function body(array $eventData): array
    {
        $timestamp = (string) time();

        return [
            'signature' => [
                'timestamp' => $timestamp,
                'token' => 'tok',
                'signature' => hash_hmac('sha256', $timestamp . 'tok', self::KEY),
            ],
            'event-data' => $eventData,
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return Request
     */
    private static function request(array $body): Request
    {
        return new Request('POST', '/webhooks/mail/mailgun', rawBody: (string) json_encode($body));
    }
}
