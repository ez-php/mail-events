<?php

declare(strict_types=1);

namespace Tests\Postmark;

use EzPhp\Events\EventDispatcher;
use EzPhp\Events\EventInterface;
use EzPhp\Http\Request;
use EzPhp\MailEvents\MailEvent;
use EzPhp\MailEvents\MailEventsException;
use EzPhp\MailEvents\MailEventType;
use EzPhp\MailEvents\Payload;
use EzPhp\MailEvents\Postmark\PostmarkAuthenticator;
use EzPhp\MailEvents\Postmark\PostmarkEventParser;
use EzPhp\MailEvents\Postmark\PostmarkWebhookController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Postmark: Basic-auth check, RecordType mapping, controller status codes.
 *
 * @package Tests\Postmark
 */
#[CoversClass(PostmarkAuthenticator::class)]
#[CoversClass(PostmarkEventParser::class)]
#[CoversClass(PostmarkWebhookController::class)]
#[CoversClass(Payload::class)]
#[UsesClass(MailEvent::class)]
final class PostmarkWebhookTest extends TestCase
{
    /**
     * @var list<MailEvent>
     */
    private array $dispatched = [];

    public function test_basic_credentials_must_match_the_configured_pair(): void
    {
        $auth = new PostmarkAuthenticator('hook', 's3cret');

        self::assertTrue($auth->verify('Basic ' . base64_encode('hook:s3cret')));
        self::assertFalse($auth->verify('Basic ' . base64_encode('hook:wrong')));
        self::assertFalse($auth->verify(null));
        self::assertFalse((new PostmarkAuthenticator('', ''))->verify('Basic ' . base64_encode(':')));
    }

    /**
     * @return array<string, array{array<string, mixed>, MailEventType, string}>
     */
    public static function records(): array
    {
        return [
            'delivery' => [['RecordType' => 'Delivery', 'Recipient' => 'a@example.com', 'DeliveredAt' => '2026-10-30T10:00:00Z', 'MessageID' => 'm-1', 'Details' => 'OK'], MailEventType::Delivered, 'a@example.com'],
            'hard bounce' => [['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'b@example.com', 'BouncedAt' => '2026-10-30T10:00:00Z', 'ID' => 42, 'Description' => 'Mailbox unknown'], MailEventType::Bounced, 'b@example.com'],
            'soft bounce' => [['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => 'c@example.com', 'BouncedAt' => '2026-10-30T10:00:00Z'], MailEventType::Deferred, 'c@example.com'],
            'complaint' => [['RecordType' => 'SpamComplaint', 'Email' => 'd@example.com', 'BouncedAt' => '2026-10-30T10:00:00Z'], MailEventType::Complained, 'd@example.com'],
            'open' => [['RecordType' => 'Open', 'Recipient' => 'e@example.com', 'ReceivedAt' => '2026-10-30T10:00:00Z'], MailEventType::Opened, 'e@example.com'],
            'click' => [['RecordType' => 'Click', 'Recipient' => 'f@example.com', 'ReceivedAt' => '2026-10-30T10:00:00Z', 'OriginalLink' => 'https://example.com/x'], MailEventType::Clicked, 'f@example.com'],
            'unsubscribe' => [['RecordType' => 'SubscriptionChange', 'Recipient' => 'g@example.com', 'ChangedAt' => '2026-10-30T10:00:00Z', 'SuppressSending' => true, 'SuppressionReason' => 'ManualSuppression'], MailEventType::Unsubscribed, 'g@example.com'],
            'resubscribe' => [['RecordType' => 'SubscriptionChange', 'Recipient' => 'g@example.com', 'ChangedAt' => '2026-10-30T10:00:00Z', 'SuppressSending' => false], MailEventType::Other, 'g@example.com'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('records')]
    public function test_record_types_map_onto_mail_event_types(array $body, MailEventType $type, string $recipient): void
    {
        $event = (new PostmarkEventParser())->parse($body);

        self::assertSame([$type, $recipient, 'postmark', '2026-10-30T10:00:00+00:00'], [$event->type, $event->recipient, $event->provider, $event->occurredAt->format('c')]);
    }

    public function test_ids_reason_and_link_are_kept(): void
    {
        $parser = new PostmarkEventParser();
        $bounce = $parser->parse(self::records()['hard bounce'][0]);
        $click = $parser->parse(self::records()['click'][0]);

        self::assertSame(['42', 'Mailbox unknown'], [$bounce->eventId, $bounce->reason]);
        self::assertSame('https://example.com/x', $click->url);
    }

    public function test_incomplete_records_are_rejected(): void
    {
        $this->expectException(MailEventsException::class);

        (new PostmarkEventParser())->parse(['RecordType' => 'Delivery', 'Recipient' => 'a@example.com', 'DeliveredAt' => 'yesterday-ish?']);
    }

    public function test_controller_answers_401_400_and_dispatches(): void
    {
        $authorized = ['Authorization' => 'Basic ' . base64_encode('hook:s3cret')];
        $body = (string) json_encode(self::records()['delivery'][0]);

        self::assertSame(401, $this->controller()(new Request('POST', '/', rawBody: $body))->status());
        self::assertSame(400, $this->controller()(new Request('POST', '/', headers: $authorized, rawBody: '{"RecordType":"Delivery"}'))->status());
        self::assertSame([], $this->dispatched);

        $response = $this->controller()(new Request('POST', '/', headers: $authorized, rawBody: $body));

        self::assertSame(200, $response->status());
        self::assertSame([MailEventType::Delivered], array_map(static fn (MailEvent $e): MailEventType => $e->type, $this->dispatched));
    }

    private function controller(): PostmarkWebhookController
    {
        $events = new EventDispatcher();
        $events->listen(MailEvent::class, function (EventInterface $event): void {
            self::assertInstanceOf(MailEvent::class, $event);
            $this->dispatched[] = $event;
        });

        return new PostmarkWebhookController(new PostmarkAuthenticator('hook', 's3cret'), new PostmarkEventParser(), $events);
    }
}
