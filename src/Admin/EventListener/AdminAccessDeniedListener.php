<?php

declare(strict_types=1);

namespace App\Admin\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

/**
 * A staff member who opens a shared back-office link for a section they
 * were not granted gets a back-office page telling them to ask an admin,
 * instead of the public "403" page that throws them out of the BO with a
 * button to the marketing homepage.
 *
 * Runs before Symfony's error controller (priority) and only for the admin
 * space: anonymous visitors and plain clients keep the public error page.
 */
#[AsEventListener(KernelEvents::EXCEPTION, priority: 64)]
final readonly class AdminAccessDeniedListener
{
    public function __construct(
        #[Autowire('%admin_path_prefix%')]
        private string $adminPathPrefix,
        private Security $security,
        private Environment $twig,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof AccessDeniedException && !$exception instanceof AccessDeniedHttpException) {
            return;
        }

        // Same anchoring as the noindex listener: the exact secret prefix,
        // so no unrelated 403 can ever reach this page.
        if (!preg_match('#^/(fr|en)/'.preg_quote($this->adminPathPrefix, '#').'/admin(?:$|/)#', $event->getRequest()->getPathInfo())) {
            return;
        }

        // Only staff: a logged-out visitor is redirected to the login form by
        // the firewall, and a plain client has no business seeing BO chrome.
        if (!$this->security->isGranted('ROLE_STAFF')) {
            return;
        }

        $event->setResponse(new Response(
            $this->twig->render('admin/access_denied.html.twig', [
                'adminPrefix' => $this->adminPathPrefix,
                // A staff member with no section at all would bounce on the
                // admin root (it forwards to the first granted section).
                'hasAnySection' => $this->hasAnySection(),
            ]),
            Response::HTTP_FORBIDDEN,
        ));
    }

    private function hasAnySection(): bool
    {
        foreach (['CONTACTS', 'DOSSIERS', 'VISITS', 'AGENTS', 'TOOLS'] as $section) {
            if ($this->security->isGranted('ROLE_SECTION_'.$section)) {
                return true;
            }
        }

        return false;
    }
}
