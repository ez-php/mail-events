<?php

declare(strict_types=1);

namespace EzPhp\MailEvents\Mailgun;

use EzPhp\MailEvents\MailEvent;
use EzPhp\MailEvents\MailEventsException;
use EzPhp\MailEvents\MailEventType;
use EzPhp\MailEvents\Payload;

/**
 * Class MailgunEventParser
 *
 * Turns the `event-data` of a Mailgun webhook into a MailEvent. `failed` is
 * split by `severity`: `permanent` → Bounced, `temporary` → Deferred.
 *
 * @package EzPhp\MailEvents\Mailgun
 */
final class MailgunEventParser
{
    /**
     * @param array<array-key, mixed> $body The decoded webhook body.
     *
     * @throws MailEventsException When `event-data` lacks event, recipient or timestamp.
     *
     * @return MailEvent
     */
    public function parse(array $body): MailEvent
    {
        $data = Payload::get($body, 'event-data');

        if (!is_array($data)) {
            throw new MailEventsException('Mailgun webhook body lacks "event-data".');
        }

        $event = Payload::requireString($data, 'event');

        /** @var array<string, mixed> $data */
        return new MailEvent(
            provider: 'mailgun',
            type: self::type($event, Payload::string($data, 'severity')),
            providerEvent: $event,
            recipient: Payload::requireString($data, 'recipient'),
            occurredAt: Payload::requireTime($data, 'timestamp'),
            eventId: Payload::string($data, 'id'),
            messageId: Payload::string($data, 'message', 'headers', 'message-id'),
            reason: Payload::string($data, 'delivery-status', 'description')
                ?? Payload::string($data, 'delivery-status', 'message')
                ?? Payload::string($data, 'reason'),
            url: Payload::string($data, 'url'),
            payload: $data,
        );
    }

    /**
     * @param string      $event
     * @param string|null $severity
     *
     * @return MailEventType
     */
    private static function type(string $event, ?string $severity): MailEventType
    {
        return match ($event) {
            'delivered' => MailEventType::Delivered,
            'failed' => $severity === 'temporary' ? MailEventType::Deferred : MailEventType::Bounced,
            'rejected' => MailEventType::Dropped,
            'complained' => MailEventType::Complained,
            'opened' => MailEventType::Opened,
            'clicked' => MailEventType::Clicked,
            'unsubscribed' => MailEventType::Unsubscribed,
            default => MailEventType::Other,
        };
    }
}
