/* ============================================
   Mining Manager - Settings JavaScript
   Version: 2.0
   ============================================ */

/* ============================================
   Settings Module
   ============================================ */
const MiningSettings = {
    // Current state
    state: {
        activeSection: 'general',
        unsavedChanges: false,
        originalValues: {}
    },
    
    // Configuration
    config: {
        autoSaveDelay: 1000,
        validateOnChange: true
    }
};

/* ============================================
   Initialize Settings
   ============================================ */

/**
 * Initialize settings page
 */
MiningSettings.init = function() {
    // console.log('Initializing Settings...');
    
    // Initialize navigation
    this.initNavigation();
    
    // Initialize forms
    this.initForms();
    
    // Initialize toggles and controls
    this.initToggles();
    this.initRangeSliders();
    this.initColorPickers();
    
    // Load settings
    this.loadSettings();
    
    // Bind event handlers
    this.bindEvents();
    
    // Initialize specific sections
    this.initTaxRateInputs();
    this.initPriceProviderSelector();
    this.initFeatureToggles();
    
    // Warn on unsaved changes
    this.setupUnsavedWarning();
    
    // console.log('Settings initialized successfully');
};

/* ============================================
   Navigation
   ============================================ */

/**
 * Initialize settings navigation
 */
MiningSettings.initNavigation = function() {
    const self = this;
    
    $('.settings-nav .nav-link').on('click', function(e) {
        e.preventDefault();
        const $link = $(this);
        const section = $link.data('section');
        
        self.switchSection(section);
    });
    
    // Handle URL hash
    const hash = window.location.hash.substring(1);
    if (hash) {
        this.switchSection(hash);
    }
};

/**
 * Switch to a settings section
 * @param {string} section - Section ID
 */
MiningSettings.switchSection = function(section) {
    // Update navigation
    $('.settings-nav .nav-link').removeClass('active');
    $(`.settings-nav .nav-link[data-section="${section}"]`).addClass('active');
    
    // Update sections
    $('.settings-section').removeClass('active');
    $(`#${section}Section`).addClass('active');
    
    // Update state
    this.state.activeSection = section;
    
    // Update URL
    window.location.hash = section;
    
    // Scroll to top
    $('.settings-content').scrollTop(0);
};

/* ============================================
   Forms
   ============================================ */

/**
 * Initialize settings forms
 */
MiningSettings.initForms = function() {
    const self = this;
    
    // Save original values
    this.saveOriginalValues();
    
    // Track changes
    $('input, select, textarea').on('change', function() {
        self.state.unsavedChanges = true;
        self.updateSaveButton();
        
        if (self.config.validateOnChange) {
            self.validateField($(this));
        }
    });
    
    // Prevent enter key submission
    $('input').on('keypress', function(e) {
        if (e.which === 13 && !$(this).is('textarea')) {
            e.preventDefault();
            return false;
        }
    });
};

/**
 * Save original form values
 */
MiningSettings.saveOriginalValues = function() {
    const self = this;
    
    $('input, select, textarea').each(function() {
        const $field = $(this);
        const name = $field.attr('name');
        
        if (name) {
            if ($field.is(':checkbox')) {
                self.state.originalValues[name] = $field.is(':checked');
            } else if ($field.is(':radio')) {
                if ($field.is(':checked')) {
                    self.state.originalValues[name] = $field.val();
                }
            } else {
                self.state.originalValues[name] = $field.val();
            }
        }
    });
};

/**
 * Check if form has changes
 * @returns {boolean} True if form has changes
 */
MiningSettings.hasChanges = function() {
    const self = this;
    let hasChanges = false;
    
    $('input, select, textarea').each(function() {
        const $field = $(this);
        const name = $field.attr('name');
        
        if (!name) return;
        
        let currentValue;
        if ($field.is(':checkbox')) {
            currentValue = $field.is(':checked');
        } else if ($field.is(':radio')) {
            if ($field.is(':checked')) {
                currentValue = $field.val();
            } else {
                return;
            }
        } else {
            currentValue = $field.val();
        }
        
        if (currentValue != self.state.originalValues[name]) {
            hasChanges = true;
            return false; // break loop
        }
    });
    
    return hasChanges;
};

/* ============================================
   Validation
   ============================================ */

/**
 * Validate a single field
 * @param {jQuery} $field - Field to validate
 * @returns {boolean} Validation result
 */
