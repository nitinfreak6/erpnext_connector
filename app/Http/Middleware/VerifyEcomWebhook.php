<?php

namespace App\Http\Middleware;

use App\Services\ConnectorRegistry;
use App\Services\Ecom\EcomInterface;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Driver-agnostic webhook verification.
 *
 * Replaces VerifyShopifyWebhook. The signature header is read from the active
 * ecom driver's registry config, and verification is delegated to that
 * driver's adapter via EcomInterface::verifyWebhook() — so a new ecom platform
 * needs no middleware change, only its verifyWebhook() implementation and a
 * 'webhooks.signature_header' entry in config/connectors.php.
 */
class VerifyEcomWebhook
{
    public function __construct(
        private readonly EcomInterface $ecom,
        private readonly SettingsService $settings,
        private readonly ConnectorRegistry $registry,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $driver   = $this->settings->ecomDriver();
        $cfg      = $this->registry->webhookConfig($driver);
        $header   = $cfg['signature_header'] ?? null;

        if (!$header) {
            abort(500, "No webhook signature header configured for ecom driver [{$driver}].");
        }

        $rawBody   = $request->getContent();
        $signature = $request->header($header, '');

        if (!$this->ecom->verifyWebhook($rawBody, $signature)) {
            abort(401, 'Invalid webhook signature.');
        }

        // Stash raw body for controller use (JSON body is already parsed by Laravel)
        $request->attributes->set('raw_body', $rawBody);

        return $next($request);
    }
}
