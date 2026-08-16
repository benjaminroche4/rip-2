<?php

declare(strict_types=1);

namespace App\Tests\Dossier;

use App\Auth\Entity\User;
use App\Contact\Domain\GuarantorType;
use App\Contact\Domain\StayDuration;
use App\Contact\Entity\Contact;
use App\Contact\Entity\ContactNote;
use App\Dossier\Domain\ContactLanguage;
use App\Dossier\Domain\DossierPersonRole;
use App\Dossier\Entity\Dossier;
use App\Dossier\Entity\DossierPerson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Transformer en dossier" flow from the contact detail page: the contact
 * becomes the primary tenant of a new dossier, the conversion is idempotent
 * on the contact email, and the mutation is CSRF-protected.
 */
final class ContactToDossierTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'contact-to-dossier-admin@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminPrefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->adminPrefix = (string) $container->getParameter('admin_path_prefix');
        $this->em = $container->get('doctrine.orm.entity_manager');

        $this->em->createQuery('DELETE FROM '.Dossier::class)->execute();
        $this->em->createQuery('DELETE FROM '.ContactNote::class)->execute();
        $this->em->createQuery('DELETE FROM '.Contact::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.email IN (:e)')
            ->setParameter('e', [self::ADMIN_EMAIL, 'dossiers-only@contact-to-dossier.local'])->execute();

        $admin = (new User())
            ->setEmail(self::ADMIN_EMAIL)
            ->setFirstName('Test')->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin);
    }

    public function testConvertsAContactIntoADossierAndRedirectsToIt(): void
    {
        $contact = $this->persistContact();

        $crawler = $this->client->request('GET', $this->contactUrl($contact));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="contact-to-dossier"]');

        $this->client->submit($crawler->filter('[data-testid="contact-to-dossier"]')->closest('form')->form());

        self::assertResponseStatusCodeSame(303);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('~/admin/dossiers/DS-\d{6}$~', $location);

        /** @var Dossier|null $dossier */
        $dossier = $this->em->getRepository(Dossier::class)->findOneBy([]);
        self::assertNotNull($dossier);
        self::assertSame('Doe', $dossier->getName());
        self::assertCount(1, $dossier->getPersons());
        $person = $dossier->getPersons()->first();
        self::assertSame(DossierPersonRole::TENANT, $person->getRole());
        self::assertTrue($person->isPrimaryContact());
        self::assertSame('jane', $person->getFirstName());
        self::assertSame('repro@example.com', $person->getEmail());
        self::assertSame('+33600000000', $person->getPhone());
        self::assertSame(ContactLanguage::EN, $person->getLanguage());

        // The contact's project is copied into the dossier's search criteria.
        $search = $dossier->getSearch();
        self::assertNotNull($search);
        self::assertSame(2500, $search->getBudget());
        self::assertSame('11e, 12e', $search->getAreas());
        self::assertSame('2026-10-01', $search->getMoveInAt()?->format('Y-m-d'));
        self::assertSame('T2', $search->getPropertyType());
        self::assertSame('long', $search->getStayDuration());
        self::assertSame('furnished', $search->getFurnishing());
        self::assertSame('physical', $search->getGuarantorType());
        self::assertSame('Cherche proche métro.', $search->getNote());

        // The origin contact is referenced for the follow-up thread's origin entry.
        self::assertSame($contact->getReference(), $dossier->getSourceContactReference());

        // The whole follow-up thread is duplicated, oldest first, authors kept.
        $notes = $dossier->getNotes()->toArray();
        self::assertCount(2, $notes);
        self::assertSame('Premier appel, très motivée.', $notes[0]->getText());
        self::assertSame('Alice Staff', $notes[0]->getAuthorName());
        self::assertSame('2026-07-01', $notes[0]->getCreatedAt()->format('Y-m-d'));
        self::assertSame('Relance par email.', $notes[1]->getText());
    }

    public function testOfferPickedInTheModalLandsOnTheContactAndTheDossier(): void
    {
        // Lead sans formule : la modale propose le choix, la valeur postée
        // est copiée sur le contact puis sur le dossier par le converter.
        $contact = $this->persistContact(offer: null);
        self::assertNull($contact->getOffer());

        $crawler = $this->client->request('GET', $this->contactUrl($contact));
        // Les radios de formule ne s'affichent que sans formule sur le lead.
        self::assertSelectorExists('[data-testid="contact-to-dossier-offer-confie"]');

        $form = $crawler->filter('[data-testid="contact-to-dossier"]')->closest('form')->form();
        $form['offer'] = 'confie';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(303);
        $dossier = $this->em->getRepository(Dossier::class)->findOneBy([]);
        self::assertSame('confie', $dossier->getOffer());
        $this->em->clear();
        self::assertSame('confie', $this->em->getRepository(\App\Contact\Entity\Contact::class)->findOneBy(['reference' => $contact->getReference()])->getOffer());
    }

    public function testConversionWithoutAnyOfferIsRefused(): void
    {
        $contact = $this->persistContact(offer: null);

        $crawler = $this->client->request('GET', $this->contactUrl($contact));
        $form = $crawler->filter('[data-testid="contact-to-dossier"]')->closest('form')->form();
        // POST sans formule (les radios required couvrent le navigateur,
        // ceci force le chemin serveur) : retour fiche lead, rien de créé.
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('/contacts/', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertNull($this->em->getRepository(Dossier::class)->findOneBy([]));
    }

    public function testTheConversionShowsACreatingOverlay(): void
    {
        $contact = $this->persistContact();

        $crawler = $this->client->request('GET', $this->contactUrl($contact));
        self::assertResponseIsSuccessful();

        // The overlay is present on the page (hidden until the submit fires),
        // and the conversion form is flagged so the controller reveals it.
        self::assertSelectorExists('[data-testid="creating-overlay"][data-controller="creating-overlay"]');
        $form = $crawler->filter('[data-testid="contact-to-dossier"]')->closest('form');
        self::assertNotNull($form->attr('data-creating-overlay-trigger'));
    }

    public function testConvertingTwiceLandsOnTheSameDossier(): void
    {
        $contact = $this->persistContact();

        $crawler = $this->client->request('GET', $this->contactUrl($contact));
        $form = $crawler->filter('[data-testid="contact-to-dossier"]')->closest('form')->form();

        $this->client->submit($form);
        $firstLocation = (string) $this->client->getResponse()->headers->get('Location');

        // Re-posting the same form (stale tab, double click) stays
        // idempotent: same dossier, no duplicate.
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(303);
        self::assertSame($firstLocation, (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(1, (int) $this->em->getRepository(Dossier::class)->count([]));

        // Once converted, the page swaps the convert action for a direct
        // link to the dossier.
        $this->client->request('GET', $this->contactUrl($contact));
        self::assertSelectorExists('[data-testid="contact-view-dossier"]');
        self::assertSelectorNotExists('[data-testid="contact-to-dossier"]');
    }

    /**
     * Regression: dossiers converted before the search/notes copy existed
     * stayed empty forever (idempotence short-circuited the copy). A later
     * conversion click must backfill the missing snapshot without duplicating.
     */
    public function testReconvertingBackfillsADossierMissingSearchAndNotes(): void
    {
        $contact = $this->persistContact();

        // Legacy-style dossier: same primary email, no search, no notes.
        $person = (new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('jane')->setLastName('Doe')
            ->setEmail('repro@example.com')
            ->setPrimaryContact(true);
        $legacy = (new Dossier())
            ->setName('Doe')
            ->setReference('DS-000099')
            ->setPairingCode('LEGACY')
            ->setCreatedAt(new \DateTimeImmutable('-1 day'))
            ->addPerson($person);
        $this->em->persist($legacy);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->contactUrl($contact));
        $this->client->submit($crawler->filter('[data-testid="contact-to-dossier"]')->closest('form')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertStringEndsWith('/admin/dossiers/DS-000099', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(1, (int) $this->em->getRepository(Dossier::class)->count([]));

        $this->em->clear();
        $fresh = $this->em->getRepository(Dossier::class)->findOneBy(['reference' => 'DS-000099']);
        self::assertNotNull($fresh->getSearch());
        self::assertSame(2500, $fresh->getSearch()->getBudget());
        self::assertSame('2026-10-01', $fresh->getSearch()->getMoveInAt()?->format('Y-m-d'));
        self::assertCount(2, $fresh->getNotes());
        self::assertSame($contact->getReference(), $fresh->getSourceContactReference());
    }

    public function testConversionRequiresTheContactsSection(): void
    {
        $contact = $this->persistContact();

        // Dossiers-only staff: can read dossiers, but converting reads the
        // lead's PII into their section. Must be refused.
        $dossierOnly = (new User())
            ->setEmail('dossiers-only@contact-to-dossier.local')
            ->setFirstName('Doss')->setLastName('Only')
            ->setRoles(['ROLE_STAFF', 'ROLE_SECTION_DOSSIERS'])->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProfileComplete(true)->setVerified(true);
        $this->em->persist($dossierOnly);
        $this->em->flush();
        $this->client->loginUser($dossierOnly);

        $this->client->request(
            'POST',
            '/fr/'.$this->adminPrefix.'/admin/dossiers/depuis-contact/'.$contact->getReference(),
            ['_token' => 'irrelevant'],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em->getRepository(Dossier::class)->count([]));
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $contact = $this->persistContact();

        $this->client->request(
            'POST',
            '/fr/'.$this->adminPrefix.'/admin/dossiers/depuis-contact/'.$contact->getReference(),
            ['_token' => 'not-a-valid-token'],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em->getRepository(Dossier::class)->count([]));
    }

    public function testConvertIsHiddenWhenADossierAlreadyCoversTheEmail(): void
    {
        // A dossier converted from a *different* contact reference but carrying
        // the same primary-tenant email already covers this lead: converting
        // again would only land on it, so the action must not be offered.
        $contact = $this->persistContact();

        $dossier = (new Dossier())
            ->setName('Doe')
            ->setReference('DS-777777')
            ->setPairingCode('ABCDEF')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setSourceContactReference('CT-999999');
        $dossier->addPerson((new DossierPerson())
            ->setRole(DossierPersonRole::TENANT)
            ->setFirstName('Jane')->setLastName('Doe')
            ->setEmail('repro@example.com')
            ->setLanguage(ContactLanguage::EN)
            ->setPrimaryContact(true)
            ->setPosition(0));
        $this->em->persist($dossier);
        $this->em->flush();

        $this->client->request('GET', $this->contactUrl($contact));
        self::assertResponseIsSuccessful();

        // "Voir le dossier" is offered, the conversion action is gone.
        self::assertSelectorExists('[data-testid="contact-view-dossier"]');
        self::assertSelectorNotExists('[data-testid="contact-to-dossier-trigger"]');
    }

    private function persistContact(?string $offer = 'accompagne'): Contact
    {
        $contact = (new Contact())
            ->setOffer($offer)
            ->setFirstName('jane')->setLastName('Doe')
            ->setEmail('repro@example.com')
            ->setPhoneNumber('+33600000000')
            ->setHelpType('contact.contactForm.helpType.choice.1')
            ->setMessage('Hello')->setLang('en')->setIp('127.0.0.1')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setProjectBudget(2500)
            ->setProjectAreas('11e, 12e')
            ->setProjectMoveInAt(new \DateTimeImmutable('2026-10-01'))
            ->setProjectPropertyType('T2')
            ->setProjectStayDuration(StayDuration::Long)
            ->setProjectFurnishing('furnished')
            ->setProjectGuarantorType(GuarantorType::Physical)
            ->setProjectNote('Cherche proche métro.');
        $this->em->persist($contact);

        $this->em->persist((new ContactNote())
            ->setContact($contact)
            ->setText('Premier appel, très motivée.')
            ->setCreatedAt(new \DateTimeImmutable('2026-07-01 10:00'))
            ->setAuthorId(1)->setAuthorName('Alice Staff'));
        $this->em->persist((new ContactNote())
            ->setContact($contact)
            ->setText('Relance par email.')
            ->setCreatedAt(new \DateTimeImmutable('2026-07-05 09:00'))
            ->setAuthorId(2)->setAuthorName('Bob Staff'));

        $this->em->flush();

        return $contact;
    }

    private function contactUrl(Contact $contact): string
    {
        return '/fr/'.$this->adminPrefix.'/admin/contacts/'.$contact->getReference();
    }
}
