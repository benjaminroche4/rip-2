<?php

namespace App\CaseStudy\Repository;

use App\CaseStudy\Domain\CaseStudy;
use App\CaseStudy\Domain\CaseStudyDocument;
use App\CaseStudy\Domain\CaseStudyOffer;
use App\CaseStudy\Domain\CaseStudySegment;

/**
 * Placeholder data set until the case studies are stored in Sanity.
 * Swap it for a GROQ-backed implementation of CaseStudyRepositoryInterface
 * (same DTO, so the controller and templates stay untouched).
 */
final class MockCaseStudyRepository implements CaseStudyRepositoryInterface
{
    /**
     * Shared facts first, then the localized texts.
     * Agents are the advisers of the About page (agentPhoto = their team photo number).
     */
    private const ITEMS = [
        [
            'lat' => 48.8637, 'lng' => 2.2769,
            'segment' => CaseStudySegment::Private, 'days' => 18, 'photo' => 1, 'publishedAt' => '2026-10-21',
            'agentName' => 'Sophie B.', 'agentPhoto' => 4,
            'fr' => [
                'slug' => 'famille-londonienne-installee-paris-16',
                'title' => 'Une famille londonienne installée dans le 16e avant la rentrée',
                'seoTitle' => 'Famille de Londres relogée à Paris 16e',
                'excerpt' => "Quatre chambres près des écoles internationales, un bail signé à distance et des clés remises le jour de l'arrivée.",
                'relocationType' => 'Relocation familiale', 'clientProfile' => 'Famille', 'housingType' => 'Logement vide',
                'stayType' => 'Installation longue durée', 'origin' => 'Londres', 'district' => 'Paris 16e',
                'photoAlt' => 'Salon lumineux de l’appartement familial dans le 16e arrondissement',
            ],
            'en' => [
                'slug' => 'london-family-settled-paris-16',
                'title' => 'A London family settled in the 16th before the school year',
                'seoTitle' => 'London family relocated to Paris 16th',
                'excerpt' => 'Four bedrooms near the international schools, a lease signed remotely and keys handed over on arrival day.',
                'relocationType' => 'Family relocation', 'clientProfile' => 'Family', 'housingType' => 'Unfurnished housing',
                'stayType' => 'Long-term relocation', 'origin' => 'London', 'district' => 'Paris 16th',
                'photoAlt' => 'Bright living room of the family apartment in the 16th arrondissement',
            ],
        ],
        [
            'lat' => 48.8771, 'lng' => 2.3375,
            'segment' => CaseStudySegment::Company, 'days' => 19, 'photo' => 2, 'publishedAt' => '2026-10-07',
            'agentName' => 'Charles M.', 'agentPhoto' => 2,
            'fr' => [
                'slug' => 'six-consultants-loges-mission-12-mois',
                'title' => 'Six consultants logés en même temps pour une mission de 12 mois',
                'seoTitle' => '6 consultants logés à Paris 9e',
                'excerpt' => 'Six appartements meublés dans le même quartier, un seul interlocuteur pour les RH et une facturation centralisée.',
                'relocationType' => "Mobilité d'entreprise", 'clientProfile' => 'Entreprise', 'housingType' => 'Logements meublés',
                'stayType' => 'Mission de 12 mois', 'origin' => 'Bruxelles', 'district' => 'Paris 9e',
                'photoAlt' => 'Immeuble haussmannien du 9e arrondissement',
            ],
            'en' => [
                'slug' => 'six-consultants-housed-12-month-assignment',
                'title' => 'Six consultants housed at once for a 12-month assignment',
                'seoTitle' => '6 consultants housed in Paris 9th',
                'excerpt' => 'Six furnished apartments in the same neighbourhood, a single contact for HR and one consolidated invoice.',
                'relocationType' => 'Corporate mobility', 'clientProfile' => 'Company', 'housingType' => 'Furnished housing',
                'stayType' => '12-month assignment', 'origin' => 'Brussels', 'district' => 'Paris 9th',
                'photoAlt' => 'Haussmann building in the 9th arrondissement',
            ],
        ],
        [
            'lat' => 48.863, 'lng' => 2.3601,
            'segment' => CaseStudySegment::Private, 'days' => 9, 'photo' => 3, 'publishedAt' => '2026-09-23',
            'agentName' => 'Eva B.', 'agentPhoto' => 3,
            'fr' => [
                'slug' => 'meuble-marais-signe-a-distance',
                'title' => 'Un meublé dans le Marais signé depuis New York',
                'seoTitle' => 'Meublé dans le Marais signé à distance',
                'excerpt' => 'Visites en vidéo, dossier préparé en 48 heures et état des lieux fait par notre équipe avant son arrivée.',
                'relocationType' => 'Relocation à distance', 'clientProfile' => 'Jeune actif', 'housingType' => 'Logement meublé',
                'stayType' => 'Bail mobilité de 10 mois', 'origin' => 'New York', 'district' => 'Paris 3e',
                'photoAlt' => 'Rue pavée du Marais à Paris',
            ],
            'en' => [
                'slug' => 'furnished-marais-apartment-signed-remotely',
                'title' => 'A furnished flat in the Marais signed from New York',
                'seoTitle' => 'Marais flat signed remotely from NYC',
                'excerpt' => 'Video viewings, an application ready in 48 hours and the check-in inspection done by our team before arrival.',
                'relocationType' => 'Remote relocation', 'clientProfile' => 'Young professional', 'housingType' => 'Furnished housing',
                'stayType' => '10-month mobility lease', 'origin' => 'New York', 'district' => 'Paris 3rd',
                'photoAlt' => 'Cobbled street in the Marais, Paris',
            ],
        ],
        [
            'lat' => 48.859, 'lng' => 2.38,
            'segment' => CaseStudySegment::Private, 'days' => 14, 'photo' => 4, 'publishedAt' => '2026-09-09',
            'agentName' => 'David K.', 'agentPhoto' => 7,
            'fr' => [
                'slug' => 'dossier-sans-garant-francais-accepte',
                'title' => 'Un dossier sans garant français accepté du premier coup',
                'seoTitle' => 'Location sans garant français à Paris',
                'excerpt' => 'Une garantie locative mise en place en quelques jours et un dossier retenu face à une vingtaine de candidats.',
                'relocationType' => 'Relocation étudiante', 'clientProfile' => 'Étudiante', 'housingType' => 'Studio meublé',
                'stayType' => 'Bail étudiant de 9 mois', 'origin' => 'Berlin', 'district' => 'Paris 11e',
                'photoAlt' => 'Façades parisiennes du 11e arrondissement',
            ],
            'en' => [
                'slug' => 'application-without-french-guarantor-accepted',
                'title' => 'An application without a French guarantor accepted first time',
                'seoTitle' => 'Renting in Paris without a guarantor',
                'excerpt' => 'A rent guarantee set up in a few days and an application chosen over some twenty other candidates.',
                'relocationType' => 'Student relocation', 'clientProfile' => 'Student', 'housingType' => 'Furnished studio',
                'stayType' => '9-month student lease', 'origin' => 'Berlin', 'district' => 'Paris 11th',
                'photoAlt' => 'Parisian facades in the 11th arrondissement',
            ],
        ],
        [
            'lat' => 48.8763, 'lng' => 2.3183,
            'segment' => CaseStudySegment::Company, 'days' => 16, 'photo' => 5, 'publishedAt' => '2026-08-26',
            'agentName' => 'Julien L.', 'agentPhoto' => 1,
            'fr' => [
                'slug' => 'logement-de-fonction-nouveau-dirigeant',
                'title' => 'Un logement de fonction trouvé pour un nouveau dirigeant',
                'seoTitle' => 'Logement de fonction à Paris 8e',
                'excerpt' => "Un appartement familial proche du siège, un bail au nom de l'entreprise et une installation prête avant sa prise de poste.",
                'relocationType' => 'Logement de fonction', 'clientProfile' => 'Dirigeant', 'housingType' => 'Logement vide',
                'stayType' => 'Installation longue durée', 'origin' => 'Genève', 'district' => 'Paris 8e',
                'photoAlt' => 'Vue sur les toits de Paris près du 8e arrondissement',
            ],
            'en' => [
                'slug' => 'company-housing-new-executive',
                'title' => 'Company housing found for a new executive',
                'seoTitle' => 'Executive company housing in Paris 8th',
                'excerpt' => 'A family apartment close to headquarters, a lease in the company name and everything ready before day one.',
                'relocationType' => 'Company housing', 'clientProfile' => 'Executive', 'housingType' => 'Unfurnished housing',
                'stayType' => 'Long-term relocation', 'origin' => 'Geneva', 'district' => 'Paris 8th',
                'photoAlt' => 'View over the Paris rooftops near the 8th arrondissement',
            ],
        ],
        [
            'lat' => 48.8445, 'lng' => 2.3497,
            'segment' => CaseStudySegment::Private, 'days' => 12, 'photo' => 6, 'publishedAt' => '2026-08-12',
            'agentName' => 'Élodie G.', 'agentPhoto' => 5,
            'fr' => [
                'slug' => 'couple-chercheurs-reloge-quartier-latin',
                'title' => 'Un couple de chercheurs relogé à deux pas de son laboratoire',
                'seoTitle' => 'Couple de chercheurs relogé à Paris 5e',
                'excerpt' => 'Une recherche ciblée sur le Quartier latin, un budget tenu et un accompagnement entièrement en espagnol.',
                'relocationType' => 'Relocation en couple', 'clientProfile' => 'Couple', 'housingType' => 'Logement meublé',
                'stayType' => 'Installation longue durée', 'origin' => 'Madrid', 'district' => 'Paris 5e',
                'photoAlt' => 'Appartement parisien rénové dans le 5e arrondissement',
            ],
            'en' => [
                'slug' => 'researcher-couple-relocated-latin-quarter',
                'title' => 'A researcher couple relocated a short walk from their lab',
                'seoTitle' => 'Researchers relocated to Paris 5th',
                'excerpt' => 'A search focused on the Latin Quarter, a budget kept in check and support entirely in Spanish.',
                'relocationType' => 'Couple relocation', 'clientProfile' => 'Couple', 'housingType' => 'Furnished housing',
                'stayType' => 'Long-term relocation', 'origin' => 'Madrid', 'district' => 'Paris 5th',
                'photoAlt' => 'Renovated Parisian apartment in the 5th arrondissement',
            ],
        ],
        [
            'lat' => 48.8562, 'lng' => 2.3121,
            'segment' => CaseStudySegment::Company, 'days' => 15, 'photo' => 7, 'publishedAt' => '2026-07-29',
            'agentName' => 'Jean-Pierre A.', 'agentPhoto' => 6,
            'fr' => [
                'slug' => 'mutation-directeur-financier-singapour-paris',
                'title' => 'La mutation d’un directeur financier et de sa famille, de Singapour à Paris',
                'seoTitle' => 'Mutation d’un DAF de Singapour à Paris',
                'excerpt' => 'Recherche, école des enfants et démarches administratives coordonnées avec le service mobilité du groupe.',
                'relocationType' => 'Mutation internationale', 'clientProfile' => 'Famille', 'housingType' => 'Logement vide',
                'stayType' => 'Installation longue durée', 'origin' => 'Singapour', 'district' => 'Paris 7e',
                'photoAlt' => 'Avenue parisienne du 7e arrondissement',
            ],
            'en' => [
                'slug' => 'cfo-transfer-singapore-paris',
                'title' => 'Transferring a CFO and their family from Singapore to Paris',
                'seoTitle' => 'CFO transfer from Singapore to Paris',
                'excerpt' => "Home search, the children's school and paperwork, all coordinated with the group's mobility team.",
                'relocationType' => 'International transfer', 'clientProfile' => 'Family', 'housingType' => 'Unfurnished housing',
                'stayType' => 'Long-term relocation', 'origin' => 'Singapore', 'district' => 'Paris 7th',
                'photoAlt' => 'Parisian avenue in the 7th arrondissement',
            ],
        ],
    ];

