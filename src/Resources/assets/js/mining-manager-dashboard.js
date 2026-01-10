/* ============================================
   Mining Manager - Dashboard JavaScript
   Version: 2.0
   ============================================ */

/* ============================================
   Dashboard Module
   ============================================ */
const MiningDashboard = {
    // Chart instances
    charts: {},
    
    // Update intervals
    intervals: {
        stats: null,
        activity: null
    },
    
    // Configuration
    config: {
        refreshInterval: 60000, // 1 minute
        chartAnimationDuration: 1000
    }
};

/* ============================================
   Initialize Dashboard
   ============================================ */

/**
 * Initialize the dashboard
 */
MiningDashboard.init = function() {
    // console.log('Initializing Mining Dashboard...');
    
    // Initialize charts
    this.initOreDistributionChart();
    this.initMiningTrendsChart();
    this.initTopMinersChart();
    this.initValueChart();
    
    // Load initial data
    this.loadDashboardStats();
    this.loadRecentActivity();
    
    // Set up auto-refresh
    this.setupAutoRefresh();
    
    // Bind event handlers
    this.bindEvents();
    
    // console.log('Mining Dashboard initialized successfully');
};

/* ============================================
   Chart Initialization
   ============================================ */

/**
 * Initialize ore distribution pie chart
 */
MiningDashboard.initOreDistributionChart = function() {
    const canvas = document.getElementById('oreDistributionChart');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    this.charts.oreDistribution = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: [],
            datasets: [{
                data: [],
                backgroundColor: [
                    MiningManager.config.chartColors.ore,
                    MiningManager.config.chartColors.ice,
                    MiningManager.config.chartColors.moon,
                    MiningManager.config.chartColors.gas,
                    MiningManager.config.chartColors.mercoxit
                ],
                borderColor: '#1a1d24',
                borderWidth: 2
            }]
        },
        options: $.extend(true, {}, MiningManager.getChartDefaults('doughnut'), {
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 15,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return `${label}: ${MiningManager.formatISK(value)} (${percentage}%)`;
                        }
                    }
                }
            }
        })
    });
};

/**
 * Initialize mining trends line chart
 */
MiningDashboard.initMiningTrendsChart = function() {
    const canvas = document.getElementById('miningTrendsChart');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    this.charts.miningTrends = new Chart(ctx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [
                {
                    label: 'Ore',
                    data: [],
                    borderColor: MiningManager.config.chartColors.ore,
                    backgroundColor: MiningManager.config.chartColors.ore + '20',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Ice',
                    data: [],
                    borderColor: MiningManager.config.chartColors.ice,
                    backgroundColor: MiningManager.config.chartColors.ice + '20',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Moon',
                    data: [],
                    borderColor: MiningManager.config.chartColors.moon,
                    backgroundColor: MiningManager.config.chartColors.moon + '20',
                    tension: 0.4,
                    fill: true
                }
            ]
        },
        options: $.extend(true, {}, MiningManager.getChartDefaults('line'), {
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return `${context.dataset.label}: ${MiningManager.formatISK(context.parsed.y)}`;
                        }
                    }
                }
            }
        })
    });
};

/**
 * Initialize top miners bar chart
 */
MiningDashboard.initTopMinersChart = function() {
    const canvas = document.getElementById('topMinersChart');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    this.charts.topMiners = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: [],
            datasets: [{
                label: 'Total Mined (ISK)',
                data: [],
                backgroundColor: MiningManager.config.chartColors.ore,
                borderColor: MiningManager.config.chartColors.ore,
                borderWidth: 1
            }]
        },
        options: $.extend(true, {}, MiningManager.getChartDefaults('bar'), {
            indexAxis: 'y',
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return MiningManager.formatISK(context.parsed.x);
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        callback: function(value) {
                            return MiningManager.formatISK(value, true);
                        }
                    }
                }
            }
        })
    });
};

