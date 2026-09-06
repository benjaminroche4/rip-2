<?php

namespace App\Tests\Shared\Webhook;

use App\Shared\Webhook\DashboardWebhookClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DashboardWebhookClientTest extends TestCase
{
    private const URL = 'https://dashboard.example/webhooks/rip/contact';
    private const SECRET = 's3cret';

    public function testItPostsAJsonBodySignedWithTheSharedSecret(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => $options['body'], 'headers' => $options['normalized_headers']];

            return new MockResponse('{"created":true}', ['http_code' => 201]);
        });

        $client = new DashboardWebhookClient($http, new NullLogger(), self::URL, self::SECRET);

        self::assertTrue($client->notify(['reference' => 'CT-000001', 'first_name' => 'Éva']));

        self::assertSame('POST', $captured['method']);
        self::assertSame(self::URL, $captured['url']);
        self::assertSame('{"reference":"CT-000001","first_name":"Éva"}', $captured['body']);
        self::assertSame(
            ['X-Signature: sha256='.hash_hmac('sha256', $captured['body'], self::SECRET)],
            $captured['headers']['x-signature'],
        );
        self::assertSame(['Accept: application/json'], $captured['headers']['accept']);
    }

    public function testARejectedOrFailedCallIsSwallowed(): void
    {
        $rejected = new DashboardWebhookClient(
            new MockHttpClient(new MockResponse('{"message":"Signature invalide."}', ['http_code' => 401])),
            new NullLogger(),
            self::URL,
            self::SECRET,
        );
        self::assertFalse($rejected->notify(['reference' => 'CT-1']));

        $down = new DashboardWebhookClient(
            new MockHttpClient(new MockResponse('', ['error' => 'Connection refused'])),
            new NullLogger(),
            self::URL,
            self::SECRET,
        );
        self::assertFalse($down->notify(['reference' => 'CT-1']));
    }

    public function testNothingIsSentWithoutUrlOrSecret(): void
    {
        $http = new MockHttpClient(fn () => self::fail('No request expected.'));

        self::assertFalse((new DashboardWebhookClient($http, new NullLogger(), '', self::SECRET))->notify(['a' => 1]));
        self::assertFalse((new DashboardWebhookClient($http, new NullLogger(), self::URL, ''))->notify(['a' => 1]));
    }
}
