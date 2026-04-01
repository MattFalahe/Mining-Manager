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
        color: #d1d5db !important;
    }

    .help-nav .nav-link {
        color: #e2e8f0 !important;
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
        color: #667eea !important;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .help-card h4 {
        color: #9ca3af !important;
        margin-top: 20px;
        margin-bottom: 10px;
        font-size: 1.1rem;
    }

    .help-card h5 {
        color: #e2e8f0 !important;
        margin-top: 15px;
        margin-bottom: 8px;
    }

    .help-card p {
        color: #d1d5db !important;
        line-height: 1.6;
    }

    .help-card ul, .help-card ol {
        color: #d1d5db !important;
        line-height: 1.8;
        margin-left: 20px;
    }

    .help-card strong {
        color: #e2e8f0 !important;
    }

    .help-card li {
        color: #d1d5db !important;
    }

    .help-card code {
        background: rgba(0, 0, 0, 0.3);
        padding: 2px 6px;
        border-radius: 3px;
        color: #fbbf24 !important;
        font-size: 0.9em;
    }

    .help-card pre {
        background: rgba(0, 0, 0, 0.3);
        padding: 15px;
        border-radius: 5px;
        overflow-x: auto;
        color: #d1d5db !important;
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
        color: #d1d5db !important;
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

    .info-box,
    .warning-box,
    .success-box {
        padding: 15px;
        margin: 15px 0;
        border-radius: 5px;
        color: #d1d5db !important;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        flex-wrap: wrap;
    }

    .info-box {
        background: rgba(23, 162, 184, 0.15);
        border-left: 4px solid #17a2b8;
    }

    .warning-box {
        background: rgba(255, 193, 7, 0.15);
        border-left: 4px solid #ffc107;
    }

    .success-box {
        background: rgba(28, 200, 138, 0.15);
        border-left: 4px solid #1cc88a;
    }

    .info-box > i,
    .warning-box > i,
    .success-box > i {
        margin-top: 3px;
        flex-shrink: 0;
    }

    .info-box > i { color: #17a2b8; }
    .warning-box > i { color: #ffc107; }
    .success-box > i { color: #1cc88a; }

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
        color: #e2e8f0 !important;
        margin-bottom: 8px;
    }

    .feature-item p {
        color: #9ca3af !important;
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
        color: #e2e8f0 !important;
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
        color: #e2e8f0 !important;
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
        color: #d1d5db !important;
    }

    .faq-item.open .faq-answer {
        padding: 15px;
        max-height: 500px;
    }

    .plugin-info-table {
        width: 100%;
        color: #d1d5db !important;
    }

    .plugin-info-table td {
        padding: 8px 12px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .plugin-info-table td:first-child {
        color: #9ca3af !important;
        width: 120px;
        font-weight: 600;
    }

    .plugin-info-table td:last-child {
        color: #e2e8f0 !important;
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
                            <a href="#" class="nav-link" data-section="custom-styling">
                                <i class="fas fa-paint-brush"></i>
                                {{ trans('mining-manager::help.custom_styling') }}
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
                        <i class="fas fa-heart"></i>
                        <div>
                            <strong>{{ trans('mining-manager::help.support_the_project') }}:</strong>
                            <ul style="margin-top: 8px; margin-bottom: 0;">
                            <li>&#11088; {{ trans('mining-manager::help.support_star') }}</li>
                            <li>&#128295; {{ trans('mining-manager::help.support_issues') }}</li>
                            <li>&#128161; {{ trans('mining-manager::help.support_features') }}</li>
                            <li>&#128295; {{ trans('mining-manager::help.support_contribute') }}</li>
                            <li>&#127775; {{ trans('mining-manager::help.support_share') }}</li>
                            </ul>
                        </div>
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
                        <i class="fas fa-lightbulb"></i>
                        <strong>{{ trans('mining-manager::help.key_benefits') }}:</strong> {{ trans('mining-manager::help.key_benefits_desc') }}
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
                        <strong>{{ trans('mining-manager::help.tip') }}:</strong> {{ trans('mining-manager::help.getting_started_tip') }}
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

                    <h4>{{ trans('mining-manager::help.dashboard_charts') }}</h4>
                    <p>{{ trans('mining-manager::help.dashboard_charts_desc') }}</p>
                    <ul>
                        <li><strong>{{ trans('mining-manager::help.chart_mining_tax') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.chart_mining_performance') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.chart_moon_mining') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.chart_event_tax') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.chart_mining_by_group') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.chart_mining_by_type') }}</strong></li>
                    </ul>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>{{ trans('mining-manager::help.note') }}:</strong> {{ trans('mining-manager::help.dashboard_note') }}
                    </div>
                </div>
            </div>

            {{-- Tax System Section --}}
            <div id="tax-system" class="help-section">
                {{-- Overview & Chain --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-coins"></i>
                        {{ trans('mining-manager::help.tax_system_explained') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.tax_system_intro') }}</p>

                    <h4>{{ trans('mining-manager::help.how_taxes_work') }}</h4>
                    <p>{{ trans('mining-manager::help.tax_chain_intro') }}</p>
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
                        <li>
                            <strong>{{ trans('mining-manager::help.tax_step_6_title') }}</strong><br>
                            {{ trans('mining-manager::help.tax_step_6_desc') }}
                        </li>
                        <li>
                            <strong>{{ trans('mining-manager::help.tax_step_7_title') }}</strong><br>
                            {{ trans('mining-manager::help.tax_step_7_desc') }}
                        </li>
                        <li>
                            <strong>{{ trans('mining-manager::help.tax_step_8_title') }}</strong><br>
                            {{ trans('mining-manager::help.tax_step_8_desc') }}
                        </li>
                        <li>
                            <strong>{{ trans('mining-manager::help.tax_step_9_title') }}</strong><br>
                            {{ trans('mining-manager::help.tax_step_9_desc') }}
                        </li>
                    </ol>
                </div>

                {{-- Tax Calculation Periods --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-calendar-alt"></i>
                        {{ trans('mining-manager::help.tax_periods_title') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.tax_periods_desc') }}</p>
                    <ul>
                        <li><strong>{{ trans('mining-manager::help.tax_period_monthly') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.tax_period_biweekly') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.tax_period_weekly') }}</strong></li>
                    </ul>

                    <div class="info-box">
                        <i class="fas fa-chart-bar"></i>
                        <strong>{{ trans('mining-manager::help.note') }}:</strong> {{ trans('mining-manager::help.tax_periods_charts') }}
                    </div>

                    <p>{{ trans('mining-manager::help.tax_periods_due_date') }}</p>
                    <p>{{ trans('mining-manager::help.tax_periods_codes') }}</p>
                </div>

                {{-- Nightly Pipeline --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-cogs"></i>
                        {{ trans('mining-manager::help.nightly_pipeline_title') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.nightly_pipeline_desc') }}</p>
                    <ol class="step-by-step">
                        <li>{{ trans('mining-manager::help.pipeline_step_1') }}</li>
                        <li>{{ trans('mining-manager::help.pipeline_step_2') }}</li>
                        <li>{{ trans('mining-manager::help.pipeline_step_3') }}</li>
                        <li>{{ trans('mining-manager::help.pipeline_step_4') }}</li>
                        <li>{{ trans('mining-manager::help.pipeline_step_5') }}</li>
                        <li>{{ trans('mining-manager::help.pipeline_step_6') }}</li>
                        <li>{{ trans('mining-manager::help.pipeline_step_7') }}</li>
                    </ol>
                    <p>{{ trans('mining-manager::help.pipeline_other') }}</p>
                </div>

                {{-- Triggered By / Audit Trail --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-history"></i>
                        {{ trans('mining-manager::help.triggered_by_title') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.triggered_by_desc') }}</p>
                    <ul>
                        <li><strong>{{ trans('mining-manager::help.triggered_by_scheduled') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.triggered_by_manual') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.triggered_by_regenerate') }}</strong></li>
                    </ul>
                </div>

                {{-- Admin Tax Controls --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-user-shield"></i>
                        {{ trans('mining-manager::help.admin_controls_title') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.admin_controls_desc') }}</p>
                    <ul>
                        <li><strong>{{ trans('mining-manager::help.admin_control_delete') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.admin_control_mark_paid') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.admin_control_status') }}</strong></li>
                    </ul>
                </div>

                {{-- Daily Summaries --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-database"></i>
                        {{ trans('mining-manager::help.daily_summaries_explained') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.daily_summaries_desc') }}</p>
                    <p>{{ trans('mining-manager::help.daily_summaries_when_generated') }}</p>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>{{ trans('mining-manager::help.important') }}:</strong> {{ trans('mining-manager::help.daily_summaries_settings') }}
                    </div>

                    <div class="info-box">
                        <i class="fas fa-sync-alt"></i>
                        <strong>{{ trans('mining-manager::help.daily_summaries_reconciliation_title') }}</strong> {{ trans('mining-manager::help.daily_summaries_reconciliation_desc') }}
                    </div>
                </div>

                {{-- Tax Rates & Categories --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-percentage"></i>
                        {{ trans('mining-manager::help.tax_rates_explained') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.tax_rates_desc') }}</p>
                    <ul>
                        <li><strong>{{ trans('mining-manager::help.tax_rate_moon_ore') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.tax_rate_regular_ore') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.tax_rate_ice') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.tax_rate_gas') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.tax_rate_abyssal') }}</strong></li>
                    </ul>

                    <h4>{{ trans('mining-manager::help.tax_selector_explained') }}</h4>
                    <p>{{ trans('mining-manager::help.tax_selector_desc') }}</p>
                    <ul>
                        <li><strong>{{ trans('mining-manager::help.tax_selector_all_moon') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.tax_selector_corp_moon') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.tax_selector_no_moon') }}</strong></li>
                    </ul>
                    <p>{{ trans('mining-manager::help.tax_selector_toggles') }}</p>
                </div>

                {{-- Guest Mining --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-user-friends"></i>
                        {{ trans('mining-manager::help.guest_mining_explained') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.guest_mining_desc') }}</p>
                    <p>{{ trans('mining-manager::help.guest_rates_config') }}</p>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>{{ trans('mining-manager::help.note') }}:</strong> {{ trans('mining-manager::help.guest_detection') }}
                    </div>
                </div>

                {{-- Event Tax Modifiers --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-calendar-check"></i>
                        {{ trans('mining-manager::help.event_modifiers_explained') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.event_modifiers_desc') }}</p>
                    <ul>
                        <li>{{ trans('mining-manager::help.event_modifier_range') }}</li>
                        <li>{{ trans('mining-manager::help.event_modifier_calc') }}</li>
                        <li>{{ trans('mining-manager::help.event_modifier_overlap') }}</li>
                        <li>{{ trans('mining-manager::help.event_modifier_daily') }}</li>
                    </ul>
                </div>

                {{-- Calculate Taxes Buttons --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-calculator"></i>
                        {{ trans('mining-manager::help.calculation_methods') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.calculation_methods_desc') }}</p>
                    <ul>
                        <li><strong>{{ trans('mining-manager::help.calc_calculate') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.calc_recalculate') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.calc_assign_codes') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.calc_regenerate_codes') }}</strong></li>
                    </ul>
                </div>

                {{-- Exemptions --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-shield-alt"></i>
                        {{ trans('mining-manager::help.exemptions_explained') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.exemptions_desc') }}</p>
                    <ul>
                        <li><strong>{{ trans('mining-manager::help.exemption_threshold') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.minimum_tax') }}</strong></li>
                    </ul>
                </div>

                {{-- Payment & Tax Codes --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-barcode"></i>
                        {{ trans('mining-manager::help.tax_codes') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.tax_codes_desc') }}</p>
                    <p>{{ trans('mining-manager::help.tax_codes_usage') }}</p>
                    <pre>{{ trans('mining-manager::help.tax_code_example') }}</pre>

                    <h4>{{ trans('mining-manager::help.payment_methods') }}</h4>
                    <p><strong>{{ trans('mining-manager::help.wallet_method_title') }}</strong></p>
                    <p>{{ trans('mining-manager::help.wallet_method_desc') }}</p>
                    <ol>
                        <li>{{ trans('mining-manager::help.wallet_step_1') }}</li>
                        <li>{{ trans('mining-manager::help.wallet_step_2') }}</li>
                        <li>{{ trans('mining-manager::help.wallet_step_3') }}</li>
                        <li>{{ trans('mining-manager::help.wallet_step_4') }}</li>
                    </ol>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>{{ trans('mining-manager::help.important') }}:</strong> {{ trans('mining-manager::help.tax_warning') }}
                    </div>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>{{ trans('mining-manager::help.note') }}:</strong> {{ trans('mining-manager::help.tax_code_prefix_note') }}
                    </div>
                </div>

                {{-- Alt Grouping --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-users"></i>
                        {{ trans('mining-manager::help.accumulated_mode') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.accumulated_mode_desc') }}</p>
                </div>

                {{-- Wallet Verification --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-check-circle"></i>
                        {{ trans('mining-manager::help.wallet_verification') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.wallet_verification_desc') }}</p>
                    <ul>
                        <li><strong>{{ trans('mining-manager::help.wallet_verification_member') }}</strong></li>
                        <li><strong>{{ trans('mining-manager::help.wallet_verification_director') }}</strong></li>
                    </ul>
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
                        <strong>{{ trans('mining-manager::help.bonus_tip') }}:</strong> {{ trans('mining-manager::help.event_bonus_desc') }}
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
                        <strong>{{ trans('mining-manager::help.moon_value') }}:</strong> {{ trans('mining-manager::help.moon_value_desc') }}
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
                        <strong>{{ trans('mining-manager::help.tip') }}:</strong> {{ trans('mining-manager::help.theft_dry_run') }}
                    </div>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>{{ trans('mining-manager::help.note') }}:</strong> {{ trans('mining-manager::help.theft_note') }}
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

                    <h4>{{ trans('mining-manager::help.settings_global_header') }}</h4>

                    <h5><i class="fas fa-sliders-h text-primary"></i> {{ trans('mining-manager::help.settings_general') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_general_desc') }}</p>

                    <h5><i class="fas fa-tag text-danger"></i> {{ trans('mining-manager::help.settings_pricing') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_pricing_desc') }}</p>

                    <h5><i class="fas fa-toggle-on text-success"></i> {{ trans('mining-manager::help.settings_features') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_features_desc') }}</p>

                    <h5><i class="fas fa-plug text-info"></i> {{ trans('mining-manager::help.settings_webhooks') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_webhooks_desc') }}</p>

                    <h5><i class="fas fa-bell text-warning"></i> {{ trans('mining-manager::help.settings_notifications') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_notifications_desc') }}</p>

                    <h5><i class="fas fa-tachometer-alt text-primary"></i> {{ trans('mining-manager::help.settings_dashboard') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_dashboard_desc') }}</p>

                    <h4>{{ trans('mining-manager::help.settings_corp_header') }}</h4>

                    <h5><i class="fas fa-percentage text-danger"></i> {{ trans('mining-manager::help.settings_tax_rates') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_tax_rates_desc') }}</p>

                    <h4>{{ trans('mining-manager::help.settings_system_header') }}</h4>

                    <h5><i class="fas fa-cogs text-warning"></i> {{ trans('mining-manager::help.settings_advanced') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_advanced_desc') }}</p>

                    <h5><i class="fas fa-question-circle text-info"></i> {{ trans('mining-manager::help.settings_help') }}</h5>
                    <p>{{ trans('mining-manager::help.settings_help_desc') }}</p>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>{{ trans('mining-manager::help.settings_warning') }}:</strong> {{ trans('mining-manager::help.settings_warning_desc') }}
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
                                    <th>Description &amp; Options</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>mining-manager:process-ledger</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_30min') }}</span></td>
                                    <td>Process corporation observer mining data from ESI. Creates mining ledger entries with ore type flags, prices, and generates daily summaries.<br>
                                        <small class="text-muted">Options: <code>--observer_id=</code> specific structure, <code>--character_id=</code> specific character, <code>--days=30</code> lookback period, <code>--recalculate</code> recalc existing entries</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:import-character-mining</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_30min') }}</span></td>
                                    <td>Import personal mining data from SeAT's ESI cache (belt, anomaly, ice, gas mining). Safety net for non-observer mining — the Queue::after hook handles real-time import, this catches any missed entries.<br>
                                        <small class="text-muted">Options: <code>--character_id=</code> specific character, <code>--days=30</code> lookback period, <code>--force</code> re-import existing entries</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:cache-prices</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_4hours') }}</span></td>
                                    <td>Cache market price data from your configured price provider for all ore types.<br>
                                        <small class="text-muted">Options: <code>--type=all</code> (ore|compressed-ore|moon|materials|minerals|ice|gas|all), <code>--region=10000002</code> region ID, <code>--force</code> refresh even if cache is fresh</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:update-ledger-prices</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily') }}</span> 1:00 AM</td>
                                    <td>Lock in current market prices on mining ledger entries. Also regenerates affected daily summaries. First step in the nightly pipeline.<br>
                                        <small class="text-muted">Options: <code>--days=1</code> days to re-price, <code>--all-unpriced</code> all entries with 0 value, <code>--force</code> re-price even if value > 0, <code>--character_id=</code> specific character</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:update-daily-summaries</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily') }}</span> 1:30 AM</td>
                                    <td>Safety net: catches non-observer mining (belt mining) and late ESI data. Generates/updates daily summaries with per-ore tax breakdown. Also runs a <strong>reconciliation step</strong> on the previous 2 days — matching character-imported moon ore entries against late-arriving observer data (ESI 12-24h lag), removing duplicates and adjusting quantities.<br>
                                        <small class="text-muted">Options: <code>--days=2</code> days back, <code>--date=YYYY-MM-DD</code> specific date, <code>--month=YYYY-MM</code> entire month, <code>--today-only</code> fast mode, <code>--character_id=</code> specific character</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:update-events</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_2hours') }}</span></td>
                                    <td>Update mining event participant data and statistics.<br>
                                        <small class="text-muted">Options: <code>--event_id=</code> specific event, <code>--active</code> only active events</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:update-extractions</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_6hours') }}</span></td>
                                    <td>Update moon extraction data from corporation structure ESI endpoints.<br>
                                        <small class="text-muted">Options: <code>--structure_id=</code> specific structure, <code>--corporation_id=</code> specific corp, <code>--active-only</code> only active extractions</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:verify-payments</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_6hours') }}</span></td>
                                    <td>Scan corporation wallet journal and match payments against issued tax codes.<br>
                                        <small class="text-muted">Options: <code>--days=7</code> days to check back, <code>--character_id=</code> specific character, <code>--auto-match</code> automatically match payments</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:monitor-active-thefts</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_6hours') }}</span></td>
                                    <td>Fast check: monitors characters already on the theft list for continued mining activity.<br>
                                        <small class="text-muted">Options: <code>--hours=6</code> lookback period, <code>--notify</code> send notifications</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:recalculate-extraction-values</code></td>
                                    <td><span class="badge badge-info">{{ trans('mining-manager::help.schedule_twice_daily') }}</span></td>
                                    <td>Recalculate moon extraction values based on current prices. Useful for extractions arriving soon.<br>
                                        <small class="text-muted">Options: <code>--hours=4</code> arrival window, <code>--force</code> recalculate even if recently done</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:calculate-taxes</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily_smart') }}</span> 2:15 AM</td>
                                    <td>Calculate tax obligations by summing daily summaries. Creates MiningTax records per main character for the previous completed period. <strong>Smart scheduling:</strong> only acts on period boundary days (2nd for monthly, 2nd/16th for biweekly, Tuesdays for weekly). The 1-day shift allows late-arriving observer data to settle before calculating. Skips silently on other days.<br>
                                        <small class="text-muted">Options: <code>--month=YYYY-MM</code> legacy monthly, <code>--period-start=YYYY-MM-DD</code> specific period, <code>--period-type=</code> override (monthly|biweekly|weekly), <code>--character_id=</code> specific character, <code>--corporation_id=</code> specific corp, <code>--recalculate</code> overwrite existing, <code>--force</code> run even if not a boundary day</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:calculate-monthly-stats</code></td>
                                    <td><span class="badge badge-success">{{ trans('mining-manager::help.schedule_monthly') }}</span> 3:00 AM + <span class="badge badge-info">{{ trans('mining-manager::help.schedule_30min') }}</span></td>
                                    <td>Pre-calculate and store dashboard statistics. Full run on the 2nd of each month at 3:00 AM for the closed month. Fast <code>--current-month</code> mode runs every 30 minutes to keep live dashboard data current.<br>
                                        <small class="text-muted">Options: <code>--month=YYYY-MM</code> specific month, <code>--user_id=</code> specific user, <code>--recalculate</code> recalculate existing, <code>--current-month</code> fast mode, <code>--all-history</code> all historical months</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:detect-jackpots</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily') }}</span></td>
                                    <td>Detect jackpot moon extractions based on mining ledger data analysis.<br>
                                        <small class="text-muted">Options: <code>--all</code> check all extractions, <code>--days=30</code> lookback period</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:archive-extractions</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily') }}</span></td>
                                    <td>Archive completed moon extractions to history table and calculate actual mined values.<br>
                                        <small class="text-muted">Options: <code>--days=7</code> archive older than N days, <code>--keep-months=12</code> keep history for N months, <code>--dry-run</code> preview without changes</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:generate-reports</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily') }}</span></td>
                                    <td>Generate mining reports and analytics summaries.<br>
                                        <small class="text-muted">Options: <code>--type=monthly</code> (daily|weekly|monthly|custom), <code>--start=YYYY-MM-DD</code>, <code>--end=YYYY-MM-DD</code>, <code>--format=json</code> (json|csv|pdf)</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:detect-theft</code></td>
                                    <td><span class="badge badge-warning">{{ trans('mining-manager::help.schedule_twice_monthly') }}</span></td>
                                    <td>Full scan: detect unauthorized moon mining by non-corporation members with overdue taxes.<br>
                                        <small class="text-muted">Options: <code>--days=15</code> lookback period, <code>--notify</code> send notifications for detected thefts</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:send-reminders</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily') }}</span> 10:00 AM</td>
                                    <td>Send tax payment reminder notifications to characters with unpaid taxes. Finds taxes within the reminder window (configurable, default 3 days before due date) or already overdue. Groups by character — one notification per player even if they owe multiple periods.<br>
                                        <small class="text-muted">Options: <code>--overdue-only</code> only overdue taxes, <code>--days-overdue=7</code> days threshold, <code>--dry-run</code> preview without sending</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:generate-invoices</code></td>
                                    <td><span class="badge badge-primary">{{ trans('mining-manager::help.schedule_daily_smart') }}</span> 2:30 AM</td>
                                    <td>Generate invoice records for unpaid taxes with completed periods. Smart: only creates invoices for taxes that don't already have one. Runs daily so biweekly/weekly periods get invoices promptly after each period ends.<br>
                                        <small class="text-muted">Options: <code>--month=YYYY-MM</code> specific month, <code>--character_id=</code> specific character, <code>--dry-run</code> preview without creating</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:generate-tax-codes</code></td>
                                    <td><span class="badge badge-warning">{{ trans('mining-manager::help.schedule_manual') }}</span></td>
                                    <td>Generate payment codes for unpaid tax records without recalculating taxes. Use this to assign codes after running calculate-taxes, or via the "Assign Codes" button in the UI.<br>
                                        <small class="text-muted">Options: <code>--month=YYYY-MM</code> specific month (defaults to previous month)</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:finalize-month</code></td>
                                    <td><span class="badge badge-success">{{ trans('mining-manager::help.schedule_monthly') }}</span> 2:00 AM</td>
                                    <td>Lock previous month's daily summaries as final so they won't be regenerated. Runs on the 2nd at 2:00 AM to allow late-arriving observer data (12-24h ESI lag) to settle. Refuses to finalize the current or future months.<br>
                                        <small class="text-muted">Arguments: <code>{month?}</code> optional month in YYYY-MM format (defaults to previous month)</small>
                                    </td>
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
                                    <td style="width: 45%;"><code>mining-manager:diagnose-character {character_id}</code></td>
                                    <td>Diagnose a specific character's corporation lookup, affiliation data, and mining records. Requires the character ID as an argument.</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:diagnose-affiliation</code></td>
                                    <td>Check character affiliations and alt grouping. Verifies CharacterInfo relationships are working correctly.</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:diagnose-extractions</code></td>
                                    <td>Diagnose moon extraction data issues. Checks for missing or incomplete extraction records from ESI.</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:diagnose-prices</code></td>
                                    <td>Test price provider connectivity, cache health, and pricing accuracy.<br>
                                        <small class="text-muted">Options: <code>--detailed</code> full breakdown, <code>--test-provider</code> test current provider, <code>--show-missing</code> list items without prices, <code>--show-sources</code> cache vs fallback, <code>--show-coverage</code> coverage stats for all 357 items</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:diagnose-type-ids</code></td>
                                    <td>Verify ore type ID mappings against ESI API or local database.<br>
                                        <small class="text-muted">Options: <code>--category=</code> specific category (ore|moon|ice|gas|all), <code>--include-abyssal</code> include Pochven ores, <code>--test-jackpot</code> test jackpot detection, <code>--verify-db</code> verify against local DB</small>
                                    </td>
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
                                    <td style="width: 45%;"><code>mining-manager:backfill-ore-types</code></td>
                                    <td>Backfill is_moon_ore, is_ice, and is_gas flags for existing mining ledger entries that were created before these flags were added.<br>
                                        <small class="text-muted">Options: <code>--batch=1000</code> records per batch</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:backfill-extraction-notifications</code></td>
                                    <td>Backfill ore composition data from character notifications for existing moon extractions.<br>
                                        <small class="text-muted">Options: <code>--limit=100</code> max extractions, <code>--structure=</code> specific structure, <code>--days=90</code> lookback, <code>--dry-run</code> preview, <code>--force</code> overwrite existing</small>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {{-- Common Manual Commands --}}
                    <h4 class="mt-4"><i class="fas fa-sync text-success"></i> {{ trans('mining-manager::help.cli_manual') }}</h4>
                    <p>{{ trans('mining-manager::help.cli_manual_desc') }}</p>

                    <div class="table-responsive">
                        <table class="table table-sm" style="color: #d1d5db;">
                            <tbody>
                                <tr>
                                    <td style="width: 55%;"><code>mining-manager:update-daily-summaries --month=2026-03</code></td>
                                    <td>Regenerate all daily summaries for March 2026 with current prices and tax rates</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:update-ledger-prices --force --days=30</code></td>
                                    <td>Force re-price all entries from last 30 days (also regenerates affected daily summaries)</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:calculate-taxes --month=2026-03 --recalculate</code></td>
                                    <td>Recalculate taxes for March 2026 (monthly mode), updating existing tax records</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:calculate-taxes --period-start=2026-03-15 --period-type=biweekly</code></td>
                                    <td>Calculate biweekly taxes for the period containing March 15 (Mar 15-31)</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:calculate-taxes --force</code></td>
                                    <td>Force tax calculation today even if it's not a period boundary day</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:cache-prices --force</code></td>
                                    <td>Force refresh all cached prices even if cache is fresh</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:process-ledger --recalculate</code></td>
                                    <td>Reprocess all mining data, recalculating prices and taxes</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:generate-tax-codes --month=2026-03</code></td>
                                    <td>Generate payment codes for March 2026 unpaid taxes (without recalculation)</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:import-character-mining --days=7</code></td>
                                    <td>Import character mining data from ESI cache for the last 7 days</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:detect-theft --days=30 --notify</code></td>
                                    <td>Run theft detection for last 30 days and send notifications</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:send-reminders --dry-run</code></td>
                                    <td>Preview which tax reminders would be sent without actually sending them</td>
                                </tr>
                                <tr>
                                    <td><code>mining-manager:diagnose-prices --detailed --show-missing</code></td>
                                    <td>Full price diagnostic showing all categories and missing items</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {{-- Test Commands --}}
                    <h4 class="mt-4"><i class="fas fa-flask text-danger"></i> {{ trans('mining-manager::help.cli_test') }}</h4>
                    <p>{{ trans('mining-manager::help.cli_test_desc') }}</p>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Warning:</strong> Test commands should only be used in development environments. They create fake data that should not be mixed with real production data.
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm" style="color: #d1d5db;">
                            <tbody>
                                <tr>
                                    <td style="width: 45%;"><code>mining-manager:generate-test-data</code></td>
                                    <td>Generate fake corporations, characters, and mining ledger entries for testing.<br>
                                        <small class="text-muted">Options: <code>--corporations=3</code> number of corps, <code>--characters=5</code> per corp, <code>--days=30</code> of mining data, <code>--entries=10</code> per day per character, <code>--cleanup</code> remove existing test data first</small>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>Note:</strong> All commands should be run from your SeAT installation directory. For Docker: <code>docker exec -it seat-docker-front-1 php artisan command-name</code>. For bare-metal: <code>php artisan command-name</code>.
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

                    @foreach(range(1, 12) as $i)
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

            {{-- Custom Styling Section --}}
            <div id="custom-styling" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-paint-brush"></i>
                        {{ trans('mining-manager::help.custom_styling_guide') }}
                    </h3>
                    <p>{{ trans('mining-manager::help.custom_styling_intro') }}</p>

                    <h4>{{ trans('mining-manager::help.css_class_hierarchy') }}</h4>
                    <p>{{ trans('mining-manager::help.css_class_hierarchy_desc') }}</p>
                    <ul>
                        <li><code>{{ trans('mining-manager::help.css_base_class') }}</code></li>
                        <li><code>{{ trans('mining-manager::help.css_tab_class') }}</code></li>
                        <li><code>{{ trans('mining-manager::help.css_page_class') }}</code></li>
                    </ul>

                    <h4>{{ trans('mining-manager::help.css_available_pages') }}</h4>
                    <ul>
                        <li>{{ trans('mining-manager::help.css_analytics_pages') }}</li>
                        <li>{{ trans('mining-manager::help.css_dashboard_pages') }}</li>
                        <li>{{ trans('mining-manager::help.css_diagnostic_pages') }}</li>
                        <li>{{ trans('mining-manager::help.css_events_pages') }}</li>
                        <li>{{ trans('mining-manager::help.css_ledger_pages') }}</li>
                        <li>{{ trans('mining-manager::help.css_moon_pages') }}</li>
                        <li>{{ trans('mining-manager::help.css_reports_pages') }}</li>
                        <li>{{ trans('mining-manager::help.css_settings_pages') }}</li>
                        <li>{{ trans('mining-manager::help.css_taxes_pages') }}</li>
                        <li>{{ trans('mining-manager::help.css_theft_pages') }}</li>
                    </ul>

                    <h4>{{ trans('mining-manager::help.css_example_title') }}</h4>

                    <h5>{{ trans('mining-manager::help.css_example_global') }}</h5>
                    <pre>{{ trans('mining-manager::help.css_example_global_code') }}</pre>

                    <h5>{{ trans('mining-manager::help.css_example_specific') }}</h5>
                    <pre>{{ trans('mining-manager::help.css_example_specific_code') }}</pre>

                    <h5>{{ trans('mining-manager::help.css_example_all') }}</h5>
                    <pre>{{ trans('mining-manager::help.css_example_all_code') }}</pre>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>{{ trans('mining-manager::help.css_where_to_add') }}:</strong> {{ trans('mining-manager::help.css_where_to_add_desc') }}
                    </div>
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
                        <strong>{{ trans('mining-manager::help.need_help') }}:</strong> {{ trans('mining-manager::help.support_message') }}
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
