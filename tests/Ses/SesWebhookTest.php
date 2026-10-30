<?php

declare(strict_types=1);

namespace Tests\Ses;

use EzPhp\Events\EventDispatcher;
use EzPhp\Events\EventInterface;
use EzPhp\Http\Request;
use EzPhp\MailEvents\MailEvent;
use EzPhp\MailEvents\MailEventsException;
use EzPhp\MailEvents\MailEventType;
use EzPhp\MailEvents\Payload;
use EzPhp\MailEvents\Ses\SesEventParser;
use EzPhp\MailEvents\Ses\SesWebhookController;
use EzPhp\MailEvents\Ses\SnsMessageVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Amazon SES via SNS: signature/topic/cert-host checks, subscription confirmation,
 * per-recipient event mapping.
 *
 * @package Tests\Ses
 */
#[CoversClass(SnsMessageVerifier::class)]
#[CoversClass(SesEventParser::class)]
#[CoversClass(SesWebhookController::class)]
#[CoversClass(Payload::class)]
#[UsesClass(MailEvent::class)]
final class SesWebhookTest extends TestCase
{
    private SesSnsSigner $signer;

    /**
     * @var list<string>
     */
    private array $fetched = [];

    /**
     * @var list<MailEvent>
     */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->signer = SesSnsSigner::generate();
    }

    private function verifier(string $topic = SesSnsSigner::TOPIC): SnsMessageVerifier
    {
        return new SnsMessageVerifier($topic, function (string $url): string {
            $this->fetched[] = $url;

            return $url === SesSnsSigner::CERT_URL ? $this->signer->certificate : 'subscribed';
        });
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, string>
     */
    private function notification(array $event, string $version = '2'): array
    {
        return $this->signer->sign(['Type' => 'Notification', 'Message' => (string) json_encode($event)], $version);
    }

    public function test_signed_messages_of_both_signature_versions_verify_and_the_cert_is_fetched_once(): void
    {
        $verifier = $this->verifier();
        $event = ['eventType' => 'Open', 'mail' => ['timestamp' => '2026-10-30T10:00:00Z', 'destination' => ['a@example.com']]];

        self::assertTrue($verifier->verify($this->notification($event, '1')));
        self::assertTrue($verifier->verify($this->notification($event, '2')));
        self::assertSame([SesSnsSigner::CERT_URL], $this->fetched);
    }

    public function test_tampered_foreign_or_misdirected_messages_are_rejected(): void
    {
        $valid = $this->notification(['eventType' => 'Open']);

        $tampered = [...$valid, 'Message' => '{"eventType":"Bounce"}'];
        $otherTopic = $valid;
        $badCertHost = [...$valid, 'SigningCertURL' => 'https://sns.evil.example.com/cert.pem'];
        $plainHttp = [...$valid, 'SigningCertURL' => 'http://sns.eu-central-1.amazonaws.com/cert.pem'];
        $unknownVersion = [...$valid, 'SignatureVersion' => '3'];

        self::assertFalse($this->verifier()->verify($tampered));
        self::assertFalse($this->verifier('arn:aws:sns:eu-central-1:999999999999:other')->verify($otherTopic));
        self::assertFalse($this->verifier()->verify($badCertHost));
        self::assertFalse($this->verifier()->verify($plainHttp));
        self::assertFalse($this->verifier()->verify($unknownVersion));
        self::assertFalse($this->verifier('')->verify($valid));
        self::assertNotContains('https://sns.evil.example.com/cert.pem', $this->fetched);
    }

    public function test_a_bounce_yields_one_event_per_recipient(): void
    {
        $events = (new SesEventParser())->parse([
            'eventType' => 'Bounce',
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'General',
                'timestamp' => '2026-10-30T10:05:00.000Z',
                'bouncedRecipients' => [
                    ['emailAddress' => 'a@example.com', 'diagnosticCode' => 'smtp; 550 5.1.1 user unknown'],
                    ['emailAddress' => 'b@example.com'],
                ],
            ],
            'mail' => ['timestamp' => '2026-10-30T10:00:00.000Z', 'messageId' => 'ses-msg-1', 'destination' => ['a@example.com', 'b@example.com']],
        ], 'sns-1');

        self::assertCount(2, $events);
        self::assertSame(['a@example.com', 'b@example.com'], [$events[0]->recipient, $events[1]->recipient]);
        self::assertSame(MailEventType::Bounced, $events[0]->type);
        self::assertSame(['sns-1:a@example.com', 'ses-msg-1', 'smtp; 550 5.1.1 user unknown'], [$events[0]->eventId, $events[0]->messageId, $events[0]->reason]);
        self::assertSame('2026-10-30T10:05:00+00:00', $events[0]->occurredAt->format('c'));
    }

    public function test_event_types_map(): void
    {
        $parser = new SesEventParser();
        $mail = ['timestamp' => '2026-10-30T10:00:00Z', 'destination' => ['a@example.com']];
        $cases = [
            [['eventType' => 'Delivery', 'mail' => $mail, 'delivery' => ['recipients' => ['a@example.com'], 'timestamp' => '2026-10-30T10:00:01Z']], MailEventType::Delivered],
            [['eventType' => 'Bounce', 'mail' => $mail, 'bounce' => ['bounceType' => 'Transient', 'bouncedRecipients' => [['emailAddress' => 'a@example.com']]]], MailEventType::Deferred],
            [['eventType' => 'Complaint', 'mail' => $mail, 'complaint' => ['complainedRecipients' => [['emailAddress' => 'a@example.com']]]], MailEventType::Complained],
            [['eventType' => 'DeliveryDelay', 'mail' => $mail, 'deliveryDelay' => ['delayedRecipients' => [['emailAddress' => 'a@example.com']], 'timestamp' => '2026-10-30T10:00:01Z']], MailEventType::Deferred],
            [['eventType' => 'Reject', 'mail' => $mail, 'reject' => ['reason' => 'Bad content']], MailEventType::Dropped],
            [['eventType' => 'Click', 'mail' => $mail, 'click' => ['link' => 'https://example.com', 'timestamp' => '2026-10-30T10:00:01Z']], MailEventType::Clicked],
            [['eventType' => 'Subscription', 'mail' => $mail], MailEventType::Unsubscribed],
            [['notificationType' => 'Send', 'mail' => $mail], MailEventType::Other],
        ];

        foreach ($cases as [$event, $type]) {
            self::assertSame($type, $parser->parse($event)[0]->type, (string) json_encode($event));
        }
    }

    public function test_events_without_recipients_or_type_are_rejected(): void
    {
        $this->expectException(MailEventsException::class);

        (new SesEventParser())->parse(['eventType' => 'Open', 'mail' => ['timestamp' => '2026-10-30T10:00:00Z', 'destination' => []]]);
    }

    public function test_controller_confirms_subscriptions_and_dispatches_notifications(): void
    {
        $confirmation = $this->signer->sign([
            'Type' => 'SubscriptionConfirmation',
            'Message' => 'You have chosen to subscribe…',
            'Token' => 'tok',
            'SubscribeURL' => 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&Token=tok',
        ]);

        self::assertSame(200, $this->controller()(self::request($confirmation))->status());
        self::assertContains('https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&Token=tok', $this->fetched);

        $delivery = $this->notification(['eventType' => 'Delivery', 'mail' => ['timestamp' => '2026-10-30T10:00:00Z', 'destination' => ['a@example.com']], 'delivery' => ['recipients' => ['a@example.com', 'b@example.com']]]);
        self::assertSame(200, $this->controller()(self::request($delivery))->status());
        self::assertCount(2, $this->dispatched);

        $notSes = $this->signer->sign(['Type' => 'Notification', 'Message' => 'plain text']);
        self::assertSame(200, $this->controller()(self::request($notSes))->status());
        self::assertCount(2, $this->dispatched);
    }

    public function test_controller_rejects_unverified_and_unparseable_requests(): void
    {
        $forged = [...$this->notification(['eventType' => 'Open']), 'Message' => '{"eventType":"Bounce"}'];

        self::assertSame(403, $this->controller()(self::request($forged))->status());
        self::assertSame(400, $this->controller()(new Request('POST', '/', rawBody: 'not json'))->status());
        self::assertSame([], $this->dispatched);
    }

    public function test_subscribe_urls_outside_aws_are_never_visited(): void
    {
        $confirmation = $this->signer->sign([
            'Type' => 'SubscriptionConfirmation',
            'Message' => 'x',
            'Token' => 'tok',
            'SubscribeURL' => 'https://evil.example.com/confirm',
        ]);

        self::assertSame(502, $this->controller()(self::request($confirmation))->status());
        self::assertNotContains('https://evil.example.com/confirm', $this->fetched);
    }

    /**
     * @param array<string, string> $message
     */
    private static function request(array $message): Request
    {
        return new Request('POST', '/webhooks/mail/ses', headers: ['Content-Type' => 'text/plain'], rawBody: (string) json_encode($message));
    }

    private function controller(): SesWebhookController
    {
        $events = new EventDispatcher();
        $events->listen(MailEvent::class, function (EventInterface $event): void {
            self::assertInstanceOf(MailEvent::class, $event);
            $this->dispatched[] = $event;
        });

        return new SesWebhookController($this->verifier(), new SesEventParser(), $events);
    }
}
