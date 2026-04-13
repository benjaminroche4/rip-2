<?php

namespace App\Data;

class ParisTransport
{
    public const LINES = [
        '1' => ['name' => 'Métro 1', 'color' => '#FFCD00', 'textColor' => '#000', 'type' => 'metro'],
        '2' => ['name' => 'Métro 2', 'color' => '#003CA6', 'textColor' => '#fff', 'type' => 'metro'],
        '3' => ['name' => 'Métro 3', 'color' => '#837902', 'textColor' => '#fff', 'type' => 'metro'],
        '3bis' => ['name' => 'Métro 3bis', 'color' => '#6EC4E8', 'textColor' => '#000', 'type' => 'metro'],
        '4' => ['name' => 'Métro 4', 'color' => '#CF009E', 'textColor' => '#fff', 'type' => 'metro'],
        '5' => ['name' => 'Métro 5', 'color' => '#FF7E2E', 'textColor' => '#000', 'type' => 'metro'],
        '6' => ['name' => 'Métro 6', 'color' => '#6ECA97', 'textColor' => '#000', 'type' => 'metro'],
        '7' => ['name' => 'Métro 7', 'color' => '#FA9ABA', 'textColor' => '#000', 'type' => 'metro'],
        '7bis' => ['name' => 'Métro 7bis', 'color' => '#6ECA97', 'textColor' => '#000', 'type' => 'metro'],
        '8' => ['name' => 'Métro 8', 'color' => '#E19BDF', 'textColor' => '#000', 'type' => 'metro'],
        '9' => ['name' => 'Métro 9', 'color' => '#B6BD00', 'textColor' => '#000', 'type' => 'metro'],
        '10' => ['name' => 'Métro 10', 'color' => '#C9910D', 'textColor' => '#fff', 'type' => 'metro'],
        '11' => ['name' => 'Métro 11', 'color' => '#704B1C', 'textColor' => '#fff', 'type' => 'metro'],
        '12' => ['name' => 'Métro 12', 'color' => '#007852', 'textColor' => '#fff', 'type' => 'metro'],
        '13' => ['name' => 'Métro 13', 'color' => '#6EC4E8', 'textColor' => '#000', 'type' => 'metro'],
        '14' => ['name' => 'Métro 14', 'color' => '#62259D', 'textColor' => '#fff', 'type' => 'metro'],
        'A' => ['name' => 'RER A', 'color' => '#F7403A', 'textColor' => '#fff', 'type' => 'rer'],
        'B' => ['name' => 'RER B', 'color' => '#4B92DB', 'textColor' => '#fff', 'type' => 'rer'],
        'C' => ['name' => 'RER C', 'color' => '#F3D311', 'textColor' => '#000', 'type' => 'rer'],
        'D' => ['name' => 'RER D', 'color' => '#45A04A', 'textColor' => '#fff', 'type' => 'rer'],
        'E' => ['name' => 'RER E', 'color' => '#E04DA7', 'textColor' => '#fff', 'type' => 'rer'],
    ];