/**
 * Initialize value over time chart
 */
MiningDashboard.initValueChart = function() {
    const canvas = document.getElementById('valueChart');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    this.charts.value = new Chart(ctx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                label: 'Total Value',
                data: [],
                borderColor: '#667eea',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                tension: 0.4,
                fill: true,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: $.extend(true, {}, MiningManager.getChartDefaults('line'), {
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return MiningManager.formatISK(context.parsed.y);
                        }
                    }
                }
            }
        })
    });
};

/* ============================================
   Data Loading Functions
   ============================================ */

/**
 * Load dashboard statistics
 */
MiningDashboard.loadDashboardStats = function() {
    // console.log('Loading dashboard stats...');
    
    return MiningManager.get(MiningManager.api.dashboard + '/stats')
        .done(function(data) {
            MiningDashboard.updateStats(data);
            // console.log('Dashboard stats loaded');
        })
        .fail(function(jqXHR) {
            console.error('Failed to load dashboard stats:', jqXHR);
        });
};

/**
 * Update statistics display
 * @param {Object} data - Statistics data
 */
MiningDashboard.updateStats = function(data) {
    // Update stat boxes
    if (data.total_value) {
        $('#totalValue').text(MiningManager.formatISK(data.total_value));
    }
    
    if (data.total_volume) {
        $('#totalVolume').text(MiningManager.formatNumber(data.total_volume) + ' m³');
    }
    
    if (data.active_miners) {
        $('#activeMiners').text(MiningManager.formatNumber(data.active_miners));
    }
    
    if (data.operations) {
        $('#totalOperations').text(MiningManager.formatNumber(data.operations));
    }
    
    // Update charts
    if (data.ore_distribution) {
        this.updateOreDistributionChart(data.ore_distribution);
    }
    
    if (data.mining_trends) {
        this.updateMiningTrendsChart(data.mining_trends);
    }
    
    if (data.top_miners) {
        this.updateTopMinersChart(data.top_miners);
    }
    
    if (data.value_history) {
        this.updateValueChart(data.value_history);
    }
    
    // Update last refresh time
    $('#lastUpdate').text(MiningManager.formatDate(new Date(), 'HH:mm:ss'));
};

/**
 * Load recent activity
 */
MiningDashboard.loadRecentActivity = function() {
    // console.log('Loading recent activity...');
    
    return MiningManager.get(MiningManager.api.dashboard + '/activity')
        .done(function(data) {
            MiningDashboard.updateRecentActivity(data);
            // console.log('Recent activity loaded');
        })
        .fail(function(jqXHR) {
            console.error('Failed to load recent activity:', jqXHR);
        });
};

/**
 * Update recent activity display
 * @param {Array} activities - Activity data
 */
MiningDashboard.updateRecentActivity = function(activities) {
    const $container = $('#recentActivity');
    if (!$container.length) return;
    
    if (!activities || activities.length === 0) {
        $container.html('<p class="text-muted text-center py-4">No recent activity</p>');
        return;
    }
    
    let html = '<ul class="activity-feed">';
    
    activities.forEach(function(activity) {
        const icon = MiningDashboard.getActivityIcon(activity.type);
        const timeAgo = MiningManager.timeAgo(activity.created_at);
        
        html += `
            <li class="activity-feed-item">
                <div class="activity-feed-icon">
                    <i class="${icon}"></i>
                </div>
                <div class="activity-feed-content">
                    <div class="activity-feed-title">${activity.title}</div>
                    <div class="activity-feed-description">${activity.description}</div>
                    <div class="activity-feed-time">${timeAgo}</div>
                </div>
            </li>
        `;
    });
    
    html += '</ul>';
    $container.html(html);
};

/**
 * Get icon for activity type
 * @param {string} type - Activity type
 * @returns {string} Icon class
 */
