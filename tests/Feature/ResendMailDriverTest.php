<?php

namespace Tests\Feature;

use Resend\Client;
use Resend\Contracts\Client as ClientContract;
use Resend\Laravel\Exceptions\ApiKeyIsMissing;
use Tests\TestCase;

/**
 * Regression guard for the Resend mail driver wiring.
 *
 * resend-laravel resolves its API key as
 *     config('resend.api_key')          // env RESEND_API_KEY
 *     ?? config('services.resend.key')
 * (ResendServiceProvider::bindResendClient). The app, however, provisions the
 * key as RESEND_KEY (docs/deploy.md, config/mail.php). With no
 * services.resend.key mapping, the transport found no key and threw
 * ApiKeyIsMissing on every send — silently killing production mail for weeks
 * while GET /up reported mail.last_sent: null. config/services.php now maps
 * RESEND_KEY into that slot; these tests keep it wired.
 */
class ResendMailDriverTest extends TestCase
{
    public function test_resend_key_env_is_mapped_into_services_config(): void
    {
        // env() reads $_ENV/$_SERVER via phpdotenv's adapters; set both so the
        // freshly required config file resolves RESEND_KEY deterministically.
        putenv('RESEND_KEY=re_unit_test_key');
        $_ENV['RESEND_KEY'] = 're_unit_test_key';
        $_SERVER['RESEND_KEY'] = 're_unit_test_key';

        $services = require base_path('config/services.php');

        $this->assertSame(
            're_unit_test_key',
            $services['resend']['key'] ?? null,
            'config/services.php must map RESEND_KEY into services.resend.key — the slot the resend transport actually reads.'
        );

        putenv('RESEND_KEY');
        unset($_ENV['RESEND_KEY'], $_SERVER['RESEND_KEY']);
    }

    public function test_resend_client_builds_when_key_comes_from_services_config(): void
    {
        // Mirror production: RESEND_API_KEY unset, RESEND_KEY present (→ services.resend.key).
        config(['resend.api_key' => null, 'services.resend.key' => 're_unit_fake_key']);
        $this->forgetResendClient();

        $client = $this->app->make('resend');

        $this->assertInstanceOf(Client::class, $client);
    }

    public function test_resend_client_throws_when_no_key_is_configured_anywhere(): void
    {
        config(['resend.api_key' => null, 'services.resend.key' => null]);
        $this->forgetResendClient();

        $this->expectException(ApiKeyIsMissing::class);

        $this->app->make('resend');
    }

    public function test_resend_is_the_default_mailer_when_mail_mailer_is_unset(): void
    {
        $original = $_ENV['MAIL_MAILER'] ?? null;
        putenv('MAIL_MAILER');
        unset($_ENV['MAIL_MAILER'], $_SERVER['MAIL_MAILER']);

        try {
            $mail = require base_path('config/mail.php');

            $this->assertSame(
                'resend',
                $mail['default'],
                'With MAIL_MAILER unset the app must default to Resend, not the unconfigured smtp skeleton default.'
            );
        } finally {
            if ($original !== null) {
                putenv("MAIL_MAILER={$original}");
                $_ENV['MAIL_MAILER'] = $_SERVER['MAIL_MAILER'] = $original;
            }
        }
    }

    /** Drop the cached singleton so the binding re-runs against overridden config. */
    private function forgetResendClient(): void
    {
        $this->app->forgetInstance(ClientContract::class);
        $this->app->forgetInstance(Client::class);
        $this->app->forgetInstance('resend');
    }
}