    /**
     * Detail page of each case study, same order as ITEMS.
     * criteria and documents point to the catalogs below; the CMS will hold them per case study.
     */
    private const STORIES = [
        [
            'offer' => 'confie', 'homes' => 5, 'featured' => true,
            'household' => 4, 'budget' => 4200, 'criteria' => ['schools', 'metro', 'quiet', 'space'],
            'documents' => [CaseStudyDocument::Employer, CaseStudyDocument::International, CaseStudyDocument::Income],
            'fr' => [
                'summary' => "En 2026, une famille de quatre personnes mutée de Londres s'est installée dans le 16e arrondissement. Recherche près des écoles internationales, visites à distance et dossier préparé avec l'employeur : tout a été géré pour une arrivée sereine avant la rentrée.",
                'quote' => "Déménager à Paris avec des enfants nous inquiétait, mais nous avons été accompagnés à chaque étape. L'équipe a compris nos besoins et nous nous sommes sentis chez nous dès le premier jour.",
                'quoteAuthor' => 'Emma, venue de Londres',
            ],
            'en' => [
                'summary' => 'In 2026, a family of four transferred from London settled in the 16th arrondissement. A search near the international schools, remote viewings and an application prepared with the employer: everything was handled for a calm arrival before the school year.',
                'quote' => 'Moving to Paris with children felt daunting at first, but we were supported at every step. The team understood our needs and made us feel at home from day one.',
                'quoteAuthor' => 'Emma, moving from London',
            ],
        ],
        [
            'offer' => 'confie', 'homes' => 9, 'featured' => false,
            'household' => 6, 'budget' => 1900, 'criteria' => ['office', 'furnished', 'metro', 'flexible'],
            'documents' => [CaseStudyDocument::Employer, CaseStudyDocument::International, CaseStudyDocument::Income],
            'fr' => [
                'summary' => "En 2026, un cabinet de conseil nous a confié le logement de six consultants pour une mission de 12 mois. Recherche groupée dans le 9e, baux meublés calés sur la mission et un seul interlocuteur pour les RH.",
                'quote' => 'Nous avons envoyé un brief, et six collaborateurs étaient logés trois semaines plus tard.',
                'quoteAuthor' => 'Responsable RH, cabinet de conseil',
            ],
            'en' => [
                'summary' => 'In 2026, a consulting firm asked us to house six consultants for a 12-month assignment. A grouped search in the 9th, furnished leases matching the assignment and a single contact for HR.',
                'quote' => 'We sent one brief, and six colleagues had a home three weeks later.',
                'quoteAuthor' => 'HR manager, consulting firm',
            ],
        ],
        [
            'offer' => 'accompagne', 'homes' => 4, 'featured' => false,
            'household' => 1, 'budget' => 1800, 'criteria' => ['remote', 'furnished', 'metro', 'character'],
            'documents' => [CaseStudyDocument::Guarantor, CaseStudyDocument::International, CaseStudyDocument::Income],
            'fr' => [
                'summary' => "En 2026, un jeune actif recruté par une start-up parisienne a signé son bail depuis New York. Visites en vidéo, dossier traduit en 48 heures et garantie locative : il est arrivé directement chez lui, dans le Marais.",
                'quote' => "J'ai signé depuis New York et je suis arrivé directement chez moi.",
                'quoteAuthor' => 'Daniel, venu de New York',
            ],
            'en' => [
                'summary' => 'In 2026, a young professional hired by a Paris start-up signed a lease from New York. Video viewings, an application translated in 48 hours and a rent guarantee: straight from the airport to a new home in the Marais.',
                'quote' => 'I signed from New York and went straight to my new home.',
                'quoteAuthor' => 'Daniel, moving from New York',
            ],
        ],
        [
            'offer' => 'accompagne', 'homes' => 6, 'featured' => false,
            'household' => 1, 'budget' => 1100, 'criteria' => ['budget', 'metro', 'furnished', 'quiet'],
            'documents' => [CaseStudyDocument::Guarantor, CaseStudyDocument::International, CaseStudyDocument::Scholarship],
            'fr' => [
                'summary' => "En 2026, une étudiante venue de Berlin, sans garant ni revenus en France, a obtenu un studio meublé dans le 11e. Garantie locative, bourse mise en valeur et accompagnement en allemand ont fait la différence.",
                'quote' => 'Sans garant en France, je pensais que ce serait impossible. Mon dossier a été accepté du premier coup.',
                'quoteAuthor' => 'Lena, venue de Berlin',
            ],
            'en' => [
                'summary' => 'In 2026, a student from Berlin, with no guarantor and no income in France, secured a furnished studio in the 11th. A rent guarantee, a well-presented scholarship and support in German made the difference.',
                'quote' => 'Without a guarantor in France, I thought it would be impossible. My application was accepted first time.',
                'quoteAuthor' => 'Lena, moving from Berlin',
            ],
        ],
        [
            'offer' => 'confie', 'homes' => 3, 'featured' => false,
            'household' => 4, 'budget' => 5500, 'criteria' => ['office', 'space', 'quiet', 'schools'],
            'documents' => [CaseStudyDocument::Employer, CaseStudyDocument::Income, CaseStudyDocument::International],
            'fr' => [
                'summary' => "En 2026, une scale-up a recruté son nouveau directeur général, venu de Genève. Appartement familial près du siège, bail au nom de l'entreprise et logement prêt une semaine avant sa prise de poste.",
                'quote' => "Notre nouveau dirigeant a pu se concentrer sur son arrivée, pas sur la recherche d'appartement.",
                'quoteAuthor' => 'Office manager, scale-up de la French Tech',
            ],
            'en' => [
                'summary' => 'In 2026, a scale-up hired its new CEO from Geneva. A family apartment near headquarters, a lease in the company name and a home ready a week before day one.',
                'quote' => 'Our new CEO could focus on joining us, not on flat hunting.',
                'quoteAuthor' => 'Office manager, French Tech scale-up',
            ],
        ],
        [
            'offer' => 'accompagne', 'homes' => 7, 'featured' => false,
            'household' => 2, 'budget' => 1700, 'criteria' => ['office', 'budget', 'furnished', 'character'],
            'documents' => [CaseStudyDocument::Guarantor, CaseStudyDocument::International, CaseStudyDocument::Income],
            'fr' => [
                'summary' => "En 2026, un couple de chercheurs venu de Madrid s'est installé à deux pas de son laboratoire, dans le 5e. Recherche ciblée sur le Quartier latin, budget tenu et accompagnement entièrement en espagnol.",
                'quote' => "Tout s'est fait en espagnol, sans aucune mauvaise surprise.",
                'quoteAuthor' => 'Lucía, venue de Madrid',
            ],
            'en' => [
                'summary' => 'In 2026, a researcher couple from Madrid settled a short walk from their lab, in the 5th. A search focused on the Latin Quarter, a budget kept in check and support entirely in Spanish.',
                'quote' => 'Everything was done in Spanish, with no bad surprises.',
                'quoteAuthor' => 'Lucía, moving from Madrid',
            ],
        ],
        [
            'offer' => 'confie', 'homes' => 4, 'featured' => false,
            'household' => 4, 'budget' => 6000, 'criteria' => ['schools', 'space', 'quiet', 'metro'],
            'documents' => [CaseStudyDocument::Employer, CaseStudyDocument::International, CaseStudyDocument::Income],
            'fr' => [
                'summary' => "En 2026, un groupe industriel a muté son directeur financier de Singapour à Paris. Recherche dans le 7e, inscription des enfants à l'école et démarches administratives, coordonnées avec le service mobilité du groupe.",
                'quote' => "Un seul interlocuteur pour le logement, l'école et l'administratif : exactement ce qu'il nous fallait.",
                'quoteAuthor' => 'Service mobilité, groupe industriel',
            ],
            'en' => [
                'summary' => "In 2026, an industrial group transferred its CFO from Singapore to Paris. A search in the 7th, the children's school enrolment and the paperwork, coordinated with the group's mobility team.",
                'quote' => 'One contact for housing, school and paperwork: exactly what we needed.',
                'quoteAuthor' => 'Mobility team, industrial group',
            ],
        ],
    ];

