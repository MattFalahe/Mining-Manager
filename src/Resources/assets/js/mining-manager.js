/* ============================================
   Mining Manager - Base JavaScript
   Version: 2.0
   ============================================ */

/* ============================================
   Global Configuration
   ============================================ */
const MiningManager = {
    // API endpoints
    api: {
        base: '/mining-manager',
        dashboard: '/mining-manager/dashboard',
        taxes: '/mining-manager/taxes',
        settings: '/mining-manager/settings',
        events: '/mining-manager/events',
        moon: '/mining-manager/moon',
        analytics: '/mining-manager/analytics',
        reports: '/mining-manager/reports'
    },
    
    // Configuration
    config: {
        refreshInterval: 60000, // 1 minute
        chartColors: {
            ore: '#a1c63c',
            ice: '#00d2ff',
            moon: '#ff0084',
            gas: '#ff9f40',
            mercoxit: '#ff6384',
            abyssal: '#ffcd56',
            triglavian: '#dc3545'
        },
        dateFormat: 'YYYY-MM-DD',
        timeFormat: 'HH:mm:ss',
        currencyFormat: {
            decimals: 2,
            separator: ',',
            suffix: ' ISK'
        }
    },
    
    // State management
    state: {
        currentUser: null,
        isLoading: false,
        lastUpdate: null
    }
};

/* ============================================
   Utility Functions
   ============================================ */

/**
 * Format ISK currency
 * @param {number} value - The value to format
 * @param {boolean} compact - Use compact notation (K, M, B)
 * @returns {string} Formatted currency string
 */
MiningManager.formatISK = function(value, compact = false) {
    if (value === null || value === undefined) return '0 ISK';
    
    const num = parseFloat(value);
    if (isNaN(num)) return '0 ISK';
    
    if (compact) {
        if (num >= 1e12) return (num / 1e12).toFixed(2) + 'T ISK';
        if (num >= 1e9) return (num / 1e9).toFixed(2) + 'B ISK';
        if (num >= 1e6) return (num / 1e6).toFixed(2) + 'M ISK';
        if (num >= 1e3) return (num / 1e3).toFixed(2) + 'K ISK';
    }
    
    return num.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' ISK';
};

/**
 * Format numbers with separators
 * @param {number} value - The value to format
 * @returns {string} Formatted number string
 */
MiningManager.formatNumber = function(value) {
    if (value === null || value === undefined) return '0';
    const num = parseFloat(value);
    if (isNaN(num)) return '0';
    return num.toLocaleString('en-US');
};

/**
 * Format date
 * @param {string|Date} date - The date to format
 * @param {string} format - Format string
 * @returns {string} Formatted date string
 */
MiningManager.formatDate = function(date, format = 'YYYY-MM-DD HH:mm:ss') {
    if (!date) return '';
    
    const d = new Date(date);
    if (isNaN(d.getTime())) return '';
    
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const hours = String(d.getHours()).padStart(2, '0');
    const minutes = String(d.getMinutes()).padStart(2, '0');
    const seconds = String(d.getSeconds()).padStart(2, '0');
    
    return format
        .replace('YYYY', year)
        .replace('MM', month)
        .replace('DD', day)
        .replace('HH', hours)
        .replace('mm', minutes)
        .replace('ss', seconds);
};

/**
 * Calculate time ago
 * @param {string|Date} date - The date to calculate from
 * @returns {string} Human-readable time ago string
 */
MiningManager.timeAgo = function(date) {
    if (!date) return '';
    
    const now = new Date();
    const past = new Date(date);
    const seconds = Math.floor((now - past) / 1000);
    
    const intervals = {
        year: 31536000,
        month: 2592000,
        week: 604800,
        day: 86400,
        hour: 3600,
        minute: 60,
        second: 1
    };
    
    for (const [name, value] of Object.entries(intervals)) {
        const interval = Math.floor(seconds / value);
        if (interval >= 1) {
            return interval === 1 
                ? `${interval} ${name} ago` 
                : `${interval} ${name}s ago`;
        }
    }
    
    return 'just now';
};

