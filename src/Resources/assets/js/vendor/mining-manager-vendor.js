/* ============================================
   Mining Manager - Vendor Bundle Integration
   Version: 2.0
   
   This file loads and initializes all vendor libraries
   in the correct order for the Mining Manager plugin.
   
   Included Libraries:
   - Chart.js 3.9.1 (Data visualization)
   - Toastr 2.1.4 (Notifications)
   - DataTables 1.13.7 (Advanced tables)
   - Moment.js 2.29.4 (Date/time handling)
   
   ============================================ */

/* ============================================
   Vendor Library Loading Order
   ============================================ */

/**
 * This file should be loaded AFTER jQuery but BEFORE
 * your custom Mining Manager scripts.
 * 
 * Correct loading order in your blade templates:
 * 
 * 1. jQuery (from SeAT)
 * 2. Bootstrap (from SeAT)
 * 3. Vendor libraries (Chart.js, Toastr, DataTables, Moment)
 * 4. mining-manager-vendor.js (this file)
 * 5. mining-manager.js
 * 6. mining-manager-dashboard.js (if needed)
 * 7. Other Mining Manager scripts
 */

/* ============================================
   Chart.js Configuration
   ============================================ */

if (typeof Chart !== 'undefined') {
    // Set global Chart.js defaults for dark theme
    Chart.defaults.color = '#d1d1d1';
    Chart.defaults.borderColor = '#2c3138';
    Chart.defaults.backgroundColor = 'rgba(102, 126, 234, 0.1)';
    
    // Legend styling
    if (Chart.defaults.plugins && Chart.defaults.plugins.legend) {
        Chart.defaults.plugins.legend.labels.color = '#d1d1d1';
        Chart.defaults.plugins.legend.labels.font = {
            family: "'Source Sans Pro', sans-serif",
            size: 12
        };
    }
    
    // Tooltip styling
    if (Chart.defaults.plugins && Chart.defaults.plugins.tooltip) {
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(35, 38, 45, 0.95)';
        Chart.defaults.plugins.tooltip.titleColor = '#ffffff';
        Chart.defaults.plugins.tooltip.bodyColor = '#d1d1d1';
        Chart.defaults.plugins.tooltip.borderColor = '#2c3138';
        Chart.defaults.plugins.tooltip.borderWidth = 1;
        Chart.defaults.plugins.tooltip.padding = 12;
        Chart.defaults.plugins.tooltip.displayColors = true;
        Chart.defaults.plugins.tooltip.boxPadding = 6;
    }
    
    // Scale defaults
    if (Chart.defaults.scale) {
        if (Chart.defaults.scale.grid) {
            Chart.defaults.scale.grid.color = 'rgba(44, 49, 56, 0.5)';
        }
        if (Chart.defaults.scale.ticks) {
            Chart.defaults.scale.ticks.color = '#9ca3af';
        }
    }
    
    console.log('✓ Chart.js configured for Mining Manager dark theme');
}

/* ============================================
   Toastr Configuration
   ============================================ */

if (typeof toastr !== 'undefined') {
    // Configure toastr for dark theme
    toastr.options = {
        closeButton: true,
        debug: false,
        newestOnTop: true,
        progressBar: true,
        positionClass: 'toast-top-right',
        preventDuplicates: true,
        onclick: null,
        showDuration: 300,
        hideDuration: 1000,
        timeOut: 5000,
        extendedTimeOut: 1000,
        showEasing: 'swing',
        hideEasing: 'linear',
        showMethod: 'fadeIn',
        hideMethod: 'fadeOut',
        // Dark theme styling
        toastClass: 'toast toast-mm',
        iconClasses: {
            error: 'toast-error',
            info: 'toast-info',
            success: 'toast-success',
            warning: 'toast-warning'
        }
    };
    
    console.log('✓ Toastr configured for Mining Manager');
}

/* ============================================
   DataTables Configuration
   ============================================ */