    /** What clients ask for, reused across the mock case studies. */
    private const CRITERIA = [
        'schools' => [
            'fr' => ['Proche des écoles et des commerces', "Une école à moins de 10 minutes à pied, des commerces et une pharmacie dans la rue."],
            'en' => ['Close to schools and daily essentials', 'A school within a 10-minute walk, with shops and a pharmacy on the street.'],
        ],
        'metro' => [
            'fr' => ['Accès direct au métro', 'Une station à moins de 5 minutes, avec une ligne directe vers le lieu de travail.'],
            'en' => ['Direct access to the metro', 'A station within 5 minutes, with a direct line to work.'],
        ],
        'quiet' => [
            'fr' => ['Un quartier calme et sûr', 'Une rue résidentielle, un immeuble sécurisé et un appartement au calme.'],
            'en' => ['A quiet, safe neighbourhood', 'A residential street, a secure building and a quiet apartment.'],
        ],
        'space' => [
            'fr' => ['De la place pour toute la famille', 'Au moins trois chambres et une pièce de vie lumineuse.'],
            'en' => ['Room for the whole family', 'At least three bedrooms and a bright living room.'],
        ],
        'office' => [
            'fr' => ['Proche du lieu de travail', 'Moins de 20 minutes de trajet, porte à porte.'],
            'en' => ['Close to work', 'Under 20 minutes door to door.'],
        ],
        'furnished' => [
            'fr' => ['Meublé et prêt à vivre', "Literie, cuisine équipée et internet dès l'arrivée."],
            'en' => ['Furnished and ready to live in', 'Bedding, an equipped kitchen and internet from day one.'],
        ],
        'flexible' => [
            'fr' => ['Un bail calé sur la mission', "Une durée et une date d'entrée alignées sur le contrat."],
            'en' => ['A lease matching the assignment', 'A length and start date aligned with the contract.'],
        ],
        'character' => [
            'fr' => ['Un appartement de caractère', 'Parquet, moulures et hauteur sous plafond : le charme parisien.'],
            'en' => ['An apartment with character', 'Parquet floors, mouldings and high ceilings: Parisian charm.'],
        ],
        'remote' => [
            'fr' => ['Tout gérer à distance', 'Visites en vidéo et signature électronique, sans se déplacer.'],
            'en' => ['Everything handled remotely', 'Video viewings and e-signature, without travelling.'],
        ],
        'budget' => [
            'fr' => ['Un budget maîtrisé', "Un loyer charges comprises dans l'enveloppe fixée au départ."],
            'en' => ['A budget kept in check', 'Rent, charges included, within the budget set at the start.'],
        ],
    ];

