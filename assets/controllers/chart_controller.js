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

        this.chart = new Chart(this.element, {
            type: this.typeValue,
            data: {
                labels: this.labelsValue,
                datasets: [
                    {
                        label: this.labelValue,
                        data: this.dataValue,
                        backgroundColor: 'rgba(255, 117, 51, 0.6)',
                        borderColor: 'rgba(255, 117, 51, 1)',
                        borderWidth: 1,
                        borderRadius: 4,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                    },
                },
            },
        });
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }
}
