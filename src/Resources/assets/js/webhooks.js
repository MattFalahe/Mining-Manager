/**
 * Mining Manager - Webhook Management JavaScript
 *
 * Handles webhook configuration UI interactions
 */

// Current webhook being edited (null for new webhook)
let currentWebhookId = null;

/**
 * Open webhook modal for creating or editing
 */
function openWebhookModal(webhookId = null) {
    currentWebhookId = webhookId;

    // Reset form
    document.getElementById('webhook-form').reset();
    document.getElementById('webhook-id').value = '';

    // Update modal title
    const modalTitle = document.getElementById('webhook-modal-title');
    modalTitle.textContent = webhookId ? 'Edit Webhook' : 'Add Webhook';

    if (webhookId) {
        // Load webhook data
        loadWebhookData(webhookId);
    } else {
        // Show Discord settings by default for new webhooks
        updateWebhookTypeSettings('discord');
    }

    // Show modal
    $('#webhookModal').modal('show');
}

/**
 * Load webhook data for editing
 */
function loadWebhookData(webhookId) {
    $.ajax({
        url: `/mining-manager/settings/webhooks/${webhookId}`,
        method: 'GET',
        success: function(response) {
            if (response.success) {
                const webhook = response.webhook;

                // Fill form fields
                document.getElementById('webhook-id').value = webhook.id;
                document.getElementById('webhook-name').value = webhook.name;
                document.getElementById('webhook-type').value = webhook.type;
                document.getElementById('webhook-url').value = webhook.webhook_url;

                // Event checkboxes
                document.getElementById('notify-theft-detected').checked = webhook.notify_theft_detected;
                document.getElementById('notify-critical-theft').checked = webhook.notify_critical_theft;
                document.getElementById('notify-active-theft').checked = webhook.notify_active_theft;
                document.getElementById('notify-incident-resolved').checked = webhook.notify_incident_resolved;
                document.getElementById('notify-moon-arrival').checked = webhook.notify_moon_arrival;
                document.getElementById('notify-jackpot-detected').checked = webhook.notify_jackpot_detected;

                // Discord settings
                const discordRoleField = document.getElementById('discord-role-id');
                const discordUsernameField = document.getElementById('discord-username');
                if (webhook.discord_role_id && discordRoleField) discordRoleField.value = webhook.discord_role_id;
                if (webhook.discord_username && discordUsernameField) discordUsernameField.value = webhook.discord_username;

                // Slack settings
                const slackChannelField = document.getElementById('slack-channel');
                const slackUsernameField = document.getElementById('slack-username');
                if (webhook.slack_channel && slackChannelField) slackChannelField.value = webhook.slack_channel;
                if (webhook.slack_username && slackUsernameField) slackUsernameField.value = webhook.slack_username;

                // Custom settings
                const customPayloadField = document.getElementById('custom-payload-template');
                if (webhook.custom_payload_template && customPayloadField) customPayloadField.value = webhook.custom_payload_template;

                // Update visible settings based on type
                updateWebhookTypeSettings(webhook.type);
            }
        },
        error: function(xhr) {
            alert('Failed to load webhook data');
        }
    });
}

/**
 * Save webhook (create or update)
 */
function saveWebhook() {
    const webhookId = document.getElementById('webhook-id').value;
    const isUpdate = webhookId !== '';

    const formData = {
        _token: $('meta[name="csrf-token"]').attr('content'),
        name: document.getElementById('webhook-name').value,
        type: document.getElementById('webhook-type').value,
        webhook_url: document.getElementById('webhook-url').value,
        notify_theft_detected: document.getElementById('notify-theft-detected').checked ? 1 : 0,
        notify_critical_theft: document.getElementById('notify-critical-theft').checked ? 1 : 0,
        notify_active_theft: document.getElementById('notify-active-theft').checked ? 1 : 0,
        notify_incident_resolved: document.getElementById('notify-incident-resolved').checked ? 1 : 0,
        notify_moon_arrival: document.getElementById('notify-moon-arrival').checked ? 1 : 0,
        notify_jackpot_detected: document.getElementById('notify-jackpot-detected').checked ? 1 : 0,
        discord_role_id: document.getElementById('discord-role-id')?.value || null,
        discord_username: document.getElementById('discord-username')?.value || null,
        slack_channel: document.getElementById('slack-channel')?.value || null,
        slack_username: document.getElementById('slack-username')?.value || null,
        custom_payload_template: document.getElementById('custom-payload-template')?.value || null,
    };

    const url = isUpdate
        ? `/mining-manager/settings/webhooks/${webhookId}`
        : '/mining-manager/settings/webhooks';

    const method = isUpdate ? 'PUT' : 'POST';

    $.ajax({
        url: url,
        method: method,
        data: formData,
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        success: function(response) {
            if (response.success) {
                $('#webhookModal').modal('hide');
                // Reload page to show updated webhooks list
                location.reload();
            } else {
                alert(response.message || 'Failed to save webhook');
            }
        },
        error: function(xhr) {
            if (xhr.status === 422) {
                // Validation errors
                const errors = xhr.responseJSON.errors;
                let errorMessage = 'Validation failed:\n';
                for (const field in errors) {
                    errorMessage += `- ${errors[field].join(', ')}\n`;
                }
                alert(errorMessage);
            } else {
                alert('Failed to save webhook');
            }
        }
    });
}

