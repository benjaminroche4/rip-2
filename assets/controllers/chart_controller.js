/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        type: { type: String, default: 'bar' },
        labels: Array,
        data: Array,
        label: String,
    };

    async connect() {
        // Chart.js is lazy-loaded only when an admin chart actually mounts —
        // avoids shipping ~200KB to public visitors who never see the dashboard.
        const { Chart } = await import('chart.js/auto');

        // Resolve --color-primary from the cascade so the chart stays in sync
        // if the brand color ever changes via app.css.
        const primary = getComputedStyle(document.documentElement)
            .getPropertyValue('--color-primary')
            .trim() || '#71172e';

        this.chart = new Chart(this.element, {
            type: this.typeValue,
            data: {
                labels: this.labelsValue,
                datasets: [
                    {
                        label: this.labelValue,
                        data: this.dataValue,
                        backgroundColor: this.#withAlpha(primary, 0.85),
                        hoverBackgroundColor: primary,
                        borderRadius: 6,
                        borderSkipped: false,
                        maxBarThickness: 36,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 350, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#111827',
                        padding: 10,
                        titleFont: { weight: '600', size: 12 },
                        bodyFont: { size: 12 },
                        cornerRadius: 8,
                        displayColors: false,
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: {
                            color: '#6b7280',
                            font: { size: 11 },
                        },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(3, 7, 18, 0.05)' },
                        border: { display: false },
                        ticks: {
                            precision: 0,
                            color: '#6b7280',
                            font: { size: 11 },
                            padding: 8,
                        },
                    },
                },
            },
        });
    }

    #withAlpha(hex, alpha) {
        const v = hex.replace('#', '');
        const r = parseInt(v.substring(0, 2), 16);
        const g = parseInt(v.substring(2, 4), 16);
        const b = parseInt(v.substring(4, 6), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }
}
