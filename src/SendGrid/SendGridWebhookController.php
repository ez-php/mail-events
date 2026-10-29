<?php

declare(strict_types=1);

namespace EzPhp\MailEvents\SendGrid;

use EzPhp\Events\EventDispatcher;
use EzPhp\Http\Request;
use EzPhp\Http\Response;
use EzPhp\MailEvents\MailEvent;
use EzPhp\MailEvents\MailEventsException;
use EzPhp\MailEvents\Payload;

/**
 * Class SendGridWebhookController
 *
 * Endpoint for SendGrid's Event Webhook (a JSON array of events per request):
 * verifies the signature over the raw body, then parses and dispatches each
 * event in order. Answers 401 for a bad or stale signature, 400 when the body
 * is not a JSON array, 200 otherwise.
 *
 * An entry that cannot be parsed is skipped rather than failing the batch: the
 * signature already proved the batch is SendGrid's, and a non-2xx answer would
 * make SendGrid redeliver every other event in it.
 *
 * @package EzPhp\MailEvents\SendGrid
 */
final readonly class SendGridWebhookController
{
    /**
     * @param SendGridSignature   $signature
     * @param SendGridEventParser $parser
     * @param EventDispatcher     $events
     */
    public function __construct(
        private SendGridSignature $signature,
        private SendGridEventParser $parser,
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
        $timestamp = $request->header(SendGridSignature::TIMESTAMP_HEADER);
        $signature = $request->header(SendGridSignature::SIGNATURE_HEADER);

        if (!is_string($timestamp) || !is_string($signature)
            || !$this->signature->verify($timestamp, $signature, $request->rawBody())) {
            return new Response('Invalid signature.', 401);
        }

        try {
            $entries = Payload::decode($request->rawBody());
        } catch (MailEventsException) {
            return new Response('Invalid payload.', 400);
        }

        if (!array_is_list($entries)) {
            return new Response('Invalid payload.', 400);
        }

        foreach ($entries as $entry) {
            $event = is_array($entry) ? $this->parseOrNull($entry) : null;

            if ($event !== null) {
                $this->events->dispatch($event);
            }
        }

        return new Response('OK');
    }

    /**
     * @param array<array-key, mixed> $entry
     *
     * @return MailEvent|null
     */
    private function parseOrNull(array $entry): ?MailEvent
    {
        try {
            return $this->parser->parse($entry);
        } catch (MailEventsException) {
            return null;
        }
    }
}