/**
 * Toggle webhook enabled/disabled status
 */
function toggleWebhookStatus(webhookId, isEnabled) {
    $.ajax({
        url: `/mining-manager/settings/webhooks/${webhookId}/toggle`,
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        success: function(response) {
            if (response.success) {
                // Update UI
                const toggle = document.querySelector(`#webhook-toggle-${webhookId}`);
                if (toggle) {
                    toggle.checked = response.is_enabled;
                }
                location.reload();
            }
        },
        error: function(xhr) {
            alert('Failed to toggle webhook status');

            // Revert toggle
            const toggle = document.querySelector(`#webhook-toggle-${webhookId}`);
            if (toggle) {
                toggle.checked = !toggle.checked;
            }
        }
    });
}

/**
 * Test webhook
 */
function testWebhook(webhookId) {
    const button = event.target.closest('button');
    const originalHtml = button.innerHTML;

    // Show loading state
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    $.ajax({
        url: `/mining-manager/settings/webhooks/${webhookId}/test`,
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        success: function(response) {
            if (response.success) {
                // Reload page to update health statistics
                location.reload();
            } else {
                alert(response.message || 'Test failed');
            }
        },
        error: function(xhr) {
            const message = xhr.responseJSON?.message || 'Failed to test webhook';
            alert(message);
        },
        complete: function() {
            // Restore button
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    });
}

/**
 * Edit webhook
 */
function editWebhook(webhookId) {
    openWebhookModal(webhookId);
}

/**
 * Delete webhook
 */
function deleteWebhook(webhookId) {
    if (!confirm('Are you sure you want to delete this webhook? This action cannot be undone.')) {
        return;
    }

    $.ajax({
        url: `/mining-manager/settings/webhooks/${webhookId}`,
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        success: function(response) {
            if (response.success) {
                // Reload page to update statistics
                location.reload();
            }
        },
        error: function(xhr) {
            alert('Failed to delete webhook');
        }
    });
}

/**
 * Update webhook type-specific settings visibility
 */
function updateWebhookTypeSettings(type) {
    // Hide all type-specific settings
    document.getElementById('discord-settings').style.display = 'none';
    document.getElementById('slack-settings').style.display = 'none';
    document.getElementById('custom-settings').style.display = 'none';

    // Show relevant settings
    switch(type) {
        case 'discord':
            document.getElementById('discord-settings').style.display = 'block';
            document.getElementById('webhook-url-help').textContent = 'Go to Discord Server Settings → Integrations → Webhooks to get your webhook URL';
            break;
        case 'slack':
            document.getElementById('slack-settings').style.display = 'block';
            document.getElementById('webhook-url-help').textContent = 'Go to Slack App Settings → Incoming Webhooks to get your webhook URL';
            break;
        case 'custom':
            document.getElementById('custom-settings').style.display = 'block';
            document.getElementById('webhook-url-help').textContent = 'Enter your custom webhook endpoint URL';
            break;
    }
}

// ============================================================================
// Event Listeners
// ============================================================================

document.addEventListener('DOMContentLoaded', function() {

    // Webhook type change handler
    const webhookTypeSelect = document.getElementById('webhook-type');
    if (webhookTypeSelect) {
        webhookTypeSelect.addEventListener('change', function() {
            updateWebhookTypeSettings(this.value);
        });
    }

    // Webhook toggle handlers
    document.querySelectorAll('.webhook-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            const webhookId = this.dataset.webhookId;
            const isEnabled = this.checked;
            toggleWebhookStatus(webhookId, isEnabled);
        });
    });

    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

});
