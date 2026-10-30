<?php

declare(strict_types=1);

namespace EzPhp\MailEvents\Ses;

use EzPhp\Events\EventDispatcher;
use EzPhp\Http\Request;
use EzPhp\Http\Response;
use EzPhp\MailEvents\MailEventsException;
use EzPhp\MailEvents\Payload;

/**
 * Class SesWebhookController
 *
 * Receives Amazon SNS messages carrying Amazon SES events. Every message must
 * verify (SnsMessageVerifier) or the answer is 403. A SubscriptionConfirmation is
 * confirmed by visiting its SubscribeURL; a Notification's `Message` is parsed
 * into one MailEvent per recipient and dispatched. A Notification that is not an
 * SES event is acknowledged with 200 and ignored, so SNS does not retry it.
 *
 * @package EzPhp\MailEvents\Ses
 */
final readonly class SesWebhookController
{
    /**
     * @param SnsMessageVerifier $verifier
     * @param SesEventParser     $parser
     * @param EventDispatcher    $events
     */
    public function __construct(
        private SnsMessageVerifier $verifier,
        private SesEventParser $parser,
        private EventDispatcher $events,
    ) {
    }

    /**
     * @param Request $request
     *
     * @return Response
     */
    public function __invoke(Request $request): Response
    {
        try {
            $message = Payload::decode($request->rawBody());
        } catch (MailEventsException) {
            return new Response('Invalid payload.', 400);
        }

        if (!$this->verifier->verify($message)) {
            return new Response('Invalid signature.', 403);
        }

        return match ($message['Type'] ?? null) {
            'SubscriptionConfirmation' => $this->verifier->confirm($message)
                ? new Response('Subscribed.')
                : new Response('Subscription could not be confirmed.', 502),
            'Notification' => $this->notification($message),
            default => new Response('OK'),
        };
    }

    /**
     * @param array<array-key, mixed> $message
     *
     * @return Response
     */
    private function notification(array $message): Response
    {
        try {
            $events = $this->parser->parse(
                Payload::decode(Payload::requireString($message, 'Message')),
                Payload::string($message, 'MessageId'),
            );
        } catch (MailEventsException) {
            return new Response('OK'); // not an SES event — acknowledged, not retried
        }

        foreach ($events as $event) {
            $this->events->dispatch($event);
        }

        return new Response('OK');
    }
}