if (typeof $.fn.DataTable !== 'undefined') {
    // Set DataTables defaults for dark theme
    $.extend($.fn.dataTable.defaults, {
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoEmpty: "No entries to show",
            infoFiltered: "(filtered from _MAX_ total entries)",
            zeroRecords: "No matching records found",
            emptyTable: "No data available in table",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        responsive: true,
        autoWidth: false,
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>"
    });
    
    console.log('✓ DataTables configured for Mining Manager');
}

/* ============================================
   Moment.js Configuration
   ============================================ */

if (typeof moment !== 'undefined') {
    // Set default locale and format
    moment.locale('en');
    
    console.log('✓ Moment.js configured for Mining Manager');
}

/* ============================================
   Custom Toastr CSS for Dark Theme
   ============================================ */

// Inject custom toastr styles for dark theme
if (typeof toastr !== 'undefined' && !document.getElementById('mining-manager-toastr-styles')) {
    const style = document.createElement('style');
    style.id = 'mining-manager-toastr-styles';
    style.textContent = `
        /* Mining Manager Toastr Dark Theme */
        .toast-mm {
            background-color: #23262d !important;
            border: 1px solid #2c3138 !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3) !important;
        }
        
        .toast-mm .toast-title {
            color: #ffffff !important;
            font-weight: 600 !important;
        }
        
        .toast-mm .toast-message {
            color: #d1d1d1 !important;
        }
        
        .toast-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%) !important;
            border-color: #28a745 !important;
        }
        
        .toast-error {
            background: linear-gradient(135deg, #dc3545 0%, #e83e4c 100%) !important;
            border-color: #dc3545 !important;
        }
        
        .toast-warning {
            background: linear-gradient(135deg, #ffc107 0%, #ffca2c 100%) !important;
            border-color: #ffc107 !important;
            color: #000 !important;
        }
        
        .toast-warning .toast-title,
        .toast-warning .toast-message {
            color: #000 !important;
        }
        
        .toast-info {
            background: linear-gradient(135deg, #17a2b8 0%, #3fc1c9 100%) !important;
            border-color: #17a2b8 !important;
        }
        
        .toast-mm .toast-progress {
            opacity: 0.6 !important;
        }
        
        .toast-mm:hover {
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4) !important;
        }
    `;
    document.head.appendChild(style);
    console.log('✓ Toastr dark theme styles injected');
}

/* ============================================
   Vendor Bundle Ready Notification
   ============================================ */

console.log('%c Mining Manager Vendor Bundle ', 'background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-size: 14px; font-weight: bold; padding: 5px 10px; border-radius: 3px;');
console.log('%c All vendor libraries loaded and configured ', 'color: #28a745; font-size: 12px;');

// Make vendor info available globally
window.MiningManagerVendor = {
    version: '2.0',
    libraries: {
        chartjs: typeof Chart !== 'undefined' ? (Chart.version || '3.9.1') : 'not loaded',
        toastr: typeof toastr !== 'undefined' ? (toastr.version || '2.1.4') : 'not loaded',
        datatables: typeof $.fn.DataTable !== 'undefined' ? $.fn.dataTable.version : 'not loaded',
        moment: typeof moment !== 'undefined' ? moment.version : 'not loaded'
    },
    ready: true
};

/* ============================================
   Compatibility Check
   ============================================ */

$(document).ready(function() {
    // Check if required libraries are loaded
    const required = ['Chart', 'toastr'];
    const missing = [];
    
    required.forEach(function(lib) {
        if (typeof window[lib] === 'undefined') {
            missing.push(lib);
        }
    });
    
    if (missing.length > 0) {
        console.warn('Mining Manager: Missing required libraries:', missing.join(', '));
        console.warn('Some features may not work correctly.');
    } else {
        console.log('✓ All required vendor libraries loaded successfully');
    }
    
    // Log library versions
    console.log('Vendor Library Versions:', window.MiningManagerVendor.libraries);
});

/* ============================================
   Export for module systems
   ============================================ */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = window.MiningManagerVendor;
}

/* ============================================
   End of Vendor Bundle Integration
   ============================================ */
