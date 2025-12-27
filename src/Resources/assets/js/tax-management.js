/* ============================================
   Mining Manager - Tax Management JavaScript
   Version: 2.0
   ============================================ */

/* ============================================
   Tax Management Module
   ============================================ */
const TaxManagement = {
    // Current state
    state: {
        currentPeriod: 'monthly',
        taxCode: null,
        calculation: null,
        paymentMethod: 'contract'
    },
    
    // Configuration
    config: {
        taxCodeLength: 12,
        autoCalcDelay: 500
    }
};

/* ============================================
   Initialize Tax Management
   ============================================ */

/**
 * Initialize tax management
 */
TaxManagement.init = function() {
    console.log('Initializing Tax Management...');
    
    // Load saved preferences
    this.loadPreferences();
    
    // Bind event handlers
    this.bindEvents();
    
    // Initialize components
    this.initPeriodSelector();
    this.initPaymentMethodSelector();
    this.initTaxCodeCopy();
    
    // Load initial data if on calculator page
    if ($('#taxCalculator').length) {
        this.calculateTax();
    }
    
    // Load tax history if on history page
    if ($('#taxHistory').length) {
        this.loadTaxHistory();
    }
    
    console.log('Tax Management initialized successfully');
};

/* ============================================
   Period Selection
   ============================================ */

/**
 * Initialize period selector
 */
TaxManagement.initPeriodSelector = function() {
    const self = this;
    
    $('.period-btn').on('click', function() {
        const $btn = $(this);
        const period = $btn.data('period');
        
        $('.period-btn').removeClass('active');
        $btn.addClass('active');
        
        self.state.currentPeriod = period;
        self.savePreferences();
        self.calculateTax();
    });
    
    // Set initial period
    const savedPeriod = MiningManager.loadLocal('tax_period', 'monthly');
    $(`.period-btn[data-period="${savedPeriod}"]`).addClass('active');
    this.state.currentPeriod = savedPeriod;
};

/* ============================================
   Tax Calculation
   ============================================ */

/**
 * Calculate tax for current period
 */
TaxManagement.calculateTax = function() {
    console.log('Calculating tax...');
    
    const $calculator = $('#taxCalculator');
    if (!$calculator.length) return;
    
    MiningManager.showLoading($calculator);
    
    const params = {
        period: this.state.currentPeriod,
        start_date: $('#startDate').val(),
        end_date: $('#endDate').val()
    };
    
    return MiningManager.get(MiningManager.api.taxes + '/calculate', params)
        .done(function(data) {
            TaxManagement.displayTaxCalculation(data);
            TaxManagement.state.calculation = data;
            console.log('Tax calculated successfully');
        })
        .fail(function(jqXHR) {
            console.error('Tax calculation failed:', jqXHR);
            MiningManager.notifyError('Failed to calculate taxes');
        })
        .always(function() {
            MiningManager.hideLoading($calculator);
        });
};

/**
 * Display tax calculation results
 * @param {Object} data - Tax calculation data
 */
TaxManagement.displayTaxCalculation = function(data) {
    // Update summary cards
    this.updateTaxSummary(data.summary);
    
    // Update breakdown table
    this.updateTaxBreakdown(data.breakdown);
    
    // Update charts
    if (data.chart_data) {
        this.updateTaxCharts(data.chart_data);
    }
    
    // Generate and display tax code
    if (data.tax_code) {
        this.displayTaxCode(data.tax_code);
    }
    
    // Update payment instructions
    this.updatePaymentInstructions(data);
};

/**
 * Update tax summary cards
 * @param {Object} summary - Tax summary data
 */
TaxManagement.updateTaxSummary = function(summary) {
    if (!summary) return;
    
    // Update each summary card
    Object.keys(summary).forEach(function(key) {
        const $card = $(`#tax-${key}`);
        if ($card.length) {
            const value = summary[key];
            $card.find('.tax-summary-value').text(MiningManager.formatISK(value));
        }
    });
    
    // Update total
    if (summary.total) {
        $('#taxTotal').text(MiningManager.formatISK(summary.total));
    }
};

