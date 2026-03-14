@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::help.help_documentation'))
@section('page_header', trans('mining-manager::help.help_documentation'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
<style>
    .help-wrapper {
        display: flex;
        gap: 20px;
    }

    .help-sidebar {
        flex: 0 0 280px;
        position: sticky;
        top: 20px;
        max-height: calc(100vh - 120px);
        overflow-y: auto;
    }

    .help-content {
        flex: 1;
        min-width: 0;
    }

    .help-nav .nav-link {
        color: #e2e8f0;
        border-radius: 5px;
        margin-bottom: 5px;
        padding: 10px 15px;
        transition: all 0.3s;
        font-size: 0.95rem;
    }

    .help-nav .nav-link:hover {
        background: rgba(102, 126, 234, 0.2);
    }

    .help-nav .nav-link.active {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }

    .help-nav .nav-link i {
        width: 24px;
        text-align: center;
        margin-right: 10px;
    }

    .help-section {
        display: none;
        animation: fadeIn 0.3s;
    }

    .help-section.active {
        display: block;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .help-card {
        background: #2d3748;
        border-radius: 10px;
        padding: 25px;
        margin-bottom: 20px;
        border: 1px solid rgba(102, 126, 234, 0.2);
    }

    .help-card h3 {
        color: #667eea;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .help-card h4 {
        color: #9ca3af;
        margin-top: 20px;
        margin-bottom: 10px;
        font-size: 1.1rem;
    }

    .help-card p {
        color: #d1d5db;
        line-height: 1.6;
    }

    .help-card ul, .help-card ol {
        color: #d1d5db;
        line-height: 1.8;
        margin-left: 20px;
    }

    .help-card code {
        background: rgba(0, 0, 0, 0.3);
        padding: 2px 6px;
        border-radius: 3px;
        color: #fbbf24;
        font-size: 0.9em;
    }

    .help-card pre {
        background: rgba(0, 0, 0, 0.3);
        padding: 15px;
        border-radius: 5px;
        overflow-x: auto;
        color: #d1d5db;
    }

    .step-by-step {
        counter-reset: step-counter;
        list-style: none;
        padding-left: 0;
    }

    .step-by-step li {
        counter-increment: step-counter;
        margin-bottom: 20px;
        padding-left: 50px;
        position: relative;
    }

    .step-by-step li::before {
        content: counter(step-counter);
        position: absolute;
        left: 0;
        top: 0;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        width: 35px;
        height: 35px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 1.1rem;
    }

    .info-box {
        background: rgba(23, 162, 184, 0.15);
        border-left: 4px solid #17a2b8;
        padding: 15px;
        margin: 15px 0;
        border-radius: 5px;
    }

    .warning-box {
        background: rgba(255, 193, 7, 0.15);
        border-left: 4px solid #ffc107;
        padding: 15px;
        margin: 15px 0;
        border-radius: 5px;
    }

    .success-box {
        background: rgba(28, 200, 138, 0.15);
        border-left: 4px solid #1cc88a;
        padding: 15px;
        margin: 15px 0;
        border-radius: 5px;
    }

    .feature-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }

    .feature-item {
        background: rgba(102, 126, 234, 0.1);
        padding: 15px;
        border-radius: 8px;
        border: 1px solid rgba(102, 126, 234, 0.3);
    }

    .feature-item i {
        font-size: 2rem;
        color: #667eea;
        margin-bottom: 10px;
    }

    .feature-item h5 {
        color: #e2e8f0;
        margin-bottom: 8px;
    }

    .feature-item p {
        color: #9ca3af;
        font-size: 0.9rem;
        margin: 0;
    }

    .search-box {
        position: relative;
        margin-bottom: 20px;
    }

    .search-box input {
        width: 100%;
        padding: 12px 45px 12px 15px;
        background: #2d3748;
        border: 1px solid rgba(102, 126, 234, 0.3);
        border-radius: 8px;
        color: #e2e8f0;
    }

    .search-box i {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
    }

    .quick-links {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
        margin: 20px 0;
    }

    .quick-link {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        padding: 15px;
        border-radius: 8px;
        text-align: center;
        color: white;
        text-decoration: none;
        transition: transform 0.2s;
    }

    .quick-link:hover {
        transform: translateY(-2px);
        color: white;
        text-decoration: none;
    }

    .quick-link i {
        font-size: 2rem;
        display: block;
        margin-bottom: 8px;
    }

    .faq-item {
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
        margin-bottom: 10px;
        overflow: hidden;
    }

    .faq-question {
        padding: 15px;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: background 0.2s;
    }

    .faq-question:hover {
        background: rgba(102, 126, 234, 0.1);
    }

    .faq-question i {
        transition: transform 0.3s;
    }

    .faq-item.open .faq-question i {
        transform: rotate(180deg);
    }

    .faq-answer {
        padding: 0 15px;
        max-height: 0;
        overflow: hidden;
        transition: all 0.3s;
    }

    .faq-item.open .faq-answer {
        padding: 15px;
        max-height: 500px;
    }

    .plugin-info-table {
        width: 100%;
        color: #d1d5db;
    }

    .plugin-info-table td {
        padding: 8px 12px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .plugin-info-table td:first-child {
        color: #9ca3af;
        width: 120px;
        font-weight: 600;
    }

    .plugin-info-table td:last-child {
        color: #e2e8f0;
    }

    @media (max-width: 768px) {
        .help-wrapper {
            flex-direction: column;
        }

        .help-sidebar {
            position: relative;
            max-height: none;
        }

        .feature-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard help-page">

    <div class="help-wrapper">
        {{-- Sidebar Navigation --}}
        <div class="help-sidebar">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-compass"></i>
                        {{ trans('mining-manager::help.navigation') }}
                    </h3>
                </div>
                <div class="card-body p-0">
                    <ul class="nav nav-pills flex-column help-nav">
                        <li class="nav-item">
                            <a href="#" class="nav-link active" data-section="overview">
                                <i class="fas fa-info-circle"></i>
                                {{ trans('mining-manager::help.overview') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="getting-started">
                                <i class="fas fa-rocket"></i>
                                {{ trans('mining-manager::help.getting_started') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="dashboard">
                                <i class="fas fa-tachometer-alt"></i>
                                {{ trans('mining-manager::help.dashboard') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="tax-system">
                                <i class="fas fa-coins"></i>
                                {{ trans('mining-manager::help.tax_system') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="events">
                                <i class="fas fa-calendar-alt"></i>
                                {{ trans('mining-manager::help.mining_events') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="moon-mining">
                                <i class="fas fa-moon"></i>
                                {{ trans('mining-manager::help.moon_mining') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="theft-detection">
                                <i class="fas fa-user-secret"></i>
                                {{ trans('mining-manager::help.theft_detection') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="analytics">
                                <i class="fas fa-chart-line"></i>
                                {{ trans('mining-manager::help.analytics_reports') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="settings">
                                <i class="fas fa-cog"></i>
                                {{ trans('mining-manager::help.settings') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="commands">
                                <i class="fas fa-terminal"></i>
                                {{ trans('mining-manager::help.cli_commands') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="permissions">
                                <i class="fas fa-shield-alt"></i>
                                {{ trans('mining-manager::help.permissions') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="faq">
                                <i class="fas fa-question-circle"></i>
                                {{ trans('mining-manager::help.faq') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="troubleshooting">
                                <i class="fas fa-wrench"></i>
                                {{ trans('mining-manager::help.troubleshooting') }}
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Content Area --}}
        <div class="help-content">

            {{-- Search Box --}}
            <div class="search-box">
                <input type="text"
                       id="helpSearch"
                       placeholder="{{ trans('mining-manager::help.search_placeholder') }}"
                       class="form-control">
                <i class="fas fa-search"></i>
            </div>

            {{-- Overview Section --}}
            <div id="overview" class="help-section active">
                {{-- Plugin Information --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-info-circle"></i>
                        {{ trans('mining-manager::help.plugin_information') }}
                    </h3>
                    <p>
                        Version: <img src="https://img.shields.io/github/v/release/MattFalahe/Mining-Manager?label=release&color=667eea" alt="version" style="vertical-align: middle;">
                        <img src="https://img.shields.io/badge/SeAT-5.0-764ba2" alt="SeAT 5.0" style="vertical-align: middle;">
                    </p>
                    <p>License: {{ trans('mining-manager::help.plugin_license') }}</p>
                    <p>
                        <i class="fas fa-user"></i> {{ trans('mining-manager::help.plugin_author') }}<br>
                        <i class="fas fa-envelope"></i> <a href="mailto:{{ trans('mining-manager::help.plugin_author_email') }}" style="color: #667eea;">{{ trans('mining-manager::help.plugin_author_email') }}</a>
                    </p>

                    <div class="quick-links" style="margin-top: 15px;">
                        <a href="https://github.com/MattFalahe/Mining-Manager" class="quick-link" target="_blank" style="padding: 10px;">
                            <i class="fas fa-code-branch" style="font-size: 1rem; margin-bottom: 4px;"></i>
                            {{ trans('mining-manager::help.github_repository') }}
                        </a>
                        <a href="https://github.com/MattFalahe/Mining-Manager/blob/main/CHANGELOG.md" class="quick-link" target="_blank" style="padding: 10px;">
                            <i class="fas fa-list" style="font-size: 1rem; margin-bottom: 4px;"></i>
                            {{ trans('mining-manager::help.full_changelog') }}
                        </a>
                        <a href="https://github.com/MattFalahe/Mining-Manager/issues" class="quick-link" target="_blank" style="padding: 10px;">
                            <i class="fas fa-bug" style="font-size: 1rem; margin-bottom: 4px;"></i>
                            {{ trans('mining-manager::help.report_issues') }}
                        </a>
                        <a href="https://github.com/MattFalahe/Mining-Manager/blob/main/README.md" class="quick-link" target="_blank" style="padding: 10px;">
                            <i class="fas fa-book" style="font-size: 1rem; margin-bottom: 4px;"></i>
                            {{ trans('mining-manager::help.readme') }}
                        </a>
                    </div>

                    <div class="success-box" style="margin-top: 20px;">
                        <strong><i class="fas fa-heart"></i> {{ trans('mining-manager::help.support_the_project') }}:</strong>
                        <ul style="margin-top: 8px; margin-bottom: 0;">
                            <li>&#11088; {{ trans('mining-manager::help.support_star') }}</li>
                            <li>&#128295; {{ trans('mining-manager::help.support_issues') }}</li>
                            <li>&#128161; {{ trans('mining-manager::help.support_features') }}</li>
                            <li>&#128295; {{ trans('mining-manager::help.support_contribute') }}</li>
                            <li>&#127775; {{ trans('mining-manager::help.support_share') }}</li>
                        </ul>
                    </div>
                </div>

                {{-- Welcome --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-gem"></i>
                        {{ trans('mining-manager::help.welcome_title') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.welcome_desc') }}</p>
                </div>

                {{-- What is Mining Manager? --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-info-circle"></i>
                        {{ trans('mining-manager::help.what_is_mining_manager') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.what_is_mining_manager_desc') }}</p>

                    <div class="info-box">
                        <strong><i class="fas fa-lightbulb"></i> {{ trans('mining-manager::help.key_benefits') }}:</strong>
                        {{ trans('mining-manager::help.key_benefits_desc') }}
                    </div>
                </div>

                {{-- Core Features --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-star"></i>
                        {{ trans('mining-manager::help.core_features') }}
                    </h3>
                    <div class="feature-grid">
                        <div class="feature-item">
                            <i class="fas fa-book"></i>
                            <h5>{{ trans('mining-manager::help.feature_ledger') }}</h5>
                            <p>{{ trans('mining-manager::help.feature_ledger_desc') }}</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-coins"></i>
                            <h5>{{ trans('mining-manager::help.feature_tax') }}</h5>
                            <p>{{ trans('mining-manager::help.feature_tax_desc') }}</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-moon"></i>
                            <h5>{{ trans('mining-manager::help.feature_moon') }}</h5>
                            <p>{{ trans('mining-manager::help.feature_moon_desc') }}</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-calendar-alt"></i>
                            <h5>{{ trans('mining-manager::help.feature_events') }}</h5>
                            <p>{{ trans('mining-manager::help.feature_events_desc') }}</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-chart-bar"></i>
                            <h5>{{ trans('mining-manager::help.feature_analytics') }}</h5>
                            <p>{{ trans('mining-manager::help.feature_analytics_desc') }}</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-user-secret"></i>
                            <h5>{{ trans('mining-manager::help.feature_theft') }}</h5>
                            <p>{{ trans('mining-manager::help.feature_theft_desc') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Getting Started Section --}}
            <div id="getting-started" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-rocket"></i>
                        {{ trans('mining-manager::help.getting_started') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.getting_started_intro') }}</p>

                    <h4>{{ trans('mining-manager::help.quick_start_guide') }}</h4>
                    <ol class="step-by-step">
                        <li>
                            <strong>{{ trans('mining-manager::help.step_1_title') }}</strong><br>
                            {{ trans('mining-manager::help.step_1_desc') }}
                        </li>
                        <li>
                            <strong>{{ trans('mining-manager::help.step_2_title') }}</strong><br>
                            {{ trans('mining-manager::help.step_2_desc') }}
                        </li>
                        <li>
                            <strong>{{ trans('mining-manager::help.step_3_title') }}</strong><br>
                            {{ trans('mining-manager::help.step_3_desc') }}
                        </li>
                        <li>
                            <strong>{{ trans('mining-manager::help.step_4_title') }}</strong><br>
                            {{ trans('mining-manager::help.step_4_desc') }}
                        </li>
                        <li>
                            <strong>{{ trans('mining-manager::help.step_5_title') }}</strong><br>
                            {{ trans('mining-manager::help.step_5_desc') }}
                        </li>
                    </ol>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>{{ trans('mining-manager::help.tip') }}:</strong>
                        {{ trans('mining-manager::help.getting_started_tip') }}
                    </div>
                </div>
            </div>

            {{-- Dashboard Section --}}
            <div id="dashboard" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-tachometer-alt"></i>
                        {{ trans('mining-manager::help.dashboard_guide') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.dashboard_intro') }}</p>

                    <h4>{{ trans('mining-manager::help.member_dashboard') }}</h4>
                    <p>{{ trans('mining-manager::help.member_dashboard_desc') }}</p>
                    <ul>
                        <li><strong>{{ trans('mining-manager::help.personal_stats') }}:</strong> {{ trans('mining-manager::help.personal_stats_desc') }}</li>
                        <li><strong>{{ trans('mining-manager::help.tax_status') }}:</strong> {{ trans('mining-manager::help.tax_status_desc') }}</li>
                        <li><strong>{{ trans('mining-manager::help.recent_activity') }}:</strong> {{ trans('mining-manager::help.recent_activity_desc') }}</li>
                        <li><strong>{{ trans('mining-manager::help.upcoming_events') }}:</strong> {{ trans('mining-manager::help.upcoming_events_desc') }}</li>
                    </ul>

                    <h4>{{ trans('mining-manager::help.director_dashboard') }}</h4>
                    <p>{{ trans('mining-manager::help.director_dashboard_desc') }}</p>
                    <ul>
                        <li><strong>{{ trans('mining-manager::help.corp_overview') }}:</strong> {{ trans('mining-manager::help.corp_overview_desc') }}</li>
                        <li><strong>{{ trans('mining-manager::help.top_miners') }}:</strong> {{ trans('mining-manager::help.top_miners_desc') }}</li>
                        <li><strong>{{ trans('mining-manager::help.tax_collection') }}:</strong> {{ trans('mining-manager::help.tax_collection_desc') }}</li>
                        <li><strong>{{ trans('mining-manager::help.active_events') }}:</strong> {{ trans('mining-manager::help.active_events_desc') }}</li>
                    </ul>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>{{ trans('mining-manager::help.note') }}:</strong>
                        {{ trans('mining-manager::help.dashboard_note') }}
                    </div>
                </div>
            </div>

            {{-- Tax System Section --}}
            <div id="tax-system" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-coins"></i>
                        {{ trans('mining-manager::help.tax_system_explained') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.tax_system_intro') }}</p>

                    <h4>{{ trans('mining-manager::help.how_taxes_work') }}</h4>
                    <ol class="step-by-step">
                        <li>
                            <strong>{{ trans('mining-manager::help.tax_step_1_title') }}</strong><br>
                            {{ trans('mining-manager::help.tax_step_1_desc') }}
                        </li>
                        <li>
                            <strong>{{ trans('mining-manager::help.tax_step_2_title') }}</strong><br>
                            {{ trans('mining-manager::help.tax_step_2_desc') }}
                        </li>
                        <li>
                            <strong>{{ trans('mining-manager::help.tax_step_3_title') }}</strong><br>
                            {{ trans('mining-manager::help.tax_step_3_desc') }}
                        </li>
                        <li>
                            <strong>{{ trans('mining-manager::help.tax_step_4_title') }}</strong><br>
                            {{ trans('mining-manager::help.tax_step_4_desc') }}
                        </li>
                        <li>
                            <strong>{{ trans('mining-manager::help.tax_step_5_title') }}</strong><br>
                            {{ trans('mining-manager::help.tax_step_5_desc') }}
                        </li>
                    </ol>

                    <h4>{{ trans('mining-manager::help.payment_methods') }}</h4>
                    <p><strong>{{ trans('mining-manager::help.wallet_method_title') }}</strong></p>
                    <p>{{ trans('mining-manager::help.wallet_method_desc') }}</p>
                    <ul>
                        <li>{{ trans('mining-manager::help.wallet_step_1') }}</li>
                        <li>{{ trans('mining-manager::help.wallet_step_2') }}</li>
                        <li>{{ trans('mining-manager::help.wallet_step_3') }}</li>
                    </ul>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>{{ trans('mining-manager::help.important') }}:</strong>
                        {{ trans('mining-manager::help.tax_warning') }}
                    </div>
                </div>

                <div class="help-card">
                    <h3>
                        <i class="fas fa-barcode"></i>
                        {{ trans('mining-manager::help.tax_codes') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.tax_codes_desc') }}</p>
                    <p>{{ trans('mining-manager::help.tax_codes_usage') }}</p>
                    <pre>{{ trans('mining-manager::help.tax_code_example') }}</pre>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>{{ trans('mining-manager::help.note') }}:</strong>
                        {{ trans('mining-manager::help.tax_code_prefix_note') }}
                    </div>
                </div>

                <div class="help-card">
                    <h3>
                        <i class="fas fa-users"></i>
                        {{ trans('mining-manager::help.accumulated_mode') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.accumulated_mode_desc') }}</p>
                </div>

                <div class="help-card">
                    <h3>
                        <i class="fas fa-check-circle"></i>
                        {{ trans('mining-manager::help.wallet_verification') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.wallet_verification_desc') }}</p>
                </div>
            </div>

            {{-- Mining Events Section --}}
            <div id="events" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-calendar-alt"></i>
                        {{ trans('mining-manager::help.mining_events_guide') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.events_intro') }}</p>

                    <h4>{{ trans('mining-manager::help.creating_events') }}</h4>
                    <ol class="step-by-step">
                        <li>{{ trans('mining-manager::help.event_create_step_1') }}</li>
                        <li>{{ trans('mining-manager::help.event_create_step_2') }}</li>
                        <li>{{ trans('mining-manager::help.event_create_step_3') }}</li>
                        <li>{{ trans('mining-manager::help.event_create_step_4') }}</li>
                        <li>{{ trans('mining-manager::help.event_create_step_5') }}</li>
                    </ol>

                    <h4>{{ trans('mining-manager::help.participating_events') }}</h4>
                    <p>{{ trans('mining-manager::help.participating_desc') }}</p>
                    <ul>
                        <li>{{ trans('mining-manager::help.participate_step_1') }}</li>
                        <li>{{ trans('mining-manager::help.participate_step_2') }}</li>
                        <li>{{ trans('mining-manager::help.participate_step_3') }}</li>
                    </ul>

                    <div class="success-box">
                        <i class="fas fa-gift"></i>
                        <strong>{{ trans('mining-manager::help.bonus_tip') }}:</strong>
                        {{ trans('mining-manager::help.event_bonus_desc') }}
                    </div>
                </div>
            </div>

            {{-- Moon Mining Section --}}
            <div id="moon-mining" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-moon"></i>
                        {{ trans('mining-manager::help.moon_mining_guide') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.moon_intro') }}</p>

                    <h4>{{ trans('mining-manager::help.moon_tracking') }}</h4>
                    <p>{{ trans('mining-manager::help.moon_tracking_desc') }}</p>

                    <h4>{{ trans('mining-manager::help.moon_compositions') }}</h4>
                    <p>{{ trans('mining-manager::help.moon_compositions_desc') }}</p>

                    <h4>{{ trans('mining-manager::help.extraction_notifications') }}</h4>
                    <p>{{ trans('mining-manager::help.extraction_notifications_desc') }}</p>

                    <div class="info-box">
                        <i class="fas fa-calculator"></i>
                        <strong>{{ trans('mining-manager::help.moon_value') }}:</strong>
                        {{ trans('mining-manager::help.moon_value_desc') }}
                    </div>
                </div>

                {{-- Moon Extraction Lifecycle --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-sync-alt"></i>
                        {{ trans('mining-manager::help.moon_lifecycle') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.moon_lifecycle_intro') }}</p>

                    <div class="feature-grid">
                        <div class="feature-item" style="border-left: 4px solid #f39c12;">
                            <h5><span class="badge badge-warning">{{ trans('mining-manager::help.moon_status_extracting') }}</span></h5>
                            <p>{{ trans('mining-manager::help.moon_status_extracting_desc') }}</p>
                        </div>
                        <div class="feature-item" style="border-left: 4px solid #28a745;">
                            <h5><span class="badge badge-success">{{ trans('mining-manager::help.moon_status_ready') }}</span></h5>
                            <p>{{ trans('mining-manager::help.moon_status_ready_desc') }}</p>
                        </div>
                        <div class="feature-item" style="border-left: 4px solid #dc3545;">
                            <h5><span class="badge badge-danger">{{ trans('mining-manager::help.moon_status_unstable') }}</span></h5>
                            <p>{{ trans('mining-manager::help.moon_status_unstable_desc') }}</p>
                        </div>
                        <div class="feature-item" style="border-left: 4px solid #6c757d;">
                            <h5><span class="badge badge-secondary">{{ trans('mining-manager::help.moon_status_expired') }}</span></h5>
                            <p>{{ trans('mining-manager::help.moon_status_expired_desc') }}</p>
                        </div>
                    </div>
                </div>

                {{-- Moon Classification --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-gem"></i>
                        {{ trans('mining-manager::help.moon_classification') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.moon_classification_desc') }}</p>
                    <ul>
                        <li><span class="badge badge-danger">R64</span> {{ trans('mining-manager::help.moon_r64') }}</li>
                        <li><span class="badge badge-warning">R32</span> {{ trans('mining-manager::help.moon_r32') }}</li>
                        <li><span class="badge badge-info">R16</span> {{ trans('mining-manager::help.moon_r16') }}</li>
                        <li><span class="badge badge-success">R8</span> {{ trans('mining-manager::help.moon_r8') }}</li>
                        <li><span class="badge badge-secondary">R4</span> {{ trans('mining-manager::help.moon_r4') }}</li>
                    </ul>

                    <h4 class="mt-4">{{ trans('mining-manager::help.moon_quality') }}</h4>
                    <p>{{ trans('mining-manager::help.moon_quality_desc') }}</p>
                    <ul>
                        <li><span class="badge" style="background: linear-gradient(135deg, #9b59b6, #8e44ad);">Exceptional</span> {{ trans('mining-manager::help.moon_quality_exceptional') }}</li>
                        <li><span class="badge badge-success">Excellent</span> {{ trans('mining-manager::help.moon_quality_excellent') }}</li>
                        <li><span class="badge badge-info">Good</span> {{ trans('mining-manager::help.moon_quality_good') }}</li>
                        <li><span class="badge badge-warning">Average</span> {{ trans('mining-manager::help.moon_quality_average') }}</li>
                        <li><span class="badge badge-secondary">Poor</span> {{ trans('mining-manager::help.moon_quality_poor') }}</li>
                    </ul>
                </div>
            </div>

            {{-- Theft Detection Section --}}
            <div id="theft-detection" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-user-secret"></i>
                        {{ trans('mining-manager::help.theft_detection_guide') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.theft_detection_intro') }}</p>

                    <h4>{{ trans('mining-manager::help.how_theft_detection_works') }}</h4>
                    <ol class="step-by-step">
                        <li>{{ trans('mining-manager::help.theft_step_1') }}</li>
                        <li>{{ trans('mining-manager::help.theft_step_2') }}</li>
                        <li>{{ trans('mining-manager::help.theft_step_3') }}</li>
                    </ol>

                    <h4>{{ trans('mining-manager::help.theft_commands') }}</h4>
                    <div class="table-responsive">
                        <table class="table table-sm" style="color: #d1d5db;">
                            <tbody>
                                <tr>
                                    <td style="width: 50%;"><code>mining-manager:detect-theft</code></td>
                                    <td>{{ trans('mining-manager::help.theft_detect_desc') }}</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:monitor-active-thefts</code></td>
                                    <td>{{ trans('mining-manager::help.theft_monitor_desc') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>{{ trans('mining-manager::help.tip') }}:</strong>
                        {{ trans('mining-manager::help.theft_dry_run') }}
                    </div>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>{{ trans('mining-manager::help.note') }}:</strong>
                        {{ trans('mining-manager::help.theft_note') }}
                    </div>
                </div>
            </div>

            {{-- Analytics & Reports Section --}}
            <div id="analytics" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-chart-line"></i>
                        {{ trans('mining-manager::help.analytics_reports_guide') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.analytics_intro') }}</p>

                    <h4>{{ trans('mining-manager::help.available_reports') }}</h4>
                    <ul>
                        <li><strong>{{ trans('mining-manager::help.report_monthly') }}:</strong> {{ trans('mining-manager::help.report_monthly_desc') }}</li>
                        <li><strong>{{ trans('mining-manager::help.report_member') }}:</strong> {{ trans('mining-manager::help.report_member_desc') }}</li>
                        <li><strong>{{ trans('mining-manager::help.report_event') }}:</strong> {{ trans('mining-manager::help.report_event_desc') }}</li>
                        <li><strong>{{ trans('mining-manager::help.report_moon') }}:</strong> {{ trans('mining-manager::help.report_moon_desc') }}</li>
                        <li><strong>{{ trans('mining-manager::help.report_comparison') }}:</strong> {{ trans('mining-manager::help.report_comparison_desc') }}</li>
                    </ul>
                </div>

                <div class="help-card">
                    <h3>
                        <i class="fas fa-moon"></i>
                        {{ trans('mining-manager::help.moon_analytics') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.moon_analytics_desc') }}</p>
                    <ul>
                        <li>{{ trans('mining-manager::help.moon_analytics_utilization') }}</li>
                        <li>{{ trans('mining-manager::help.moon_analytics_pool_vs_mined') }}</li>
                        <li>{{ trans('mining-manager::help.moon_analytics_per_extraction') }}</li>
                        <li>{{ trans('mining-manager::help.moon_analytics_popularity') }}</li>
                    </ul>
                </div>

                <div class="help-card">
                    <h3>
                        <i class="fas fa-file-export"></i>
                        {{ trans('mining-manager::help.exporting_data') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.exporting_desc') }}</p>
                </div>
            </div>

            {{-- Settings Section --}}
            <div id="settings" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-cog"></i>
                        {{ trans('mining-manager::help.settings_guide') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.settings_intro') }}</p>

                    <h4>{{ trans('mining-manager::help.settings_tabs') }}</h4>

                    <h5><i class="fas fa-sliders-h text-primary"></i> {{ trans('mining-manager::help.settings_general') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_general_desc') }}</p>

                    <h5><i class="fas fa-wallet text-success"></i> {{ trans('mining-manager::help.settings_tax_payment') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_tax_payment_desc') }}</p>

                    <h5><i class="fas fa-credit-card text-info"></i> {{ trans('mining-manager::help.settings_wallet') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_wallet_desc') }}</p>

                    <h5><i class="fas fa-database text-warning"></i> {{ trans('mining-manager::help.settings_calc_source') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_calc_source_desc') }}</p>

                    <h5><i class="fas fa-percentage text-danger"></i> {{ trans('mining-manager::help.settings_tax_rates') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_tax_rates_desc') }}</p>

                    <h5><i class="fas fa-filter text-primary"></i> {{ trans('mining-manager::help.settings_tax_selector') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_tax_selector_desc') }}</p>

                    <h5><i class="fas fa-broadcast-tower text-success"></i> {{ trans('mining-manager::help.settings_live_tracking') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_live_tracking_desc') }}</p>

                    <h5><i class="fas fa-bell text-info"></i> {{ trans('mining-manager::help.settings_notifications') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_notifications_desc') }}</p>

                    <h5><i class="fas fa-hand-pointer text-warning"></i> {{ trans('mining-manager::help.settings_manual_actions') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_manual_actions_desc') }}</p>

                    <h5><i class="fas fa-tag text-danger"></i> {{ trans('mining-manager::help.settings_pricing') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_pricing_desc') }}</p>

                    <h5><i class="fas fa-moon text-primary"></i> {{ trans('mining-manager::help.settings_moon') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_moon_desc') }}</p>

                    <h5><i class="fas fa-stethoscope text-info"></i> {{ trans('mining-manager::help.settings_diagnostics') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_diagnostics_desc') }}</p>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>{{ trans('mining-manager::help.settings_warning') }}:</strong>
                        {{ trans('mining-manager::help.settings_warning_desc') }}
                    </div>
                </div>
            </div>

            {{-- CLI Commands Section --}}
            <div id="commands" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-terminal"></i>
                        {{ trans('mining-manager::help.cli_commands') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.cli_intro') }}</p>

                    {{-- Scheduled Commands --}}
                    <h4><i class="fas fa-clock text-primary"></i> {{ trans('mining-manager::help.cli_scheduled') }}</h4>
                    <p>{{ trans('mining-manager::help.cli_scheduled_desc') }}</p>

                    <div class="table-responsive">
                        <table class="table table-sm" style="color: #d1d5db;">
                            <thead>
                                <tr>
                                    <th>Command</th>
                                    <th>Schedule</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>mining-manager:process-ledger</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_30min') }}</span></td>
                                    <td>Process mining ledger data from ESI</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:cache-prices</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_4hours') }}</span></td>
                                    <td>Cache ore prices from price provider</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:update-ledger-prices</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_4hours') }}</span></td>
                                    <td>Update ledger entries with current prices</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:update-events</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_2hours') }}</span></td>
                                    <td>Update mining event status</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:update-extractions</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_6hours') }}</span></td>
                                    <td>Update moon extraction data from ESI</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:verify-payments</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_6hours') }}</span></td>
                                    <td>Verify wallet payments against tax codes</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:monitor-active-thefts</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_6hours') }}</span></td>
                                    <td>Monitor active theft incidents</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:recalculate-extraction-values</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_twice_daily') }}</span></td>
                                    <td>Update extraction values with current prices</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:update-daily-summaries</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily') }}</span></td>
                                    <td>Update daily mining summaries</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:calculate-taxes</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily') }}</span></td>
                                    <td>Calculate monthly tax obligations</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:calculate-statistics</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily') }}</span></td>
                                    <td>Calculate monthly mining statistics</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:detect-jackpots</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily') }}</span></td>
                                    <td>Detect jackpot moon extractions</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:archive-extractions</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily') }}</span></td>
                                    <td>Archive completed extractions</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:generate-reports</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily') }}</span></td>
                                    <td>Generate scheduled reports</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:detect-theft</code></td>
                                    <td><span class="badge badge-warning">{{ trans('mining-manager::help.schedule_twice_monthly') }}</span></td>
                                    <td>Full theft detection scan</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:send-reminders</code></td>
                                    <td><span class="badge badge-success">25th of month</span></td>
                                    <td>Send tax payment reminders</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:generate-invoices</code></td>
                                    <td><span class="badge badge-success">{{ trans('mining-manager::help.schedule_monthly') }}</span></td>
                                    <td>Generate tax invoices</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:finalize-month</code></td>
                                    <td><span class="badge badge-success">2nd of month</span></td>
                                    <td>Finalize previous month summaries</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {{-- Diagnostic Commands --}}
                    <h4 class="mt-4"><i class="fas fa-wrench text-info"></i> {{ trans('mining-manager::help.cli_diagnostic') }}</h4>
                    <p>{{ trans('mining-manager::help.cli_diagnostic_desc') }}</p>

                    <div class="table-responsive">
                        <table class="table table-sm" style="color: #d1d5db;">
                            <tbody>
                                <tr>
                                    <td style="width: 50%;"><code>mining-manager:diagnose-character {character_id}</code></td>
                                    <td>Diagnose a specific character's data</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:diagnose-affiliations</code></td>
                                    <td>Check character affiliations and alt grouping</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:diagnose-extractions</code></td>
                                    <td>Diagnose moon extraction issues</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:diagnose-prices</code></td>
                                    <td>Test price provider connectivity and accuracy</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:diagnose-type-ids</code></td>
                                    <td>Validate ore type ID mappings</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {{-- Data Management Commands --}}
                    <h4 class="mt-4"><i class="fas fa-database text-warning"></i> {{ trans('mining-manager::help.cli_data_management') }}</h4>
                    <p>{{ trans('mining-manager::help.cli_data_management_desc') }}</p>

                    <div class="table-responsive">
                        <table class="table table-sm" style="color: #d1d5db;">
                            <tbody>
                                <tr>
                                    <td style="width: 50%;"><code>mining-manager:backfill-ore-flags</code></td>
                                    <td>Backfill ore type flags for existing ledger data</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:backfill-extraction-notifications</code></td>
                                    <td>Backfill extraction notification records</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {{-- Manual Execution --}}
                    <h4 class="mt-4"><i class="fas fa-sync text-success"></i> {{ trans('mining-manager::help.cli_manual') }}</h4>
                    <p>{{ trans('mining-manager::help.cli_manual_desc') }}</p>

                    <div class="table-responsive">
                        <table class="table table-sm" style="color: #d1d5db;">
                            <tbody>
                                <tr>
                                    <td style="width: 50%;"><code>mining-manager:process-ledger --force</code></td>
                                    <td>Force process all mining data</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:update-extractions --corporation={id}</code></td>
                                    <td>Update extractions for specific corp</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:detect-theft --dry-run</code></td>
                                    <td>Preview theft detection without changes</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:archive-extractions --dry-run</code></td>
                                    <td>Preview archival without changes</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {{-- Test Commands --}}
                    <h4 class="mt-4"><i class="fas fa-flask text-danger"></i> {{ trans('mining-manager::help.cli_test') }}</h4>
                    <p>{{ trans('mining-manager::help.cli_test_desc') }}</p>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Warning:</strong> Test commands should only be used in development environments.
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm" style="color: #d1d5db;">
                            <tbody>
                                <tr>
                                    <td style="width: 50%;"><code>mining-manager:generate-test-data</code></td>
                                    <td>Generate test mining data</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>Note:</strong>
                        All commands should be run from your SeAT installation directory using <code>php artisan</code>.
                    </div>
                </div>
            </div>

            {{-- Permissions Section --}}
            <div id="permissions" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-shield-alt"></i>
                        {{ trans('mining-manager::help.permissions_guide') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.permissions_intro') }}</p>

                    <h4>{{ trans('mining-manager::help.available_permissions') }}</h4>

                    <div class="feature-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                        <div class="feature-item" style="border-left: 4px solid #6c757d;">
                            <h5><span class="badge badge-secondary">{{ trans('mining-manager::help.perm_view') }}</span></h5>
                            <p>{{ trans('mining-manager::help.perm_view_desc') }}</p>
                        </div>
                        <div class="feature-item" style="border-left: 4px solid #17a2b8;">
                            <h5><span class="badge badge-info">{{ trans('mining-manager::help.perm_member') }}</span></h5>
                            <p>{{ trans('mining-manager::help.perm_member_desc') }}</p>
                        </div>
                        <div class="feature-item" style="border-left: 4px solid #f39c12;">
                            <h5><span class="badge badge-warning">{{ trans('mining-manager::help.perm_director') }}</span></h5>
                            <p>{{ trans('mining-manager::help.perm_director_desc') }}</p>
                        </div>
                        <div class="feature-item" style="border-left: 4px solid #dc3545;">
                            <h5><span class="badge badge-danger">{{ trans('mining-manager::help.perm_admin') }}</span></h5>
                            <p>{{ trans('mining-manager::help.perm_admin_desc') }}</p>
                        </div>
                    </div>

                    <h4>{{ trans('mining-manager::help.setting_permissions') }}</h4>
                    <p>{{ trans('mining-manager::help.setting_permissions_desc') }}</p>
                </div>
            </div>

            {{-- FAQ Section --}}
            <div id="faq" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-question-circle"></i>
                        {{ trans('mining-manager::help.frequently_asked') }}
                    </h3>

                    @foreach(range(1, 10) as $i)
                    <div class="faq-item">
                        <div class="faq-question">
                            <strong>{{ trans("mining-manager::help.faq_q{$i}") }}</strong>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="faq-answer">
                            <p>{{ trans("mining-manager::help.faq_a{$i}") }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Troubleshooting Section --}}
            <div id="troubleshooting" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-wrench"></i>
                        {{ trans('mining-manager::help.troubleshooting_guide') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.troubleshooting_intro') }}</p>

                    <h4>{{ trans('mining-manager::help.common_issues') }}</h4>

                    <h5>{{ trans('mining-manager::help.issue_1_title') }}</h5>
                    <p>{{ trans('mining-manager::help.issue_1_desc') }}</p>
                    <ul>
                        <li>{{ trans('mining-manager::help.issue_1_solution_1') }}</li>
                        <li>{{ trans('mining-manager::help.issue_1_solution_2') }}</li>
                        <li>{{ trans('mining-manager::help.issue_1_solution_3') }}</li>
                        <li><code>{{ trans('mining-manager::help.issue_1_solution_4') }}</code></li>
                    </ul>

                    <h5>{{ trans('mining-manager::help.issue_2_title') }}</h5>
                    <p>{{ trans('mining-manager::help.issue_2_desc') }}</p>
                    <ul>
                        <li>{{ trans('mining-manager::help.issue_2_solution_1') }}</li>
                        <li><code>{{ trans('mining-manager::help.issue_2_solution_2') }}</code></li>
                        <li><code>{{ trans('mining-manager::help.issue_2_solution_3') }}</code></li>
                    </ul>

                    <h5>{{ trans('mining-manager::help.issue_3_title') }}</h5>
                    <p>{{ trans('mining-manager::help.issue_3_desc') }}</p>
                    <ul>
                        <li>{{ trans('mining-manager::help.issue_3_solution_1') }}</li>
                        <li>{{ trans('mining-manager::help.issue_3_solution_2') }}</li>
                        <li>{{ trans('mining-manager::help.issue_3_solution_3') }}</li>
                    </ul>

                    <h5>{{ trans('mining-manager::help.issue_4_title') }}</h5>
                    <p>{{ trans('mining-manager::help.issue_4_desc') }}</p>
                    <ul>
                        <li>{{ trans('mining-manager::help.issue_4_solution_1') }}</li>
                        <li><code>{{ trans('mining-manager::help.issue_4_solution_2') }}</code></li>
                        <li><code>{{ trans('mining-manager::help.issue_4_solution_3') }}</code></li>
                    </ul>

                    <div class="info-box">
                        <i class="fas fa-life-ring"></i>
                        <strong>{{ trans('mining-manager::help.need_help') }}:</strong>
                        {{ trans('mining-manager::help.support_message') }}
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

@push('javascript')
<script>
$(document).ready(function() {
    // Navigation
    $('.help-nav .nav-link').on('click', function(e) {
        e.preventDefault();

        const section = $(this).data('section');

        // Update nav
        $('.help-nav .nav-link').removeClass('active');
        $(this).addClass('active');

        // Update content
        $('.help-section').removeClass('active');
        $(`#${section}`).addClass('active');

        // Update URL hash
        window.location.hash = section;

        // Scroll to top of content
        $('.help-content').scrollTop(0);
    });

    // Load section from URL hash
    if (window.location.hash) {
        const hash = window.location.hash.substring(1);
        $(`.help-nav .nav-link[data-section="${hash}"]`).click();
    }

    // FAQ Accordion
    $('.faq-question').on('click', function() {
        $(this).closest('.faq-item').toggleClass('open');
    });

    // Search functionality
    let searchTimeout;
    $('#helpSearch').on('input', function() {
        clearTimeout(searchTimeout);
        const query = $(this).val().toLowerCase();

        if (query.length < 2) {
            $('.help-card').show();
            return;
        }

        searchTimeout = setTimeout(() => {
            $('.help-card').each(function() {
                const text = $(this).text().toLowerCase();
                $(this).toggle(text.includes(query));
            });
        }, 300);
    });
});
</script>
@endpush
@endsection