MiningDashboard.getActivityIcon = function(type) {
    const icons = {
        'mining': 'fas fa-gem',
        'tax': 'fas fa-coins',
        'moon': 'fas fa-moon',
        'event': 'fas fa-calendar',
        'alert': 'fas fa-exclamation-triangle',
        'default': 'fas fa-info-circle'
    };
    
    return icons[type] || icons.default;
};

/* ============================================
   Chart Update Functions
   ============================================ */

/**
 * Update ore distribution chart
 * @param {Object} data - Ore distribution data
 */
MiningDashboard.updateOreDistributionChart = function(data) {
    const chart = this.charts.oreDistribution;
    if (!chart) return;
    
    chart.data.labels = data.labels;
    chart.data.datasets[0].data = data.values;
    chart.update('none'); // Update without animation
};

/**
 * Update mining trends chart
 * @param {Object} data - Mining trends data
 */
MiningDashboard.updateMiningTrendsChart = function(data) {
    const chart = this.charts.miningTrends;
    if (!chart) return;
    
    chart.data.labels = data.labels;
    chart.data.datasets[0].data = data.ore;
    chart.data.datasets[1].data = data.ice;
    chart.data.datasets[2].data = data.moon;
    chart.update('none');
};

/**
 * Update top miners chart
 * @param {Object} data - Top miners data
 */
MiningDashboard.updateTopMinersChart = function(data) {
    const chart = this.charts.topMiners;
    if (!chart) return;
    
    chart.data.labels = data.labels;
    chart.data.datasets[0].data = data.values;
    chart.update('none');
};

/**
 * Update value chart
 * @param {Object} data - Value history data
 */
MiningDashboard.updateValueChart = function(data) {
    const chart = this.charts.value;
    if (!chart) return;
    
    chart.data.labels = data.labels;
    chart.data.datasets[0].data = data.values;
    chart.update('none');
};

/* ============================================
   Auto-Refresh
   ============================================ */

/**
 * Set up auto-refresh intervals
 */
MiningDashboard.setupAutoRefresh = function() {
    const self = this;
    
    // Clear existing intervals
    if (this.intervals.stats) {
        clearInterval(this.intervals.stats);
    }
    if (this.intervals.activity) {
        clearInterval(this.intervals.activity);
    }
    
    // Set up new intervals
    this.intervals.stats = setInterval(function() {
        self.loadDashboardStats();
    }, this.config.refreshInterval);
    
    this.intervals.activity = setInterval(function() {
        self.loadRecentActivity();
    }, this.config.refreshInterval);
    
    // console.log(`Auto-refresh enabled (${this.config.refreshInterval / 1000}s interval)`);
};

/**
 * Stop auto-refresh
 */
MiningDashboard.stopAutoRefresh = function() {
    if (this.intervals.stats) {
        clearInterval(this.intervals.stats);
        this.intervals.stats = null;
    }
    
    if (this.intervals.activity) {
        clearInterval(this.intervals.activity);
        this.intervals.activity = null;
    }
    
    // console.log('Auto-refresh disabled');
};

/* ============================================
   Event Handlers
   ============================================ */

/**
 * Bind dashboard event handlers
 */
MiningDashboard.bindEvents = function() {
    const self = this;
    
    // Manual refresh button
    $('#refreshDashboard').on('click', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const $icon = $btn.find('i');
        
        $icon.addClass('fa-spin');
        $btn.prop('disabled', true);
        
        $.when(
            self.loadDashboardStats(),
            self.loadRecentActivity()
        ).always(function() {
            $icon.removeClass('fa-spin');
            $btn.prop('disabled', false);
            MiningManager.notify('Dashboard refreshed', 'Success');
        });
    });
    
    // Period selector
    $('.period-selector').on('click', 'button', function() {
        const $btn = $(this);
        const period = $btn.data('period');
        
        $('.period-selector button').removeClass('active');
        $btn.addClass('active');
        
        self.loadDashboardStats({ period: period });
    });
    
    // Export data button
    $('#exportData').on('click', function(e) {
        e.preventDefault();
        self.exportData();
    });
    
    // Chart type toggle
    $('.chart-type-toggle').on('click', 'button', function() {
        const $btn = $(this);
        const chartId = $btn.data('chart');
        const type = $btn.data('type');
        
        $('.chart-type-toggle button').removeClass('active');
        $btn.addClass('active');
        
        self.changeChartType(chartId, type);
    });
    
    // Info box click handlers
    $('.info-box').on('click', function() {
        const target = $(this).data('target');
        if (target) {
            window.location.href = target;
        }
    });
    
    // Table sorting
    if ($.fn.DataTable) {
        $('.mining-table').each(function() {
            MiningManager.initDataTable(this);
        });
    }
};

