/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Grouped bar chart with one or more data series.
 *
 * Each series is a plain object on data-chart-bars-series-value:
 *   { label, data, color, fillColor }
 *
 * - `color`     : bar border color
 * - `fillColor` : bar fill (any CSS color, opacity baked in)
 *
 * Chart.js is lazy-loaded inside connect() so public pages never ship the
 * ~200 KB bundle.
 */
export default class extends Controller {
    static values = {
        labels: Array,
        series: Array,
    };

    async connect() {
        const { Chart } = await import('chart.js/auto');

        const datasets = this.seriesValue.map((serie) => ({
            type: 'bar',
            label: serie.label,
            data: serie.data,
            backgroundColor: serie.fillColor,
            borderColor: serie.color,
            borderWidth: 2,
            borderRadius: 6,
            borderSkipped: 'bottom',
            categoryPercentage: 0.7,
            barPercentage: 0.85,
        }));

        this.chart = new Chart(this.element, {
            type: 'bar',
            data: {
                labels: this.labelsValue,
                datasets,
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 350, easing: 'easeOutQuart' },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: this.seriesValue.length > 1,
                        position: 'bottom',
                        align: 'end',
                        labels: {
                            boxWidth: 8,
                            boxHeight: 8,
                            usePointStyle: true,
                            pointStyle: 'rect',
                            padding: 16,
                            color: '#6b7280',
                            font: { size: 11 },
                        },
                    },
                    tooltip: {
                        backgroundColor: '#111827',
                        padding: 10,
                        titleFont: { weight: '600', size: 12 },
                        bodyFont: { size: 12 },
                        cornerRadius: 8,
                        displayColors: true,
                        boxWidth: 8,
                        boxHeight: 8,
                        usePointStyle: true,
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

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }
}
