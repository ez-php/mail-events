<?php

declare(strict_types=1);

namespace EzPhp\MailEvents\Postmark;

use EzPhp\MailEvents\MailEvent;
use EzPhp\MailEvents\MailEventsException;
use EzPhp\MailEvents\MailEventType;
use EzPhp\MailEvents\Payload;

/**
 * Class PostmarkEventParser
 *
 * Maps one Postmark webhook body (`RecordType` Delivery, Bounce, SpamComplaint,
 * Open, Click, SubscriptionChange) onto a MailEvent. Soft bounces and transient
 * failures are `Deferred`; every other bounce type is `Bounced`. A subscription
 * change is `Unsubscribed` when it suppresses sending, otherwise `Other`.
 *
 * @package EzPhp\MailEvents\Postmark
 */
final class PostmarkEventParser
{
    /**
     * Bounce `Type`s that Postmark retries — the message may still arrive.
     */
    private const array DEFERRED_BOUNCES = ['SoftBounce', 'Transient', 'DnsError', 'AutoResponder'];

    /**
     * @param array<array-key, mixed> $body
     *
     * @return MailEvent
     * @throws MailEventsException When a required field is missing.
     */
    public function parse(array $body): MailEvent
    {
        $recordType = Payload::requireString($body, 'RecordType');
        $id = Payload::get($body, 'ID');

        return new MailEvent(
            provider: 'postmark',
            type: self::type($recordType, $body),
            providerEvent: $recordType,
            recipient: Payload::string($body, 'Recipient') ?? Payload::requireString($body, 'Email'),
            occurredAt: Payload::requireIsoTime($body, self::timeField($recordType)),
            eventId: is_int($id) || is_string($id) ? (string) $id : null,
            messageId: Payload::string($body, 'MessageID'),
            reason: Payload::string($body, 'Description')
                ?? Payload::string($body, 'Details')
                ?? Payload::string($body, 'SuppressionReason'),
            url: Payload::string($body, 'OriginalLink'),
            payload: $body,
        );
    }

    /**
     * @param string                  $recordType
     * @param array<array-key, mixed> $body
     *
     * @return MailEventType
     */
    private static function type(string $recordType, array $body): MailEventType
    {
        return match ($recordType) {
            'Delivery' => MailEventType::Delivered,
            'Bounce' => in_array(Payload::string($body, 'Type'), self::DEFERRED_BOUNCES, true)
                ? MailEventType::Deferred
                : MailEventType::Bounced,
            'SpamComplaint' => MailEventType::Complained,
            'Open' => MailEventType::Opened,
            'Click' => MailEventType::Clicked,
            'SubscriptionChange' => Payload::get($body, 'SuppressSending') === true
                ? MailEventType::Unsubscribed
                : MailEventType::Other,
            default => MailEventType::Other,
        };
    }

    /**
     * @param string $recordType
     *
     * @return string
     */
    private static function timeField(string $recordType): string
    {
        return match ($recordType) {
            'Delivery' => 'DeliveredAt',
            'Bounce', 'SpamComplaint' => 'BouncedAt',
            'SubscriptionChange' => 'ChangedAt',
            default => 'ReceivedAt',
        };
    }
}
