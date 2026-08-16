<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Service;

use App\RealEstateAgent\Domain\AgentDetail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Presentation email sent to a directory agent: we let them know they are
 * in our partner directory, list the information we hold about them, and
 * give them our contact details (transparency, and a way to ask for a fix
 * or a removal). French: our partner agents work in Paris.
 */
final readonly class AgentIntroMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function send(AgentDetail $agent): void
    {
        if (null === $agent->email || '' === $agent->email) {
            throw new \InvalidArgumentException('The agent has no email address.');
        }

        $email = (new TemplatedEmail())
            ->from('Relocation in Paris <contact@relocation-in-paris.fr>')
            ->to($agent->email)
            ->subject('Relocation in Paris : vous êtes dans notre annuaire de partenaires')
            ->htmlTemplate('emails/agent_intro.html.twig')
            ->context([
                'locale' => 'fr',
                'agent' => $agent,
                'specialtyLabels' => array_map(
                    fn ($specialty): string => $this->translator->trans($specialty->labelKey(), locale: 'fr'),
                    $agent->specialties,
                ),
                'cardLabels' => array_map(
                    fn ($card): string => \sprintf(
                        '%s (%s)',
                        $this->translator->trans($card->labelKey(), locale: 'fr'),
                        $this->translator->trans($card->hintKey(), locale: 'fr'),
                    ),
                    $agent->professionalCards,
                ),
                'positionLabel' => null !== $agent->position
                    ? $this->translator->trans($agent->position->labelKey(), locale: 'fr')
                    : null,
            ]);

        $this->mailer->send($email);
    }
}
