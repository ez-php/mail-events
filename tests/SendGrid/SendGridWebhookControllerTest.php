<?php

declare(strict_types=1);

namespace Tests\SendGrid;

use EzPhp\Events\EventDispatcher;
use EzPhp\Events\EventInterface;
use EzPhp\Http\Request;
use EzPhp\MailEvents\MailEvent;
use EzPhp\MailEvents\MailEventType;
use EzPhp\MailEvents\Payload;
use EzPhp\MailEvents\SendGrid\SendGridEventParser;
use EzPhp\MailEvents\SendGrid\SendGridSignature;
use EzPhp\MailEvents\SendGrid\SendGridWebhookController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class SendGridWebhookControllerTest
 *
 * @package Tests\SendGrid
 */
#[CoversClass(SendGridWebhookController::class)]
#[UsesClass(SendGridSignature::class)]
#[UsesClass(SendGridEventParser::class)]
#[UsesClass(MailEvent::class)]
#[CoversClass(Payload::class)]
final class SendGridWebhookControllerTest extends TestCase
{
    private SendGridKeyPair $keys;

    /**
     * @var list<MailEvent>
     */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->keys = SendGridKeyPair::generate();
    }

    public function test_dispatches_every_event_of_a_verified_batch_and_skips_invalid_entries(): void
    {
        $body = (string) json_encode([
            ['email' => 'a@example.com', 'timestamp' => 1, 'event' => 'delivered'],
            ['email' => 'b@example.com', 'event' => 'bounce'],
            'not an object',
            ['email' => 'c@example.com', 'timestamp' => 2, 'event' => 'spamreport'],
        ]);

        $response = $this->controller()($this->signed($body));

        self::assertSame(200, $response->status());
        self::assertSame(
            [MailEventType::Delivered, MailEventType::Complained],
            array_map(fn (MailEvent $event): MailEventType => $event->type, $this->dispatched),
        );
    }

    public function test_rejects_missing_or_invalid_signatures(): void
    {
        $body = '[{"email":"a@example.com","timestamp":1,"event":"delivered"}]';
        $forged = $this->signed($body);
        $tampered = new Request('POST', '/', headers: $forged->headers(), rawBody: str_replace('delivered', 'bounce', $body));

        self::assertSame(401, $this->controller()(new Request('POST', '/', rawBody: $body))->status());
        self::assertSame(401, $this->controller()($tampered)->status());
        self::assertSame([], $this->dispatched);
    }

    public function test_answers_400_when_the_signed_body_is_not_a_json_array(): void
    {
        self::assertSame(400, $this->controller()($this->signed('not json'))->status());
        self::assertSame(400, $this->controller()($this->signed('{"event":"delivered"}'))->status());
    }

    /**
     * @return SendGridWebhookController
     */
    private function controller(): SendGridWebhookController
    {
        $events = new EventDispatcher();
        $events->listen(MailEvent::class, function (EventInterface $event): void {
            self::assertInstanceOf(MailEvent::class, $event);
            $this->dispatched[] = $event;
        });

        return new SendGridWebhookController(
            new SendGridSignature($this->keys->publicBase64()),
            new SendGridEventParser(),
            $events,
        );
    }

    /**
     * @param string $body
     *
     * @return Request
     */
    private function signed(string $body): Request
    {
        $timestamp = (string) time();

        return new Request('POST', '/webhooks/mail/sendgrid', headers: [
            SendGridSignature::TIMESTAMP_HEADER => $timestamp,
            SendGridSignature::SIGNATURE_HEADER => $this->keys->sign($timestamp, $body),
        ], rawBody: $body);
    }
}
