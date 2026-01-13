import { Controller } from '@hotwired/stimulus';
import confetti from 'canvas-confetti';

export default class extends Controller {
    connect() {
        this.launchFireworks();
    }

    launchFireworks() {
        const colors = ['#8B1538', '#FFD700', '#FF6B6B', '#4ECDC4'];

        confetti({
            particleCount: 50,
            angle: 60,
            spread: 55,
            origin: { x: 0 },
            colors: colors
        });

        confetti({
            particleCount: 50,
            angle: 120,
            spread: 55,
            origin: { x: 1 },
            colors: colors
        });
    }
}