    /** Texts every mock case study shares; %district% is replaced by its area. */
    private const SHARED = [
        'fr' => [
            'location' => "Nous avons ciblé %district% et ses alentours, en croisant temps de trajet, écoles et vie de quartier, pour trouver l'emplacement qui correspondait au quotidien du client.",
            'application' => 'Nous préparons le dossier locatif à vos côtés, pièce par pièce. Chaque document est vérifié, traduit si besoin et présenté pour rassurer le propriétaire : un dossier complet et professionnel, prêt à être retenu.',
            'visits' => [
                ['Première visite', "Lors de la première visite, nous présentons une sélection de logements choisis selon vos priorités, votre budget et le quartier visé. Agencement, voisinage, transports : chaque point est passé en revue avec vous.", 'first'],
                ['Deuxième visite', "La deuxième visite se concentre sur les logements retenus. Nous vérifions l'état de l'immeuble, les conditions du bail et les détails pratiques pour décider en toute confiance.", 'second'],
            ],
            'securing' => "Une fois le logement choisi, nous le sécurisons pour vous : échanges avec le propriétaire ou l'agence, dépôt de l'offre et coordination des documents jusqu'à la signature du bail.",
            'photoAlt' => ['first' => 'Rue de Paris visitée pendant la première visite', 'second' => 'Intérieur d’un appartement visité pendant la deuxième visite'],
            'text' => [
                'title' => 'Pourquoi %district% ?',
                'paragraphs' => [
                    "Le quartier a été choisi avec le client à partir de son quotidien : trajets, écoles, commerces et ambiance de rue. Nous avons comparé plusieurs secteurs avant de concentrer la recherche sur %district%.",
                    "Sur ce marché, les biens partent souvent en quelques jours. Notre réseau d'agences et de propriétaires nous a permis de visiter des logements avant leur mise en ligne et de déposer un dossier complet dès la première visite.",
                ],
            ],
            'table' => [
                'title' => 'Le projet en chiffres',
                'caption' => 'Montants indicatifs au moment de la signature du bail.',
                'head' => ['Poste', 'Détail', 'Commentaire'],
                'rent' => ['Loyer', '%budget% €/mois', 'Charges comprises, dans le budget fixé au départ'],
                'deposit' => ['Dépôt de garantie', '%deposit%', 'Hors charges, restitué en fin de bail'],
                'delay' => ['Délai', '%days% jours', 'Du premier échange au bail signé'],
            ],
        ],
        'en' => [
            'location' => "We focused on %district% and the surrounding area, weighing commute times, schools and neighbourhood life, to find the location that fitted the client's daily routine.",
            'application' => 'We prepare the tenant application with you, document by document. Each one is checked, translated if needed and presented to reassure the landlord: a complete, professional file, ready to be chosen.',
            'visits' => [
                ['First visit', 'During the first visit, we present a selection of homes chosen around your priorities, budget and target area. Layout, neighbourhood, transport: every point is reviewed with you.', 'first'],
                ['Second visit', 'The second visit focuses on the shortlisted homes. We check the building condition, the lease terms and the practical details so you can decide with confidence.', 'second'],
            ],
            'securing' => 'Once the home is chosen, we secure it for you: talking to the landlord or agency, submitting the offer and coordinating the documents until the lease is signed.',
            'photoAlt' => ['first' => 'Paris street seen during the first visit', 'second' => 'Inside an apartment seen during the second visit'],
            'text' => [
                'title' => 'Why %district%?',
                'paragraphs' => [
                    "The area was chosen with the client around their daily life: commutes, schools, shops and street atmosphere. We compared several areas before focusing the search on %district%.",
                    'In this market, homes often go within days. Our network of agencies and landlords let us view homes before they were listed and submit a complete application at the first viewing.',
                ],
            ],
            'table' => [
                'title' => 'The project in figures',
                'caption' => 'Indicative amounts at the time the lease was signed.',
                'head' => ['Item', 'Detail', 'Note'],
                'rent' => ['Rent', '€%budget%/month', 'Charges included, within the budget set at the start'],
                'deposit' => ['Security deposit', '%deposit%', 'Excluding charges, returned at the end of the lease'],
                'delay' => ['Timeline', '%days% days', 'From the first call to the signed lease'],
            ],
        ],
    ];

