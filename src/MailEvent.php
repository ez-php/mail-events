<?php

declare(strict_types=1);

namespace EzPhp\MailEvents;

use DateTimeImmutable;
use EzPhp\Events\EventInterface;

/**
 * Class MailEvent
 *
 * One delivery event, normalised from a provider webhook and dispatched through
 * ez-php/events. Listen for `MailEvent::class` and branch on $type.
 *
 * $eventId is the provider's unique event id: providers retry webhooks, so a
 * listener that must act once (e.g. counting bounces) de-duplicates on it.
 *
 * @package EzPhp\MailEvents
 */
final readonly class MailEvent implements EventInterface
{
    /**
     * @param string               $provider      `mailgun` or `sendgrid`.
     * @param MailEventType        $type
     * @param string               $providerEvent The provider's own event name, e.g. `failed`, `spamreport`.
     * @param string               $recipient
     * @param DateTimeImmutable    $occurredAt
     * @param string|null          $eventId       Provider event id, for de-duplication.
     * @param string|null          $messageId     Provider or Message-ID of the sent message.
     * @param string|null          $reason        Bounce/drop/deferral reason, when given.
     * @param string|null          $url           Clicked URL (Clicked only).
     * @param array<string, mixed> $payload       The provider's raw event data.
     */
    public function __construct(
        public string $provider,
        public MailEventType $type,
        public string $providerEvent,
        public string $recipient,
        public DateTimeImmutable $occurredAt,
        public ?string $eventId = null,
        public ?string $messageId = null,
        public ?string $reason = null,
        public ?string $url = null,
        public array $payload = [],
    ) {
    }
}