/**
 * Update tax breakdown table
 * @param {Array} breakdown - Tax breakdown data
 */
TaxManagement.updateTaxBreakdown = function(breakdown) {
    const $tbody = $('#taxBreakdownTable tbody');
    if (!$tbody.length || !breakdown) return;
    
    let html = '';
    let total = 0;
    
    breakdown.forEach(function(item) {
        const oreIcon = TaxManagement.getOreIcon(item.ore_type);
        const taxAmount = item.value * (item.tax_rate / 100);
        total += taxAmount;
        
        html += `
            <tr class="tax-breakdown-row">
                <td class="tax-breakdown-cell cell-ore-type">
                    <div class="ore-type-icon" style="background: ${TaxManagement.getOreColor(item.ore_type)}20;">
                        <i class="${oreIcon}" style="color: ${TaxManagement.getOreColor(item.ore_type)};"></i>
                    </div>
                    <div>
                        <div class="ore-type-name">${item.ore_type}</div>
                        <div class="ore-type-rate">${item.tax_rate}% tax rate</div>
                    </div>
                </td>
                <td class="tax-breakdown-cell text-right">
                    ${MiningManager.formatNumber(item.quantity)}
                </td>
                <td class="tax-breakdown-cell text-right">
                    ${MiningManager.formatISK(item.value)}
                </td>
                <td class="tax-breakdown-cell text-right">
                    ${MiningManager.formatISK(taxAmount)}
                </td>
            </tr>
        `;
    });
    
    // Add total row
    html += `
        <tr class="tax-breakdown-row tax-breakdown-total">
            <td class="tax-breakdown-cell" colspan="3"><strong>TOTAL TAX DUE</strong></td>
            <td class="tax-breakdown-cell text-right"><strong>${MiningManager.formatISK(total)}</strong></td>
        </tr>
    `;
    
    $tbody.html(html);
};

/**
 * Get ore type icon
 * @param {string} oreType - Ore type name
 * @returns {string} Icon class
 */
TaxManagement.getOreIcon = function(oreType) {
    const icons = {
        'ore': 'fas fa-gem',
        'ice': 'fas fa-snowflake',
        'moon': 'fas fa-moon',
        'gas': 'fas fa-wind',
        'mercoxit': 'fas fa-radiation',
        'default': 'fas fa-cube'
    };
    
    const type = oreType.toLowerCase();
    return icons[type] || icons.default;
};

/**
 * Get ore type color
 * @param {string} oreType - Ore type name
 * @returns {string} Color hex code
 */
TaxManagement.getOreColor = function(oreType) {
    const type = oreType.toLowerCase();
    return MiningManager.config.chartColors[type] || '#9ca3af';
};

/* ============================================
   Tax Code Management
   ============================================ */

/**
 * Display tax code
 * @param {string} code - Tax code
 */
TaxManagement.displayTaxCode = function(code) {
    const $codeDisplay = $('#taxCodeDisplay');
    if (!$codeDisplay.length) return;
    
    this.state.taxCode = code;
    
    $codeDisplay.find('.tax-code-value').text(code);
    $codeDisplay.show();
    
    // Animate appearance
    $codeDisplay.addClass('fade-in');
};

/**
 * Initialize tax code copy functionality
 */
TaxManagement.initTaxCodeCopy = function() {
    $(document).on('click', '.btn-copy-code', function(e) {
        e.preventDefault();
        const code = TaxManagement.state.taxCode;
        
        if (!code) {
            MiningManager.notifyWarning('No tax code available');
            return;
        }
        
        const $btn = $(this);
        const originalHtml = $btn.html();
        
        MiningManager.copyToClipboard(code, function() {
            $btn.html('<i class="fas fa-check"></i> Copied!');
            $btn.addClass('copied');
            
            setTimeout(function() {
                $btn.html(originalHtml);
                $btn.removeClass('copied');
            }, 2000);
        });
    });
};