MiningSettings.validateField = function($field) {
    // Clear previous errors
    $field.removeClass('is-invalid');
    $field.next('.invalid-feedback').remove();
    
    let isValid = true;
    const value = $field.val();
    
    // Required fields
    if ($field.prop('required') && (!value || value.trim() === '')) {
        isValid = false;
        this.showFieldError($field, 'This field is required');
    }
    
    // Number validation
    if ($field.attr('type') === 'number' && value) {
        const num = parseFloat(value);
        const min = parseFloat($field.attr('min'));
        const max = parseFloat($field.attr('max'));
        
        if (isNaN(num)) {
            isValid = false;
            this.showFieldError($field, 'Please enter a valid number');
        } else if (!isNaN(min) && num < min) {
            isValid = false;
            this.showFieldError($field, `Value must be at least ${min}`);
        } else if (!isNaN(max) && num > max) {
            isValid = false;
            this.showFieldError($field, `Value must be at most ${max}`);
        }
    }
    
    // Email validation
    if ($field.attr('type') === 'email' && value) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(value)) {
            isValid = false;
            this.showFieldError($field, 'Please enter a valid email address');
        }
    }
    
    // URL validation
    if ($field.attr('type') === 'url' && value) {
        try {
            new URL(value);
        } catch (e) {
            isValid = false;
            this.showFieldError($field, 'Please enter a valid URL');
        }
    }
    
    return isValid;
};

/**
 * Show field error
 * @param {jQuery} $field - Field with error
 * @param {string} message - Error message
 */
MiningSettings.showFieldError = function($field, message) {
    $field.addClass('is-invalid');
    $field.after(`<div class="invalid-feedback">${message}</div>`);
};

/* ============================================
   Toggle Controls
   ============================================ */

/**
 * Initialize toggle switches
 */
MiningSettings.initToggles = function() {
    // Custom switches already work with Bootstrap
    // Add any additional toggle logic here
    
    $('.custom-switch-mm input[type="checkbox"]').on('change', function() {
        const $input = $(this);
        const target = $input.data('toggle-target');
        
        if (target) {
            $(target).toggle($input.is(':checked'));
        }
    });
};

/**
 * Initialize range sliders
 */
MiningSettings.initRangeSliders = function() {
    $('input[type="range"]').each(function() {
        const $slider = $(this);
        const $output = $slider.siblings('.range-value');
        
        if ($output.length) {
            $slider.on('input', function() {
                $output.text($(this).val());
            });
            
            // Set initial value
            $output.text($slider.val());
        }
    });
};

/**
 * Initialize color pickers
 */
MiningSettings.initColorPickers = function() {
    $('input[type="color"]').each(function() {
        const $picker = $(this);
        const $preview = $picker.siblings('.color-preview');
        
        $picker.on('input change', function() {
            const color = $(this).val();
            if ($preview.length) {
                $preview.css('background-color', color);
            }
        });
        
        // Set initial color
        if ($preview.length) {
            $preview.css('background-color', $picker.val());
        }
    });
};

/* ============================================
   Tax Rate Inputs
   ============================================ */

/**
 * Initialize tax rate inputs
 */
MiningSettings.initTaxRateInputs = function() {
    $('.tax-rate-input input').on('change', function() {
        const $input = $(this);
        const value = parseFloat($input.val());
        
        if (!isNaN(value)) {
            // Ensure value is between 0 and 100
            const clampedValue = Math.max(0, Math.min(100, value));
            $input.val(clampedValue.toFixed(1));
        }
    });
};

/* ============================================
   Price Provider
   ============================================ */

/**
 * Initialize price provider selector
 */
MiningSettings.initPriceProviderSelector = function() {
    const self = this;
    
    $('.price-provider-option').on('click', function() {
        const $option = $(this);
        const provider = $option.data('provider');
        
        $('.price-provider-option').removeClass('selected');
        $option.addClass('selected');
        
        $('#priceProvider').val(provider);
        self.state.unsavedChanges = true;
        self.updateSaveButton();
        
        // Show provider-specific settings
        $('.provider-settings').hide();
        $(`.provider-settings[data-provider="${provider}"]`).show();
    });
};

/* ============================================
   Feature Toggles
   ============================================ */

/**
 * Initialize feature toggles
 */
MiningSettings.initFeatureToggles = function() {
    $('.feature-toggle-item input[type="checkbox"]').on('change', function() {
        const $checkbox = $(this);
        const feature = $checkbox.data('feature');
        const enabled = $checkbox.is(':checked');
        
        // console.log(`Feature ${feature} ${enabled ? 'enabled' : 'disabled'}`);
        
        // Show/hide related settings
        const $relatedSettings = $(`.feature-settings[data-feature="${feature}"]`);
        if ($relatedSettings.length) {
            if (enabled) {
                $relatedSettings.slideDown();
            } else {
                $relatedSettings.slideUp();
            }
        }
    });
};

/* ============================================
   Load/Save Settings
   ============================================ */

/**
 * Load settings from server
 */