    public const STATIONS_BY_LINE = [
        '1' => [
            'La Défense', 'Esplanade de La Défense', 'Pont de Neuilly', 'Les Sablons',
            'Porte Maillot', 'Argentine', 'Charles de Gaulle–Étoile', 'George V',
            'Franklin D. Roosevelt', 'Champs-Élysées–Clemenceau', 'Concorde', 'Tuileries',
            'Palais Royal–Musée du Louvre', 'Louvre–Rivoli', 'Châtelet', 'Hôtel de Ville',
            'Saint-Paul', 'Bastille', 'Gare de Lyon', 'Reuilly–Diderot', 'Nation',
            'Porte de Vincennes', 'Saint-Mandé', 'Bérault', 'Château de Vincennes',
        ],
        '2' => [
            'Porte Dauphine', 'Victor Hugo', 'Charles de Gaulle–Étoile', 'Ternes',
            'Courcelles', 'Monceau', 'Villiers', 'Rome', 'Place de Clichy', 'Blanche',
            'Pigalle', 'Anvers', 'Barbès–Rochechouart', 'La Chapelle', 'Stalingrad',
            'Jaurès', 'Colonel Fabien', 'Belleville', 'Couronnes', 'Ménilmontant',
            'Père Lachaise', 'Philippe Auguste', 'Alexandre Dumas', 'Avron', 'Nation',
        ],
        '3' => [
            'Pont de Levallois–Bécon', 'Anatole France', 'Louise Michel',
            'Porte de Champerret', 'Pereire', 'Wagram', 'Malesherbes', 'Villiers',
            'Europe', 'Saint-Lazare', 'Havre–Caumartin', 'Opéra', 'Quatre-Septembre',
            'Bourse', 'Sentier', 'Réaumur–Sébastopol', 'Arts et Métiers', 'Temple',
            'République', 'Parmentier', 'Rue Saint-Maur', 'Père Lachaise', 'Gambetta',
            'Porte de Bagnolet', 'Gallieni',
        ],
        '3bis' => [
            'Gambetta', 'Pelleport', 'Saint-Fargeau', 'Porte des Lilas',
        ],
        '4' => [
            'Porte de Clignancourt', 'Simplon', 'Marcadet–Poissonniers', 'Château Rouge',
            'Barbès–Rochechouart', 'Gare du Nord', 'Gare de l\'Est', 'Château d\'Eau',
            'Strasbourg–Saint-Denis', 'Réaumur–Sébastopol', 'Étienne Marcel', 'Les Halles',
            'Châtelet', 'Cité', 'Saint-Michel', 'Odéon', 'Saint-Germain-des-Prés',
            'Saint-Sulpice', 'Saint-Placide', 'Montparnasse–Bienvenüe', 'Vavin', 'Raspail',
            'Denfert-Rochereau', 'Mouton-Duvernet', 'Alésia', 'Porte d\'Orléans',
            'Mairie de Montrouge', 'Barbara', 'Bagneux–Lucie Aubrac',
        ],
        '5' => [
            'Bobigny–Pablo Picasso', 'Bobigny–Pantin–Raymond Queneau', 'Église de Pantin',
            'Hoche', 'Porte de Pantin', 'Ourcq', 'Laumière', 'Jaurès', 'Stalingrad',
            'Gare du Nord', 'Gare de l\'Est', 'Jacques Bonsergent', 'République',
            'Oberkampf', 'Richard-Lenoir', 'Bréguet-Sabin', 'Bastille', 'Quai de la Rapée',
            'Gare d\'Austerlitz', 'Saint-Marcel', 'Campo-Formio', 'Place d\'Italie',
        ],
        '6' => [
            'Charles de Gaulle–Étoile', 'Kléber', 'Boissière', 'Trocadéro', 'Passy',
            'Bir-Hakeim', 'Dupleix', 'La Motte-Picquet–Grenelle', 'Cambronne',
            'Sèvres–Lecourbe', 'Pasteur', 'Montparnasse–Bienvenüe', 'Edgar Quinet',
            'Raspail', 'Denfert-Rochereau', 'Saint-Jacques', 'Glacière', 'Corvisart',
            'Place d\'Italie', 'Nationale', 'Chevaleret', 'Quai de la Gare', 'Bercy',
            'Dugommier', 'Daumesnil', 'Bel-Air', 'Picpus', 'Nation',
        ],
        '7' => [
            'La Courneuve–8 Mai 1945', 'Fort d\'Aubervilliers',
            'Aubervilliers–Pantin–Quatre Chemins', 'Porte de la Villette',
            'Corentin Cariou', 'Crimée', 'Riquet', 'Stalingrad', 'Louis Blanc',
            'Château-Landon', 'Gare de l\'Est', 'Poissonnière', 'Cadet', 'Le Peletier',
            'Chaussée d\'Antin–La Fayette', 'Opéra', 'Pyramides',
            'Palais Royal–Musée du Louvre', 'Pont Neuf', 'Châtelet', 'Pont Marie',
            'Sully–Morland', 'Jussieu', 'Place Monge', 'Censier–Daubenton',
            'Les Gobelins', 'Place d\'Italie', 'Tolbiac', 'Maison Blanche',
            'Porte d\'Italie', 'Porte de Choisy', 'Porte d\'Ivry',
            'Pierre et Marie Curie', 'Mairie d\'Ivry', 'Le Kremlin-Bicêtre',
            'Villejuif–Léo Lagrange', 'Villejuif–Paul Vaillant-Couturier',
            'Villejuif–Louis Aragon',
        ],
        '7bis' => [
            'Louis Blanc', 'Jaurès', 'Bolivar', 'Buttes Chaumont', 'Botzaris',
            'Place des Fêtes', 'Pré Saint-Gervais',
        ],
        '8' => [
            'Balard', 'Lourmel', 'Boucicaut', 'Félix Faure', 'Commerce',
            'La Motte-Picquet–Grenelle', 'École Militaire', 'La Tour-Maubourg',
            'Invalides', 'Concorde', 'Madeleine', 'Opéra', 'Richelieu–Drouot',
            'Grands Boulevards', 'Bonne Nouvelle', 'Strasbourg–Saint-Denis', 'République',
            'Filles du Calvaire', 'Saint-Sébastien–Froissart', 'Chemin Vert', 'Bastille',
            'Ledru-Rollin', 'Faidherbe–Chaligny', 'Reuilly–Diderot', 'Montgallet',
            'Daumesnil', 'Michel Bizot', 'Porte Dorée', 'Porte de Charenton', 'Liberté',
            'Charenton-Écoles', 'École Vétérinaire de Maisons-Alfort',
            'Maisons-Alfort–Stade', 'Maisons-Alfort–Les Juilliottes', 'Créteil–L\'Échat',
            'Créteil–Université', 'Créteil–Préfecture', 'Pointe du Lac',
        ],
        '9' => [
            'Pont de Sèvres', 'Billancourt', 'Marcel Sembat', 'Porte de Saint-Cloud',
            'Exelmans', 'Michel-Ange–Molitor', 'Michel-Ange–Auteuil', 'Jasmin',
            'Ranelagh', 'La Muette', 'Rue de la Pompe', 'Trocadéro', 'Iéna',
            'Alma–Marceau', 'Franklin D. Roosevelt', 'Saint-Philippe du Roule',
            'Miromesnil', 'Saint-Augustin', 'Havre–Caumartin',
            'Chaussée d\'Antin–La Fayette', 'Richelieu–Drouot', 'Grands Boulevards',
            'Bonne Nouvelle', 'Strasbourg–Saint-Denis', 'République', 'Oberkampf',
            'Saint-Ambroise', 'Voltaire', 'Charonne', 'Rue des Boulets', 'Nation',
            'Buzenval', 'Maraîchers', 'Porte de Montreuil', 'Robespierre',
            'Croix de Chavaux', 'Mairie de Montreuil',
        ],
        '10' => [
            'Boulogne–Pont de Saint-Cloud', 'Boulogne–Jean Jaurès',
            'Michel-Ange–Molitor', 'Chardon Lagache', 'Mirabeau',
            'Javel–André Citroën', 'Charles Michels', 'Avenue Émile Zola',
            'La Motte-Picquet–Grenelle', 'Ségur', 'Duroc', 'Vaneau',
            'Sèvres–Babylone', 'Mabillon', 'Odéon', 'Cluny–La Sorbonne',
            'Maubert–Mutualité', 'Cardinal Lemoine', 'Jussieu', 'Gare d\'Austerlitz',
        ],
        '11' => [
            'Châtelet', 'Hôtel de Ville', 'Rambuteau', 'Arts et Métiers', 'République',
            'Goncourt', 'Belleville', 'Pyrénées', 'Jourdain', 'Place des Fêtes',
            'Télégraphe', 'Porte des Lilas', 'Mairie des Lilas',
        ],
        '12' => [
            'Front Populaire', 'Aimé Césaire', 'Porte de la Chapelle', 'Marx Dormoy',
            'Marcadet–Poissonniers', 'Jules Joffrin', 'Lamarck–Caulaincourt', 'Abbesses',
            'Pigalle', 'Saint-Georges', 'Notre-Dame-de-Lorette',
            'Trinité–d\'Estienne d\'Orves', 'Saint-Lazare', 'Madeleine', 'Concorde',
            'Assemblée Nationale', 'Solférino', 'Rue du Bac', 'Sèvres–Babylone',
            'Rennes', 'Notre-Dame-des-Champs', 'Montparnasse–Bienvenüe', 'Falguière',
            'Pasteur', 'Volontaires', 'Vaugirard', 'Convention', 'Porte de Versailles',
            'Corentin Celton', 'Mairie d\'Issy',
        ],
        '13' => [
            'Châtillon–Montrouge', 'Malakoff–Plateau de Vanves',
            'Malakoff–Rue Étienne Dolet', 'Porte de Vanves', 'Plaisance', 'Pernety',
            'Gaîté', 'Montparnasse–Bienvenüe', 'Duroc', 'Saint-François-Xavier',
            'Varenne', 'Invalides', 'Champs-Élysées–Clemenceau', 'Miromesnil',
            'Saint-Lazare', 'Liège', 'Place de Clichy', 'La Fourche', 'Guy Môquet',
            'Porte de Saint-Ouen', 'Garibaldi', 'Mairie de Saint-Ouen',
            'Carrefour Pleyel', 'Saint-Denis–Porte de Paris',
            'Basilique de Saint-Denis', 'Saint-Denis–Université', 'Brochant',
            'Porte de Clichy', 'Mairie de Clichy', 'Gabriel Péri', 'Les Agnettes',
            'Les Courtilles',
        ],
        '14' => [
            'Saint-Denis Pleyel', 'Mairie de Saint-Ouen', 'Porte de Clichy',
            'Pont Cardinet', 'Saint-Lazare', 'Madeleine', 'Pyramides', 'Châtelet',
            'Gare de Lyon', 'Bercy', 'Cour Saint-Émilion',
            'Bibliothèque François Mitterrand', 'Olympiades',
            'Villejuif–Institut Gustave Roussy', 'Chevilly–Trois Communes',
            'M.I.N. Porte de Thiais', 'Pont de Rungis', 'Aéroport d\'Orly',
        ],
        'A' => [
            'Saint-Germain-en-Laye', 'Le Vésinet–Le Pecq', 'Le Vésinet–Centre',
            'Chatou–Croissy', 'Rueil-Malmaison', 'Nanterre–Ville',
            'Nanterre–Préfecture', 'La Défense', 'Charles de Gaulle–Étoile', 'Auber',
            'Châtelet–Les Halles', 'Gare de Lyon', 'Nation', 'Vincennes',
            'Fontenay-sous-Bois', 'Nogent-sur-Marne', 'Joinville-le-Pont',
            'Saint-Maur–Créteil', 'Le Parc de Saint-Maur', 'Champigny',
            'La Varenne–Chennevières', 'Sucy–Bonneuil', 'Boissy-Saint-Léger',
            'Val de Fontenay', 'Neuilly-Plaisance', 'Bry-sur-Marne',
            'Noisy-le-Grand–Mont d\'Est', 'Noisy–Champs', 'Noisiel', 'Lognes', 'Torcy',
            'Bussy-Saint-Georges', 'Val d\'Europe', 'Marne-la-Vallée–Chessy',
            'Cergy–Le Haut', 'Cergy–Saint-Christophe', 'Cergy–Préfecture',
            'Neuville–Université', 'Conflans–Fin d\'Oise', 'Achères-Ville', 'Poissy',
            'Houilles–Carrières-sur-Seine', 'Sartrouville', 'Maisons-Laffitte',
        ],
        'B' => [
            'Aéroport Charles de Gaulle 2', 'Aéroport Charles de Gaulle 1',
            'Parc des Expositions', 'Villepinte', 'Sevran–Beaudottes',
            'Aulnay-sous-Bois', 'Le Blanc-Mesnil', 'Drancy', 'Le Bourget',
            'La Courneuve–Aubervilliers', 'La Plaine–Stade de France', 'Gare du Nord',
            'Châtelet–Les Halles', 'Saint-Michel–Notre-Dame', 'Luxembourg', 'Port-Royal',
            'Denfert-Rochereau', 'Cité Universitaire', 'Gentilly', 'Laplace',
            'Arcueil–Cachan', 'Bagneux', 'Bourg-la-Reine', 'Sceaux',
            'Fontenay-aux-Roses', 'Robinson', 'Parc de Sceaux', 'La Croix de Berny',
            'Antony', 'Les Baconnets', 'Massy–Verrières', 'Massy–Palaiseau',
            'Palaiseau', 'Palaiseau–Villebon', 'Lozère', 'Le Guichet', 'Orsay-Ville',
            'Bures-sur-Yvette', 'La Hacquinière', 'Gif-sur-Yvette',
            'Courcelle-sur-Yvette', 'Saint-Rémy-lès-Chevreuse', 'Mitry–Claye',
        ],
        'C' => [
            'Pontoise', 'Saint-Ouen-l\'Aumône', 'Épluches',
            'Franconville–Le Plessis-Bouchard', 'Cernay', 'Ermont–Eaubonne',
            'Saint-Gratien', 'Épinay-sur-Seine', 'Gennevilliers', 'Les Grésillons',
            'Champ de Mars–Tour Eiffel', 'Pont de l\'Alma', 'Invalides',
            'Musée d\'Orsay', 'Saint-Michel–Notre-Dame', 'Gare d\'Austerlitz',
            'Bibliothèque François Mitterrand', 'Ivry-sur-Seine', 'Vitry-sur-Seine',
            'Les Ardoines', 'Choisy-le-Roi', 'Villeneuve-Saint-Georges', 'Juvisy',
            'Savigny-sur-Orge', 'Épinay-sur-Orge', 'Sainte-Geneviève-des-Bois',
            'Saint-Michel-sur-Orge', 'Brétigny', 'Versailles-Château–Rive Gauche',
            'Versailles-Chantiers', 'Porchefontaine', 'Viroflay–Rive Gauche',
            'Chaville–Vélizy', 'Meudon–Val Fleury', 'Issy',
            'Avenue du Président Kennedy', 'Boulainvilliers', 'Avenue Henri Martin',
            'Pereire–Levallois', 'Neuilly–Porte Maillot', 'Porte de Clichy',
            'Avenue Foch',
        ],
        'D' => [
            'Orry-la-Ville–Coye', 'La Borne Blanche', 'Survilliers–Fosses', 'Louvres',
            'Les Noues', 'Goussainville',
            'Villiers-le-Bel–Gonesse–Arnouville', 'Garges–Sarcelles',
            'Pierrefitte–Stains', 'Saint-Denis', 'Stade de France–Saint-Denis',
            'Gare du Nord', 'Châtelet–Les Halles', 'Gare de Lyon',
            'Maisons-Alfort–Alfortville', 'Le Vert de Maisons', 'Créteil–Pompadour',
            'Villeneuve-Saint-Georges', 'Villeneuve-Triage', 'Boussy-Saint-Antoine',
            'Brunoy', 'Yerres', 'Montgeron–Crosne', 'Vigneux-sur-Seine', 'Juvisy',
            'Viry-Châtillon', 'Grigny-Centre', 'Orangis–Bois de l\'Épine',
            'Évry-Courcouronnes', 'Le Bras de Fer', 'Corbeil-Essonnes', 'Melun',
            'Combs-la-Ville–Quincy', 'Lieusaint–Moissy', 'Savigny-le-Temple–Nandy',
            'Cesson', 'Vert-Saint-Denis', 'Le Mée-sur-Seine',
        ],
        'E' => [
            'Haussmann–Saint-Lazare', 'Magenta', 'Rosa Parks', 'Pantin',
            'Noisy-le-Sec', 'Bondy', 'Le Raincy–Villemomble–Montfermeil', 'Gagny',
            'Le Chénay–Gagny', 'Chelles–Gournay', 'Vaires–Torcy',
            'Lagny–Thorigny', 'Bussy-Saint-Georges', 'Val d\'Europe',
            'Marne-la-Vallée–Chessy', 'Tournan', 'Ozoir-la-Ferrière',
            'Gretz-Armainvilliers', 'Nanterre–La Folie', 'La Défense',
        ],
    ];

    public static function getLine(string $id): ?array
    {
        return self::LINES[$id] ?? null;
    }

    public static function getStationsByLine(string $lineId): array
    {
        return self::STATIONS_BY_LINE[$lineId] ?? [];
    }

    public static function getStations(): array
    {
        $stationMap = [];

        foreach (self::STATIONS_BY_LINE as $lineId => $stations) {
            foreach ($stations as $stationName) {
                if (!isset($stationMap[$stationName])) {
                    $stationMap[$stationName] = [];
                }
                if (!in_array($lineId, $stationMap[$stationName], true)) {
                    $stationMap[$stationName][] = $lineId;
                }
            }
        }

        ksort($stationMap, SORT_LOCALE_STRING);

        $result = [];
        foreach ($stationMap as $name => $lines) {
            $result[] = ['name' => $name, 'lines' => $lines];
        }

        return $result;
    }

    public static function searchStations(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $queryLower = mb_strtolower($query);
        $stations = self::getStations();

        return array_values(array_filter($stations, function (array $station) use ($queryLower) {
            return str_contains(mb_strtolower($station['name']), $queryLower);
        }));
    }
}