/* ============================================
   Payment Methods
   ============================================ */

/**
 * Initialize payment method selector
 */
TaxManagement.initPaymentMethodSelector = function() {
    const self = this;
    
    $('.payment-method-card').on('click', function() {
        const $card = $(this);
        const method = $card.data('method');
        
        $('.payment-method-card').removeClass('selected');
        $card.addClass('selected');
        
        self.state.paymentMethod = method;
        self.savePreferences();
        self.updatePaymentInstructions();
    });
    
    // Set initial method
    const savedMethod = MiningManager.loadLocal('payment_method', 'contract');
    $(`.payment-method-card[data-method="${savedMethod}"]`).addClass('selected');
    this.state.paymentMethod = savedMethod;
};

/**
 * Update payment instructions based on selected method
 * @param {Object} data - Optional calculation data
 */
TaxManagement.updatePaymentInstructions = function(data) {
    const $instructions = $('#paymentInstructions');
    if (!$instructions.length) return;
    
    const method = this.state.paymentMethod;
    const taxCode = this.state.taxCode;
    const amount = data && data.summary ? data.summary.total : 0;
    
    let html = '';
    
    if (method === 'contract') {
        html = this.getContractInstructions(taxCode, amount);
    } else if (method === 'wallet') {
        html = this.getWalletInstructions(taxCode, amount);
    }
    
    $instructions.html(html);
};

/**
 * Get contract payment instructions
 * @param {string} taxCode - Tax code
 * @param {number} amount - Tax amount
 * @returns {string} HTML instructions
 */
TaxManagement.getContractInstructions = function(taxCode, amount) {
    return `
        <div class="payment-steps">
            <div class="payment-step">
                <div class="payment-step-title">Step 1: Create Item Exchange Contract</div>
                <div class="payment-step-description">
                    In EVE Online, create an Item Exchange contract for the items you've mined.
                </div>
            </div>
            <div class="payment-step">
                <div class="payment-step-title">Step 2: Set Contract Details</div>
                <div class="payment-step-description">
                    Assign the contract to: <strong>${this.getCorpName()}</strong><br>
                    Include all mined items from the period.
                </div>
            </div>
            <div class="payment-step">
                <div class="payment-step-title">Step 3: Add Tax Code to Description</div>
                <div class="payment-step-description">
                    In the contract description, include your tax code: <code>${taxCode || 'TAX-XXXXXX'}</code><br>
                    This helps us match your payment to your mining activity.
                </div>
            </div>
            <div class="payment-step">
                <div class="payment-step-title">Step 4: Submit Contract</div>
                <div class="payment-step-description">
                    Submit the contract. It will be processed within 24 hours.
                    You can track the status on the Tax History page.
                </div>
            </div>
        </div>
    `;
};

/**
 * Get wallet payment instructions
 * @param {string} taxCode - Tax code
 * @param {number} amount - Tax amount
 * @returns {string} HTML instructions
 */
TaxManagement.getWalletInstructions = function(taxCode, amount) {
    return `
        <div class="payment-steps">
            <div class="payment-step">
                <div class="payment-step-title">Step 1: Calculate Tax Amount</div>
                <div class="payment-step-description">
                    Total tax due: <strong>${MiningManager.formatISK(amount)}</strong>
                </div>
            </div>
            <div class="payment-step">
                <div class="payment-step-title">Step 2: Send ISK Transfer</div>
                <div class="payment-step-description">
                    In EVE Online, send ISK to: <strong>${this.getCorpWallet()}</strong><br>
                    Amount: ${MiningManager.formatISK(amount)}
                </div>
            </div>
            <div class="payment-step">
                <div class="payment-step-title">Step 3: Include Tax Code in Reason</div>
                <div class="payment-step-description">
                    In the transfer reason field, include: <code>${taxCode || 'TAX-XXXXXX'}</code><br>
                    This is required to match your payment to your mining activity.
                </div>
            </div>
            <div class="payment-step">
                <div class="payment-step-title">Step 4: Confirmation</div>
                <div class="payment-step-description">
                    Once sent, your payment will be processed within 24 hours.
                    Check the Tax History page for payment status.
                </div>
            </div>
        </div>
    `;
};