    public function findAll(string $locale): array
    {
        $locale = 'en' === $locale ? 'en' : 'fr';

        $items = array_map(
            static function (array $item, array $story) use ($locale): CaseStudy {
                $text = $item[$locale];
                $shared = self::SHARED[$locale];
                $otherLocale = 'en' === $locale ? 'fr' : 'en';

                return new CaseStudy(
                    slug: $text['slug'],
                    title: $text['title'],
                    seoTitle: $text['seoTitle'],
                    excerpt: $text['excerpt'],
                    segment: $item['segment'],
                    relocationType: $text['relocationType'],
                    clientProfile: $text['clientProfile'],
                    housingType: $text['housingType'],
                    stayType: $text['stayType'],
                    origin: $text['origin'],
                    district: $text['district'],
                    daysToSettle: $item['days'],
                    agentName: $item['agentName'],
                    agentPhoto: 'medias/team/thumb/team_'.$item['agentPhoto'].'.webp',
                    photo: 'medias/case-studies/case-study-'.$item['photo'].'.webp',
                    photoSmall: 'medias/case-studies/case-study-'.$item['photo'].'-600.webp',
                    photoAlt: $text['photoAlt'],
                    publishedAt: new \DateTimeImmutable($item['publishedAt']),
                    alternateSlug: $item[$otherLocale]['slug'],
                    householdSize: $story['household'],
                    budget: $story['budget'],
                    summary: $story[$locale]['summary'],
                    locationText: str_replace('%district%', $text['district'], $shared['location']),
                    latitude: $item['lat'],
                    longitude: $item['lng'],
                    criteria: array_map(
                        static fn (string $key): array => ['title' => self::CRITERIA[$key][$locale][0], 'text' => self::CRITERIA[$key][$locale][1]],
                        $story['criteria'],
                    ),
                    applicationText: $shared['application'],
                    applicationDocuments: $story['documents'],
                    visits: array_map(
                        static fn (array $visit): array => [
                            'title' => $visit[0],
                            'text' => $visit[1],
                            'photos' => array_map(
                                static fn (int $n): array => ['src' => 'medias/case-studies/visit-'.$visit[2].'-'.$n.'.webp', 'alt' => $shared['photoAlt'][$visit[2]].' ('.$n.'/4)'],
                                [1, 2, 3, 4],
                            ),
                        ],
                        $shared['visits'],
                    ),
                    securingText: $shared['securing'],
                    textBlock: [
                        'title' => str_replace('%district%', $text['district'], $shared['text']['title']),
                        'paragraphs' => array_map(
                            static fn (string $paragraph): string => str_replace('%district%', $text['district'], $paragraph),
                            $shared['text']['paragraphs'],
                        ),
                    ],
                    table: self::figures($shared['table'], $story['budget'], $item['days'], $locale, str_contains(strtolower($item['en']['housingType']), 'unfurnished')),
                    quote: $story[$locale]['quote'],
                    quoteAuthor: $story[$locale]['quoteAuthor'],
                    quotePhoto: 'medias/case-studies/testimonial-1.webp',
                    featured: $story['featured'],
                    offer: CaseStudyOffer::from($story['offer']),
                    homesVisited: $story['homes'],
                );
            },
            self::ITEMS,
            self::STORIES,
        );

        usort($items, static fn (CaseStudy $a, CaseStudy $b): int => $b->publishedAt <=> $a->publishedAt);

        return $items;
    }

