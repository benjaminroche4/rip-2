import { Controller } from '@hotwired/stimulus';

/**
 * Controller Stimulus pour Google Places Autocomplete
 *
 * Initialise l'autocomplete d'adresses Google Places sur un champ input.
 * Attend que l'API Google Maps soit chargée avant d'initialiser.
 */
export default class extends Controller {
    static targets = ['input'];

    connect() {
        console.log('Google Places controller connected');

        // Vérifier que le target existe
        if (!this.hasInputTarget) {
            console.error('Google Places: input target not found');
            return;
        }

        // Attendre que Google Maps API soit chargée
        this.initAttempts = 0;
        this.maxAttempts = 50; // 5 secondes max (50 * 100ms)
        this.initializeAutocomplete();
    }

    /**
     * Initialise Google Places Autocomplete
     * Gère le cas où l'API n'est pas encore chargée (script async)
     */
    initializeAutocomplete() {
        // Vérifier si on a dépassé le nombre max de tentatives
        if (this.initAttempts >= this.maxAttempts) {
            console.error('Google Places API failed to load after maximum attempts');
            return;
        }

        this.initAttempts++;

        // Vérifier si Google Maps est disponible
        if (typeof google === 'undefined' || typeof google.maps === 'undefined' || typeof google.maps.places === 'undefined') {
            // Si Google n'est pas encore chargé, réessayer après un délai
            setTimeout(() => this.initializeAutocomplete(), 100);
            return;
        }

        try {
            console.log('Initializing Google Places Autocomplete');

            // Définir les limites géographiques de Paris
            const parisBounds = new google.maps.LatLngBounds(
                new google.maps.LatLng(48.815573, 2.224199), // Sud-Ouest
                new google.maps.LatLng(48.902145, 2.469920)  // Nord-Est
            );

            // Configuration de l'autocomplete
            const options = {
                types: ['address'], // Limiter aux adresses
                bounds: parisBounds, // Limiter géographiquement à Paris
                strictBounds: true, // Forcer les résultats dans les limites définies
                componentRestrictions: { country: 'fr' }, // Limiter à la France
                fields: ['formatted_address', 'address_components', 'geometry'] // Données à récupérer
            };

            // Initialiser l'autocomplete sur le champ input
            this.autocomplete = new google.maps.places.Autocomplete(
                this.inputTarget,
                options
            );

            // Écouter l'événement de sélection d'une adresse
            this.autocomplete.addListener('place_changed', () => {
                this.handlePlaceChanged();
            });

            console.log('Google Places Autocomplete initialized successfully');
        } catch (error) {
            console.error('Error initializing Google Places:', error);
        }
    }

    /**
     * Gère la sélection d'une adresse
     */
    handlePlaceChanged() {
        const place = this.autocomplete.getPlace();

        // Vérifier que l'adresse est complète
        if (!place.formatted_address) {
            console.warn('Adresse incomplète sélectionnée');
            return;
        }

        // Remplir le champ avec l'adresse formatée
        this.inputTarget.value = place.formatted_address;

        // Optionnel : extraire et stocker des informations supplémentaires
        // (code postal, ville, latitude/longitude)
        // Vous pouvez créer des champs hidden pour stocker ces informations
        this.extractAddressComponents(place);
    }

    /**
     * Extrait les composants de l'adresse (code postal, ville, etc.)
     * Prêt pour stocker dans des champs hidden si nécessaire
     */
    extractAddressComponents(place) {
        if (!place.address_components) return;

        const components = {
            postalCode: '',
            city: '',
            latitude: place.geometry?.location?.lat() || null,
            longitude: place.geometry?.location?.lng() || null
        };

        // Parcourir les composants de l'adresse
        place.address_components.forEach(component => {
            const types = component.types;

            if (types.includes('postal_code')) {
                components.postalCode = component.long_name;
            }
            if (types.includes('locality')) {
                components.city = component.long_name;
            }
        });

        // Logs pour debug (à retirer en production)
        console.log('Adresse sélectionnée:', {
            address: place.formatted_address,
            ...components
        });

        // Si vous souhaitez stocker ces informations, vous pouvez :
        // 1. Créer des champs hidden dans le formulaire
        // 2. Les dispatcher via un événement personnalisé
        // Exemple :
        // this.dispatch('addressSelected', { detail: components });
    }

    disconnect() {
        // Nettoyage si nécessaire
        if (this.autocomplete) {
            google.maps.event.clearInstanceListeners(this.autocomplete);
        }
    }
}