/**
 * Get corporation name
 * @returns {string} Corporation name
 */
TaxManagement.getCorpName = function() {
    return $('#corpName').text() || 'Your Corporation';
};

/**
 * Get corporation wallet
 * @returns {string} Corporation wallet name
 */
TaxManagement.getCorpWallet = function() {
    return $('#corpWallet').text() || 'Corp Wallet';
};

/* ============================================
   Tax History
   ============================================ */

/**
 * Load tax history
 */
TaxManagement.loadTaxHistory = function() {
    console.log('Loading tax history...');
    
    const $container = $('#taxHistory');
    MiningManager.showLoading($container);
    
    return MiningManager.get(MiningManager.api.taxes + '/history')
        .done(function(data) {
            TaxManagement.displayTaxHistory(data);
            console.log('Tax history loaded');
        })
        .fail(function(jqXHR) {
            console.error('Failed to load tax history:', jqXHR);
            MiningManager.notifyError('Failed to load tax history');
        })
        .always(function() {
            MiningManager.hideLoading($container);
        });
};

/**
 * Display tax history
 * @param {Array} history - Tax history data
 */
TaxManagement.displayTaxHistory = function(history) {
    const $container = $('#taxHistoryList');
    if (!$container.length) return;
    
    if (!history || history.length === 0) {
        $container.html(`
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-receipt"></i></div>
                <div class="empty-state-title">No Tax History</div>
                <div class="empty-state-description">You don't have any tax records yet.</div>
            </div>
        `);
        return;
    }
    
    let html = '<div class="tax-history-timeline">';
    
    history.forEach(function(item) {
        const status = item.status.toLowerCase();
        const statusClass = `icon-${status}`;
        const statusIcon = TaxManagement.getTaxStatusIcon(status);
        
        html += `
            <div class="tax-history-item">
                <div class="tax-history-icon ${statusClass}">
                    <i class="${statusIcon}"></i>
                </div>
                <div class="tax-history-content">
                    <div class="tax-history-date">${MiningManager.formatDate(item.date)}</div>
                    <div class="tax-history-title">${item.title}</div>
                    <div class="tax-history-details">
                        Amount: ${MiningManager.formatISK(item.amount)} | 
                        Status: <span class="tax-status tax-status-${status}">${item.status}</span>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    $container.html(html);
};

/**
 * Get tax status icon
 * @param {string} status - Tax status
 * @returns {string} Icon class
 */
TaxManagement.getTaxStatusIcon = function(status) {
    const icons = {
        'paid': 'fas fa-check',
        'pending': 'fas fa-clock',
        'overdue': 'fas fa-exclamation-triangle',
        'partial': 'fas fa-percent',
        'calculated': 'fas fa-calculator',
        'default': 'fas fa-circle'
    };
    
    return icons[status] || icons.default;
};

/* ============================================
   Tax Charts
   ============================================ */

/**
 * Update tax charts
 * @param {Object} data - Chart data
 */
TaxManagement.updateTaxCharts = function(data) {
    if (data.ore_breakdown) {
        this.updateOreBreakdownChart(data.ore_breakdown);
    }
    
    if (data.tax_trend) {
        this.updateTaxTrendChart(data.tax_trend);
    }
};

/**
 * Update ore breakdown chart
 * @param {Object} data - Ore breakdown data
 */
TaxManagement.updateOreBreakdownChart = function(data) {
    const canvas = document.getElementById('oreBreakdownChart');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    if (this.oreBreakdownChart) {
        this.oreBreakdownChart.destroy();
    }
    
    this.oreBreakdownChart = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: data.labels,
            datasets: [{
                data: data.values,
                backgroundColor: Object.values(MiningManager.config.chartColors),
                borderColor: '#1a1d24',
                borderWidth: 2
            }]
        },
        options: $.extend(true, {}, MiningManager.getChartDefaults('pie'), {
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return `${context.label}: ${MiningManager.formatISK(context.parsed)}`;
                        }
                    }
                }
            }
        })
    });
};

/**
 * Update tax trend chart
 * @param {Object} data - Tax trend data
 */
TaxManagement.updateTaxTrendChart = function(data) {
    const canvas = document.getElementById('taxTrendChart');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    if (this.taxTrendChart) {
        this.taxTrendChart.destroy();
    }
    
    this.taxTrendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Tax Amount',
                data: data.values,
                borderColor: '#667eea',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                tension: 0.4,
                fill: true
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
   Event Handlers
   ============================================ */

/**
 * Bind tax management event handlers
 */
TaxManagement.bindEvents = function() {
    const self = this;
    
    // Recalculate button
    $('#recalculateTax').on('click', function(e) {
        e.preventDefault();
        self.calculateTax();
    });
    
    // Date range change
    $('#startDate, #endDate').on('change', MiningManager.debounce(function() {
        self.calculateTax();
    }, self.config.autoCalcDelay));
    
    // Export tax report
    $('#exportTaxReport').on('click', function(e) {
        e.preventDefault();
        self.exportTaxReport();
    });
    
    // View tax details
    $(document).on('click', '.view-tax-details', function(e) {
        e.preventDefault();
        const taxId = $(this).data('tax-id');
        self.viewTaxDetails(taxId);
    });
    
    // Pay tax button
    $(document).on('click', '.pay-tax-btn', function(e) {
        e.preventDefault();
        const taxId = $(this).data('tax-id');
        self.showPaymentModal(taxId);
    });
};

/* ============================================
   Export Functions
   ============================================ */

/**
 * Export tax report
 */
TaxManagement.exportTaxReport = function() {
    if (!this.state.calculation) {
        MiningManager.notifyWarning('Please calculate taxes first');
        return;
    }
    
    MiningManager.buttonLoading('#exportTaxReport', 'Exporting...');
    
    const params = {
        period: this.state.currentPeriod,
        calculation: this.state.calculation
    };
    
    MiningManager.post(MiningManager.api.taxes + '/export', params)
        .done(function(data) {
            // Create and download PDF or CSV
            window.open(data.download_url, '_blank');
            MiningManager.notify('Tax report exported successfully');
        })
        .fail(function() {
            MiningManager.notifyError('Failed to export tax report');
        })
        .always(function() {
            MiningManager.buttonReset('#exportTaxReport');
        });
};

/* ============================================
   Preferences
   ============================================ */

/**
 * Save preferences to local storage
 */
TaxManagement.savePreferences = function() {
    MiningManager.saveLocal('tax_period', this.state.currentPeriod);
    MiningManager.saveLocal('payment_method', this.state.paymentMethod);
};

/**
 * Load preferences from local storage
 */
TaxManagement.loadPreferences = function() {
    this.state.currentPeriod = MiningManager.loadLocal('tax_period', 'monthly');
    this.state.paymentMethod = MiningManager.loadLocal('payment_method', 'contract');
};

/* ============================================
   Initialize on Page Load
   ============================================ */

$(document).ready(function() {
    if ($('#taxManagement').length || $('#taxCalculator').length || $('#taxHistory').length) {
        TaxManagement.init();
    }
});

/* ============================================
   Export for module systems
   ============================================ */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = TaxManagement;
}

/* ============================================
   End of Tax Management JavaScript
   ============================================ */
