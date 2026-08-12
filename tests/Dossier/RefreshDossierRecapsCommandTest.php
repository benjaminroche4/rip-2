<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Dossier\Command\RefreshDossierRecapsCommand;
use App\Dossier\Domain\DossierRecap;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierNote;
use App\Dossier\Repository\DossierRepository;
use App\Dossier\Service\DossierRecapGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Nightly refresh of the AI dossier recaps: only stale recaps of open
 * dossiers are regenerated, the run is capped, and a model failure never
 * fails the cron. The agent is faked: no network call in tests.
 */
final class RefreshDossierRecapsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DossierRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->repository = self::getContainer()->get(DossierRepository::class);
        $this->em->createQuery('DELETE FROM '.DossierNote::class.' n')->execute();
        $this->em->createQuery('DELETE FROM '.Dossier::class.' d WHERE d.name LIKE :p')->setParameter('p', 'RefreshRecap%')->execute();
    }

    public function testItRefreshesOnlyTheStaleRecap(): void
    {
        $stale = $this->persistDossier('RefreshRecap Stale', recapAt: new \DateTimeImmutable('-2 days'));
        $this->addNote($stale, new \DateTimeImmutable('-1 hour')); // arrived after the recap
        $fresh = $this->persistDossier('RefreshRecap Fresh', recapAt: new \DateTimeImmutable('-2 days'));
        $this->addNote($fresh, new \DateTimeImmutable('-3 days')); // older than the recap
        $never = $this->persistDossier('RefreshRecap Never', recapAt: null); // no recap yet
        $this->addNote($never, new \DateTimeImmutable('-1 hour'));

        $tester = $this->tester('{"summary": "Resume rafraichi.", "attentionPoints": [], "nextAction": null}');
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 recap(s) refreshed', $tester->getDisplay());

        $this->em->clear();
        self::assertStringContainsString('rafraichi', (string) $this->reload($stale)->getRecapJson());
        // Untouched dossier: its recap is left exactly as it was.
        self::assertStringNotContainsString('rafraichi', (string) $this->reload($fresh)->getRecapJson());
        // A dossier nobody ever generated a recap for stays empty.
        self::assertNull($this->reload($never)->getRecapJson());
    }

    public function testItSkipsClosedDossiers(): void
    {
        $closed = $this->persistDossier('RefreshRecap Closed', recapAt: new \DateTimeImmutable('-2 days'));
        $closed->setClosedAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();
        $this->addNote($closed, new \DateTimeImmutable('-1 hour'));

        $tester = $this->tester('{"summary": "Nouveau.", "attentionPoints": [], "nextAction": null}');
        $tester->execute([]);

        self::assertStringContainsString('No recap to refresh', $tester->getDisplay());
        $this->em->clear();
        self::assertStringNotContainsString('Nouveau', (string) $this->reload($closed)->getRecapJson());
    }

    public function testTheRunIsCappedByTheLimitOption(): void
    {
        foreach (['RefreshRecap A', 'RefreshRecap B', 'RefreshRecap C'] as $name) {
            $d = $this->persistDossier($name, recapAt: new \DateTimeImmutable('-2 days'));
            $this->addNote($d, new \DateTimeImmutable('-1 hour'));
        }

        $tester = $this->tester('{"summary": "Resume rafraichi.", "attentionPoints": [], "nextAction": null}');
        $tester->execute(['--limit' => '2']);

        self::assertStringContainsString('2 recap(s) refreshed', $tester->getDisplay());
    }

    public function testForceAlsoRefreshesUpToDateRecaps(): void
    {
        $fresh = $this->persistDossier('RefreshRecap Forced', recapAt: new \DateTimeImmutable('-2 days'));
        $this->addNote($fresh, new \DateTimeImmutable('-3 days'));

        $tester = $this->tester('{"summary": "Resume rafraichi.", "attentionPoints": [], "nextAction": null}');
        $tester->execute(['--force' => true]);

        self::assertStringContainsString('1 recap(s) refreshed', $tester->getDisplay());
    }

    public function testAModelFailureIsReportedWithoutFailingTheCron(): void
    {
        $stale = $this->persistDossier('RefreshRecap Failing', recapAt: new \DateTimeImmutable('-2 days'));
        $this->addNote($stale, new \DateTimeImmutable('-1 hour'));

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
        $tester = new CommandTester(new RefreshDossierRecapsCommand(
            $this->repository,
            new DossierRecapGenerator($agent, $this->em, new NullLogger()),
        ));
        $tester->execute([]);

        // The cron must stay green: a model hiccup is expected noise.
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 failure(s)', $tester->getDisplay());
    }

    public function testANonPositiveLimitIsRejected(): void
    {
        $tester = $this->tester('{"summary": "x", "attentionPoints": [], "nextAction": null}');
        $tester->execute(['--limit' => '0']);

        self::assertSame(2, $tester->getStatusCode());
    }

    private function tester(string $answer): CommandTester
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

        return new CommandTester(new RefreshDossierRecapsCommand(
            $this->repository,
            new DossierRecapGenerator($agent, $this->em, new NullLogger()),
        ));
    }

    private function reload(Dossier $dossier): Dossier
    {
        return $this->em->find(Dossier::class, $dossier->getId());
    }

    private function persistDossier(string $name, ?\DateTimeImmutable $recapAt): Dossier
    {
        $dossier = (new Dossier())
            ->setName($name)
            ->setReference('DS-'.random_int(100000, 999999))
            ->setPairingCode(substr(strtoupper(bin2hex(random_bytes(4))), 0, 6))
            ->setCreatedAt(new \DateTimeImmutable('-10 days'));
        if (null !== $recapAt) {
            $dossier->setRecap((new DossierRecap('Ancien resume.', [], null))->toJson(), $recapAt);
        }
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
    }

    private function addNote(Dossier $dossier, \DateTimeImmutable $at): void
    {
        $note = (new DossierNote())
            ->setDossier($dossier)
            ->setText('Note de suivi')
            ->setAuthorId(1)
            ->setAuthorName('Test')
            ->setCreatedAt($at);
        $this->em->persist($note);
        $this->em->flush();
    }
}
