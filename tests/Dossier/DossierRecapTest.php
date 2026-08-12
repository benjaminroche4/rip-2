<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Domain\DossierRecap;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use App\Dossier\Service\DossierRecapGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * AI quick recap of a dossier: generation parses and persists the model's
 * JSON, garbage answers fail softly, and the card renders the stored
 * recap. The agent is faked: no network call ever happens in tests.
 */
final class DossierRecapTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->createQuery('DELETE FROM '.Dossier::class.' d WHERE d.name LIKE :p')->setParameter('p', 'Recap%')->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :p')->setParameter('p', '%@recap-test.local')->execute();
    }

    public function testGenerationParsesAndPersistsTheModelJson(): void
    {
        $dossier = $this->persistDossier();
        $generator = $this->generatorReturning(<<<'JSON'
            {"summary": "Famille de 2 locataires, budget 2500, recherche en cours.", "attentionPoints": ["Garant à confirmer"], "nextAction": "Planifier une visite"}
            JSON);

        $recap = $generator->generate($dossier);

        self::assertNotNull($recap);
        self::assertStringContainsString('budget 2500', $recap->summary);
        self::assertSame(['Garant à confirmer'], $recap->attentionPoints);
        self::assertSame('Planifier une visite', $recap->nextAction);

        // Persisted: reload and rebuild from the stored JSON.
        $this->em->clear();
        $reloaded = $this->em->find(Dossier::class, $dossier->getId());
        self::assertNotNull($reloaded->getRecapGeneratedAt());
        $stored = DossierRecap::fromJson((string) $reloaded->getRecapJson());
        self::assertSame('Planifier une visite', $stored->nextAction);
    }

    public function testCodeFencedJsonIsTolerated(): void
    {
        $dossier = $this->persistDossier();
        $generator = $this->generatorReturning("```json\n{\"summary\": \"Résumé.\", \"attentionPoints\": [], \"nextAction\": null}\n```");

        $recap = $generator->generate($dossier);

        self::assertNotNull($recap);
        self::assertSame('Résumé.', $recap->summary);
        self::assertNull($recap->nextAction);
    }

    public function testGarbageAnswerFailsSoftlyAndPersistsNothing(): void
    {
        $dossier = $this->persistDossier();
        $generator = $this->generatorReturning('Sorry, I cannot answer that.');

        self::assertNull($generator->generate($dossier));

        $this->em->clear();
        $reloaded = $this->em->find(Dossier::class, $dossier->getId());
        self::assertNull($reloaded->getRecapJson());
        self::assertNull($reloaded->getRecapGeneratedAt());
    }

    public function testAgentFailureFailsSoftly(): void
    {
        $dossier = $this->persistDossier();
        $agent = new class implements AgentInterface {
            public function call(string|MessageBag|UserMessage $input, array $options = []): ResultInterface
            {
                throw new \RuntimeException('API unreachable');
            }

            public function getName(): string
            {
                return 'failing';
            }
        };
        $generator = new DossierRecapGenerator($agent, $this->em, new NullLogger());

        self::assertNull($generator->generate($dossier));
    }

    public function testContextCarriesTheDossierBusinessData(): void
    {
        $dossier = $this->persistDossier();
        $captured = null;
        $agent = new class($captured) implements AgentInterface {
            public ?string $prompt = null;

            public function __construct(private mixed $unused)
            {
            }

            public function call(string|MessageBag|UserMessage $input, array $options = []): ResultInterface
            {
                \assert($input instanceof MessageBag);
                foreach ($input->getMessages() as $message) {
                    if ($message instanceof UserMessage) {
                        $content = $message->getContent()[0];
                        $this->prompt = $content instanceof \Symfony\AI\Platform\Message\Content\Text ? $content->getText() : null;
                    }
                }

                return new TextResult('{"summary": "ok", "attentionPoints": [], "nextAction": null}');
            }

            public function getName(): string
            {
                return 'capturing';
            }
        };
        $generator = new DossierRecapGenerator(
            $agent,
            $this->em,
            new NullLogger(),
            self::getContainer()->get('translator'),
        );

        $generator->generate($dossier);

        self::assertNotNull($agent->prompt);
        self::assertStringContainsString($dossier->getReference(), $agent->prompt);
        self::assertStringContainsString('Jean Recaptest', $agent->prompt);
        // Status handed over in French: the model quotes it verbatim in its
        // summary, "awaiting_documents" would leak into the UI.
        self::assertStringContainsString('Statut : Nouveau', $agent->prompt);
    }

    public function testCardRendersTheStoredRecapAndTheEmptyState(): void
    {
        $dossier = $this->persistDossier();
        $this->loginAsAdmin();

        $empty = (string) $this->renderTwigComponent('Dossier:Recap', ['dossierId' => (int) $dossier->getId()]);
        self::assertStringContainsString('data-testid="dossier-recap-empty"', $empty);
        self::assertStringContainsString('data-testid="dossier-recap-generate"', $empty);

        $dossier->setRecap(
            (new DossierRecap('Résumé du dossier.', ['Pièce manquante'], 'Relancer le garant'))->toJson(),
            new \DateTimeImmutable('2026-08-12 10:00'),
        );
        $this->em->flush();

        $rendered = (string) $this->renderTwigComponent('Dossier:Recap', ['dossierId' => (int) $dossier->getId()]);
        self::assertStringContainsString('data-testid="dossier-recap-content"', $rendered);
        self::assertStringContainsString('Résumé du dossier.', $rendered);
        self::assertStringContainsString('Pièce manquante', $rendered);
        self::assertStringContainsString('Relancer le garant', $rendered);
        self::assertStringContainsString('12.08.2026', $rendered);
    }

    private function generatorReturning(string $answer): DossierRecapGenerator
    {
        $agent = new class($answer) implements AgentInterface {
            public function __construct(private readonly string $answer)
            {
            }

            public function call(string|MessageBag|UserMessage $input, array $options = []): ResultInterface
            {
                return new TextResult($this->answer);
            }

            public function getName(): string
            {
                return 'fixed';
            }
        };

        return new DossierRecapGenerator($agent, $this->em, new NullLogger());
    }

    private function persistDossier(): Dossier
    {
        $dossier = (new Dossier())
            ->setName('Recap Famille Test')
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable());
        $person = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jean')
            ->setLastName('Recaptest')
            ->setEmail('jean@recap-test.local')
            ->setPrimaryContact(true);
        $dossier->addPerson($person);
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    private function loginAsAdmin(): void
    {
        $admin = (new User())
            ->setEmail('admin@recap-test.local')
            ->setFirstName('Admin')
            ->setLastName('Recap')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)
            ->setVerified(true);
        $admin->setPassword('irrelevant');
        $this->em->persist($admin);
        $this->em->flush();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($admin, 'main', $admin->getRoles()),
        );
    }
}