/**
 * Debounce function
 * @param {Function} func - Function to debounce
 * @param {number} wait - Wait time in milliseconds
 * @returns {Function} Debounced function
 */
MiningManager.debounce = function(func, wait = 300) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
};

/**
 * Throttle function
 * @param {Function} func - Function to throttle
 * @param {number} limit - Time limit in milliseconds
 * @returns {Function} Throttled function
 */
MiningManager.throttle = function(func, limit = 300) {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
};

/* ============================================
   AJAX Helper Functions
   ============================================ */

/**
 * Make AJAX GET request
 * @param {string} url - The URL to request
 * @param {Object} data - Query parameters
 * @returns {Promise} jQuery AJAX promise
 */
MiningManager.get = function(url, data = {}) {
    return $.ajax({
        url: url,
        type: 'GET',
        data: data,
        dataType: 'json',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    });
};

/**
 * Make AJAX POST request
 * @param {string} url - The URL to request
 * @param {Object} data - POST data
 * @returns {Promise} jQuery AJAX promise
 */
MiningManager.post = function(url, data = {}) {
    return $.ajax({
        url: url,
        type: 'POST',
        data: JSON.stringify(data),
        contentType: 'application/json',
        dataType: 'json',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });
};

/* ============================================
   Loading States
   ============================================ */

/**
 * Show loading spinner
 * @param {string|jQuery} element - Element selector or jQuery object
 */
MiningManager.showLoading = function(element) {
    const $el = $(element);
    $el.addClass('loading');
    $el.css('pointer-events', 'none');
    
    if (!$el.find('.loading-overlay').length) {
        $el.append(`
            <div class="loading-overlay">
                <div class="spinner-mm"></div>
            </div>
        `);
    }
};

/**
 * Hide loading spinner
 * @param {string|jQuery} element - Element selector or jQuery object
 */
MiningManager.hideLoading = function(element) {
    const $el = $(element);
    $el.removeClass('loading');
    $el.css('pointer-events', '');
    $el.find('.loading-overlay').remove();
};

/**
 * Show button loading state
 * @param {string|jQuery} button - Button selector or jQuery object
 * @param {string} text - Optional loading text
 */
MiningManager.buttonLoading = function(button, text = 'Loading...') {
    const $btn = $(button);
    $btn.prop('disabled', true);
    $btn.addClass('btn-loading');
    $btn.data('original-text', $btn.html());
    $btn.html(`<i class="fas fa-spinner fa-spin"></i> ${text}`);
};

/**
 * Reset button from loading state
 * @param {string|jQuery} button - Button selector or jQuery object
 */
MiningManager.buttonReset = function(button) {
    const $btn = $(button);
    $btn.prop('disabled', false);
    $btn.removeClass('btn-loading');
    const originalText = $btn.data('original-text');
    if (originalText) {
        $btn.html(originalText);
    }
};

/* ============================================
   Notification Functions
   ============================================ */

/**
 * Show success notification
 * @param {string} message - Success message
 * @param {string} title - Optional title
 */
MiningManager.notify = function(message, title = 'Success') {
    toastr.success(message, title, {
        closeButton: true,
        progressBar: true,
        positionClass: 'toast-top-right',
        timeOut: 5000
    });
};

/**
 * Show error notification
 * @param {string} message - Error message
 * @param {string} title - Optional title
 */
MiningManager.notifyError = function(message, title = 'Error') {
    toastr.error(message, title, {
        closeButton: true,
        progressBar: true,
        positionClass: 'toast-top-right',
        timeOut: 7000
    });
};

/**
 * Show warning notification
 * @param {string} message - Warning message
 * @param {string} title - Optional title
 */
MiningManager.notifyWarning = function(message, title = 'Warning') {
    toastr.warning(message, title, {
        closeButton: true,
        progressBar: true,
        positionClass: 'toast-top-right',
        timeOut: 6000
    });
};

/**
 * Show info notification
 * @param {string} message - Info message
 * @param {string} title - Optional title
 */