/* ============================================
   Export Functions
   ============================================ */

/**
 * Export dashboard data
 */
MiningDashboard.exportData = function() {
    MiningManager.buttonLoading('#exportData', 'Exporting...');
    
    MiningManager.get(MiningManager.api.dashboard + '/export')
        .done(function(data) {
            // Create CSV
            const csv = MiningDashboard.convertToCSV(data);
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            
            // Download file
            const a = document.createElement('a');
            a.href = url;
            a.download = `mining-dashboard-${MiningManager.formatDate(new Date(), 'YYYY-MM-DD')}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            MiningManager.notify('Data exported successfully', 'Success');
        })
        .fail(function() {
            MiningManager.notifyError('Failed to export data');
        })
        .always(function() {
            MiningManager.buttonReset('#exportData');
        });
};

/**
 * Convert data to CSV format
 * @param {Object} data - Data to convert
 * @returns {string} CSV string
 */
MiningDashboard.convertToCSV = function(data) {
    if (!data || data.length === 0) return '';
    
    const headers = Object.keys(data[0]);
    const rows = data.map(row => 
        headers.map(header => 
            JSON.stringify(row[header] || '')
        ).join(',')
    );
    
    return [headers.join(','), ...rows].join('\n');
};

/* ============================================
   Chart Type Toggle
   ============================================ */

/**
 * Change chart type
 * @param {string} chartId - Chart identifier
 * @param {string} type - New chart type
 */
MiningDashboard.changeChartType = function(chartId, type) {
    const chart = this.charts[chartId];
    if (!chart) return;
    
    chart.config.type = type;
    chart.update();
    
    MiningManager.saveLocal(`chart_type_${chartId}`, type);
};

/* ============================================
   Cleanup
   ============================================ */

/**
 * Cleanup dashboard resources
 */
MiningDashboard.cleanup = function() {
    // Stop auto-refresh
    this.stopAutoRefresh();
    
    // Destroy charts
    Object.values(this.charts).forEach(function(chart) {
        if (chart) {
            chart.destroy();
        }
    });
    
    this.charts = {};
    
    // console.log('Dashboard cleanup complete');
};

/* ============================================
   Window Events
   ============================================ */

// Initialize on page load
$(document).ready(function() {
    if ($('#miningDashboard').length) {
        MiningDashboard.init();
    }
});

// Cleanup on page unload
$(window).on('beforeunload', function() {
    if (typeof MiningDashboard !== 'undefined') {
        MiningDashboard.cleanup();
    }
});

// Handle visibility change (pause refresh when tab hidden)
document.addEventListener('visibilitychange', function() {
    if (typeof MiningDashboard === 'undefined') return;
    
    if (document.hidden) {
        MiningDashboard.stopAutoRefresh();
        // console.log('Dashboard paused (tab hidden)');
    } else {
        MiningDashboard.setupAutoRefresh();
        MiningDashboard.loadDashboardStats();
        // console.log('Dashboard resumed (tab visible)');
    }
});

/* ============================================
   Export for module systems
   ============================================ */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = MiningDashboard;
}

/* ============================================
   End of Dashboard JavaScript
   ============================================ */
