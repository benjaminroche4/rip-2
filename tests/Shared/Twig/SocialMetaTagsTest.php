<?php

declare(strict_types=1);

namespace App\Tests\Shared\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * Open Graph / Twitter tags of the shared meta partial: values coming from block() are
 * already escaped and social networks need an absolute image URL.
 */
final class SocialMetaTagsTest extends KernelTestCase
{
    public function testDescriptionsAreEscapedOnceAndImagesAreAbsolute(): void
    {
        self::bootKernel();
        $request = Request::create('/fr/test');
        $request->setLocale('fr');
        static::getContainer()->get('request_stack')->push($request);

        // What block('description') hands over: text already escaped by the page template
        $html = static::getContainer()->get(Environment::class)->render('_partials/meta.html.twig', [
            'pageTitle' => 'Vivre à l&#039;étranger',
            'pageDescription' => 'Votre projet d&#039;installation',
            'pageUrl' => '/fr/test',
            'pageOgImage' => '/medias/banner/relocation-in-paris-banner.webp',
        ]);

        self::assertStringContainsString('<meta property="og:description" content="Votre projet d&#039;installation">', $html);
        self::assertStringContainsString('<meta property="og:title" content="Relocation In Paris - Vivre à l&#039;étranger">', $html);
        self::assertStringNotContainsString('&amp;#039;', $html);
        self::assertMatchesRegularExpression('#<meta property="og:image" content="https://[^"]+/medias/banner/relocation-in-paris-banner\.webp">#', $html);
    }

    public function testAnAbsoluteImageIsKeptAsIs(): void
    {
        self::bootKernel();
        static::getContainer()->get('request_stack')->push(Request::create('/fr/test'));

        $html = static::getContainer()->get(Environment::class)->render('_partials/meta.html.twig', [
            'pageTitle' => 'T',
            'pageDescription' => 'D',
            'pageUrl' => '/fr/test',
            'pageOgImage' => 'https://cdn.sanity.io/images/x.avif?w=1200',
        ]);

        self::assertStringContainsString('<meta property="og:image" content="https://cdn.sanity.io/images/x.avif?w=1200">', $html);
    }
}