    /**
     * "The project in figures" table: deposit is one month of rent for an unfurnished home, two for a furnished one.
     *
     * @param array{title: string, caption: string, head: list<string>, rent: list<string>, deposit: list<string>, delay: list<string>} $labels
     *
     * @return array{title: string, caption: string, rows: list<list<string>>}
     */
    private static function figures(array $labels, int $budget, int $days, string $locale, bool $unfurnished): array
    {
        $values = [
            '%budget%' => number_format($budget, 0, ',', 'fr' === $locale ? "\u{202F}" : ','),
            '%deposit%' => match (true) {
                'fr' === $locale => ($unfurnished ? '1' : '2').' mois de loyer',
                $unfurnished => '1 month of rent',
                default => '2 months of rent',
            },
            '%days%' => (string) $days,
        ];

        return [
            'title' => $labels['title'],
            'caption' => $labels['caption'],
            'rows' => [
                $labels['head'],
                array_map(static fn (string $cell): string => strtr($cell, $values), $labels['rent']),
                array_map(static fn (string $cell): string => strtr($cell, $values), $labels['deposit']),
                array_map(static fn (string $cell): string => strtr($cell, $values), $labels['delay']),
            ],
        ];
    }

    public function findOneBySlug(string $slug, string $locale): ?CaseStudy
    {
        foreach ($this->findAll($locale) as $caseStudy) {
            if ($caseStudy->slug === $slug) {
                return $caseStudy;
            }
        }

        return null;
    }
}