MiningSettings.loadSettings = function() {
    // console.log('Loading settings...');
    
    const $page = $('.settings-page');
    MiningManager.showLoading($page);
    
    return MiningManager.get(MiningManager.api.settings + '/load')
        .done(function(data) {
            MiningSettings.populateSettings(data);
            MiningSettings.saveOriginalValues();
            MiningSettings.state.unsavedChanges = false;
            MiningSettings.updateSaveButton();
            // console.log('Settings loaded successfully');
        })
        .fail(function(jqXHR) {
            console.error('Failed to load settings:', jqXHR);
            MiningManager.notifyError('Failed to load settings');
        })
        .always(function() {
            MiningManager.hideLoading($page);
        });
};

/**
 * Populate form with settings data
 * @param {Object} data - Settings data
 */
MiningSettings.populateSettings = function(data) {
    Object.keys(data).forEach(function(key) {
        const $field = $(`[name="${key}"]`);
        const value = data[key];
        
        if (!$field.length) return;
        
        if ($field.is(':checkbox')) {
            $field.prop('checked', !!value);
        } else if ($field.is(':radio')) {
            $field.filter(`[value="${value}"]`).prop('checked', true);
        } else {
            $field.val(value);
        }
        
        // Trigger change for any dependent logic
        $field.trigger('change');
    });
};

/**
 * Save settings to server
 */
MiningSettings.saveSettings = function() {
    // console.log('Saving settings...');
    
    // Validate all fields
    let isValid = true;
    $('input, select, textarea').each(function() {
        if (!MiningSettings.validateField($(this))) {
            isValid = false;
        }
    });
    
    if (!isValid) {
        MiningManager.notifyError('Please fix validation errors before saving');
        return;
    }
    
    // Collect form data
    const formData = this.collectFormData();
    
    MiningManager.buttonLoading('#saveSettings', 'Saving...');
    
    return MiningManager.post(MiningManager.api.settings + '/save', formData)
        .done(function(data) {
            MiningManager.notify('Settings saved successfully');
            MiningSettings.saveOriginalValues();
            MiningSettings.state.unsavedChanges = false;
            MiningSettings.updateSaveButton();
        })
        .fail(function(jqXHR) {
            console.error('Failed to save settings:', jqXHR);
            MiningManager.notifyError('Failed to save settings');
        })
        .always(function() {
            MiningManager.buttonReset('#saveSettings');
        });
};

/**
 * Collect form data
 * @returns {Object} Form data object
 */
MiningSettings.collectFormData = function() {
    const data = {};
    
    $('input, select, textarea').each(function() {
        const $field = $(this);
        const name = $field.attr('name');
        
        if (!name) return;
        
        if ($field.is(':checkbox')) {
            data[name] = $field.is(':checked');
        } else if ($field.is(':radio')) {
            if ($field.is(':checked')) {
                data[name] = $field.val();
            }
        } else {
            data[name] = $field.val();
        }
    });
    
    return data;
};

/**
 * Reset settings to defaults
 */
MiningSettings.resetSettings = function() {
    MiningManager.confirm(
        'Are you sure you want to reset all settings to their default values? This action cannot be undone.',
        function() {
            MiningManager.buttonLoading('#resetSettings', 'Resetting...');
            
            MiningManager.post(MiningManager.api.settings + '/reset')
                .done(function(data) {
                    MiningManager.notify('Settings reset to defaults');
                    MiningSettings.populateSettings(data);
                    MiningSettings.saveOriginalValues();
                    MiningSettings.state.unsavedChanges = false;
                    MiningSettings.updateSaveButton();
                })
                .fail(function() {
                    MiningManager.notifyError('Failed to reset settings');
                })
                .always(function() {
                    MiningManager.buttonReset('#resetSettings');
                });
        }
    );
};

/* ============================================
   UI Updates
   ============================================ */

/**
 * Update save button state
 */
MiningSettings.updateSaveButton = function() {
    const $saveBtn = $('#saveSettings');
    const hasChanges = this.hasChanges();
    
    $saveBtn.prop('disabled', !hasChanges);
    
    if (hasChanges) {
        $saveBtn.removeClass('btn-secondary').addClass('btn-mm-primary');
        $saveBtn.html('<i class="fas fa-save"></i> Save Changes');
    } else {
        $saveBtn.removeClass('btn-mm-primary').addClass('btn-secondary');
        $saveBtn.html('<i class="fas fa-check"></i> Saved');
    }
};

/* ============================================
   Event Handlers
   ============================================ */

/**
 * Bind settings event handlers
 */
