# ez-php/mail-events

Delivery webhooks for ez-php, the inbound side of `ez-php/mail`'s Mailgun and SendGrid
drivers. The module verifies each webhook, turns every event into a provider-neutral
`MailEvent` and dispatches it through `ez-php/events`.

---

## Installation

```bash
composer require ez-php/mail-events
```

SendGrid's signature check needs ext-openssl.

---

## Setup

```php
// provider/modules.php — events first
EzPhp\Events\EventServiceProvider::class,
EzPhp\MailEvents\MailEventsServiceProvider::class,
```

`config/mail_events.php`:

```php
return [
    'tolerance' => 300,                         // seconds; older webhooks are rejected
    'mailgun' => [
        'signing_key' => getenv('MAIL_EVENTS_MAILGUN_SIGNING_KEY') ?: '',
        'path' => '/webhooks/mail/mailgun',
    ],
    'sendgrid' => [
        'public_key' => getenv('MAIL_EVENTS_SENDGRID_PUBLIC_KEY') ?: '',
        'path' => '/webhooks/mail/sendgrid',
    ],
    'postmark' => [
        'username' => getenv('MAIL_EVENTS_POSTMARK_USERNAME') ?: '',
        'password' => getenv('MAIL_EVENTS_POSTMARK_PASSWORD') ?: '',
        'path' => '/webhooks/mail/postmark',
    ],
    'ses' => [
        'topic_arn' => getenv('MAIL_EVENTS_SES_TOPIC_ARN') ?: '',
        'path' => '/webhooks/mail/ses',
    ],
];
```

A route is only registered for a provider with a key, and these routes are exempt from CSRF
checks. Point the provider's webhook settings at the URL:

- **Mailgun:** Sending → Webhooks. Use the domain's *HTTP webhook signing key*.
- **SendGrid:** Settings → Mail Settings → Event Webhook. Enable *Signed Event Webhook* and
  copy the verification key.
- **Postmark:** Servers → Webhooks. Postmark does not sign webhooks; put the configured
  username/password into the URL (`https://hook:secret@example.com/webhooks/mail/postmark`).
- **Amazon SES:** publish the configuration set's events to an SNS topic and subscribe the URL to it
  over HTTPS. Set `ses.topic_arn` to the topic's ARN; the subscription confirmation is answered
  automatically. One SES event becomes one `MailEvent` per recipient.

---

## Listening

```php
use EzPhp\Events\EventDispatcher;
use EzPhp\Events\EventInterface;
use EzPhp\MailEvents\MailEvent;
use EzPhp\MailEvents\MailEventType;

$events->listen(MailEvent::class, function (EventInterface $event): void {
    if (!$event instanceof MailEvent) {
        return;
    }

    match ($event->type) {
        MailEventType::Bounced, MailEventType::Complained => $suppressions->add($event->recipient),
        MailEventType::Delivered => $log->info("delivered to {$event->recipient}"),
        default => null,
    };
});
```

| `MailEventType` | Mailgun | SendGrid |
|---|---|---|
| `Delivered` | `delivered` | `delivered` |
| `Bounced` | `failed` (permanent) | `bounce` (incl. blocks) |
| `Deferred` | `failed` (temporary) | `deferred` |
| `Dropped` | `rejected` | `dropped` |
| `Complained` | `complained` | `spamreport` |
| `Opened` | `opened` | `open` |
| `Clicked` | `clicked` | `click` |
| `Unsubscribed` | `unsubscribed` | `unsubscribe`, `group_unsubscribe` |
| `Other` | everything else | everything else |

`MailEvent` also carries:

- `$providerEvent`: the provider's own event name
- `$occurredAt`
- `$eventId`
- `$messageId`
- `$reason`: bounce, drop or deferral reason
- `$url`: for clicks
- `$payload`: the raw event data

Providers retry webhooks, so the same event can arrive more than once. If something must
happen only once, de-duplicate on `$eventId`.

---

## Security

- A request with a missing or invalid signature gets 401 and is never dispatched.
- Timestamps outside the tolerance window are rejected, so a captured request cannot be replayed later.
- The SendGrid signature covers the raw body, so any modification is detected.
- Postmark requests need the configured Basic credentials (401 otherwise).
- SES (SNS) messages must come from the configured topic and carry a valid SNS signature from a
  certificate served by `sns.<region>.amazonaws.com` (403 otherwise). SNS redelivers with the
  original timestamp, so there is no replay window here — de-duplicate on `MailEvent::$eventId`.

---

## License

MIT
