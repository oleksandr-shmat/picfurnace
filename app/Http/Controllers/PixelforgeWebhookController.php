<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Generations\Webhooks\WebhookEventHandler;
use App\Pixelforge\Webhook\ProviderEvent;
use App\Pixelforge\Webhook\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, WebhookEventHandler $handler): Response
    {
        // The signature covers the raw body, so check it before anything is decoded.
        $rawBody = $request->getContent();

        if (! $signature->isValid($rawBody, (string) $request->header('Pixelforge-Signature'), now())) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            abort(400, 'The body is not a JSON object.');
        }

        try {
            $event = ProviderEvent::fromArray($payload);
        } catch (InvalidArgumentException $e) {
            report($e);
            abort(400, 'Unreadable event.');
        }

        $handler->handle($event, $payload);

        return response()->noContent();
    }
}