MiningSettings.bindEvents = function() {
    const self = this;
    
    // Save button
    $('#saveSettings').on('click', function(e) {
        e.preventDefault();
        self.saveSettings();
    });
    
    // Reset button
    $('#resetSettings').on('click', function(e) {
        e.preventDefault();
        self.resetSettings();
    });
    
    // Cancel button
    $('#cancelSettings').on('click', function(e) {
        e.preventDefault();
        if (self.state.unsavedChanges) {
            MiningManager.confirm(
                'You have unsaved changes. Are you sure you want to discard them?',
                function() {
                    self.loadSettings();
                }
            );
        }
    });
    
    // Test connection buttons
    $('.test-connection-btn').on('click', function(e) {
        e.preventDefault();
        const service = $(this).data('service');
        self.testConnection(service);
    });
    
    // Import settings
    $('#importSettings').on('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            self.importSettings(file);
        }
    });
    
    // Export settings
    $('#exportSettings').on('click', function(e) {
        e.preventDefault();
        self.exportSettings();
    });
};

/* ============================================
   Test Connections
   ============================================ */

/**
 * Test connection to external service
 * @param {string} service - Service name
 */
MiningSettings.testConnection = function(service) {
    const $btn = $(`.test-connection-btn[data-service="${service}"]`);
    MiningManager.buttonLoading($btn, 'Testing...');
    
    MiningManager.post(MiningManager.api.settings + '/test-connection', { service: service })
        .done(function(data) {
            if (data.success) {
                MiningManager.notify(`Connection to ${service} successful`);
                $btn.html('<i class="fas fa-check"></i> Connected');
                $btn.removeClass('btn-secondary').addClass('btn-success');
            } else {
                MiningManager.notifyError(`Connection to ${service} failed: ${data.message}`);
                $btn.html('<i class="fas fa-times"></i> Failed');
                $btn.removeClass('btn-secondary').addClass('btn-danger');
            }
        })
        .fail(function() {
            MiningManager.notifyError(`Failed to test connection to ${service}`);
        })
        .always(function() {
            setTimeout(function() {
                MiningManager.buttonReset($btn);
            }, 3000);
        });
};

/* ============================================
   Import/Export
   ============================================ */

/**
 * Import settings from file
 * @param {File} file - Settings file
 */
MiningSettings.importSettings = function(file) {
    const reader = new FileReader();
    
    reader.onload = function(e) {
        try {
            const settings = JSON.parse(e.target.result);
            
            MiningManager.confirm(
                'This will overwrite your current settings. Are you sure you want to continue?',
                function() {
                    MiningSettings.populateSettings(settings);
                    MiningSettings.state.unsavedChanges = true;
                    MiningSettings.updateSaveButton();
                    MiningManager.notify('Settings imported successfully. Click Save to apply.');
                }
            );
        } catch (error) {
            console.error('Failed to parse settings file:', error);
            MiningManager.notifyError('Invalid settings file format');
        }
    };
    
    reader.readAsText(file);
};

/**
 * Export settings to file
 */
MiningSettings.exportSettings = function() {
    const settings = this.collectFormData();
    const json = JSON.stringify(settings, null, 2);
    const blob = new Blob([json], { type: 'application/json' });
    const url = window.URL.createObjectURL(blob);
    
    const a = document.createElement('a');
    a.href = url;
    a.download = `mining-manager-settings-${MiningManager.formatDate(new Date(), 'YYYY-MM-DD')}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
    
    MiningManager.notify('Settings exported successfully');
};

/* ============================================
   Unsaved Changes Warning
   ============================================ */

/**
 * Set up warning for unsaved changes
 */
MiningSettings.setupUnsavedWarning = function() {
    const self = this;
    
    window.addEventListener('beforeunload', function(e) {
        if (self.state.unsavedChanges) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            return e.returnValue;
        }
    });
};

/* ============================================
   Keyboard Shortcuts
   ============================================ */

/**
 * Set up keyboard shortcuts
 */
MiningSettings.setupKeyboardShortcuts = function() {
    const self = this;
    
    $(document).on('keydown', function(e) {
        // Ctrl/Cmd + S to save
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            if (self.state.unsavedChanges) {
                self.saveSettings();
            }
        }
        
        // Escape to cancel
        if (e.key === 'Escape') {
            if (self.state.unsavedChanges) {
                $('#cancelSettings').click();
            }
        }
    });
};

/* ============================================
   Initialize on Page Load
   ============================================ */

$(document).ready(function() {
    if ($('.settings-page').length) {
        MiningSettings.init();
        MiningSettings.setupKeyboardShortcuts();
    }
});

/* ============================================
   Cleanup
   ============================================ */

/**
 * Cleanup settings resources
 */
MiningSettings.cleanup = function() {
    // Remove event listeners
    $(document).off('keydown');
    
    // console.log('Settings cleanup complete');
};

// Cleanup on page unload
$(window).on('beforeunload', function() {
    if (typeof MiningSettings !== 'undefined') {
        MiningSettings.cleanup();
    }
});

/* ============================================
   Export for module systems
   ============================================ */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = MiningSettings;
}

/* ============================================
   End of Settings JavaScript
   ============================================ */
