<?php

declare(strict_types=1);

namespace App\Blog\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class BlogExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            // Lazy runtime: the repository is only built when a template asks for the count
            new TwigFunction('blog_post_count', [BlogRuntime::class, 'postCount']),
        ];
    }
}