MiningManager.notifyInfo = function(message, title = 'Info') {
    toastr.info(message, title, {
        closeButton: true,
        progressBar: true,
        positionClass: 'toast-top-right',
        timeOut: 5000
    });
};

/* ============================================
   Modal Functions
   ============================================ */

/**
 * Show confirmation modal
 * @param {string} message - Confirmation message
 * @param {Function} onConfirm - Callback on confirm
 * @param {Function} onCancel - Callback on cancel
 */
MiningManager.confirm = function(message, onConfirm, onCancel = null) {
    const modalHtml = `
        <div class="modal fade" id="confirmModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Action</h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p>${message}</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="confirmBtn">Confirm</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal
    $('#confirmModal').remove();
    
    // Add modal to body
    $('body').append(modalHtml);
    
    // Show modal
    const $modal = $('#confirmModal');
    $modal.modal('show');
    
    // Bind confirm button
    $('#confirmBtn').on('click', function() {
        if (typeof onConfirm === 'function') {
            onConfirm();
        }
        $modal.modal('hide');
    });
    
    // Bind cancel
    if (typeof onCancel === 'function') {
        $modal.on('hidden.bs.modal', function() {
            onCancel();
        });
    }
    
    // Remove modal from DOM when hidden
    $modal.on('hidden.bs.modal', function() {
        $(this).remove();
    });
};

/* ============================================
   Copy to Clipboard
   ============================================ */

/**
 * Copy text to clipboard
 * @param {string} text - Text to copy
 * @param {Function} callback - Success callback
 */
MiningManager.copyToClipboard = function(text, callback = null) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            MiningManager.notify('Copied to clipboard!', 'Success');
            if (typeof callback === 'function') {
                callback();
            }
        }).catch(function(err) {
            console.error('Failed to copy:', err);
            MiningManager.notifyError('Failed to copy to clipboard', 'Error');
        });
    } else {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        
        try {
            document.execCommand('copy');
            MiningManager.notify('Copied to clipboard!', 'Success');
            if (typeof callback === 'function') {
                callback();
            }
        } catch (err) {
            console.error('Failed to copy:', err);
            MiningManager.notifyError('Failed to copy to clipboard', 'Error');
        }
        
        document.body.removeChild(textarea);
    }
};

/* ============================================
   Data Table Helpers
   ============================================ */

/**
 * Initialize DataTable with common settings
 * @param {string} selector - Table selector
 * @param {Object} options - Additional DataTable options
 * @returns {DataTable} DataTable instance
 */
MiningManager.initDataTable = function(selector, options = {}) {
    const defaultOptions = {
        responsive: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[0, 'desc']],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoEmpty: "No entries to show",
            infoFiltered: "(filtered from _MAX_ total entries)",
            zeroRecords: "No matching records found",
            emptyTable: "No data available"
        },
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        autoWidth: false
    };
    
    const finalOptions = $.extend(true, {}, defaultOptions, options);
    return $(selector).DataTable(finalOptions);
};

/* ============================================
   Chart Helpers
   ============================================ */

/**
 * Get default chart options
 * @param {string} type - Chart type
 * @returns {Object} Chart options
 */
MiningManager.getChartDefaults = function(type = 'line') {
    const defaults = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    color: '#d1d1d1',
                    font: {
                        family: "'Source Sans Pro', sans-serif"
                    }
                }
            },
            tooltip: {
                backgroundColor: 'rgba(35, 38, 45, 0.9)',
                titleColor: '#ffffff',
                bodyColor: '#d1d1d1',
                borderColor: '#2c3138',
                borderWidth: 1,
                padding: 12,
                displayColors: true,
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) {
                            label += ': ';
                        }
                        if (context.parsed.y !== null) {
                            label += MiningManager.formatNumber(context.parsed.y);
                        }
                        return label;
                    }
                }
            }
        },
        scales: {
            x: {
                ticks: {
                    color: '#9ca3af'
                },
                grid: {
                    color: 'rgba(44, 49, 56, 0.5)'
                }
            },
            y: {
                ticks: {
                    color: '#9ca3af',
                    callback: function(value) {
                        return MiningManager.formatNumber(value);
                    }
                },
                grid: {
                    color: 'rgba(44, 49, 56, 0.5)'
                }
            }
        }
    };
    
    // Type-specific defaults
    if (type === 'pie' || type === 'doughnut') {
        delete defaults.scales;
    }
    
    return defaults;
};

/* ============================================
   Form Validation
   ============================================ */

/**
 * Validate form
 * @param {string|jQuery} form - Form selector or jQuery object
 * @returns {boolean} Validation result
 */
MiningManager.validateForm = function(form) {
    const $form = $(form);
    let isValid = true;
    
    // Clear previous errors
    $form.find('.is-invalid').removeClass('is-invalid');
    $form.find('.invalid-feedback').remove();
    
    // Check required fields
    $form.find('[required]').each(function() {
        const $field = $(this);
        const value = $field.val();
        
        if (!value || value.trim() === '') {
            isValid = false;
            $field.addClass('is-invalid');
            $field.after('<div class="invalid-feedback">This field is required</div>');
        }
    });
    
    // Check email fields
    $form.find('[type="email"]').each(function() {
        const $field = $(this);
        const value = $field.val();
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        
        if (value && !emailRegex.test(value)) {
            isValid = false;
            $field.addClass('is-invalid');
            $field.after('<div class="invalid-feedback">Please enter a valid email address</div>');
        }
    });
    
    // Check number fields
    $form.find('[type="number"]').each(function() {
        const $field = $(this);
        const value = $field.val();
        const min = $field.attr('min');
        const max = $field.attr('max');
        
        if (value && min !== undefined && parseFloat(value) < parseFloat(min)) {
            isValid = false;
            $field.addClass('is-invalid');
            $field.after(`<div class="invalid-feedback">Value must be at least ${min}</div>`);
        }
        
        if (value && max !== undefined && parseFloat(value) > parseFloat(max)) {
            isValid = false;
            $field.addClass('is-invalid');
            $field.after(`<div class="invalid-feedback">Value must be at most ${max}</div>`);
        }
    });
    
    return isValid;
};

/* ============================================
   Local Storage Helpers
   ============================================ */

/**
 * Save to local storage
 * @param {string} key - Storage key
 * @param {*} value - Value to store
 */
MiningManager.saveLocal = function(key, value) {
    try {
        const serialized = JSON.stringify(value);
        localStorage.setItem(`mm_${key}`, serialized);
    } catch (err) {
        console.error('Failed to save to localStorage:', err);
    }
};

/**
 * Load from local storage
 * @param {string} key - Storage key
 * @param {*} defaultValue - Default value if not found
 * @returns {*} Stored value or default
 */
MiningManager.loadLocal = function(key, defaultValue = null) {
    try {
        const serialized = localStorage.getItem(`mm_${key}`);
        if (serialized === null) {
            return defaultValue;
        }
        return JSON.parse(serialized);
    } catch (err) {
        console.error('Failed to load from localStorage:', err);
        return defaultValue;
    }
};

/**
 * Remove from local storage
 * @param {string} key - Storage key
 */
MiningManager.removeLocal = function(key) {
    try {
        localStorage.removeItem(`mm_${key}`);
    } catch (err) {
        console.error('Failed to remove from localStorage:', err);
    }
};

/* ============================================
   URL Helpers
   ============================================ */

/**
 * Get URL parameter
 * @param {string} param - Parameter name
 * @returns {string|null} Parameter value
 */
MiningManager.getUrlParam = function(param) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(param);
};

/**
 * Update URL parameter without reload
 * @param {string} param - Parameter name
 * @param {string} value - Parameter value
 */
MiningManager.updateUrlParam = function(param, value) {
    const url = new URL(window.location);
    url.searchParams.set(param, value);
    window.history.pushState({}, '', url);
};

/* ============================================
   Event Handlers - Common
   ============================================ */

$(document).ready(function() {
    
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip({
        boundary: 'window',
        container: 'body'
    });
    
    // Initialize popovers
    $('[data-toggle="popover"]').popover({
        trigger: 'hover',
        boundary: 'window',
        container: 'body'
    });
    
    // Auto-hide alerts
    $('.alert[data-auto-dismiss]').each(function() {
        const $alert = $(this);
        const delay = $alert.data('auto-dismiss') || 5000;
        setTimeout(function() {
            $alert.fadeOut('slow', function() {
                $(this).remove();
            });
        }, delay);
    });
    
    // Copy to clipboard buttons
    $(document).on('click', '[data-copy-text]', function(e) {
        e.preventDefault();
        const text = $(this).data('copy-text');
        MiningManager.copyToClipboard(text);
    });
    
    // Refresh button handler
    $(document).on('click', '[data-refresh]', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const $icon = $btn.find('i');
        
        $icon.addClass('fa-spin');
        $btn.prop('disabled', true);
        
        const target = $btn.data('refresh');
        if (target && typeof window[target] === 'function') {
            window[target]().always(function() {
                $icon.removeClass('fa-spin');
                $btn.prop('disabled', false);
            });
        } else {
            location.reload();
        }
    });
    
    // Print button handler
    $(document).on('click', '[data-print]', function(e) {
        e.preventDefault();
        window.print();
    });
    
    // Number input formatting
    $('input[type="number"]').on('change', function() {
        const $input = $(this);
        const value = parseFloat($input.val());
        if (!isNaN(value)) {
            $input.val(value.toFixed(2));
        }
    });
    
    // Prevent double form submission
    $('form').on('submit', function() {
        const $form = $(this);
        const $submitBtn = $form.find('button[type="submit"]');
        
        if ($form.data('submitted') === true) {
            return false;
        }
        
        $form.data('submitted', true);
        $submitBtn.prop('disabled', true);
        
        setTimeout(function() {
            $form.data('submitted', false);
            $submitBtn.prop('disabled', false);
        }, 3000);
    });
    
    // Smooth scroll for anchor links
    $('a[href^="#"]').on('click', function(e) {
        const target = $(this.hash);
        if (target.length) {
            e.preventDefault();
            $('html, body').animate({
                scrollTop: target.offset().top - 70
            }, 500);
        }
    });
    
    // Back to top button
    const $backToTop = $('<button>', {
        class: 'btn btn-mm-primary btn-back-to-top',
        html: '<i class="fas fa-arrow-up"></i>',
        css: {
            position: 'fixed',
            bottom: '20px',
            right: '20px',
            display: 'none',
            'z-index': 9999
        }
    }).appendTo('body');
    
    $(window).scroll(function() {
        if ($(this).scrollTop() > 300) {
            $backToTop.fadeIn();
        } else {
            $backToTop.fadeOut();
        }
    });
    
    $backToTop.on('click', function() {
        $('html, body').animate({ scrollTop: 0 }, 500);
    });
    
});

/* ============================================
   Error Handling
   ============================================ */

/**
 * Global AJAX error handler
 */
$(document).ajaxError(function(event, jqXHR, settings, thrownError) {
    console.error('AJAX Error:', {
        url: settings.url,
        status: jqXHR.status,
        error: thrownError
    });
    
    let message = 'An error occurred. Please try again.';
    
    if (jqXHR.status === 401) {
        message = 'Your session has expired. Please log in again.';
    } else if (jqXHR.status === 403) {
        message = 'You do not have permission to perform this action.';
    } else if (jqXHR.status === 404) {
        message = 'The requested resource was not found.';
    } else if (jqXHR.status === 500) {
        message = 'A server error occurred. Please try again later.';
    } else if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
        message = jqXHR.responseJSON.message;
    }
    
    MiningManager.notifyError(message);
});

/* ============================================
   Console Information
   ============================================ */
// console.log('%cMining Manager', 'color: #667eea; font-size: 24px; font-weight: bold;');
// console.log('%cVersion 2.0', 'color: #9ca3af; font-size: 14px;');
// console.log('%cInitialized successfully', 'color: #28a745; font-size: 12px;');

/* ============================================
   Export for module systems
   ============================================ */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = MiningManager;
}

/* ============================================
   End of Base JavaScript
   ============================================ */
