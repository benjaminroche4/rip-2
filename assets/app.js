import './bootstrap.js';
import './styles/app.css';
import '@hotwired/turbo';
import Alpine from 'alpinejs'
import collapse from '@alpinejs/collapse'
import '@tailwindplus/elements';

window.Alpine = Alpine
Alpine.plugin(collapse)
Alpine.start()
