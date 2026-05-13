<style>
    :root {
        --ac-page-bg: #eff5fb;
        --ac-page-bg-alt: #f8fbff;
        --ac-surface: rgba(255, 255, 255, 0.94);
        --ac-surface-strong: #ffffff;
        --ac-surface-muted: #f8fafc;
        --ac-surface-emphasis: #fff9e8;
        --ac-input-surface: #f6f0de;
        --ac-input-surface-alt: #ecdfbf;
        --ac-border: rgba(148, 163, 184, 0.28);
        --ac-border-strong: rgba(148, 163, 184, 0.42);
        --ac-text: #0f172a;
        --ac-text-muted: #475569;
        --ac-text-soft: #64748b;
        --ac-primary: #1d4ed8;
        --ac-primary-soft: #dbeafe;
        --ac-success: #15803d;
        --ac-success-soft: #dcfce7;
        --ac-warning: #b45309;
        --ac-warning-soft: #fef3c7;
        --ac-danger: #b91c1c;
        --ac-danger-soft: #fee2e2;
        --ac-info: #0369a1;
        --ac-info-soft: #e0f2fe;
        --ac-neutral-soft: #e2e8f0;
        --ac-shadow-sm: 0 16px 40px -28px rgba(15, 23, 42, 0.5);
        --ac-shadow-lg: 0 26px 70px -32px rgba(15, 23, 42, 0.45);
        --ac-radius-xl: 24px;
        --ac-radius-lg: 20px;
        --ac-radius-md: 16px;
        --ac-radius-sm: 12px;
    }

    .dark {
        --ac-page-bg: #020617;
        --ac-page-bg-alt: #0f172a;
        --ac-surface: rgba(15, 23, 42, 0.92);
        --ac-surface-strong: rgba(15, 23, 42, 0.98);
        --ac-surface-muted: rgba(30, 41, 59, 0.82);
        --ac-surface-emphasis: rgba(51, 65, 85, 0.92);
        --ac-input-surface: rgba(51, 65, 85, 0.9);
        --ac-input-surface-alt: rgba(71, 85, 105, 0.86);
        --ac-border: rgba(100, 116, 139, 0.38);
        --ac-border-strong: rgba(148, 163, 184, 0.5);
        --ac-text: #e2e8f0;
        --ac-text-muted: #cbd5e1;
        --ac-text-soft: #94a3b8;
        --ac-primary: #60a5fa;
        --ac-primary-soft: rgba(37, 99, 235, 0.22);
        --ac-success: #4ade80;
        --ac-success-soft: rgba(34, 197, 94, 0.18);
        --ac-warning: #fbbf24;
        --ac-warning-soft: rgba(251, 191, 36, 0.18);
        --ac-danger: #f87171;
        --ac-danger-soft: rgba(248, 113, 113, 0.18);
        --ac-info: #38bdf8;
        --ac-info-soft: rgba(14, 165, 233, 0.18);
        --ac-neutral-soft: rgba(148, 163, 184, 0.18);
        --ac-shadow-sm: 0 18px 44px -30px rgba(2, 6, 23, 0.82);
        --ac-shadow-lg: 0 28px 78px -36px rgba(2, 6, 23, 0.92);
    }

    .fi-main.fi-admin-content-wide {
        width: min(90%, 2200px);
        max-width: none;
    }

    .fi-main-sidebar {
        border-inline-end: 1px solid rgba(148, 163, 184, 0.22);
        background:
            linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 252, 0.98) 100%);
        box-shadow: 16px 0 36px -30px rgba(15, 23, 42, 0.45);
    }

    .fi-sidebar-header,
    .fi-sidebar-nav,
    .fi-sidebar-footer {
        background: transparent;
    }

    .fi-body {
        background:
            radial-gradient(circle at top left, rgba(37, 99, 235, 0.09), transparent 28%),
            radial-gradient(circle at top right, rgba(14, 165, 233, 0.08), transparent 24%),
            linear-gradient(180deg, var(--ac-page-bg-alt) 0%, var(--ac-page-bg) 100%);
    }

    .fi-main,
    .fi-main-ctn {
        background: transparent;
    }

    .fi-topbar {
        border-bottom: 1px solid var(--ac-border);
        background: rgba(248, 250, 252, 0.82);
        backdrop-filter: blur(16px);
    }

    .dark .fi-topbar {
        background: rgba(15, 23, 42, 0.84);
    }

    .fi-ta-ctn {
        border: 1px solid var(--ac-border);
        border-radius: 24px;
        background: var(--ac-surface-strong);
        box-shadow: var(--ac-shadow-sm);
        overflow: visible;
    }

    .fi-page-header-main-ctn,
    .fi-page-content {
        display: grid;
        gap: 1rem;
    }

    .fi-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        border: 1px solid var(--ac-border);
        border-radius: 24px;
        background: linear-gradient(180deg, var(--ac-surface-strong) 0%, var(--ac-surface) 100%);
        box-shadow: var(--ac-shadow-sm);
        padding: 1rem 1.1rem;
    }

    .fi-header-heading {
        margin: 0;
        font-size: clamp(1.35rem, 1.1rem + 0.9vw, 1.9rem);
        font-weight: 750;
        line-height: 1.15;
        color: var(--ac-text);
    }

    .fi-header-subheading {
        margin: 0.35rem 0 0;
        max-width: 48rem;
        font-size: 0.94rem;
        line-height: 1.55;
        color: var(--ac-text-soft);
    }

    .fi-header-actions-ctn {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .fi-breadcrumbs-list {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        flex-wrap: wrap;
        margin-bottom: 0.5rem;
    }

    .fi-breadcrumbs-item-label {
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--ac-text-soft);
    }

    .fi-page:has([data-role="dialog-page"]) .fi-header.fi-header-has-breadcrumbs {
        align-items: center;
        padding-block: 1.05rem 0.52rem;
    }

    .fi-page:has([data-role="dialog-page"]) .fi-header.fi-header-has-breadcrumbs .fi-breadcrumbs-list {
        margin-bottom: 0;
    }

    .fi-page-sub-navigation-tabs,
    .fi-page-sub-navigation-sidebar-ctn {
        border: 1px solid var(--ac-border);
        border-radius: 20px;
        background: linear-gradient(180deg, var(--ac-surface-strong) 0%, var(--ac-surface) 100%);
        box-shadow: var(--ac-shadow-sm);
        padding: 0.45rem;
    }

    .fi-tabs-item {
        border-radius: 14px;
    }

    .fi-tabs-item-label {
        font-weight: 600;
    }

    .fi-btn,
    .fi-icon-btn {
        border-radius: 14px;
        box-shadow: 0 12px 28px -22px rgba(15, 23, 42, 0.55);
        transition: transform 140ms ease, box-shadow 140ms ease;
    }

    .fi-btn {
        font-weight: 700;
    }

    .fi-btn:not(.fi-color-primary):not(.fi-color-success):not(.fi-color-danger):not(.fi-color-warning):not(.fi-color-info) {
        border-color: rgba(51, 65, 85, 0.92);
        background: linear-gradient(
            180deg,
            #475569 0%,
            #334155 100%
        );
        color: #ffffff;
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.08),
            0 12px 28px -22px rgba(15, 23, 42, 0.62);
    }

    .fi-btn:hover,
    .fi-icon-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 36px -26px rgba(15, 23, 42, 0.62);
    }

    .fi-btn:not(.fi-color-primary):not(.fi-color-success):not(.fi-color-danger):not(.fi-color-warning):not(.fi-color-info):hover {
        border-color: rgba(30, 41, 59, 0.96);
        background: linear-gradient(
            180deg,
            #334155 0%,
            #1e293b 100%
        );
        color: #ffffff;
    }

    .fi-btn.fi-color-success,
    .fi-btn.fi-color-danger,
    .fi-btn.fi-color-info {
        color: #ffffff;
    }

    .fi-btn.fi-color-primary,
    .fi-btn.fi-color-warning {
        color: #111111;
    }

    .fi-input-wrp {
        border-color: color-mix(in srgb, var(--ac-border-strong) 85%, var(--ac-text-soft));
        border-radius: 16px;
        background: linear-gradient(
            180deg,
            color-mix(in srgb, var(--ac-input-surface-alt) 64%, var(--ac-input-surface) 36%) 0%,
            color-mix(in srgb, var(--ac-input-surface) 88%, var(--ac-surface-strong) 12%) 100%
        );
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.78),
            0 1px 2px rgba(15, 23, 42, 0.08);
        transition: border-color 140ms ease, box-shadow 140ms ease, background 140ms ease;
    }

    .fi-input-wrp:not(.fi-fo-select) {
        overflow: hidden;
        isolation: isolate;
    }

    .fi-fo-select.fi-input-wrp {
        overflow: visible;
        isolation: auto;
    }

    .fi-input,
    .fi-select-input {
        color: var(--ac-text);
    }

    .ac-auto-reply-status-inline--simple {
        max-width: 26rem;
    }

    .ac-auto-reply-status-inline--simple .fi-fo-field-wrp {
        margin: 0;
    }

    .ac-auto-reply-status-inline--simple .fi-fo-checkbox {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.9rem;
        flex-direction: row-reverse;
    }

    .ac-auto-reply-status-inline--simple .fi-fo-checkbox-label {
        margin: 0;
        font-size: clamp(1.85rem, 1.55rem + 0.55vw, 2.25rem);
        font-weight: 700;
        line-height: 1.1;
        color: #111827;
    }

    .ac-auto-reply-status-inline--simple .fi-fo-checkbox-state {
        border-radius: 0.5rem;
    }

    .ac-auto-reply-form-section--flat,
    .ac-auto-reply-form-section--flat .fi-section {
        border: 0;
        background: transparent;
        box-shadow: none;
    }

    .ac-auto-reply-form-section--flat .fi-section-header {
        padding: 0 0 0.85rem;
        border: 0;
    }

    .ac-auto-reply-form-section--flat .fi-section-content {
        padding: 0;
    }

    .ac-auto-reply-form-section--flat .fi-section-heading {
        font-size: clamp(1.85rem, 1.55rem + 0.5vw, 2.15rem);
        font-weight: 700;
        line-height: 1.1;
        color: #111827;
    }

    .ac-auto-reply-form-section--minimal .fi-fo-field-wrp {
        display: grid;
        grid-template-columns: minmax(13.5rem, 16rem) minmax(0, 1fr);
        gap: 0.85rem 1.25rem;
        align-items: start;
    }

    .ac-auto-reply-form-section--minimal .fi-fo-field-wrp-label {
        margin: 0;
        padding-top: 0.45rem;
        font-size: 1.15rem;
        font-weight: 600;
        line-height: 1.2;
        color: #111827;
    }

    .ac-auto-reply-form-section--minimal .fi-fo-field-wrp-helper-text,
    .ac-auto-reply-form-section--minimal .fi-fo-field-wrp-hint {
        grid-column: 2;
        margin-top: 0.15rem;
    }

    .ac-auto-reply-form-section--minimal .fi-input-wrp {
        border: 0;
        border-radius: 0.375rem;
        background: #e9edf3;
        box-shadow: none;
    }

    .ac-auto-reply-form-section--minimal input.fi-input {
        min-height: 2.95rem;
    }

    .ac-auto-reply-form-section--minimal .fi-fo-text-input .fi-input-wrp {
        border-radius: 0.375rem;
    }

    .ac-auto-reply-form-section--reply .fi-input-wrp {
        border: 0;
        border-radius: 0.375rem;
        background: #e9edf3;
        box-shadow: none;
    }

    .ac-auto-reply-form-section--reply textarea.fi-input {
        min-height: 10.5rem;
    }

    .ac-auto-reply-form-section--effects {
        max-width: 46rem;
    }

    @media (max-width: 900px) {
        .ac-auto-reply-form-section--minimal .fi-fo-field-wrp {
            grid-template-columns: 1fr;
            gap: 0.55rem;
        }

        .ac-auto-reply-form-section--minimal .fi-fo-field-wrp-helper-text,
        .ac-auto-reply-form-section--minimal .fi-fo-field-wrp-hint {
            grid-column: auto;
        }
    }

    .fi-input-wrp-content-ctn,
    .fi-input-wrp-prefix,
    .fi-input-wrp-suffix,
    .fi-select-input-btn,
    .fi-input,
    .fi-select-input {
        border-radius: inherit;
        background-clip: padding-box;
    }

    .fi-input-wrp-prefix,
    .fi-input-wrp-suffix {
        background: color-mix(in srgb, var(--ac-input-surface-alt) 70%, var(--ac-input-surface) 30%);
    }

    .fi-input-wrp-prefix {
        border-inline-end: 1px solid color-mix(in srgb, var(--ac-border-strong) 82%, transparent);
    }

    .fi-input-wrp-suffix {
        border-inline-start: 1px solid color-mix(in srgb, var(--ac-border-strong) 82%, transparent);
    }

    .fi-input-wrp-actions {
        color: color-mix(in srgb, var(--ac-text-soft) 82%, var(--ac-text));
    }

    .fi-input::placeholder {
        color: color-mix(in srgb, var(--ac-text-soft) 86%, var(--ac-text-muted));
    }

    .fi-global-search-field .fi-input-wrp,
    .fi-ta-search-field .fi-input-wrp,
    .fi-pagination-records-per-page-select .fi-input-wrp {
        min-height: 2.9rem;
        border-color: color-mix(in srgb, var(--ac-warning) 16%, var(--ac-border-strong));
        background: linear-gradient(
            180deg,
            color-mix(in srgb, var(--ac-surface-emphasis) 78%, var(--ac-surface-strong)) 0%,
            var(--ac-surface-strong) 100%
        );
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.72),
            0 12px 28px -26px rgba(15, 23, 42, 0.42);
    }

    .fi-global-search-field .fi-input,
    .fi-ta-search-field .fi-input,
    .fi-pagination-records-per-page-select .fi-select-input,
    .fi-pagination-records-per-page-select .fi-select-input-btn {
        min-height: 2.9rem;
    }

    .fi-global-search-field .fi-input-wrp-prefix,
    .fi-ta-search-field .fi-input-wrp-prefix,
    .fi-pagination-records-per-page-select .fi-input-wrp-prefix,
    .fi-global-search-field .fi-input-wrp-suffix,
    .fi-pagination-records-per-page-select .fi-input-wrp-suffix {
        color: var(--ac-text-soft);
        border-color: color-mix(in srgb, var(--ac-warning) 18%, var(--ac-border));
        background: color-mix(in srgb, var(--ac-surface-emphasis) 42%, transparent);
    }

    .fi-global-search-field .fi-input::placeholder,
    .fi-ta-search-field .fi-input::placeholder,
    .fi-pagination-records-per-page-select .fi-input-wrp-label {
        color: var(--ac-text-soft);
    }

    .fi-ta-search-field .fi-input-wrp {
        display: flex;
        align-items: stretch;
    }

    .fi-ta-search-field .fi-input-wrp-content-ctn {
        order: 1;
        min-width: 0;
    }

    .fi-ta-search-field .fi-input-wrp-prefix {
        order: 2;
        border-inline-start: 1px solid color-mix(in srgb, var(--ac-warning) 18%, var(--ac-border));
        border-inline-end: none;
    }

    .fi-ta-header-toolbar .fi-ta-search-field {
        min-width: min(100%, 17rem);
    }

    .fi-ta-header-toolbar .fi-btn,
    .fi-ta-header-toolbar .fi-icon-btn {
        min-height: 2.9rem;
    }

    .fi-input-wrp:hover {
        border-color: color-mix(in srgb, var(--ac-border-strong) 72%, var(--ac-primary));
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.82),
            0 0 0 1px color-mix(in srgb, var(--ac-primary-soft) 38%, transparent),
            0 2px 8px rgba(15, 23, 42, 0.06);
    }

    .fi-input-wrp:has(textarea.fi-input) {
        border-color: color-mix(in srgb, var(--ac-border-strong) 76%, var(--ac-text-soft));
        background: linear-gradient(
            180deg,
            color-mix(in srgb, var(--ac-input-surface-alt) 72%, var(--ac-input-surface) 28%) 0%,
            color-mix(in srgb, var(--ac-input-surface) 92%, var(--ac-surface-strong) 8%) 100%
        );
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.82),
            inset 0 0 0 1px rgba(148, 163, 184, 0.08),
            0 2px 8px rgba(15, 23, 42, 0.06);
    }

    .fi-input-wrp:has(textarea.fi-input):hover {
        border-color: color-mix(in srgb, var(--ac-border-strong) 62%, var(--ac-primary));
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.84),
            inset 0 0 0 1px color-mix(in srgb, var(--ac-primary-soft) 36%, transparent),
            0 0 0 1px color-mix(in srgb, var(--ac-primary-soft) 44%, transparent),
            0 4px 12px rgba(15, 23, 42, 0.08);
    }

    .fi-input-wrp:focus-within {
        border-color: color-mix(in srgb, var(--ac-primary) 72%, white 28%);
        background: color-mix(in srgb, var(--ac-surface-strong) 88%, var(--ac-primary-soft));
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.84),
            0 0 0 1px color-mix(in srgb, var(--ac-primary) 30%, transparent),
            0 0 0 4px color-mix(in srgb, var(--ac-primary-soft) 56%, transparent);
    }

    .fi-ta-main {
        background: transparent;
    }

    .fi-ta-header-ctn {
        padding: 1rem 1rem 0;
    }

    .fi-ta-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        padding: 0;
    }

    .fi-ta-header-heading {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
        line-height: 1.25;
        color: var(--ac-text);
    }

    .fi-ta-header-description {
        margin: 0.3rem 0 0;
        font-size: 0.88rem;
        line-height: 1.5;
        color: var(--ac-text-soft);
    }

    .fi-ta-header-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.9rem;
        flex-wrap: wrap;
        margin-top: 1rem;
        padding: 0.9rem 0 0;
        border-top: 1px solid var(--ac-border);
    }

    .fi-ta-header-toolbar > :last-child {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.65rem;
        flex-wrap: nowrap;
        margin-inline-start: auto;
    }

    .fi-ta-header-toolbar > :last-child > .fi-ta-search-field {
        flex: 0 1 17rem;
        min-width: 17rem;
    }

    .fi-ta-header-toolbar > :last-child > :not(.fi-ta-search-field) {
        flex: 0 0 auto;
    }

    .fi-ta-actions {
        gap: 0.65rem;
    }

    .ac-table-toolbar-trigger {
        min-height: 2.9rem;
    }

    .fi-ta-filter-indicators {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        flex-wrap: wrap;
        margin: 0 1rem;
        padding: 0.85rem 0 0;
    }

    .fi-ta-filter-indicators-label {
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--ac-text-soft);
    }

    .fi-ta-filter-indicators-badges-ctn {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .fi-badge {
        border-radius: 999px;
        box-shadow: none;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) {
        border: 1px solid color-mix(in srgb, var(--ac-border-strong) 88%, white 12%);
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-strong) 96%, white 4%) 0%, var(--ac-surface) 100%);
        box-shadow: 0 40px 90px -42px rgba(15, 23, 42, 0.42);
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-modal-header {
        border-bottom: 1px solid color-mix(in srgb, var(--ac-border) 88%, transparent);
        padding-bottom: 1rem;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-modal-footer {
        border-top: 0;
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-muted) 78%, transparent) 0%, transparent 100%);
        padding-top: 1.25rem;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-modal-footer-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.75rem;
        flex-wrap: wrap;
        width: 100%;
        padding-top: 0.25rem;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-modal-content {
        display: grid;
        gap: 1rem;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-section-content {
        display: grid;
        gap: 1rem;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-fo-field {
        gap: 0.45rem;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-fo-field-label {
        color: var(--ac-text);
        font-weight: 650;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-fo-field-label-content {
        letter-spacing: -0.01em;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-fo-field-content-col {
        gap: 0.45rem;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-fo-field-wrp-error-message,
    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-fo-field-wrp-error-list {
        color: var(--ac-danger);
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-input-wrp {
        border-color: color-mix(in srgb, var(--ac-border-strong) 84%, var(--ac-text));
        background: linear-gradient(
            180deg,
            color-mix(in srgb, var(--ac-input-surface-alt) 78%, var(--ac-input-surface) 22%) 0%,
            color-mix(in srgb, var(--ac-input-surface) 90%, var(--ac-surface-strong) 10%) 100%
        );
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.82),
            0 1px 2px rgba(15, 23, 42, 0.08);
        transition: border-color 140ms ease, box-shadow 140ms ease, background 140ms ease;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-input::placeholder {
        color: color-mix(in srgb, var(--ac-text-soft) 76%, var(--ac-text));
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-input-wrp-prefix,
    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-input-wrp-suffix {
        background: color-mix(in srgb, var(--ac-input-surface-alt) 74%, var(--ac-input-surface) 26%);
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-input-wrp-prefix {
        border-inline-end: 1px solid color-mix(in srgb, var(--ac-border-strong) 78%, var(--ac-text-soft));
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-input-wrp-suffix {
        border-inline-start: 1px solid color-mix(in srgb, var(--ac-border-strong) 78%, var(--ac-text-soft));
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-input-wrp-actions {
        color: color-mix(in srgb, var(--ac-text-soft) 78%, var(--ac-text));
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-input-wrp:hover {
        border-color: color-mix(in srgb, var(--ac-border-strong) 66%, var(--ac-primary));
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.86),
            0 0 0 1px color-mix(in srgb, var(--ac-primary-soft) 54%, transparent),
            0 4px 12px rgba(15, 23, 42, 0.06);
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-input-wrp:has(textarea.fi-input) {
        border-color: color-mix(in srgb, var(--ac-border-strong) 72%, var(--ac-text));
        background: linear-gradient(
            180deg,
            color-mix(in srgb, var(--ac-input-surface-alt) 84%, var(--ac-input-surface) 16%) 0%,
            color-mix(in srgb, var(--ac-input-surface) 94%, var(--ac-surface-strong) 6%) 100%
        );
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.86),
            inset 0 0 0 1px rgba(148, 163, 184, 0.1),
            0 2px 10px rgba(15, 23, 42, 0.08);
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-input-wrp:has(textarea.fi-input):hover {
        border-color: color-mix(in srgb, var(--ac-border-strong) 58%, var(--ac-primary));
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.88),
            inset 0 0 0 1px color-mix(in srgb, var(--ac-primary-soft) 42%, transparent),
            0 0 0 1px color-mix(in srgb, var(--ac-primary-soft) 54%, transparent),
            0 4px 14px rgba(15, 23, 42, 0.08);
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-input-wrp:focus-within {
        border-color: color-mix(in srgb, var(--ac-primary) 72%, white 28%);
        background: color-mix(in srgb, var(--ac-surface-strong) 84%, var(--ac-primary-soft));
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.88),
            0 0 0 1px color-mix(in srgb, var(--ac-primary) 38%, transparent),
            0 0 0 4px color-mix(in srgb, var(--ac-primary-soft) 76%, transparent);
    }

    textarea.fi-input {
        line-height: 1.6;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-input-wrp:has([aria-invalid='true']) {
        border-color: color-mix(in srgb, var(--ac-danger) 70%, var(--ac-border-strong));
        box-shadow:
            inset 0 1px 2px rgba(15, 23, 42, 0.05),
            0 0 0 1px color-mix(in srgb, var(--ac-danger-soft) 60%, transparent);
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-toggle {
        box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--ac-border-strong) 88%, transparent);
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-fo-toggle {
        display: flex;
        align-items: flex-start;
        gap: 0.8rem;
    }

    :is(.ac-user-form-section, .ac-channel-form-section, .ac-tag-form-section, .ac-auto-reply-form-section, .ac-scenario-form-section) {
        border: 1px solid color-mix(in srgb, var(--ac-border-strong) 86%, white 14%);
        border-radius: 22px;
        overflow: hidden;
        box-shadow: 0 16px 34px -28px rgba(15, 23, 42, 0.24);
    }

    :is(.ac-user-form-section, .ac-channel-form-section, .ac-tag-form-section, .ac-auto-reply-form-section, .ac-scenario-form-section) .fi-section-header {
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-muted) 95%, white 5%) 0%, color-mix(in srgb, var(--ac-surface-strong) 98%, white 2%) 100%);
        border-bottom: 1px solid color-mix(in srgb, var(--ac-border) 92%, transparent);
        padding-bottom: 1rem;
    }

    :is(.ac-user-form-section, .ac-channel-form-section, .ac-tag-form-section, .ac-auto-reply-form-section, .ac-scenario-form-section) .fi-section-header-description {
        max-width: 34rem;
        color: var(--ac-text-soft);
    }

    :is(.ac-user-form-section, .ac-channel-form-section, .ac-tag-form-section, .ac-auto-reply-form-section, .ac-scenario-form-section) .fi-section-content-ctn {
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-strong) 95%, white 5%) 0%, var(--ac-surface) 100%);
    }

    :is(.ac-user-form-section, .ac-channel-form-section, .ac-tag-form-section, .ac-auto-reply-form-section, .ac-scenario-form-section) .fi-section-content {
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-strong) 95%, white 5%) 0%, var(--ac-surface) 100%);
    }

    :is(.ac-user-form-field, .ac-channel-form-field, .ac-tag-form-field, .ac-auto-reply-form-field) .fi-input-wrp {
        background: linear-gradient(
            180deg,
            color-mix(in srgb, var(--ac-surface-strong) 86%, var(--ac-page-bg-alt) 14%) 0%,
            color-mix(in srgb, var(--ac-surface-muted) 90%, white 10%) 100%
        );
    }

    .ac-scenario-builder-table-action.fi-btn {
        min-width: 8.5rem;
        justify-content: center;
        font-weight: 750;
    }

    .ac-scenario-start-preview {
        display: grid;
        gap: 0.45rem;
        border: 1px solid color-mix(in srgb, var(--ac-success) 42%, var(--ac-border));
        border-radius: 16px;
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-success-soft) 74%, var(--ac-surface-strong)) 0%, var(--ac-surface-strong) 100%);
        color: var(--ac-text);
        padding: 0.95rem 1rem;
    }

    .ac-scenario-start-preview strong {
        color: color-mix(in srgb, var(--ac-success) 72%, var(--ac-text));
        font-size: 1rem;
        letter-spacing: -0.02em;
    }

    .ac-scenario-start-preview span {
        color: var(--ac-text-muted);
        font-size: 0.88rem;
        line-height: 1.45;
    }

    .ac-scenario-start-preview b {
        color: var(--ac-text);
    }

    .ac-scenario-start-preview__warning {
        color: color-mix(in srgb, var(--ac-warning) 78%, var(--ac-text)) !important;
        font-weight: 700;
    }

    .ac-scenario-builder-entrypoint {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        border: 1px solid color-mix(in srgb, var(--ac-success) 36%, var(--ac-border));
        border-radius: 18px;
        background:
            radial-gradient(circle at top right, color-mix(in srgb, var(--ac-success-soft) 76%, transparent), transparent 48%),
            var(--ac-surface-strong);
        padding: 1rem;
    }

    .ac-scenario-builder-entrypoint strong,
    .ac-scenario-builder-entrypoint span {
        display: block;
    }

    .ac-scenario-builder-entrypoint strong {
        color: var(--ac-text);
        font-weight: 850;
        letter-spacing: -0.02em;
    }

    .ac-scenario-builder-entrypoint span {
        max-width: 42rem;
        margin-top: 0.25rem;
        color: var(--ac-text-muted);
        font-size: 0.88rem;
        line-height: 1.45;
    }

    .ac-scenario-builder-page {
        display: grid;
        gap: 1rem;
    }

    .ac-scenario-builder-hero,
    .ac-scenario-builder-shell,
    .ac-scenario-builder-empty-state {
        border: 1px solid var(--ac-border);
        border-radius: 26px;
        background: var(--ac-surface-strong);
        box-shadow: var(--ac-shadow-sm);
    }

    .ac-scenario-builder-hero {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.2rem;
    }

    .ac-scenario-builder-hero__eyebrow,
    .ac-scenario-builder-panel__header span,
    .ac-scenario-builder-workspace__topbar span,
    .ac-scenario-builder-node__top span,
    .ac-scenario-builder-fieldset label {
        color: var(--ac-text-soft);
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .ac-scenario-builder-hero h2 {
        margin: 0.2rem 0 0;
        color: var(--ac-text);
        font-size: clamp(1.45rem, 2vw, 2rem);
        font-weight: 850;
        letter-spacing: -0.04em;
    }

    .ac-scenario-builder-hero p {
        max-width: 48rem;
        margin: 0.45rem 0 0;
        color: var(--ac-text-muted);
        line-height: 1.5;
    }

    .ac-scenario-builder-hero__actions {
        display: flex;
        gap: 0.65rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .ac-scenario-builder-shell {
        display: grid;
        grid-template-columns: minmax(42rem, 1fr) minmax(22rem, 24rem);
        min-height: 40rem;
        overflow: hidden;
    }

    .ac-scenario-builder-palette {
        display: grid;
        align-content: start;
        gap: 0.9rem;
        padding: 1rem;
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-muted) 92%, transparent) 0%, color-mix(in srgb, var(--ac-surface-strong) 100%, transparent) 100%);
    }

    .ac-scenario-builder-palette {
        border-left: 1px solid var(--ac-border);
    }

    .ac-scenario-builder-settings,
    .ac-scenario-builder-palette > div {
        display: grid;
        gap: 0.9rem;
    }

    .ac-scenario-builder-panel__header--with-action {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.7rem;
    }

    .ac-scenario-builder-panel__header--with-action button {
        border: 1px solid var(--ac-border);
        border-radius: 999px;
        background: var(--ac-surface-strong);
        color: var(--ac-text-muted);
        cursor: pointer;
        font-size: 0.78rem;
        font-weight: 750;
        padding: 0.38rem 0.68rem;
    }

    .ac-scenario-builder-panel__header strong,
    .ac-scenario-builder-workspace__topbar strong,
    .ac-scenario-builder-node__top strong {
        display: block;
        margin-top: 0.16rem;
        color: var(--ac-text);
        font-weight: 850;
        letter-spacing: -0.02em;
    }

    .ac-scenario-builder-palette__item,
    .ac-scenario-builder-fieldset,
    .ac-scenario-builder-trigger-row {
        border: 1px solid var(--ac-border);
        border-radius: 18px;
        background: var(--ac-surface-strong);
        box-shadow: 0 14px 34px -30px rgba(15, 23, 42, 0.5);
    }

    .ac-scenario-builder-palette__item {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 0.75rem;
        padding: 0.85rem;
        color: inherit;
        font: inherit;
        text-align: left;
    }

    .ac-scenario-builder-palette__item--green {
        border-color: color-mix(in srgb, var(--ac-success) 46%, var(--ac-border));
        background: color-mix(in srgb, var(--ac-success-soft) 70%, var(--ac-surface-strong));
        cursor: pointer;
        width: 100%;
    }

    .ac-scenario-builder-palette__item--locked {
        opacity: 0.62;
    }

    .ac-scenario-builder-palette__dot {
        width: 0.8rem;
        height: 0.8rem;
        border-radius: 999px;
        margin-top: 0.25rem;
        background: var(--ac-neutral-soft);
    }

    .ac-scenario-builder-palette__item--green .ac-scenario-builder-palette__dot {
        background: var(--ac-success);
        box-shadow: 0 0 0 5px var(--ac-success-soft);
    }

    .ac-scenario-builder-palette__item strong {
        color: var(--ac-text);
        font-weight: 800;
    }

    .ac-scenario-builder-palette__item p,
    .ac-scenario-builder-fieldset p {
        margin: 0.25rem 0 0;
        color: var(--ac-text-soft);
        font-size: 0.82rem;
        line-height: 1.45;
    }

    .ac-scenario-builder-element-list {
        display: grid;
        gap: 0.55rem;
    }

    .ac-scenario-builder-element-list__item {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        align-items: center;
        gap: 0.75rem;
        width: 100%;
        border: 1px solid var(--ac-border);
        border-radius: 16px;
        background: var(--ac-surface-strong);
        color: inherit;
        font: inherit;
        padding: 0.72rem 0.8rem;
        text-align: left;
    }

    .ac-scenario-builder-element-list__item--green {
        border-color: color-mix(in srgb, var(--ac-success) 42%, var(--ac-border));
        background: color-mix(in srgb, var(--ac-success-soft) 66%, var(--ac-surface-strong));
        cursor: pointer;
    }

    .ac-scenario-builder-element-list__item.is-disabled {
        opacity: 0.58;
    }

    .ac-scenario-builder-element-list__icon {
        display: grid;
        place-items: center;
        width: 2rem;
        height: 2rem;
        border: 2px solid color-mix(in srgb, var(--ac-primary) 52%, var(--ac-border));
        border-radius: 9px;
        color: color-mix(in srgb, var(--ac-primary) 76%, var(--ac-text));
        font-weight: 900;
        line-height: 1;
    }

    .ac-scenario-builder-element-list__item--green .ac-scenario-builder-element-list__icon {
        border-color: var(--ac-success);
        color: var(--ac-success);
    }

    .ac-scenario-builder-element-list__icon--danger {
        border-color: var(--ac-danger);
        color: var(--ac-danger);
    }

    .ac-scenario-builder-element-list__item strong {
        color: var(--ac-text);
        font-size: 0.95rem;
        font-weight: 790;
        line-height: 1.2;
    }

    .ac-scenario-builder-block-list {
        display: grid;
        gap: 0.5rem;
        margin-top: 0.2rem;
    }

    .ac-scenario-builder-block-list > span {
        color: var(--ac-text-soft);
        font-size: 0.68rem;
        font-weight: 850;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .ac-scenario-builder-block-list__item {
        border: 1px solid var(--ac-border);
        border-radius: 15px;
        background: var(--ac-surface-strong);
        color: inherit;
        cursor: pointer;
        font: inherit;
        padding: 0.68rem 0.75rem;
        text-align: left;
    }

    .ac-scenario-builder-block-list__item.is-selected {
        border-color: color-mix(in srgb, var(--ac-success) 54%, var(--ac-border));
        background: color-mix(in srgb, var(--ac-success-soft) 68%, var(--ac-surface-strong));
    }

    .ac-scenario-builder-block-list__item strong,
    .ac-scenario-builder-block-list__item small {
        display: block;
    }

    .ac-scenario-builder-block-list__item strong {
        color: var(--ac-text);
        font-size: 0.9rem;
        font-weight: 830;
    }

    .ac-scenario-builder-block-list__item small {
        margin-top: 0.12rem;
        color: var(--ac-text-soft);
        font-size: 0.72rem;
        font-weight: 750;
    }

    .ac-scenario-builder-block-type-selector {
        display: grid;
        gap: 0.55rem;
    }

    .ac-scenario-builder-block-type-selector > span {
        color: var(--ac-text-soft);
        font-size: 0.68rem;
        font-weight: 850;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .ac-scenario-builder-block-type-option {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        align-items: center;
        gap: 0.7rem;
        border: 1px solid var(--ac-border);
        border-radius: 16px;
        background: var(--ac-surface-strong);
        color: inherit;
        font: inherit;
        padding: 0.72rem 0.8rem;
        text-align: left;
    }

    .ac-scenario-builder-block-type-option.is-active {
        border-color: color-mix(in srgb, var(--ac-success) 54%, var(--ac-border));
        background: color-mix(in srgb, var(--ac-success-soft) 72%, var(--ac-surface-strong));
    }

    .ac-scenario-builder-block-type-option.is-disabled {
        opacity: 0.55;
    }

    .ac-scenario-builder-block-type-option__icon {
        display: grid;
        place-items: center;
        width: 2.2rem;
        height: 2.2rem;
        border-radius: 12px;
        background: color-mix(in srgb, var(--ac-success) 88%, #064e3b);
        color: white;
        font-size: 1.08rem;
        font-weight: 900;
    }

    .ac-scenario-builder-block-type-option.is-disabled .ac-scenario-builder-block-type-option__icon {
        background: var(--ac-neutral-soft);
        color: var(--ac-text-soft);
    }

    .ac-scenario-builder-block-type-option strong,
    .ac-scenario-builder-block-type-option small {
        display: block;
    }

    .ac-scenario-builder-block-type-option strong {
        color: var(--ac-text);
        font-weight: 850;
        line-height: 1.2;
    }

    .ac-scenario-builder-block-type-option small {
        margin-top: 0.1rem;
        color: var(--ac-text-soft);
        font-size: 0.74rem;
        font-weight: 760;
    }

    .ac-scenario-builder-readonly-value {
        display: block;
        width: 100%;
        border: 1px solid var(--ac-border-strong);
        border-radius: 14px;
        background: color-mix(in srgb, var(--ac-surface-muted) 74%, var(--ac-surface-strong));
        color: var(--ac-text);
        font-size: 1rem;
        font-weight: 850;
        padding: 0.7rem 0.8rem;
        overflow-wrap: anywhere;
    }

    .ac-scenario-builder-meta-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.65rem;
    }

    .ac-scenario-builder-condition-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.7rem;
    }

    .ac-scenario-builder-condition-grid div {
        display: grid;
        gap: 0.45rem;
    }

    .ac-scenario-builder-section-badge {
        border: 1px solid color-mix(in srgb, var(--ac-success) 34%, var(--ac-border));
        border-radius: 999px;
        background: color-mix(in srgb, var(--ac-success-soft) 76%, var(--ac-surface-strong));
        color: color-mix(in srgb, var(--ac-success) 76%, var(--ac-text));
        font-size: 0.7rem;
        font-weight: 850;
        padding: 0.26rem 0.52rem;
    }

    .ac-scenario-builder-sale-bot-sections {
        display: grid;
        gap: 0.55rem;
    }

    .ac-scenario-builder-sale-bot-sections div {
        border: 1px dashed var(--ac-border-strong);
        border-radius: 14px;
        background: color-mix(in srgb, var(--ac-surface-muted) 62%, var(--ac-surface-strong));
        padding: 0.68rem 0.75rem;
    }

    .ac-scenario-builder-sale-bot-sections strong,
    .ac-scenario-builder-sale-bot-sections span {
        display: block;
    }

    .ac-scenario-builder-sale-bot-sections strong {
        color: var(--ac-text);
        font-size: 0.86rem;
        font-weight: 830;
    }

    .ac-scenario-builder-sale-bot-sections span {
        margin-top: 0.12rem;
        color: var(--ac-text-soft);
        font-size: 0.74rem;
        line-height: 1.35;
    }

    .ac-scenario-builder-workspace {
        display: grid;
        grid-template-rows: auto minmax(0, 1fr);
        min-width: 0;
        background:
            linear-gradient(135deg, color-mix(in srgb, var(--ac-page-bg-alt) 92%, transparent) 0%, color-mix(in srgb, var(--ac-page-bg) 96%, transparent) 100%);
    }

    .ac-scenario-builder-workspace__topbar {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.15rem;
        border-bottom: 1px solid var(--ac-border);
        background: color-mix(in srgb, var(--ac-surface-strong) 84%, transparent);
    }

    .ac-scenario-builder-workspace__topbar p {
        margin: 0.35rem 0 0;
        color: var(--ac-text-soft);
        font-size: 0.82rem;
        line-height: 1.35;
    }

    .ac-scenario-builder-workspace__actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.65rem;
        flex-wrap: wrap;
    }

    .ac-scenario-builder-workspace__badge {
        align-self: center;
        border: 1px solid color-mix(in srgb, var(--ac-warning) 42%, var(--ac-border));
        border-radius: 999px;
        background: var(--ac-warning-soft);
        color: color-mix(in srgb, var(--ac-warning) 80%, var(--ac-text));
        font-size: 0.78rem;
        font-weight: 800;
        padding: 0.35rem 0.7rem;
    }

    .ac-scenario-builder-canvas {
        position: relative;
        min-height: clamp(44rem, 76vh, 64rem);
        overflow: auto;
    }

    .ac-scenario-builder-canvas__surface {
        position: relative;
        min-width: 96rem;
        min-height: 80rem;
        background-image:
            radial-gradient(circle, color-mix(in srgb, var(--ac-text-soft) 46%, transparent) 0 1.35px, transparent 1.45px 100%);
        background-position: 0 0;
        background-size: 18px 18px;
    }

    .ac-scenario-builder-node {
        position: absolute;
        width: 14rem;
        border: 1px solid var(--ac-border);
        border-radius: 18px;
        background: var(--ac-surface-strong);
        box-shadow: 0 28px 70px -42px rgba(15, 23, 42, 0.72);
        padding: 1rem;
    }

    .ac-scenario-builder-node--green {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 5.5rem;
        border-color: color-mix(in srgb, var(--ac-success) 72%, var(--ac-border));
        background:
            radial-gradient(circle at top left, color-mix(in srgb, white 24%, transparent) 0%, transparent 34%),
            linear-gradient(135deg, color-mix(in srgb, var(--ac-success) 88%, #064e3b) 0%, color-mix(in srgb, var(--ac-success) 76%, #22c55e) 100%);
        color: white;
        cursor: grab;
        touch-action: none;
        user-select: none;
        z-index: 2;
        transition: box-shadow 0.16s ease, transform 0.16s ease;
    }

    .ac-scenario-builder-node--green.is-dragging {
        cursor: grabbing;
        transform: scale(1.015);
        box-shadow:
            0 34px 90px -40px rgba(15, 23, 42, 0.8),
            0 0 0 4px color-mix(in srgb, var(--ac-success-soft) 76%, transparent);
    }

    .ac-scenario-builder-node--green.is-selected {
        box-shadow:
            0 30px 80px -38px rgba(15, 23, 42, 0.82),
            0 0 0 5px color-mix(in srgb, var(--ac-success-soft) 72%, transparent);
    }

    .ac-scenario-builder-node--green strong {
        color: white;
        font-size: 1.05rem;
        font-weight: 850;
        letter-spacing: -0.02em;
        line-height: 1.15;
        text-align: center;
    }

    .ac-scenario-builder-node p {
        margin: 0.7rem 0 0;
        color: var(--ac-text-muted);
        line-height: 1.45;
    }

    .ac-scenario-builder-node__facts {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.55rem;
        margin-top: 0.95rem;
    }

    .ac-scenario-builder-node__facts div {
        border: 1px solid color-mix(in srgb, var(--ac-success) 28%, var(--ac-border));
        border-radius: 14px;
        background: color-mix(in srgb, var(--ac-success-soft) 52%, var(--ac-surface-strong));
        padding: 0.65rem;
    }

    .ac-scenario-builder-node__facts span {
        display: block;
        color: var(--ac-text-soft);
        font-size: 0.7rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }

    .ac-scenario-builder-node__facts strong {
        display: block;
        margin-top: 0.15rem;
        color: var(--ac-text);
        font-size: 0.95rem;
    }

    .ac-scenario-builder-node__chips {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        margin-top: 0.9rem;
    }

    .ac-scenario-builder-node__chips span {
        border: 1px solid color-mix(in srgb, var(--ac-success) 34%, var(--ac-border));
        border-radius: 999px;
        background: color-mix(in srgb, var(--ac-success-soft) 76%, var(--ac-surface-strong));
        color: color-mix(in srgb, var(--ac-success) 76%, var(--ac-text));
        font-size: 0.76rem;
        font-weight: 750;
        padding: 0.3rem 0.58rem;
    }

    .ac-bot-constructor-node.ac-scenario-builder-node--green {
        display: grid;
        place-items: center;
        width: 14rem;
        height: 5.2rem;
        min-height: 5.2rem;
        padding: 0.7rem 1rem;
        overflow: hidden;
        border: 1px solid color-mix(in srgb, #087c3f 84%, black);
        border-radius: 14px;
        background:
            linear-gradient(180deg, #08bf5c 0%, #06b553 100%);
        box-shadow:
            0 14px 26px -20px rgba(2, 44, 23, 0.76),
            0 1px 0 rgba(255, 255, 255, 0.18) inset;
        text-align: center;
    }

    .ac-bot-constructor-node.ac-scenario-builder-node--green.is-selected {
        border-color: #2563eb;
        box-shadow:
            0 14px 26px -20px rgba(2, 44, 23, 0.76),
            0 0 0 3px #2563eb;
    }

    .ac-bot-constructor-node.ac-scenario-builder-node--green.is-dragging {
        transform: none;
    }

    .ac-bot-constructor-node.ac-scenario-builder-node--green strong {
        display: block;
        min-width: 0;
        overflow: hidden;
        color: white;
        font-size: 1.14rem;
        font-weight: 850;
        letter-spacing: 0;
        line-height: 1.16;
        text-align: center;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ac-bot-constructor-node__id {
        position: absolute;
        top: 0.38rem;
        left: 0.5rem;
        max-width: 4rem;
        overflow: hidden;
        color: rgba(255, 255, 255, 0.72);
        font-size: 0.52rem;
        font-weight: 850;
        letter-spacing: 0;
        line-height: 1;
        text-overflow: ellipsis;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .ac-bot-constructor-node.is-inactive {
        filter: saturate(0.88);
    }

    .ac-bot-constructor-arrows {
        position: absolute;
        inset: 0;
        z-index: 1;
        overflow: visible;
        pointer-events: none;
    }

    .ac-bot-constructor-arrows marker path {
        fill: #8aa0bd;
    }

    .ac-bot-constructor-arrows #ac-bot-constructor-arrow-head-selected path {
        fill: #3b82f6;
    }

    .ac-bot-constructor-arrow {
        pointer-events: none;
    }

    .ac-bot-constructor-arrow__hit {
        stroke: transparent;
        stroke-width: 18;
        pointer-events: stroke;
        cursor: pointer;
    }

    .ac-bot-constructor-arrow__line {
        stroke: #8aa0bd;
        stroke-width: 2.5;
        stroke-linecap: round;
        pointer-events: none;
    }

    .ac-bot-constructor-arrow.is-selected .ac-bot-constructor-arrow__line {
        stroke: #3b82f6;
        stroke-width: 3.5;
    }

    .ac-bot-constructor-arrow.is-inactive .ac-bot-constructor-arrow__line {
        opacity: 0.42;
        stroke-dasharray: 8 7;
    }

    .ac-bot-constructor-arrow__label {
        fill: var(--ac-text);
        paint-order: stroke;
        stroke: var(--ac-page-bg);
        stroke-width: 5px;
        font-size: 0.76rem;
        font-weight: 800;
        pointer-events: auto;
        cursor: pointer;
        text-anchor: middle;
    }

    .ac-bot-constructor-inline-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(7rem, 0.7fr);
        gap: 0.55rem;
    }

    .ac-scenario-builder-panel__danger-action {
        border: 1px solid color-mix(in srgb, var(--ac-danger) 46%, var(--ac-border));
        border-radius: 12px;
        background: var(--ac-danger-soft);
        color: color-mix(in srgb, var(--ac-danger) 84%, var(--ac-text));
        font-size: 0.78rem;
        font-weight: 850;
        line-height: 1;
        padding: 0.55rem 0.75rem;
    }

    .ac-scenario-builder-inline-meta {
        display: block;
        margin-top: 0.25rem;
        color: var(--ac-text-soft);
        font-size: 0.78rem;
        font-weight: 800;
        letter-spacing: 0;
        line-height: 1.35;
        text-transform: none;
    }

    .ac-scenario-builder-fieldset {
        display: grid;
        gap: 0.7rem;
        padding: 0.85rem;
    }

    .ac-scenario-builder-fieldset__title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.65rem;
    }

    .ac-scenario-builder-fieldset select,
    .ac-scenario-builder-fieldset input,
    .ac-scenario-builder-fieldset textarea {
        width: 100%;
        border: 1px solid var(--ac-border-strong);
        border-radius: 14px;
        background: var(--ac-input-surface);
        color: var(--ac-text);
        padding: 0.7rem 0.8rem;
    }

    .ac-scenario-builder-triggers {
        display: grid;
        gap: 0.7rem;
    }

    .ac-scenario-builder-trigger-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.55rem;
        padding: 0.65rem;
    }

    .ac-scenario-builder-trigger-row button {
        border: 1px solid color-mix(in srgb, var(--ac-danger) 44%, var(--ac-border));
        border-radius: 12px;
        background: var(--ac-danger-soft);
        color: color-mix(in srgb, var(--ac-danger) 78%, var(--ac-text));
        font-weight: 750;
        padding: 0 0.65rem;
    }

    .ac-scenario-builder-json-toggle label {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        color: var(--ac-text-muted);
        font-size: 0.86rem;
        font-weight: 700;
    }

    .ac-scenario-builder-empty-state {
        display: grid;
        gap: 0.25rem;
        padding: 1rem;
    }

    @media (max-width: 1180px) {
        .ac-scenario-builder-shell {
            grid-template-columns: 1fr;
        }

        .ac-scenario-builder-palette {
            border: 0;
            border-top: 1px solid var(--ac-border);
        }

        .ac-scenario-builder-canvas {
            min-height: 30rem;
        }

        .ac-scenario-builder-canvas__surface {
            min-width: 48rem;
            min-height: 32rem;
        }

        .ac-scenario-builder-meta-grid {
            grid-template-columns: 1fr;
        }
    }

    :is(.ac-user-form-toggle, .ac-channel-form-toggle, .ac-tag-form-toggle, .ac-auto-reply-form-toggle) {
        border: 1px solid color-mix(in srgb, var(--ac-border) 90%, transparent);
        border-radius: 18px;
        padding: 0.95rem 1rem;
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-muted) 96%, white 4%) 0%, var(--ac-surface-strong) 100%);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
    }

    :is(.ac-user-form-toggle, .ac-channel-form-toggle, .ac-tag-form-toggle, .ac-auto-reply-form-toggle) .fi-fo-field-label {
        font-size: 0.98rem;
    }

    :is(.ac-user-form-toggle, .ac-channel-form-toggle, .ac-tag-form-toggle, .ac-auto-reply-form-toggle) .fi-fo-field-content-col {
        gap: 0.6rem;
    }

    :is(.ac-user-form-toggle, .ac-channel-form-toggle, .ac-tag-form-toggle, .ac-auto-reply-form-toggle) .fi-toggle {
        margin-top: 0.15rem;
        flex-shrink: 0;
    }

    :is(.ac-user-form-toggle, .ac-channel-form-toggle, .ac-tag-form-toggle, .ac-auto-reply-form-toggle) .fi-fo-field-content-col > p,
    :is(.ac-user-form-toggle, .ac-channel-form-toggle, .ac-tag-form-toggle, .ac-auto-reply-form-toggle) .fi-fo-field-content-col > div {
        color: var(--ac-text-soft);
        font-size: 0.88rem;
        line-height: 1.5;
    }

    .ac-auto-reply-status-inline .fi-fo-checkbox {
        display: inline-flex;
        align-items: center;
        gap: 0.85rem;
        flex-direction: row-reverse;
        justify-content: flex-end;
    }

    .ac-auto-reply-status-inline .fi-fo-field-label {
        font-size: 1rem;
        font-weight: 700;
        color: var(--ac-text);
    }

    .ac-auto-reply-form-section--minimal,
    .ac-auto-reply-form-section--reply {
        border: none;
        background: transparent;
        box-shadow: none;
    }

    .ac-auto-reply-form-section--minimal .fi-section-header,
    .ac-auto-reply-form-section--reply .fi-section-header {
        padding: 0 0 1rem;
        background: transparent;
    }

    .ac-auto-reply-form-section--minimal .fi-section-content-ctn,
    .ac-auto-reply-form-section--reply .fi-section-content-ctn {
        border: none;
        background: transparent;
    }

    .ac-auto-reply-form-section--minimal .fi-section-content,
    .ac-auto-reply-form-section--reply .fi-section-content {
        padding: 0;
        background: transparent;
    }

    .ac-auto-reply-form-section--minimal .fi-fo-field {
        display: grid;
        grid-template-columns: minmax(12rem, 18rem) minmax(0, 1fr);
        align-items: start;
        column-gap: 1.2rem;
    }

    .ac-auto-reply-form-section--minimal .fi-fo-field-label {
        padding-top: 0.45rem;
        font-size: 1rem;
        font-weight: 700;
        color: var(--ac-text);
    }

    .ac-auto-reply-form-section--minimal .fi-fo-field-content-col {
        gap: 0.45rem;
    }

    .ac-auto-reply-form-section--minimal .fi-fo-field-content-col > p,
    .ac-auto-reply-form-section--minimal .fi-fo-field-content-col > div {
        font-size: 0.86rem;
        line-height: 1.45;
        color: var(--ac-text-soft);
    }

    .ac-auto-reply-form-section--reply .fi-fo-field-label {
        font-size: 1rem;
        font-weight: 700;
        color: var(--ac-text);
    }

    .ac-auto-reply-form-section {
        border: 0;
        border-radius: 0;
        overflow: visible;
        box-shadow: none;
        background: transparent;
    }

    .ac-auto-reply-form-section .fi-section-header {
        overflow: visible;
        border-bottom: 0;
        background: transparent;
        padding: 0 0 0.9rem;
    }

    .ac-auto-reply-form-section .fi-section-content-ctn,
    .ac-auto-reply-form-section .fi-section-content {
        background: transparent;
    }

    .ac-auto-reply-form-section .fi-section-heading,
    .ac-auto-reply-form-section .fi-section-header-heading {
        overflow: visible;
        margin-left: 0;
        padding-left: 0.2rem;
        border-radius: 0;
        background: transparent;
        line-height: 1.15;
    }

    .ac-auto-reply-form-modal .fi-modal-footer {
        border-top: 0;
        padding-top: 1.25rem;
    }

    .ac-auto-reply-form-modal .fi-modal-footer-actions {
        padding-top: 0.25rem;
    }

    .ac-auto-reply-sections-grid {
        column-gap: 2rem;
        row-gap: 2rem;
    }

    .ac-auto-reply-form-section--minimal .fi-fo-field {
        display: grid;
        grid-template-columns: 10rem minmax(0, 1fr);
        column-gap: 0.55rem;
        align-items: center;
    }

    .ac-auto-reply-form-section--minimal .fi-fo-field-label-col {
        min-width: 0;
        margin: 0;
    }

    .ac-auto-reply-form-section--minimal .fi-fo-field-label-ctn {
        justify-content: flex-start;
    }

    .ac-auto-reply-form-section--minimal .fi-fo-field-label {
        margin: 0;
        text-align: left;
    }

    .ac-auto-reply-form-section--minimal .fi-fo-field-content-col {
        min-width: 0;
    }

    @media (max-width: 900px) {
        .ac-auto-reply-form-section--minimal .fi-fo-field {
            grid-template-columns: 1fr;
            row-gap: 0.55rem;
        }
    }

    .ac-auto-reply-form-modal .fi-select-input .fi-dropdown-panel,
    .ac-auto-reply-form-modal .fi-select-input .fi-dropdown-list,
    .ac-auto-reply-form-modal .fi-select-input .fi-select-input-search-ctn,
    .ac-auto-reply-form-modal .fi-select-input .fi-select-input-options-ctn,
    .ac-auto-reply-form-modal .fi-select-input .fi-dropdown-header,
    .fi-ta-filters-dropdown .fi-select-input .fi-dropdown-panel,
    .fi-ta-filters-dropdown .fi-select-input .fi-dropdown-list,
    .fi-ta-filters-dropdown .fi-select-input .fi-select-input-search-ctn,
    .fi-ta-filters-dropdown .fi-select-input .fi-select-input-options-ctn,
    .fi-ta-filters-dropdown .fi-select-input .fi-dropdown-header {
        border-radius: 0 !important;
    }

    .ac-auto-reply-form-modal .fi-select-input .fi-select-input-search-ctn .fi-input-wrp,
    .ac-auto-reply-form-modal .fi-select-input .fi-select-input-option,
    .fi-ta-filters-dropdown .fi-select-input .fi-select-input-search-ctn .fi-input-wrp,
    .fi-ta-filters-dropdown .fi-select-input .fi-select-input-option {
        border-radius: 0 !important;
    }

    .ac-auto-reply-form-modal .fi-select-input .fi-dropdown-panel,
    .fi-ta-filters-dropdown .fi-select-input .fi-dropdown-panel {
        overflow: hidden !important;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-modal-footer .fi-btn.fi-color-primary,
    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-modal-footer .fi-btn.fi-color-success {
        border: 1px solid rgba(255, 255, 255, 0.14);
        background: linear-gradient(180deg, #1cae6a 0%, #18985d 100%);
        color: #ffffff;
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.08),
            0 12px 28px -22px rgba(28, 174, 106, 0.42);
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-modal-footer .fi-btn.fi-color-primary:hover,
    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-modal-footer .fi-btn.fi-color-success:hover,
    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-modal-footer .fi-btn.fi-color-primary:focus-visible,
    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-modal-footer .fi-btn.fi-color-success:focus-visible {
        border-color: rgba(255, 255, 255, 0.14);
        background: linear-gradient(180deg, #18985d 0%, #1cae6a 100%);
        color: #ffffff;
    }

    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-modal-footer .fi-btn.fi-color-primary:active,
    :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal, .ac-scenario-form-modal) .fi-modal-footer .fi-btn.fi-color-success:active {
        border-color: rgba(255, 255, 255, 0.14);
        background: linear-gradient(180deg, #13784a 0%, #18985d 100%);
        color: #ffffff;
    }

    .ac-tag-color-picker.fi-fo-toggle-buttons {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.65rem;
        width: 100%;
    }

    .ac-tag-color-picker .fi-fo-toggle-buttons-btn-ctn {
        min-width: 0;
    }

    .ac-tag-color-picker .fi-btn {
        width: 100%;
        justify-content: center;
        font-weight: 650;
        min-height: 2.85rem;
        border-radius: 0.75rem;
        transition:
            transform 140ms ease,
            box-shadow 140ms ease,
            border-color 140ms ease,
            background 140ms ease,
            color 140ms ease;
    }

    .ac-tag-color-picker .fi-fo-toggle-buttons-input + .fi-btn {
        border-color: color-mix(in srgb, var(--ac-border-strong) 76%, transparent);
        background: linear-gradient(
            180deg,
            color-mix(in srgb, var(--ac-input-surface-alt) 84%, white 16%) 0%,
            color-mix(in srgb, var(--ac-input-surface) 90%, var(--ac-surface-strong) 10%) 100%
        );
        color: color-mix(in srgb, var(--ac-text) 84%, var(--ac-text-soft) 16%);
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.76),
            0 12px 28px -22px rgba(15, 23, 42, 0.34);
    }

    .ac-tag-color-picker .fi-fo-toggle-buttons-input + .fi-btn.fi-color {
        border-color: color-mix(in srgb, var(--color-300) 62%, var(--ac-border-strong) 38%);
        background: linear-gradient(
            180deg,
            color-mix(in srgb, var(--color-50) 86%, white 14%) 0%,
            color-mix(in srgb, var(--color-100) 82%, var(--ac-input-surface) 18%) 100%
        );
        color: color-mix(in srgb, var(--color-700) 76%, var(--ac-text) 24%);
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.82),
            0 12px 28px -22px rgba(15, 23, 42, 0.28);
    }

    .ac-tag-color-picker .fi-fo-toggle-buttons-input + .fi-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 36px -26px rgba(15, 23, 42, 0.36);
    }

    .ac-tag-color-picker .fi-fo-toggle-buttons-input:checked + .fi-btn {
        border-color: rgba(51, 65, 85, 0.92);
        background: linear-gradient(
            180deg,
            #64748b 0%,
            #475569 100%
        );
        color: #ffffff;
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.12),
            0 18px 36px -26px rgba(15, 23, 42, 0.48);
    }

    .ac-tag-color-picker .fi-fo-toggle-buttons-input:checked + .fi-btn.fi-color {
        border-color: color-mix(in srgb, var(--bg) 72%, var(--ac-border-strong) 28%);
        background: linear-gradient(
            180deg,
            color-mix(in srgb, var(--bg) 84%, white 16%) 0%,
            var(--bg) 100%
        );
        color: var(--text);
    }

    .ac-tag-color-picker .fi-fo-toggle-buttons-input:checked + .fi-btn:hover {
        box-shadow: 0 20px 40px -28px rgba(15, 23, 42, 0.54);
    }

    .ac-tag-color-picker .fi-fo-toggle-buttons-input:focus-visible + .fi-btn {
        outline: none;
        box-shadow:
            0 0 0 3px color-mix(in srgb, var(--ac-warning) 22%, transparent),
            0 18px 36px -26px rgba(15, 23, 42, 0.42);
    }

    .ac-tag-form-section,
    .ac-tag-form-section .fi-section,
    .ac-tag-form-section .fi-section-content-ctn,
    .ac-tag-form-section .fi-section-content {
        width: 100%;
        max-width: none;
    }

    .ac-tag-form-modal .fi-modal-content > * {
        width: 100%;
    }

    .ac-tag-form-checkbox .fi-fo-field-wrp {
        margin-top: 0.25rem;
    }

    .ac-tag-form-checkbox .fi-fo-checkbox {
        display: inline-flex;
        align-items: center;
        gap: 0.75rem;
        flex-direction: row-reverse;
        justify-content: flex-end;
    }

    .ac-tag-form-checkbox .fi-fo-checkbox-label {
        font-size: 1rem;
        font-weight: 600;
        color: var(--ac-text);
    }

    @media (min-width: 900px) {
        .ac-tag-color-picker.fi-fo-toggle-buttons {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .ac-channel-form-modal .fi-modal-content {
        gap: 0.9rem;
    }

    .ac-channel-form-modal .ac-channel-form-section {
        overflow: hidden;
        border: 1px solid #9aa8ba;
        border-radius: 6px;
        background: #f8fafc;
        box-shadow: none;
    }

    .ac-channel-form-modal .ac-channel-form-section--profile {
        overflow: visible;
    }

    .ac-channel-form-modal .ac-channel-form-section .fi-section-header {
        border: 0;
        border-bottom: 1px solid #9aa8ba;
        background: #dbe4ef;
        padding: 0.58rem 0.72rem;
    }

    .ac-channel-form-modal .ac-channel-form-section .fi-section-heading,
    .ac-channel-form-modal .ac-channel-form-section .fi-section-header-heading {
        color: #0f172a;
        font-size: 0.92rem;
        font-weight: 800;
        letter-spacing: 0;
    }

    .ac-channel-form-modal .ac-channel-form-section .fi-section-content-ctn,
    .ac-channel-form-modal .ac-channel-form-section .fi-section-content {
        background: #f8fafc;
    }

    .ac-channel-form-modal .ac-channel-form-section .fi-section-content {
        display: block !important;
        gap: 0;
        padding: 0;
    }

    .ac-channel-form-modal .ac-channel-form-field {
        display: grid !important;
        grid-template-columns: 14rem minmax(0, 1fr);
        gap: 0;
        align-items: stretch;
        min-width: 0;
        min-height: 3rem;
        border: 0;
        border-block-end: 1px solid #9aa8ba;
        border-radius: 0;
        background: #f8fafc;
        padding: 0;
    }

    .ac-channel-form-modal .ac-channel-form-field .fi-fo-field-label-col,
    .ac-channel-form-modal .ac-channel-form-field .fi-fo-field-label-ctn {
        min-width: 0;
    }

    .ac-channel-form-modal .ac-channel-form-field .fi-fo-field-label-col {
        display: flex;
        align-items: center;
        border-inline-end: 1px solid #9aa8ba;
        background: #e3eaf3;
        padding: 0.52rem 0.66rem;
    }

    .ac-channel-form-modal .ac-channel-form-field .fi-fo-field-content-col {
        min-width: 0;
        display: flex;
        align-items: center;
        background: #f8fafc;
        padding: 0.36rem 0.48rem;
    }

    .ac-channel-form-modal .ac-channel-form-field .fi-fo-field-content-col > p,
    .ac-channel-form-modal .ac-channel-form-field .fi-fo-field-content-col > div {
        max-width: none;
    }

    .ac-channel-form-modal .ac-channel-form-field .fi-fo-field-label {
        color: #0f172a !important;
        font-size: 0.7rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        line-height: 1.2;
        text-transform: uppercase;
    }

    .ac-channel-form-modal .ac-channel-form-field .fi-fo-field-label-content,
    .ac-channel-form-modal .ac-channel-form-field .fi-fo-field-label-content span {
        color: #0f172a !important;
    }

    .ac-channel-form-modal .ac-channel-form-field .fi-fo-field-label-content .fi-fo-field-label-required-mark,
    .ac-channel-form-modal .ac-channel-form-field .fi-fo-field-label-required-mark {
        color: #dc2626 !important;
    }

    .ac-channel-form-modal .ac-channel-form-field .fi-fo-field-wrp-helper-text {
        color: #475569 !important;
    }

    .ac-channel-form-modal .ac-channel-form-field .fi-input-wrp {
        width: 100%;
        min-height: 2.05rem;
        border: 1px solid #8b9aac !important;
        border-radius: 4px;
        background: #ffffff !important;
        box-shadow: none !important;
    }

    .ac-channel-form-modal .ac-channel-form-field .fi-input-wrp:hover,
    .ac-channel-form-modal .ac-channel-form-field .fi-input-wrp:focus-within {
        border-color: #2563eb !important;
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.14) !important;
    }

    .ac-channel-form-modal .fi-input,
    .ac-channel-form-modal .fi-select-input {
        color: #0f172a;
        font-size: 0.86rem;
        line-height: 1.35;
    }

    .ac-channel-form-modal .fi-select-input .fi-select-input-btn,
    .ac-channel-form-modal .fi-select-input .fi-select-input-value-ctn,
    .ac-channel-form-modal .fi-select-input .fi-select-input-value-label,
    .ac-channel-form-modal .fi-select-input .fi-select-input-value-label > span {
        color: #0f172a !important;
        -webkit-text-fill-color: #0f172a !important;
    }

    .ac-channel-form-modal .fi-select-input .fi-select-input-placeholder {
        color: #475569 !important;
        -webkit-text-fill-color: #475569 !important;
    }

    .ac-channel-form-modal .fi-select-input .fi-dropdown-panel,
    .ac-channel-form-modal .fi-select-input .fi-dropdown-list,
    .ac-channel-form-modal .fi-select-input .fi-select-input-search-ctn,
    .ac-channel-form-modal .fi-select-input .fi-select-input-options-ctn {
        background: #0f172a !important;
        color: #e2e8f0 !important;
    }

    .ac-channel-form-modal .fi-select-input .fi-select-input-search-ctn .fi-input-wrp {
        border-color: rgba(148, 163, 184, 0.42) !important;
        background: #18181b !important;
    }

    .ac-channel-form-modal .fi-select-input .fi-select-input-search-ctn .fi-input,
    .ac-channel-form-modal .fi-select-input .fi-select-input-search-ctn .fi-input::placeholder,
    .ac-channel-form-modal .fi-select-input .fi-select-input-option,
    .ac-channel-form-modal .fi-select-input .fi-select-input-option > span,
    .ac-channel-form-modal .fi-select-input .fi-dropdown-header,
    .ac-channel-form-modal .fi-select-input .fi-select-input-message {
        color: #e2e8f0 !important;
    }

    .ac-channel-form-modal .fi-select-input .fi-select-input-option:hover,
    .ac-channel-form-modal .fi-select-input .fi-select-input-option:focus,
    .ac-channel-form-modal .fi-select-input .fi-select-input-option.fi-active,
    .ac-channel-form-modal .fi-select-input .fi-select-input-option.fi-selected {
        background: #1e3a8a !important;
        color: #ffffff !important;
    }

    .ac-channel-form-modal .fi-select-input .fi-select-input-option:hover > span,
    .ac-channel-form-modal .fi-select-input .fi-select-input-option:focus > span,
    .ac-channel-form-modal .fi-select-input .fi-select-input-option.fi-active > span,
    .ac-channel-form-modal .fi-select-input .fi-select-input-option.fi-selected > span {
        color: #ffffff !important;
    }

    .ac-channel-form-modal .fi-select-input .fi-input-wrp-actions button:first-child:not(:last-child) {
        display: none;
    }

    .ac-channel-form-modal .ac-channel-form-section .fi-section-content > .ac-channel-form-field:last-child {
        border-block-end: 0;
    }

    @media (max-width: 900px) {
        .ac-channel-form-modal .ac-channel-form-field {
            grid-template-columns: minmax(0, 1fr);
        }

        .ac-channel-form-modal .ac-channel-form-field .fi-fo-field-label-col {
            border-inline-end: 0;
            border-block-end: 1px solid #9aa8ba;
        }

        .ac-channel-form-modal .ac-channel-form-field:last-child {
            border-block-end: 0;
        }
    }

    .ac-channel-view-modal {
        border: 1px solid color-mix(in srgb, var(--ac-border-strong) 88%, white 12%);
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-strong) 96%, white 4%) 0%, var(--ac-surface) 100%);
        box-shadow: 0 40px 90px -42px rgba(15, 23, 42, 0.42);
    }

    .ac-channel-view-modal .fi-modal-header {
        border-bottom: 1px solid color-mix(in srgb, var(--ac-border) 88%, transparent);
        padding-bottom: 1rem;
    }

    .ac-channel-view-modal .fi-modal-content {
        display: grid;
        gap: 0;
        padding: 1rem;
    }

    .ac-channel-view-sheet {
        display: grid;
        gap: 1rem;
    }

    .ac-channel-view-panel {
        overflow: hidden;
        border: 1px solid var(--ac-border);
        border-radius: 12px;
        background: var(--ac-surface);
        box-shadow: var(--ac-shadow-soft);
    }

    .ac-channel-view-panel__header {
        border-bottom: 1px solid var(--ac-border);
        background: var(--ac-surface);
        color: var(--ac-text);
        font-size: 0.95rem;
        font-weight: 800;
        line-height: 1.25;
        padding: 0.85rem 1rem;
    }

    .ac-channel-view-panel__summary {
        display: flex;
        cursor: pointer;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        list-style: none;
    }

    .ac-channel-view-panel__summary::-webkit-details-marker {
        display: none;
    }

    .ac-channel-view-panel__summary-state::before {
        content: 'Развернуть';
        color: var(--ac-text-soft);
        font-size: 0.72rem;
        font-weight: 700;
    }

    .ac-channel-view-panel[open] .ac-channel-view-panel__summary-state::before {
        content: 'Свернуть';
    }

    .ac-channel-view-panel:not([open]) .ac-channel-view-panel__summary {
        border-bottom: 0;
    }

    .ac-channel-view-panel__body {
        padding: 1rem;
    }

    .ac-channel-view-modal .ac-bitrix-overview-grid {
        gap: 0.9rem;
    }

    .ac-channel-view-modal .ac-bitrix-table-shell {
        border-color: var(--ac-border-strong);
        background: var(--ac-surface-muted);
    }

    .ac-channel-view-modal .ac-bitrix-table th,
    .ac-channel-view-modal .ac-bitrix-table td {
        border-color: var(--ac-border-strong);
    }

    .ac-channel-view-feed-table {
        min-width: 760px;
    }

    .ac-channel-view-feed-table th {
        width: 15rem;
    }

    .ac-channel-view-feed-table td {
        white-space: normal;
    }

    .ac-channel-view-feed-table td > div {
        min-width: 0;
    }

    :is(.ac-user-table-badge, .ac-channel-table-badge) .fi-badge {
        font-weight: 650;
        letter-spacing: -0.01em;
        padding-inline: 0.7rem;
    }

    :is(.ac-user-table-action, .ac-channel-table-action) {
        font-weight: 600;
    }

    .ac-channel-table-operation {
        color: var(--ac-text-soft);
    }

    @media (max-width: 900px) {
        .fi-ta-header-toolbar > :last-child {
            width: 100%;
            justify-content: flex-start;
            flex-wrap: wrap;
        }

        .fi-ta-header-toolbar > :last-child > .fi-ta-search-field {
            flex: 1 1 100%;
            min-width: 100%;
        }

        :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal) .fi-modal-footer-actions {
            justify-content: stretch;
        }

        :is(.ac-user-form-modal, .ac-channel-form-modal, .ac-tag-form-modal, .ac-auto-reply-form-modal) .fi-modal-footer-actions > * {
            width: 100%;
        }
    }

    .fi-ta-content-ctn {
        margin-top: 0.95rem;
        border-top: 1px solid var(--ac-border);
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-muted) 55%, transparent) 0%, transparent 100%);
    }

    .fi-ta-table {
        background: transparent;
    }

    .fi-ta-header-cell,
    .fi-ta-actions-header-cell,
    .fi-ta-empty-header-cell {
        background: color-mix(in srgb, var(--ac-surface-muted) 88%, transparent);
    }

    .fi-ta-header-cell {
        border-bottom-color: var(--ac-border);
    }

    .fi-ta-header-cell-sort-btn,
    .fi-ta-cell,
    .fi-ta-col,
    .fi-ta-group-header-cell {
        color: var(--ac-text);
    }

    .fi-ta-cell,
    .fi-ta-group-header-cell {
        border-top-color: var(--ac-border);
    }

    .fi-ta-row,
    .fi-ta-record {
        transition: background 140ms ease;
    }

    .fi-ta-row:hover td,
    .fi-ta-record:hover {
        background: color-mix(in srgb, var(--ac-primary-soft) 34%, var(--ac-surface-strong));
    }

    .fi-resource-contacts .fi-ta-text:not(.fi-inline) {
        padding-top: 0.8rem;
        padding-bottom: 0.8rem;
    }

    .fi-resource-contacts .fi-ta-text.fi-ta-text-has-descriptions,
    .fi-resource-contacts .fi-ta-text.fi-ta-text-list-limited {
        gap: 0.2rem;
    }

    .fi-resource-contacts .fi-ta-text-item {
        line-height: 1.35;
    }

    .fi-resource-contacts .fi-ta-text-item.fi-size-sm {
        font-size: 0.92rem;
    }

    .fi-resource-contacts .fi-ta-text > .fi-ta-text-description,
    .fi-resource-contacts .fi-ta-text > .fi-ta-text-list-limited-message {
        font-size: 0.76rem;
        line-height: 1.35;
        color: color-mix(in srgb, var(--ac-text-soft) 88%, transparent);
    }

    .fi-ta-group-header,
    .fi-ta-group-heading,
    .fi-ta-group-description {
        color: var(--ac-text);
    }

    .fi-ta-empty-state {
        padding: 1.25rem;
    }

    .fi-ta-empty-state-content {
        border: 1px dashed var(--ac-border-strong);
        border-radius: 20px;
        background: var(--ac-surface-muted);
        padding: 1.75rem 1rem;
    }

    .fi-ta-empty-state-heading {
        color: var(--ac-text);
    }

    .fi-ta-empty-state-description {
        color: var(--ac-text-soft);
    }

    .fi-dropdown-list {
        border: 1px solid var(--ac-border);
        border-radius: 18px;
        background: var(--ac-surface-strong);
        box-shadow: var(--ac-shadow-lg);
    }

    .fi-ta-col-manager-dropdown .fi-dropdown-panel {
        max-height: min(70vh, 32rem);
        overflow-y: auto;
    }

    .fi-dropdown-list-item {
        border-radius: 12px;
    }

    .fi-ta-col-manager {
        gap: 0.9rem;
    }

    .fi-ta-col-manager-header {
        align-items: baseline;
        gap: 0.85rem;
    }

    .fi-ta-col-manager-heading,
    .fi-ta-col-manager-label {
        color: var(--ac-text);
    }

    .fi-ta-col-manager-item {
        min-height: 2.1rem;
    }

    .fi-ta-col-manager-actions-ctn {
        padding-top: 0.35rem;
        border-top: 1px solid var(--ac-border);
    }

    .fi-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
        flex-wrap: wrap;
        border-top: 1px solid var(--ac-border);
        padding: 1rem;
        background: color-mix(in srgb, var(--ac-surface-muted) 70%, var(--ac-surface-strong));
    }

    .fi-pagination-overview {
        font-size: 0.88rem;
        color: var(--ac-text-soft);
    }

    .fi-pagination-items {
        display: flex;
        align-items: center;
        gap: 0.45rem;
    }

    .fi-pagination-item-btn,
    .fi-pagination-previous-btn,
    .fi-pagination-next-btn {
        border-radius: 12px;
    }

    .ac-panel-stack {
        display: grid;
        gap: 1rem;
    }

    .ac-panel-stack--relaxed {
        gap: 1.25rem;
    }

    .ac-dialog-overview,
    .ac-dialog-workspace {
        display: grid;
        gap: 1rem;
        align-items: start;
    }

    .ac-dialog-overview {
        grid-template-columns: minmax(0, 1.45fr) minmax(22rem, 1fr);
    }

    .ac-dialog-overview--single {
        grid-template-columns: minmax(0, 1fr);
    }

    .ac-dialog-workspace {
        grid-template-columns: minmax(0, 1.45fr) minmax(22rem, 1fr);
    }

    .ac-surface {
        border: 1px solid var(--ac-border);
        border-radius: var(--ac-radius-xl);
        background: linear-gradient(180deg, var(--ac-surface-strong) 0%, var(--ac-surface) 100%);
        box-shadow: var(--ac-shadow-sm);
        padding: 1rem;
        color: var(--ac-text);
    }

    .ac-surface--emphasis {
        background: linear-gradient(180deg, var(--ac-surface-emphasis) 0%, var(--ac-surface-strong) 100%);
    }

    .ac-surface--hero {
        border-color: color-mix(in srgb, var(--ac-primary) 24%, var(--ac-border));
        background:
            radial-gradient(circle at top right, color-mix(in srgb, var(--ac-primary-soft) 72%, transparent) 0%, transparent 42%),
            linear-gradient(180deg, var(--ac-surface-strong) 0%, color-mix(in srgb, var(--ac-primary-soft) 32%, var(--ac-surface)) 100%);
    }

    .ac-surface--secondary {
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-muted) 92%, var(--ac-surface-strong)) 0%, var(--ac-surface) 100%);
    }

    .ac-surface__header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.85rem;
        flex-wrap: wrap;
    }

    .ac-surface__header--centered {
        align-items: center;
    }

    .ac-surface__title-group {
        display: grid;
        gap: 0.3rem;
    }

    .ac-dialog-header-identity {
        display: inline-flex;
        align-items: center;
        gap: 0.9rem;
        min-width: 0;
    }

    .ac-dialog-avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 3.25rem;
        height: 3.25rem;
        border-radius: 9999px;
        overflow: hidden;
        flex-shrink: 0;
        border: 1px solid var(--ac-border);
        background: linear-gradient(180deg, var(--ac-primary-soft) 0%, var(--ac-surface-strong) 100%);
        color: var(--ac-primary);
        box-shadow: var(--ac-shadow-sm);
    }

    .ac-dialog-avatar__image {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .ac-dialog-avatar__fallback {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
        font-size: 0.95rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .ac-dialog-summary {
        display: grid;
        gap: 0;
    }

    .ac-dialog-summary__top {
        display: flex;
        min-width: 0;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .ac-dialog-summary__identity {
        display: flex;
        min-width: min(100%, 28rem);
        align-items: center;
        gap: 0.9rem;
    }

    .ac-dialog-summary__title-row {
        display: flex;
        min-width: 0;
        align-items: center;
        gap: 0.55rem;
        flex-wrap: wrap;
    }

    .ac-dialog-summary__actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .ac-dialog-summary__stage {
        margin-top: 0.9rem;
    }

    .ac-dialog-summary__sections {
        display: grid;
        grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.35fr);
        gap: 1rem;
        align-items: start;
        margin-top: 0.95rem;
    }

    .ac-dialog-summary__section {
        min-width: 0;
    }

    .ac-dialog-summary__section-title {
        margin: 0 0 0.65rem;
        color: var(--ac-text-soft);
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        line-height: 1.2;
        text-transform: uppercase;
    }

    .ac-dialog-stage-strip {
        --stage-active-top: #74e1ec;
        --stage-active-bottom: #50cad8;
        --stage-active-outline: #1596a8;
        --stage-active-text: #0f2a37;
        --stage-active-shadow: rgba(21, 150, 168, 0.18);
        display: grid;
        min-width: 0;
        gap: 0.4rem;
    }

    .ac-dialog-stage-strip[data-current-tone="gray"] {
        --stage-active-top: #d6dee8;
        --stage-active-bottom: #bdc8d7;
        --stage-active-outline: #8391a4;
        --stage-active-text: #1f2d40;
        --stage-active-shadow: rgba(82, 96, 114, 0.16);
    }

    .ac-dialog-stage-strip[data-current-tone="info"] {
        --stage-active-top: #75d8ff;
        --stage-active-bottom: #42bdea;
        --stage-active-outline: #1896c4;
        --stage-active-text: #0d2d3f;
        --stage-active-shadow: rgba(24, 150, 196, 0.18);
    }

    .ac-dialog-stage-strip[data-current-tone="success"] {
        --stage-active-top: #7fdc9d;
        --stage-active-bottom: #4fc477;
        --stage-active-outline: #259852;
        --stage-active-text: #0d321d;
        --stage-active-shadow: rgba(37, 152, 82, 0.18);
    }

    .ac-dialog-stage-strip[data-current-tone="warning"] {
        --stage-active-top: #ffe993;
        --stage-active-bottom: #ffd24f;
        --stage-active-outline: #c99316;
        --stage-active-text: #34250b;
        --stage-active-shadow: rgba(201, 147, 22, 0.18);
    }

    .ac-dialog-stage-strip[data-current-tone="primary"] {
        --stage-active-top: #8fc7ff;
        --stage-active-bottom: #5fa7ef;
        --stage-active-outline: #2f7fc9;
        --stage-active-text: #102b4a;
        --stage-active-shadow: rgba(47, 127, 201, 0.18);
    }

    .ac-dialog-stage-strip__track {
        display: flex;
        min-width: 0;
        overflow-x: auto;
        gap: 0.9rem;
        padding-right: 0.8rem;
        padding-bottom: 0.1rem;
        scrollbar-width: thin;
    }

    .ac-dialog-stage-step {
        --stage-arrow-width: 0.78rem;
        --stage-border-width: 1px;
        --stage-fill-top: rgba(247, 249, 252, 0.96);
        --stage-fill-bottom: rgba(225, 231, 239, 0.96);
        --stage-outline: rgba(112, 127, 148, 0.3);
        --stage-shape: polygon(
            0 0,
            calc(100% - var(--stage-arrow-width)) 0,
            100% 50%,
            calc(100% - var(--stage-arrow-width)) 100%,
            0 100%
        );
        position: relative;
        display: inline-flex;
        flex: 1 0 9.75rem;
        min-width: 9.75rem;
        min-height: 2.75rem;
        align-items: center;
        justify-content: center;
        isolation: isolate;
        margin: 0;
        border: 0;
        border-radius: 0;
        padding: 0.55rem 0.95rem 0.55rem 1.1rem;
        background: transparent;
        color: #4a5870;
        font-size: 0.82rem;
        font-weight: 800;
        line-height: 1.2;
        text-align: center;
        transition:
            filter 0.16s ease,
            color 0.16s ease;
    }

    .ac-dialog-stage-step::before {
        content: "";
        position: absolute;
        inset: 0;
        background: var(--stage-outline);
        clip-path: var(--stage-shape);
        transition: background 0.16s ease;
        z-index: 0;
    }

    .ac-dialog-stage-step::after {
        content: "";
        position: absolute;
        inset: var(--stage-border-width);
        background: linear-gradient(180deg, var(--stage-fill-top) 0%, var(--stage-fill-bottom) 100%);
        clip-path: var(--stage-shape);
        transition: background 0.16s ease, inset 0.16s ease;
        z-index: 1;
    }

    .ac-dialog-stage-step__label {
        display: block;
        min-width: 0;
        position: relative;
        z-index: 2;
        overflow-wrap: anywhere;
    }

    .ac-dialog-stage-step[data-state="completed"] {
        --stage-fill-top: var(--stage-active-top);
        --stage-fill-bottom: var(--stage-active-bottom);
        --stage-outline: var(--stage-active-outline);
        color: var(--stage-active-text);
    }

    .ac-dialog-stage-step[data-state="completed"]:not(:disabled) {
        cursor: pointer;
    }

    .ac-dialog-stage-step[data-state="current"] {
        --stage-fill-top: var(--stage-active-top);
        --stage-fill-bottom: var(--stage-active-bottom);
        --stage-outline: var(--stage-active-outline);
        --stage-border-width: 2px;
        color: var(--stage-active-text);
        filter: drop-shadow(0 0.75rem 1.1rem var(--stage-active-shadow));
        z-index: 1;
    }

    .ac-dialog-stage-step[data-state="available"] {
        --stage-fill-top: rgba(244, 247, 251, 0.96);
        --stage-fill-bottom: rgba(220, 227, 236, 0.96);
        --stage-outline: rgba(122, 136, 155, 0.46);
        color: #526072;
        cursor: pointer;
    }

    .ac-dialog-stage-step[data-state="available"]:hover,
    .ac-dialog-stage-step[data-state="available"]:focus-visible {
        --stage-fill-top: #f6fcfd;
        --stage-fill-bottom: #e7f7fa;
        --stage-outline: #3cb8c8;
        color: #20364a;
        filter: drop-shadow(0 0.7rem 1rem rgba(37, 137, 154, 0.14));
        outline: none;
        z-index: 2;
    }

    .ac-dialog-stage-step[data-state="available"]:active {
        --stage-fill-top: #fff2b6;
        --stage-fill-bottom: #ffd95a;
        --stage-outline: #c18c14;
        --stage-border-width: 2px;
        color: #17283a;
        filter: drop-shadow(0 0.55rem 0.9rem rgba(193, 140, 20, 0.16));
    }

    .ac-dialog-stage-step[data-state="locked"] {
        --stage-fill-top: rgba(243, 246, 250, 0.9);
        --stage-fill-bottom: rgba(219, 226, 235, 0.9);
        --stage-outline: rgba(122, 136, 155, 0.36);
        color: #7b8798;
    }

    .ac-dialog-stage-step:disabled {
        cursor: default;
    }

    .ac-dialog-stage-strip__hint {
        margin: 0;
        color: var(--ac-danger);
        font-size: 0.78rem;
        font-weight: 700;
        line-height: 1.35;
    }

    .ac-surface__eyebrow {
        margin: 0;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--ac-text-soft);
    }

    .ac-surface__title {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
        line-height: 1.25;
        color: var(--ac-text);
    }

    .ac-surface__title--hero {
        font-size: clamp(1.3rem, 1.05rem + 0.9vw, 1.8rem);
        line-height: 1.15;
    }

    .ac-surface__subtitle {
        margin: 0;
        font-size: 0.88rem;
        line-height: 1.5;
        color: var(--ac-text-soft);
    }

    .ac-surface__divider {
        margin-top: 0.9rem;
        padding-top: 0.85rem;
        border-top: 1px solid var(--ac-border);
    }

    .ac-meta-grid {
        display: grid;
        gap: 0.85rem;
        grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    }

    .ac-meta-grid--compact {
        grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
    }

    .ac-meta-grid--dialog-summary {
        grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr));
    }

    .ac-card-grid {
        display: grid;
        gap: 0.85rem;
        grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
    }

    .ac-card-grid--kanban-filters {
        grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    }

    .ac-bitrix-table-shell {
        overflow-x: auto;
        border: 1px solid var(--ac-border);
        border-radius: 12px;
        background: var(--ac-surface-muted);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
    }

    .ac-bitrix-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        color: var(--ac-text);
        font-size: 0.78rem;
    }

    .ac-bitrix-table--overview {
        min-width: 0;
    }

    .ac-bitrix-overview-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.9rem;
    }

    .ac-bitrix-overview-table-shell {
        min-width: 0;
        background: var(--ac-surface-strong);
    }

    .ac-bitrix-overview-grid + .ac-bitrix-queue-health,
    .ac-bitrix-queue-health + .ac-bitrix-snippet {
        margin-block-start: 1rem;
    }

    .ac-bitrix-queue-health {
        display: grid;
        min-width: 0;
        gap: 0.65rem;
        padding: 0.85rem;
        border: 1px solid var(--ac-border);
        border-radius: 12px;
        background: var(--ac-surface-strong);
    }

    .ac-bitrix-queue-health[data-tone="success"] {
        border-color: color-mix(in srgb, #22c55e 32%, var(--ac-border) 68%);
        background: color-mix(in srgb, #22c55e 8%, var(--ac-surface-strong) 92%);
    }

    .ac-bitrix-queue-health[data-tone="warning"] {
        border-color: color-mix(in srgb, #f59e0b 38%, var(--ac-border) 62%);
        background: color-mix(in srgb, #f59e0b 10%, var(--ac-surface-strong) 90%);
    }

    .ac-bitrix-queue-health[data-tone="danger"] {
        border-color: color-mix(in srgb, #ef4444 44%, var(--ac-border) 56%);
        background: color-mix(in srgb, #ef4444 10%, var(--ac-surface-strong) 90%);
    }

    .ac-bitrix-queue-health__header {
        display: flex;
        min-width: 0;
        gap: 0.75rem;
        align-items: flex-start;
        justify-content: space-between;
    }

    .ac-bitrix-queue-health__title {
        display: grid;
        min-width: 0;
        gap: 0.12rem;
        color: var(--ac-text);
        font-size: 0.9rem;
        font-weight: 850;
        line-height: 1.2;
    }

    .ac-bitrix-queue-health__title small,
    .ac-bitrix-queue-health__summary {
        color: var(--ac-muted);
        font-size: 0.74rem;
        font-weight: 650;
        line-height: 1.35;
    }

    .ac-bitrix-queue-health__details {
        display: grid;
        grid-template-columns: repeat(7, minmax(0, 1fr));
        gap: 0.45rem;
    }

    .ac-bitrix-queue-health__item {
        display: grid;
        min-width: 0;
        gap: 0.14rem;
        padding: 0.5rem;
        border: 1px solid var(--ac-border);
        border-radius: 0.5rem;
        background: var(--ac-surface-muted);
    }

    .ac-bitrix-queue-health__item strong {
        min-width: 0;
        overflow: hidden;
        color: var(--ac-muted);
        font-size: 0.64rem;
        font-weight: 850;
        letter-spacing: 0.04em;
        line-height: 1.1;
        text-overflow: ellipsis;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .ac-bitrix-queue-health__item span {
        min-width: 0;
        overflow: hidden;
        color: var(--ac-text);
        font-size: 0.76rem;
        font-weight: 800;
        line-height: 1.2;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ac-bitrix-queue-health__item[data-tone="success"] strong {
        color: #15803d;
    }

    .ac-bitrix-queue-health__item[data-tone="warning"] strong {
        color: #b45309;
    }

    .ac-bitrix-queue-health__item[data-tone="danger"] strong {
        color: #b91c1c;
    }

    .ac-bitrix-queue-health__recommendation {
        padding: 0.5rem 0.65rem;
        border: 1px solid color-mix(in srgb, #ef4444 36%, transparent);
        border-radius: 0.5rem;
        color: #991b1b;
        background: color-mix(in srgb, #fee2e2 72%, transparent);
        font-size: 0.76rem;
        font-weight: 800;
        line-height: 1.35;
    }

    .ac-bitrix-snippet {
        max-width: 100%;
        min-width: 0;
        overflow: hidden;
    }

    .ac-bitrix-snippet > summary {
        list-style: none;
    }

    .ac-bitrix-snippet > summary::-webkit-details-marker {
        display: none;
    }

    .ac-bitrix-snippet-summary,
    .ac-bitrix-snippet-code-summary {
        display: flex;
        min-width: 0;
        cursor: pointer;
        user-select: none;
        gap: 0.75rem;
        align-items: center;
        justify-content: space-between;
        font-weight: 800;
    }

    .ac-bitrix-snippet-summary-main {
        display: grid;
        min-width: 0;
        gap: 0.12rem;
    }

    .ac-bitrix-snippet-summary-main > small {
        color: color-mix(in srgb, currentColor 72%, transparent);
        font-size: 0.72rem;
        font-weight: 650;
        line-height: 1.2;
    }

    .ac-bitrix-snippet-toggle {
        flex: none;
        padding: 0.22rem 0.45rem;
        border: 1px solid color-mix(in srgb, #f59e0b 44%, transparent);
        border-radius: 0.38rem;
        color: #92400e;
        font-size: 0.68rem;
        font-weight: 850;
        letter-spacing: 0.04em;
        line-height: 1;
        text-transform: uppercase;
    }

    .ac-bitrix-snippet-body {
        margin-block-start: 0.65rem;
        color: color-mix(in srgb, currentColor 86%, transparent);
        font-size: 0.75rem;
        line-height: 1.45;
    }

    .ac-bitrix-snippet-code-details {
        margin-block-start: 0.7rem;
        padding-block-start: 0.7rem;
        border-block-start: 1px solid color-mix(in srgb, #f59e0b 28%, transparent);
    }

    .ac-bitrix-snippet-code-details > summary {
        list-style: none;
    }

    .ac-bitrix-snippet-code-details > summary::-webkit-details-marker {
        display: none;
    }

    .ac-bitrix-snippet-code {
        box-sizing: border-box;
        display: block;
        max-width: 100%;
        min-width: 0;
        overflow: auto;
        white-space: pre;
    }

    .ac-bitrix-snippet-code > code {
        display: block;
        min-width: max-content;
    }

    .ac-bitrix-profile-settings {
        display: grid;
        gap: 0.55rem;
    }

    .ac-bitrix-profile-hint {
        display: flex;
        flex-wrap: wrap;
        gap: 0.18rem 0.55rem;
        align-items: center;
        color: var(--ac-text-soft);
        font-size: 0.72rem;
        font-weight: 600;
        line-height: 1.3;
    }

    .ac-bitrix-profile-hint__label {
        color: var(--ac-text-muted);
        font-weight: 850;
    }

    .ac-bitrix-profile-grid {
        display: grid;
        grid-template-columns: minmax(15rem, 0.62fr) minmax(0, 1.38fr);
        gap: 0.9rem;
        align-items: start;
    }

    .ac-bitrix-profile-form {
        display: grid;
        gap: 0.55rem;
        min-width: 0;
        padding: 0.7rem;
        border: 1px solid var(--ac-border);
        border-radius: 12px;
        background: var(--ac-surface-strong);
    }

    .ac-bitrix-profile-section {
        display: grid;
        min-width: 0;
        gap: 0.55rem;
        padding-block-start: 0.55rem;
        border-block-start: 1px solid color-mix(in srgb, var(--ac-border) 72%, transparent);
    }

    .ac-bitrix-profile-section:first-child {
        padding-block-start: 0;
        border-block-start: 0;
    }

    .ac-bitrix-profile-section--static {
        display: grid;
    }

    .ac-bitrix-profile-section > summary {
        list-style: none;
        cursor: pointer;
    }

    .ac-bitrix-profile-section > summary::-webkit-details-marker {
        display: none;
    }

    .ac-bitrix-profile-section-title {
        display: flex;
        min-width: 0;
        gap: 0.45rem;
        align-items: baseline;
        justify-content: space-between;
        color: var(--ac-text);
        font-size: 0.72rem;
        font-weight: 850;
        line-height: 1.2;
    }

    .ac-bitrix-profile-section-title > span {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ac-bitrix-profile-section-title > small {
        color: var(--ac-text);
        flex: none;
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 1.2;
        opacity: 0.6;
    }

    .ac-bitrix-profile-section-body {
        min-width: 0;
    }

    .ac-profile-inline-action,
    .ac-profile-secondary-button {
        border: 1px solid color-mix(in srgb, #f59e0b 42%, var(--ac-border) 58%);
        border-radius: 0.55rem;
        background: color-mix(in srgb, #fbbf24 18%, var(--ac-surface) 82%);
        color: #92400e;
        font-size: 0.68rem;
        font-weight: 800;
        line-height: 1;
        padding: 0.42rem 0.62rem;
    }

    .ac-profile-inline-action:hover,
    .ac-profile-secondary-button:hover {
        background: color-mix(in srgb, #fbbf24 28%, var(--ac-surface) 72%);
    }

    .ac-callback-owner-list {
        display: grid;
        gap: 0.5rem;
        min-width: 0;
    }

    .ac-callback-owner-card {
        display: grid;
        gap: 0.45rem;
        min-width: 0;
        padding: 0.55rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 76%, transparent);
        border-radius: 0.7rem;
        background: color-mix(in srgb, var(--ac-surface) 76%, var(--ac-surface-strong) 24%);
    }

    .ac-callback-owner-card__header {
        display: flex;
        min-width: 0;
        flex-wrap: wrap;
        gap: 0.35rem;
        align-items: center;
        justify-content: space-between;
    }

    .ac-callback-owner-card__header > strong {
        min-width: 0;
        overflow: hidden;
        color: var(--ac-text);
        font-size: 0.8rem;
        font-weight: 850;
        line-height: 1.2;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ac-empty-state--compact {
        padding: 0.65rem 0.75rem;
        font-size: 0.78rem;
    }

    @media (max-width: 1180px) {
        .ac-bitrix-overview-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .ac-bitrix-queue-health__details {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
    }

    @media (max-width: 720px) {
        .ac-bitrix-overview-grid {
            grid-template-columns: minmax(0, 1fr);
        }

        .ac-bitrix-queue-health__header {
            align-items: stretch;
            flex-direction: column;
        }

        .ac-bitrix-queue-health__details {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 980px) {
        .ac-bitrix-profile-grid {
            grid-template-columns: minmax(0, 1fr);
        }
    }

    .ac-bitrix-table--overview th,
    .ac-bitrix-table--overview td {
        border-inline-end-color: var(--ac-border-strong);
        border-block-end-color: var(--ac-border-strong);
    }

    .ac-bitrix-table--overview th {
        width: 43%;
    }

    .ac-bitrix-table--overview td {
        background: var(--ac-surface-strong);
    }

    .ac-bitrix-table--overview tbody th {
        background: color-mix(in srgb, var(--ac-surface-muted) 78%, var(--ac-surface-strong) 22%);
    }

    .ac-bitrix-table--routes {
        min-width: 0;
    }

    .ac-bitrix-table--callbacks,
    .ac-bitrix-table--sync {
        min-width: 1100px;
    }

    .ac-bitrix-table th,
    .ac-bitrix-table td {
        border-inline-end: 1px solid var(--ac-border);
        border-block-end: 1px solid var(--ac-border);
        padding: 0.38rem 0.45rem;
        vertical-align: middle;
    }

    .ac-bitrix-table th:last-child,
    .ac-bitrix-table td:last-child {
        border-inline-end: 0;
    }

    .ac-bitrix-table tbody tr:last-child th,
    .ac-bitrix-table tbody tr:last-child td {
        border-block-end: 0;
    }

    .ac-bitrix-table thead th,
    .ac-bitrix-table tbody th {
        background: color-mix(in srgb, var(--ac-surface-strong) 88%, var(--ac-surface-muted) 12%);
        color: var(--ac-text-soft);
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        line-height: 1.2;
        text-align: left;
        text-transform: uppercase;
    }

    .ac-bitrix-table thead th {
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .ac-bitrix-table tbody tr {
        background: color-mix(in srgb, var(--ac-surface-muted) 92%, var(--ac-surface-strong) 8%);
    }

    .ac-bitrix-table tbody tr:hover {
        background: color-mix(in srgb, var(--ac-primary-soft) 22%, var(--ac-surface-muted) 78%);
    }

    .ac-bitrix-table .ac-pill {
        max-width: 100%;
        padding: 0.22rem 0.42rem;
        font-size: 0.68rem;
        line-height: 1.1;
    }

    .ac-bitrix-cell-main,
    .ac-bitrix-cell-muted,
    .ac-bitrix-cell-clip {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ac-bitrix-cell-main {
        font-weight: 750;
        color: var(--ac-text);
    }

    .ac-bitrix-cell-muted {
        margin-top: 0.1rem;
        color: var(--ac-text-soft);
        font-size: 0.72rem;
        line-height: 1.25;
    }

    .ac-bitrix-cell-clip {
        color: var(--ac-text-muted);
    }

    .ac-bitrix-table .ac-bitrix-table__control {
        min-height: 1.78rem;
        height: 1.78rem;
        border-radius: 7px;
        padding: 0.18rem 0.4rem;
        font-size: 0.74rem;
        line-height: 1.2;
    }

    .ac-bitrix-table select.ac-bitrix-table__control {
        padding-block: 0.16rem;
    }

    .ac-bitrix-action-cell {
        text-align: right;
    }

    .ac-bitrix-action-stack {
        display: inline-flex;
        flex-direction: column;
        gap: 0.35rem;
        align-items: flex-end;
        max-width: 100%;
    }

    .ac-bitrix-readonly-note {
        margin: 0;
        color: var(--ac-text-soft);
        font-size: 0.72rem;
        line-height: 1.25;
        text-align: right;
    }

    .ac-bitrix-readonly-note--route-help {
        text-align: left;
    }

    .ac-bitrix-filters {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem 0.85rem;
        align-items: center;
    }

    .ac-bitrix-filters > div {
        display: inline-flex;
        min-width: 0;
        align-items: center;
        gap: 0.4rem;
    }

    .ac-bitrix-filters .ac-field-label {
        margin-bottom: 0;
        font-size: 0.68rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        white-space: nowrap;
        color: var(--ac-text-soft);
    }

    .ac-bitrix-filters .ac-bitrix-table__control {
        width: 9.5rem;
        min-height: 1.78rem;
        height: 1.78rem;
        border-radius: 7px;
        padding: 0.18rem 0.4rem;
        font-size: 0.74rem;
        line-height: 1.2;
    }

    .ac-route-col-channel {
        width: 11rem;
    }

    .ac-route-col-config {
        width: 31rem;
    }

    .ac-route-col-diagnostics {
        width: 8.5rem;
    }

    .ac-route-channel-cell {
        display: grid;
        min-width: 0;
        gap: 0.45rem;
        align-content: center;
    }

    .ac-route-channel-controls {
        display: flex;
        min-width: 0;
        flex-wrap: wrap;
        gap: 0.35rem 0.45rem;
        align-items: center;
    }

    .ac-route-channel-actions {
        display: flex;
        min-width: 0;
        flex-wrap: wrap;
        gap: 0.35rem;
        align-items: center;
    }

    .ac-route-status-pill {
        max-width: 100%;
    }

    .ac-route-icon-button {
        width: 1.9rem;
        height: 1.9rem;
        border-radius: 0.55rem;
    }

    .ac-route-icon-button--warning {
        border-color: color-mix(in srgb, #f59e0b 44%, var(--ac-border) 56%);
        background: color-mix(in srgb, #fbbf24 22%, var(--ac-surface-strong) 78%);
        color: #92400e;
    }

    .ac-route-icon-button:disabled {
        cursor: not-allowed;
        opacity: 0.48;
    }

    .ac-route-icon-button:disabled:hover {
        transform: none;
        border-color: color-mix(in srgb, var(--ac-border) 88%, transparent);
        box-shadow: none;
    }

    .ac-route-action-note {
        margin: 0;
        max-width: 100%;
        overflow: hidden;
        color: var(--ac-text-soft);
        font-size: 0.68rem;
        font-weight: 650;
        line-height: 1.2;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ac-route-config-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.45rem;
    }

    .ac-route-field {
        display: grid;
        min-width: 0;
        gap: 0.18rem;
    }

    .ac-route-field--wide {
        grid-column: span 2;
    }

    .ac-route-field--action {
        align-content: end;
    }

    .ac-route-field > span,
    .ac-route-diagnostic-line > span {
        color: var(--ac-text-soft);
        font-size: 0.62rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        line-height: 1.1;
        text-transform: uppercase;
    }

    .ac-route-field-label {
        display: inline-flex;
        min-width: 0;
        gap: 0.2rem;
        align-items: center;
    }

    .ac-route-info-icon {
        display: inline-flex;
        flex: none;
        width: 0.88rem;
        height: 0.88rem;
        align-items: center;
        justify-content: center;
        color: var(--ac-text-muted);
        cursor: help;
        line-height: 1;
        opacity: 0.8;
    }

    .ac-route-info-icon:hover {
        color: var(--ac-accent);
        opacity: 1;
    }

    .ac-route-diagnostics {
        display: grid;
        min-width: 0;
        gap: 0.32rem;
    }

    .ac-route-diagnostic-line {
        display: flex;
        min-width: 0;
        flex-wrap: wrap;
        gap: 0.08rem 0.32rem;
        align-items: baseline;
    }

    .ac-route-diagnostic-line > strong {
        min-width: 2rem;
        color: var(--ac-text-muted);
        font-size: 0.7rem;
        font-weight: 700;
        line-height: 1.2;
        overflow-wrap: anywhere;
    }

    .ac-route-diagnostic-line[data-tone="success"] > strong {
        color: #15803d;
    }

    .ac-route-diagnostic-line[data-tone="warning"] > strong {
        color: #b45309;
    }

    .ac-route-diagnostic-line[data-tone="danger"] > strong {
        color: #b91c1c;
    }

    .ac-route-mini-action {
        border: 1px solid color-mix(in srgb, #ef4444 44%, var(--ac-border) 56%);
        border-radius: 0.45rem;
        background: color-mix(in srgb, #fee2e2 72%, var(--ac-surface) 28%);
        color: #991b1b;
        flex: none;
        font-size: 0.62rem;
        font-weight: 800;
        line-height: 1;
        padding: 0.25rem 0.4rem;
    }

    @media (max-width: 1180px) {
        .ac-bitrix-table--routes {
            min-width: 880px;
        }

        .ac-route-col-channel {
            width: 11rem;
        }

        .ac-route-col-config {
            width: 31rem;
        }

        .ac-route-col-diagnostics {
            width: 8.5rem;
        }
    }

    @media (max-width: 760px) {
        .ac-route-config-grid {
            grid-template-columns: minmax(0, 1fr);
        }

        .ac-route-field--wide {
            grid-column: auto;
        }
    }

    .ac-kanban-filters-shell__header {
        justify-content: flex-end;
    }

    .ac-kanban-filters-panel {
        display: grid;
        gap: 0.85rem;
    }

    .ac-kanban-board {
        display: flex;
        gap: 1rem;
        align-items: start;
        overflow-x: auto;
        padding-bottom: 0.35rem;
        scrollbar-width: thin;
    }

    .ac-kanban-column {
        --ac-kanban-column-width: min(22rem, calc(100vw - 2rem));
        flex: 0 0 var(--ac-kanban-column-width);
        width: var(--ac-kanban-column-width);
        display: flex;
        flex-direction: column;
        min-height: 20rem;
        min-width: 0;
        transition:
            opacity 150ms ease,
            box-shadow 150ms ease,
            border-color 150ms ease,
            flex-basis 180ms ease,
            width 180ms ease;
    }

    .ac-kanban-column--empty {
        --ac-kanban-column-width: 10.75rem;
    }

    .ac-kanban-column--empty:hover,
    .ac-kanban-column--empty.ac-kanban-column--drop-target {
        --ac-kanban-column-width: 13rem;
    }

    .ac-kanban-column--empty .ac-surface__title {
        font-size: 0.95rem;
        line-height: 1.35;
    }

    .ac-kanban-column--drop-target {
        border-color: color-mix(in srgb, var(--ac-warning) 48%, var(--ac-border));
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--ac-warning) 45%, transparent);
    }

    .ac-kanban-column--inactive {
        opacity: 0.7;
    }

    .ac-kanban-column__cards {
        margin-top: 1rem;
        display: grid;
        gap: 0.75rem;
        flex: 1;
    }

    .ac-kanban-column__footer {
        margin-top: 1rem;
    }

    .ac-kanban-card {
        border: 1px solid color-mix(in srgb, var(--ac-border) 82%, transparent);
        border-radius: var(--ac-radius-lg);
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-strong) 92%, var(--ac-page-bg-alt)) 0%, color-mix(in srgb, var(--ac-surface-muted) 86%, var(--ac-surface-strong)) 100%);
        padding: 1rem;
        min-width: 0;
        overflow: hidden;
        box-shadow: var(--ac-shadow-sm);
        transition: border-color 150ms ease, transform 150ms ease, box-shadow 150ms ease;
    }

    .ac-kanban-card:hover {
        border-color: color-mix(in srgb, var(--ac-warning) 26%, var(--ac-border));
        transform: translateY(-1px);
        box-shadow: var(--ac-shadow-md);
    }

    .ac-kanban-card__header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
    }

    .ac-kanban-card__title-group {
        min-width: 0;
        display: grid;
        gap: 0.3rem;
    }

    .ac-kanban-card__title {
        display: block;
        font-size: 1rem;
        font-weight: 700;
        color: var(--ac-text);
        text-decoration: none;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ac-kanban-card__title:hover {
        color: var(--ac-warning);
    }

    .ac-kanban-card__channel {
        margin: 0;
        font-size: 0.9rem;
        line-height: 1.45;
        color: var(--ac-text-soft);
        overflow-wrap: anywhere;
    }

    .ac-kanban-card__body {
        margin-top: 0.85rem;
        display: grid;
        gap: 0.75rem;
    }

    .ac-kanban-card__preview {
        margin: 0;
        font-size: 0.88rem;
        line-height: 1.5;
        color: var(--ac-text);
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .ac-kanban-card__facts {
        display: grid;
        gap: 0.55rem;
    }

    .ac-kanban-card__fact {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.6rem;
        min-width: 0;
    }

    .ac-kanban-card__fact--route {
        align-items: flex-start;
    }

    .ac-kanban-card__fact-label {
        margin: 0;
        flex-shrink: 0;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--ac-text-soft);
    }

    .ac-kanban-card__fact-value {
        margin: 0;
        min-width: 0;
        text-align: right;
        font-size: 0.84rem;
        line-height: 1.4;
        color: var(--ac-text);
        overflow-wrap: anywhere;
    }

    .ac-kanban-card__route-status {
        margin: 0;
        min-width: 0;
        text-align: right;
        font-size: 0.84rem;
        font-weight: 700;
        line-height: 1.4;
    }

    .ac-kanban-card__route-status[data-tone="success"] {
        color: var(--ac-success);
    }

    .ac-kanban-card__route-status[data-tone="warning"] {
        color: var(--ac-warning);
    }

    .ac-kanban-card__route-status[data-tone="danger"] {
        color: var(--ac-danger);
    }

    .ac-kanban-card__route-status[data-tone="info"],
    .ac-kanban-card__route-status[data-tone="primary"] {
        color: var(--ac-primary);
    }

    .ac-kanban-card__footer {
        display: flex;
        justify-content: flex-start;
        margin-top: 0.15rem;
    }

    .ac-button.ac-button--compact {
        min-height: 1.8rem;
        padding: 0.34rem 0.68rem;
        border-radius: 10px;
        font-size: 0.76rem;
        line-height: 1.1;
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.06),
            0 8px 18px -18px rgba(15, 23, 42, 0.5);
    }

    .ac-kanban-empty-column {
        display: flex;
        flex: 1;
        align-items: center;
        justify-content: center;
        min-height: 9rem;
        border-radius: var(--ac-radius-lg);
        border: 1px dashed color-mix(in srgb, var(--ac-border) 88%, transparent);
        background: color-mix(in srgb, var(--ac-surface-muted) 82%, transparent);
        padding: 1rem;
        font-size: 0.9rem;
        line-height: 1.6;
        text-align: center;
        color: var(--ac-text-soft);
        overflow-wrap: anywhere;
    }

    .ac-kanban-column--empty .ac-kanban-empty-column {
        min-height: 7.5rem;
        padding: 0.85rem;
        font-size: 0.82rem;
        line-height: 1.45;
        text-wrap: balance;
    }

    .ac-meta {
        min-width: 0;
    }

    .ac-meta__label {
        margin: 0 0 0.25rem;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--ac-text-soft);
    }

    .ac-meta__label-row {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        margin-bottom: 0.25rem;
    }

    .ac-meta__label-row .ac-meta__label {
        margin-bottom: 0;
    }

    .ac-inline-help {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        border: 0;
        background: transparent;
        color: var(--ac-text-soft);
        cursor: help;
        line-height: 1;
    }

    .ac-inline-help:hover,
    .ac-inline-help:focus-visible {
        color: var(--ac-text);
    }

    .ac-inline-help:focus-visible {
        outline: 2px solid rgba(15, 23, 42, 0.18);
        outline-offset: 2px;
        border-radius: 999px;
    }

    [x-cloak] {
        display: none !important;
    }

    .ac-inline-popover {
        margin-top: 0.6rem;
        max-width: 22rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 84%, transparent);
        border-radius: 0.9rem;
        background: color-mix(in srgb, var(--ac-surface-muted) 88%, var(--ac-surface-strong));
        padding: 0.7rem 0.8rem;
        font-size: 0.78rem;
        line-height: 1.5;
        color: var(--ac-text-soft);
        box-shadow: 0 18px 32px -28px rgba(15, 23, 42, 0.45);
    }
    .ac-meta__value {
        margin: 0;
        font-size: 0.95rem;
        line-height: 1.45;
        color: var(--ac-text);
    }

    .ac-meta__value--emphasis {
        font-weight: 700;
    }

    .ac-meta__value--muted {
        color: var(--ac-text-muted);
    }

    .ac-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        min-height: 2rem;
        border: 1px solid var(--ac-border);
        border-radius: 999px;
        background: var(--ac-neutral-soft);
        padding: 0.3rem 0.75rem;
        font-size: 0.78rem;
        font-weight: 700;
        line-height: 1;
        color: var(--ac-text-muted);
    }

    .ac-pill[data-tone="success"] {
        border-color: color-mix(in srgb, var(--ac-success) 35%, transparent);
        background: var(--ac-success-soft);
        color: var(--ac-success);
    }

    .ac-pill[data-tone="warning"] {
        border-color: color-mix(in srgb, var(--ac-warning) 35%, transparent);
        background: var(--ac-warning-soft);
        color: var(--ac-warning);
    }

    .ac-pill[data-tone="danger"] {
        border-color: color-mix(in srgb, var(--ac-danger) 35%, transparent);
        background: var(--ac-danger-soft);
        color: var(--ac-danger);
    }

    .ac-pill[data-tone="info"],
    .ac-pill[data-tone="primary"] {
        border-color: color-mix(in srgb, var(--ac-primary) 35%, transparent);
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
    }

    .ac-button-group {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .ac-button-group--end {
        justify-content: flex-end;
    }

    .ac-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        min-height: 2.75rem;
        border: 1px solid rgba(51, 65, 85, 0.92);
        border-radius: 14px;
        background: linear-gradient(
            180deg,
            #475569 0%,
            #334155 100%
        );
        padding: 0.7rem 1rem;
        font-size: 0.875rem;
        font-weight: 700;
        line-height: 1.2;
        color: #ffffff;
        text-decoration: none;
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.08),
            0 10px 24px -22px rgba(15, 23, 42, 0.55);
        cursor: pointer;
        transition: transform 140ms ease, box-shadow 140ms ease, border-color 140ms ease, background 140ms ease;
    }

    .ac-button:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 34px -26px rgba(15, 23, 42, 0.6);
    }

    .ac-button:not(.ac-button--primary):not(.ac-button--warning):not(.ac-button--warning-soft):not(.ac-button--primary-soft):not(.ac-button--success):not(.ac-button--danger):not(.ac-button--danger-soft):hover {
        border-color: rgba(30, 41, 59, 0.96);
        background: linear-gradient(
            180deg,
            #334155 0%,
            #1e293b 100%
        );
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.1),
            0 18px 34px -26px rgba(15, 23, 42, 0.6);
    }

    .ac-button:disabled,
    .ac-button[disabled] {
        transform: none;
        box-shadow: none;
        cursor: not-allowed;
        opacity: 0.65;
    }

    .ac-button--secondary {
        background: linear-gradient(
            180deg,
            #475569 0%,
            #334155 100%
        );
        color: #ffffff;
    }

    .ac-button--primary {
        border-color: color-mix(in srgb, var(--ac-primary) 45%, transparent);
        background: var(--ac-primary);
        color: #111111;
    }

    .ac-button--warning {
        border-color: color-mix(in srgb, #eab308 58%, transparent);
        background: linear-gradient(
            180deg,
            #fde047 0%,
            #facc15 56%,
            #eab308 100%
        );
        color: #111111;
    }

    .ac-button--warning:hover {
        border-color: color-mix(in srgb, #ca8a04 62%, transparent);
        background: linear-gradient(
            180deg,
            #facc15 0%,
            #eab308 100%
        );
    }

    .ac-button--warning-soft {
        border-color: color-mix(in srgb, #facc15 44%, transparent);
        background: linear-gradient(
            180deg,
            color-mix(in srgb, #fde68a 82%, #ffffff 18%) 0%,
            color-mix(in srgb, #facc15 88%, #ca8a04 12%) 100%
        );
        color: #111111;
    }

    .ac-button--accent {
        border-color: color-mix(in srgb, #f59e0b 52%, transparent);
        background: linear-gradient(
            180deg,
            #f59e0b 0%,
            #ea580c 100%
        );
        color: #111111;
    }

    .ac-button--primary-soft {
        border-color: color-mix(in srgb, var(--ac-primary) 46%, transparent);
        background: linear-gradient(
            180deg,
            color-mix(in srgb, var(--ac-primary) 84%, #ffffff 16%) 0%,
            color-mix(in srgb, var(--ac-primary) 90%, #1e3a8a 10%) 100%
        );
        color: #111111;
    }

    .ac-button--success {
        border-color: color-mix(in srgb, var(--ac-success) 45%, transparent);
        background: var(--ac-success);
        color: #ffffff;
    }

    .ac-button--danger {
        border-color: color-mix(in srgb, var(--ac-danger) 45%, transparent);
        background: var(--ac-danger);
        color: #ffffff;
    }

    .ac-button--danger-soft {
        border-color: color-mix(in srgb, var(--ac-danger) 30%, transparent);
        background: color-mix(in srgb, var(--ac-danger) 82%, #7f1d1d 18%);
        color: #ffffff;
    }

    .ac-button--full {
        width: 100%;
    }

    .ac-note {
        margin: 0;
        font-size: 0.84rem;
        line-height: 1.5;
        color: var(--ac-text-muted);
    }

    .ac-note--warning {
        color: var(--ac-warning);
    }

    .ac-note--danger {
        color: var(--ac-danger);
    }

    .ac-empty-state {
        border: 1px dashed var(--ac-border-strong);
        border-radius: var(--ac-radius-md);
        background: var(--ac-surface-muted);
        padding: 1.3rem 1rem;
        text-align: center;
        font-size: 0.9rem;
        line-height: 1.5;
        color: var(--ac-text-soft);
    }

    .ac-empty-state[data-tone="success"] {
        border-color: color-mix(in srgb, var(--ac-success) 42%, var(--ac-border));
        background: color-mix(in srgb, var(--ac-success-soft) 72%, var(--ac-surface-muted));
        color: color-mix(in srgb, var(--ac-success) 72%, var(--ac-text));
    }

    .ac-list-stack {
        display: grid;
        gap: 0.75rem;
    }

    .ac-list-card {
        border: 1px solid var(--ac-border);
        border-radius: 18px;
        background: var(--ac-surface-muted);
        padding: 0.9rem 1rem;
        color: var(--ac-text);
        transition: transform 140ms ease, box-shadow 140ms ease, border-color 140ms ease, background 140ms ease;
    }

    .ac-list-card--warning {
        border-color: color-mix(in srgb, var(--ac-warning) 30%, transparent);
        background: var(--ac-warning-soft);
    }

    .ac-list-card--soft {
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-muted) 92%, var(--ac-surface-strong)) 0%, var(--ac-surface-strong) 100%);
    }

    .ac-list-card--interactive:hover {
        transform: translateY(-1px);
        border-color: color-mix(in srgb, var(--ac-primary) 24%, var(--ac-border));
        box-shadow: 0 18px 36px -28px rgba(15, 23, 42, 0.45);
    }

    .ac-list-card__title {
        margin: 0;
        font-size: 0.98rem;
        font-weight: 700;
        line-height: 1.35;
        color: var(--ac-text);
    }

    .ac-list-card__body {
        margin: 0.35rem 0 0;
        font-size: 0.875rem;
        line-height: 1.55;
        color: var(--ac-text-muted);
    }

    .ac-list-card__section {
        margin-top: 0.85rem;
    }

    .ac-thread {
        max-height: 36rem;
        overflow-y: auto;
        border: 1px solid var(--ac-border);
        border-radius: var(--ac-radius-lg);
        background: linear-gradient(180deg, var(--ac-surface-muted) 0%, var(--ac-surface) 100%);
        padding: 1rem;
    }

    .ac-thread__stack {
        display: grid;
        gap: 0.3rem;
    }

    .ac-thread__date {
        display: flex;
        justify-content: center;
        padding: 0.4rem 0 0.85rem;
    }

    .ac-thread__date-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 1.9rem;
        border: 1px solid var(--ac-border);
        border-radius: 999px;
        background: var(--ac-surface-strong);
        padding: 0.25rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--ac-text-soft);
    }

    .ac-message {
        display: flex;
        width: 100%;
        margin-bottom: 0.55rem;
    }

    .ac-message--outbound {
        justify-content: flex-end;
    }

    .ac-message--inbound {
        justify-content: flex-start;
    }

    .ac-message--system {
        justify-content: center;
    }

    .ac-message__bubble {
        display: inline-flex;
        flex-direction: column;
        align-items: flex-start;
        width: auto;
        max-width: min(36rem, 72%);
        border: 1px solid var(--ac-border);
        border-radius: 18px;
        background: var(--ac-surface-strong);
        padding: 0.5rem 0.7rem;
        box-shadow: 0 10px 24px -22px rgba(15, 23, 42, 0.5);
    }

    .ac-message--outbound .ac-message__bubble {
        border-top-right-radius: 6px;
        border-color: color-mix(in srgb, var(--ac-success) 24%, var(--ac-border));
        background: color-mix(in srgb, var(--ac-success-soft) 44%, var(--ac-surface-strong));
    }

    .ac-message--inbound .ac-message__bubble {
        border-top-left-radius: 6px;
    }

    .ac-message--system .ac-message__bubble {
        align-items: flex-start;
        max-width: min(36rem, 90%);
        border-radius: 14px;
        border-color: color-mix(in srgb, var(--ac-text-soft) 18%, var(--ac-border));
        background: color-mix(in srgb, var(--ac-surface-muted) 74%, var(--ac-surface-strong));
        padding: 0.45rem 0.65rem;
        box-shadow: 0 8px 20px -22px rgba(15, 23, 42, 0.36);
    }

    .ac-message__meta {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.55rem;
        flex-wrap: wrap;
        margin-bottom: 0.45rem;
    }

    .ac-message__meta-main {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        flex-wrap: wrap;
    }

    .ac-message__meta > .ac-message__meta-main > .ac-pill {
        min-height: auto;
        border: 0;
        border-radius: 0;
        background: transparent;
        padding: 0;
        font-size: 0.74rem;
        font-weight: 800;
        letter-spacing: 0;
        line-height: 1.15;
        box-shadow: none;
    }

    .ac-message__meta > .ac-message__meta-main > .ac-pill[data-tone="info"] {
        color: var(--ac-primary);
    }

    .ac-message__meta > .ac-message__meta-main > .ac-pill[data-tone="success"] {
        color: var(--ac-success);
    }

    .ac-message__meta > .ac-message__meta-main > .ac-pill[data-tone="primary"] {
        color: var(--ac-primary);
    }

    .ac-message__meta > .ac-message__meta-main > .ac-pill[data-tone="warning"] {
        color: var(--ac-warning);
    }

    .ac-message__meta > .ac-message__meta-main > .ac-pill[data-tone="danger"] {
        color: var(--ac-danger);
    }

    .ac-message__meta > .ac-message__meta-main > .ac-pill[data-tone="gray"] {
        color: var(--ac-text-soft);
    }

    .ac-message__meta > .ac-message__meta-main > .ac-pill:not(:first-child)::before {
        content: "·";
        margin-right: 0.35rem;
        color: color-mix(in srgb, var(--ac-text-soft) 58%, transparent);
    }

    .ac-message--system .ac-message__meta {
        width: 100%;
        justify-content: space-between;
        gap: 0.45rem;
        margin-bottom: 0.24rem;
    }

    .ac-message--system .ac-message__meta-main {
        justify-content: flex-start;
        gap: 0.32rem;
    }

    .ac-message--system .ac-pill {
        color: var(--ac-text-soft);
    }

    .ac-message__text {
        white-space: pre-wrap;
        word-break: break-word;
        font-size: 0.95rem;
        line-height: 1.45;
        color: var(--ac-text);
        text-align: left;
    }

    .ac-message--system .ac-message__text {
        color: #374151;
        font-size: 0.9rem;
        line-height: 1.36;
        text-align: left;
    }

    .ac-message__text--html a {
        color: var(--ac-primary);
        text-decoration: underline;
        text-underline-offset: 2px;
    }

    .ac-message__text--html code {
        border-radius: 8px;
        background: color-mix(in srgb, var(--ac-surface-strong) 72%, #0f172a 28%);
        padding: 0.08rem 0.32rem;
        font-size: 0.92em;
    }

    .ac-message__text--html pre {
        margin: 0.55rem 0 0;
        overflow-x: auto;
        border-radius: 12px;
        background: color-mix(in srgb, var(--ac-surface-strong) 78%, #0f172a 22%);
        padding: 0.75rem 0.85rem;
        white-space: pre-wrap;
    }

    .ac-message__timestamp {
        flex: 0 0 auto;
        font-size: 0.72rem;
        line-height: 1.2;
        color: var(--ac-text-soft);
    }

    .ac-preview-card {
        border: 1px solid var(--ac-border);
        border-radius: 16px;
        background: linear-gradient(180deg, var(--ac-surface-muted) 0%, var(--ac-surface-strong) 100%);
        padding: 0.8rem 0.9rem;
    }

    .ac-preview-card__body {
        margin: 0.6rem 0 0;
        color: var(--ac-text);
        font-size: 0.92rem;
        line-height: 1.5;
        display: -webkit-box;
        overflow: hidden;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }

    .ac-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 75;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        background: rgba(15, 23, 42, 0.42);
        backdrop-filter: blur(8px);
    }

    .ac-modal-backdrop--drawer {
        justify-content: flex-start;
        align-items: stretch;
        padding: 0;
    }

    .ac-modal {
        width: min(100%, 38rem);
        border: 1px solid var(--ac-border);
        border-radius: 26px;
        background: linear-gradient(180deg, var(--ac-surface-strong) 0%, var(--ac-surface) 100%);
        box-shadow: var(--ac-shadow-lg);
        color: var(--ac-text);
        overflow: hidden;
    }

    .ac-modal--sm {
        width: min(100%, 30rem);
    }

    .ac-modal--md {
        width: min(100%, 32rem);
    }

    .ac-modal--drawer {
        width: min(100%, 28rem);
        height: 100%;
        border-radius: 0 28px 28px 0;
        border-left: 0;
    }

    .ac-modal__body {
        padding: 1.25rem;
    }

    .ac-modal__header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .ac-modal__title {
        margin: 0 0 0.35rem;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.35;
        color: var(--ac-text);
    }

    .ac-modal__description {
        margin: 0;
        font-size: 0.875rem;
        line-height: 1.55;
        color: var(--ac-text-muted);
    }

    .ac-modal__close {
        border: 0;
        background: transparent;
        color: var(--ac-text-soft);
        font-size: 0.95rem;
        line-height: 1;
        cursor: pointer;
    }

    .ac-form-grid {
        display: grid;
        gap: 0.9rem;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .ac-form-grid--single {
        grid-template-columns: minmax(0, 1fr);
    }

    .ac-form-field--full {
        grid-column: 1 / -1;
    }

    .ac-field-label {
        display: block;
        margin-bottom: 0.45rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--ac-text);
    }

    .ac-input,
    .ac-select,
    .ac-textarea {
        display: block;
        width: 100%;
        box-sizing: border-box;
        border: 1px solid var(--ac-border-strong);
        border-radius: 16px;
        background: var(--ac-surface-strong);
        color: var(--ac-text);
        padding: 0.85rem 0.95rem;
        font-size: 0.95rem;
        line-height: 1.45;
        box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.05);
    }

    .ac-input:disabled,
    .ac-select:disabled,
    .ac-textarea:disabled {
        background: var(--ac-surface-muted);
        cursor: not-allowed;
    }

    .ac-textarea {
        min-width: 100%;
        resize: vertical;
    }

    .ac-field-help {
        margin: 0.45rem 0 0;
        font-size: 0.75rem;
        line-height: 1.5;
        color: var(--ac-text-soft);
    }

    .ac-field-error {
        margin: 0.45rem 0 0;
        font-size: 0.75rem;
        line-height: 1.45;
        color: var(--ac-danger);
    }

    .ac-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-top: 1rem;
    }

    .ac-actions--between {
        align-items: center;
        justify-content: space-between;
    }

    .ac-actions__hint {
        flex: 1 1 16rem;
    }

    .ac-inline-split {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .ac-copy {
        margin: 0;
        font-size: 0.95rem;
        line-height: 1.55;
        color: var(--ac-text-muted);
    }

    .ac-copy strong {
        color: var(--ac-text);
    }

    .ac-copy--spaced {
        margin-bottom: 1rem;
    }

    .ac-link-reset {
        color: inherit;
        text-decoration: none;
    }

    .ac-meta--wide {
        min-width: 18rem;
        flex: 1 1 24rem;
    }

    .ac-note--offset {
        margin-top: 0.85rem;
    }

    .ac-note-stack {
        display: grid;
        gap: 0.75rem;
    }

    .ac-note-box {
        border: 1px solid var(--ac-border);
        border-radius: var(--ac-radius-md);
        background: linear-gradient(180deg, var(--ac-surface-muted) 0%, var(--ac-surface-strong) 100%);
        padding: 0.85rem 0.95rem;
    }

    .ac-note-box--info {
        border-color: color-mix(in srgb, var(--ac-info) 28%, transparent);
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-info-soft) 82%, var(--ac-surface-strong)) 0%, var(--ac-surface-strong) 100%);
    }

    .ac-note-box--warning {
        border-color: color-mix(in srgb, var(--ac-warning) 28%, transparent);
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-warning-soft) 82%, var(--ac-surface-strong)) 0%, var(--ac-surface-strong) 100%);
    }

    .ac-note-box--danger {
        border-color: color-mix(in srgb, var(--ac-danger) 28%, transparent);
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-danger-soft) 82%, var(--ac-surface-strong)) 0%, var(--ac-surface-strong) 100%);
    }

    .ac-composer {
        position: sticky;
        top: 1rem;
    }

    .ac-textarea--composer {
        min-height: 11rem;
    }

    .ac-right-aligned {
        text-align: right;
    }

    .ac-title-with-gap {
        margin-bottom: 0.75rem;
    }

    .ac-contact-modal-section .fi-section-header {
        padding-bottom: 0.75rem;
    }

    .ac-contact-modal-section .fi-section-header-text-ctn {
        gap: 0.25rem;
    }

    .ac-contact-modal-section .fi-section-header-heading {
        font-size: 1.02rem;
        line-height: 1.25;
    }

    .ac-contact-modal-section .fi-section-header-description {
        font-size: 0.84rem;
        line-height: 1.45;
    }

    .ac-contact-modal-section .fi-section-content-ctn {
        padding-top: 0.75rem;
    }

    .ac-contact-modal-surface {
        padding: 0.85rem 0.9rem;
        border-radius: 22px;
    }

    .ac-contact-modal-surface .ac-surface__header {
        gap: 0.65rem;
    }

    .ac-contact-modal-surface .ac-surface__title-group {
        gap: 0.2rem;
    }

    .ac-contact-modal-surface .ac-surface__eyebrow {
        font-size: 0.69rem;
    }

    .ac-contact-modal-surface .ac-surface__title {
        font-size: 1rem;
    }

    .ac-contact-modal-surface .ac-surface__subtitle {
        font-size: 0.84rem;
        line-height: 1.45;
    }

    .ac-contact-modal-surface .ac-surface__divider {
        margin-top: 0.7rem;
        padding-top: 0.7rem;
    }

    .ac-contact-modal-surface .ac-card-grid,
    .ac-contact-modal-surface .ac-meta-grid {
        gap: 0.7rem;
    }

    .ac-contact-modal-surface .ac-list-card {
        border-radius: 16px;
        padding: 0.75rem 0.85rem;
    }

    .ac-contact-modal-surface .ac-list-card__title {
        font-size: 0.95rem;
    }

    .ac-contact-modal-surface .ac-list-card__section {
        margin-top: 0.65rem;
    }

    .ac-contact-modal-surface .ac-meta__label {
        margin-bottom: 0.18rem;
    }

    .ac-contact-modal-surface .ac-meta__value {
        font-size: 0.92rem;
        line-height: 1.38;
    }

    .ac-contact-modal-surface .ac-note-box {
        padding: 0.75rem 0.8rem;
    }

    .ac-contact-modal-surface--profile .ac-card-grid,
    .ac-contact-modal-surface--collector .ac-card-grid {
        grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
    }

    .ac-contact-modal-surface--dialogs .ac-list-stack {
        gap: 0.65rem;
    }

    .ac-contact-modal-dialogs__heading {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.7rem;
        flex-wrap: wrap;
    }

    .ac-contact-modal-dialogs__primary {
        gap: 0.16rem;
    }

    .ac-contact-modal-surface--dialogs [data-role="contact-dialog"] {
        gap: 0.75rem;
    }

    .ac-contact-modal-dialogs__route {
        font-size: 0.8rem;
        line-height: 1.4;
        color: var(--ac-text-muted);
    }

    .ac-contact-modal-surface--dialogs .ac-note {
        font-size: 0.8rem;
        line-height: 1.45;
    }

    .ac-contact-modal-dialogs__meta {
        gap: 0.55rem;
        grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr));
    }

    .ac-contact-modal-dialogs__meta-panel {
        padding: 0.65rem 0.75rem;
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-muted) 88%, var(--ac-surface-strong)) 0%, var(--ac-surface-strong) 100%);
    }

    .ac-contact-modal-dialogs__meta-panel .ac-meta__label {
        color: var(--ac-text-muted);
    }

    .ac-contact-modal-dialogs__meta-panel .ac-meta__value {
        font-size: 0.88rem;
    }

    .ac-contact-modal-surface--dialogs .ac-preview-card {
        padding: 0.65rem 0.75rem;
        border-color: color-mix(in srgb, var(--ac-primary) 18%, var(--ac-border));
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-primary-soft) 18%, var(--ac-surface-muted)) 0%, var(--ac-surface-strong) 100%);
    }

    .ac-contact-modal-surface--dialogs .ac-preview-card__body {
        margin-top: 0.45rem;
        font-size: 0.89rem;
        line-height: 1.45;
    }

    .ac-contact-modal-surface--dialogs .ac-inline-split {
        align-items: center;
        gap: 0.65rem;
    }

    .ac-contact-modal-dialogs__cta {
        padding-top: 0.7rem;
        border-top: 1px solid color-mix(in srgb, var(--ac-border) 92%, transparent);
    }

    .ac-contact-modal-surface--dialogs .ac-pill {
        min-height: 1.85rem;
        padding: 0.28rem 0.68rem;
    }

    .ac-contact-modal-surface--collector .ac-actions {
        margin-top: 0.75rem;
    }

    .ac-contact-modal-surface--collector .ac-actions__hint {
        font-size: 0.82rem;
    }

    .ac-contact-modal-section--secondary .fi-section-header {
        padding-bottom: 0.6rem;
    }

    .ac-contact-modal-section--secondary .fi-section-header-heading {
        font-size: 0.98rem;
        color: color-mix(in srgb, var(--ac-text) 88%, var(--ac-text-muted));
    }

    .ac-contact-modal-section--secondary .fi-section-header-description {
        font-size: 0.8rem;
        color: var(--ac-text-muted);
    }

    .ac-contact-modal-section--secondary .fi-section-content-ctn {
        padding-top: 0.55rem;
    }

    .ac-contact-modal-surface--secondary-block {
        border-color: color-mix(in srgb, var(--ac-border) 90%, transparent);
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-muted) 94%, var(--ac-surface-strong)) 0%, var(--ac-surface-strong) 100%);
        box-shadow: 0 12px 24px -26px rgba(15, 23, 42, 0.22);
        padding: 0.75rem 0.8rem;
    }

    .ac-contact-modal-surface--secondary-block .ac-surface__header {
        gap: 0.55rem;
    }

    .ac-contact-modal-surface--secondary-block .ac-surface__title-group {
        gap: 0.18rem;
    }

    .ac-contact-modal-surface--secondary-block .ac-surface__eyebrow {
        font-size: 0.66rem;
        color: var(--ac-text-muted);
    }

    .ac-contact-modal-surface--secondary-block .ac-surface__title {
        font-size: 0.96rem;
    }

    .ac-contact-modal-surface--secondary-block .ac-surface__subtitle {
        font-size: 0.82rem;
        line-height: 1.42;
    }

    .ac-contact-modal-surface--secondary-block .ac-surface__divider {
        margin-top: 0.6rem;
        padding-top: 0.6rem;
    }

    .ac-contact-modal-surface--secondary-block .ac-card-grid,
    .ac-contact-modal-surface--secondary-block .ac-list-stack,
    .ac-contact-modal-surface--secondary-block .ac-meta-grid {
        gap: 0.6rem;
    }

    .ac-contact-modal-surface--secondary-block .ac-list-card {
        border-radius: 15px;
        padding: 0.72rem 0.78rem;
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-muted) 95%, var(--ac-surface-strong)) 0%, var(--ac-surface-strong) 100%);
        box-shadow: none;
    }

    .ac-contact-modal-surface--secondary-block .ac-list-card__body {
        font-size: 0.84rem;
        line-height: 1.45;
    }

    .ac-contact-modal-surface--secondary-block .ac-list-card__section {
        margin-top: 0.5rem;
    }

    .ac-contact-modal-surface--secondary-block .ac-note-box {
        padding: 0.68rem 0.75rem;
    }

    .ac-contact-modal-surface--secondary-block .ac-note {
        font-size: 0.79rem;
        line-height: 1.45;
    }

    .ac-contact-modal-surface--secondary-block .ac-actions {
        gap: 0.6rem;
        margin-top: 0.8rem;
    }

    .ac-contact-modal-surface--secondary-block .ac-actions--between {
        align-items: flex-start;
    }

    .ac-contact-modal-surface--secondary-block .ac-actions__hint {
        flex-basis: 17rem;
        max-width: 34rem;
    }

    .ac-contact-modal-surface--secondary-block .ac-button-group {
        gap: 0.55rem;
    }

    .ac-contact-modal-surface--secondary-block .ac-button {
        min-height: 2.55rem;
        padding: 0.62rem 0.9rem;
        border-radius: 13px;
        box-shadow: none;
    }

    .ac-contact-modal-surface--ownership .ac-card-grid {
        grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
    }

    .ac-contact-modal-surface--phones .ac-empty-state {
        padding: 1rem 0.9rem;
    }

    .ac-contact-modal-surface--phones .ac-inline-split {
        align-items: center;
        gap: 0.65rem;
    }

    .ac-contact-modal-surface--phones .ac-pill {
        min-height: 1.8rem;
        padding: 0.25rem 0.62rem;
    }

    .ac-contact-modal-surface--phones .ac-actions {
        justify-content: flex-start;
    }

    .ac-contact-modal-section--details .fi-section-header {
        padding-bottom: 0.45rem;
    }

    .ac-contact-modal-section--details .fi-section-header-heading {
        font-size: 0.94rem;
        color: var(--ac-text-muted);
    }

    .ac-contact-modal-section--details .fi-section-content-ctn {
        padding-top: 0.45rem;
    }

    .ac-contact-modal-section--details .fi-section-content {
        border-radius: 18px;
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-muted) 86%, var(--ac-surface-strong)) 0%, var(--ac-surface-strong) 100%);
    }

    .ac-contact-page {
        display: grid;
        gap: 1rem;
    }

    .ac-contact-page__tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .ac-contact-page__tab {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 2.75rem;
        padding: 0.65rem 1rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 88%, transparent);
        border-radius: 999px;
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-muted) 92%, var(--ac-surface-strong)) 0%, var(--ac-surface-strong) 100%);
        color: var(--ac-text);
        font-size: 0.92rem;
        font-weight: 600;
        line-height: 1;
        text-decoration: none;
        transition: transform 160ms ease, border-color 160ms ease, box-shadow 160ms ease;
    }

    .ac-contact-page__tab:hover {
        transform: translateY(-1px);
        border-color: color-mix(in srgb, var(--ac-primary) 22%, var(--ac-border));
        box-shadow: 0 14px 28px -22px rgba(15, 23, 42, 0.28);
    }

    .ac-contact-page__tab--active {
        border-color: color-mix(in srgb, var(--ac-primary) 32%, transparent);
        background: linear-gradient(180deg, color-mix(in srgb, var(--ac-primary-soft) 74%, var(--ac-surface-strong)) 0%, var(--ac-surface-strong) 100%);
        color: color-mix(in srgb, var(--ac-primary) 84%, var(--ac-text) 16%);
        box-shadow: 0 18px 34px -24px rgba(14, 116, 144, 0.28);
    }

    .ac-contact-page__layout {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
        align-items: start;
    }

    .ac-contact-page__column,
    .ac-contact-page__stack,
    .ac-contact-page__full-width {
        display: grid;
        gap: 1rem;
        align-content: start;
    }

    .ac-contact-page__column {
        gap: 2rem;
    }

    .ac-contact-page__column > * + * {
        padding-top: 1.5rem;
        border-top: 1px solid color-mix(in srgb, var(--ac-border) 78%, transparent);
    }

    .ac-contact-form-section {
        display: grid;
        gap: 1rem;
        margin: 30px;
        padding: 0.35rem 0;
    }

    .ac-contact-form-section__header {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding-bottom: 0.25rem;
        border-bottom: 1px solid color-mix(in srgb, #f5d76e 42%, var(--ac-border));
    }

    .ac-contact-form-section__title {
        margin: 0;
        display: inline-flex;
        align-items: center;
        min-height: 2rem;
        padding: 0.2rem 0.4rem;
        background: linear-gradient(180deg, color-mix(in srgb, #fff4c2 88%, white) 0%, color-mix(in srgb, #fff7da 92%, white) 100%);
        color: var(--ac-text);
        font-size: 1.25rem;
        font-weight: 700;
        line-height: 1.15;
    }

    .ac-contact-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem 1.1rem;
        align-items: start;
    }

    .ac-contact-form-row {
        display: grid;
        gap: 0.35rem;
        min-height: 5.1rem;
    }

    .ac-contact-form-row__label {
        margin: 0;
        font-size: 0.98rem;
        font-weight: 600;
        line-height: 1.35;
        color: var(--ac-text);
    }

    .ac-contact-form-row__value-shell {
        position: relative;
        display: flex;
        align-items: stretch;
    }

    .ac-contact-form-row__value {
        margin: 0;
        min-height: 2.7rem;
        width: 100%;
        padding: 0.7rem 0.85rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 84%, transparent);
        border-radius: 0.85rem;
        background: color-mix(in srgb, var(--ac-surface-muted) 76%, white);
        color: var(--ac-text);
        font-size: 0.95rem;
        line-height: 1.45;
        word-break: break-word;
    }

    .ac-contact-form-row__value--with-action {
        padding-right: 3.4rem;
    }

    .ac-contact-form-row__key {
        margin: 0;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        font-size: 0.74rem;
        line-height: 1.35;
        color: var(--ac-text-muted);
    }

    .ac-contact-form-row__items {
        display: grid;
        gap: 0.5rem;
    }

    .ac-contact-form-item {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.65rem;
        padding: 0.6rem 0.75rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 82%, transparent);
        border-radius: 0.85rem;
        background: color-mix(in srgb, var(--ac-surface-strong) 92%, white);
    }

    .ac-contact-form-item__body {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.45rem;
        min-width: 0;
    }

    .ac-contact-form-item__value,
    .ac-contact-form-item__meta {
        font-size: 0.85rem;
        line-height: 1.35;
    }

    .ac-contact-form-item__value {
        font-weight: 600;
        color: var(--ac-text);
    }

    .ac-contact-form-item__meta {
        color: var(--ac-text-muted);
    }

    .ac-contact-form-item__actions {
        display: inline-flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem;
    }

    .ac-icon-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.15rem;
        height: 2.15rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 88%, transparent);
        border-radius: 0.7rem;
        background: var(--ac-surface-strong);
        color: var(--ac-text);
        transition: transform 160ms ease, border-color 160ms ease, box-shadow 160ms ease;
    }

    .ac-icon-button:hover {
        transform: translateY(-1px);
        border-color: color-mix(in srgb, var(--ac-primary) 28%, var(--ac-border));
        box-shadow: 0 14px 28px -24px rgba(15, 23, 42, 0.28);
    }

    .ac-icon-button--field {
        position: absolute;
        inset-inline-end: 0.45rem;
        top: 50%;
        transform: translateY(-50%);
    }

    .ac-icon-button--field:hover {
        transform: translateY(calc(-50% - 1px));
    }

    .ac-inline-action {
        border: none;
        background: transparent;
        padding: 0;
        color: color-mix(in srgb, var(--ac-primary) 82%, var(--ac-text) 18%);
        font-size: 0.82rem;
        font-weight: 600;
        line-height: 1.2;
    }

    .ac-inline-action--danger {
        color: color-mix(in srgb, var(--ac-danger) 84%, var(--ac-text) 16%);
    }

    .ac-history-timeline {
        display: grid;
        gap: 1rem;
    }

    .ac-history-timeline__item {
        display: grid;
        grid-template-columns: 1.5rem minmax(0, 1fr);
        gap: 0.9rem;
        align-items: start;
    }

    .ac-history-timeline__rail {
        position: relative;
        display: flex;
        justify-content: center;
        min-height: 100%;
    }

    .ac-history-timeline__rail::before {
        content: '';
        position: absolute;
        inset-block: 0;
        inset-inline-start: 50%;
        width: 2px;
        transform: translateX(-50%);
        background: color-mix(in srgb, var(--ac-border-strong) 82%, transparent);
    }

    .ac-history-timeline__item:last-child .ac-history-timeline__rail::before {
        bottom: 0.75rem;
    }

    .ac-history-timeline__dot {
        position: relative;
        z-index: 1;
        margin-top: 0.35rem;
        width: 0.8rem;
        height: 0.8rem;
        border: 2px solid var(--ac-surface-strong);
        border-radius: 999px;
        background: color-mix(in srgb, var(--ac-primary) 80%, white);
        box-shadow: 0 10px 24px -18px rgba(15, 23, 42, 0.4);
    }

    .ac-history-timeline__card {
        display: grid;
        gap: 0.3rem;
        padding: 0.95rem 1rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 84%, transparent);
        border-radius: 1rem;
        background: color-mix(in srgb, var(--ac-surface-strong) 94%, white);
    }

    .ac-history-timeline__timestamp,
    .ac-history-timeline__description {
        margin: 0;
    }

    .ac-history-timeline__timestamp {
        font-size: 0.8rem;
        font-weight: 600;
        line-height: 1.4;
        color: var(--ac-text-soft);
    }

    .ac-history-timeline__title {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.35;
        color: var(--ac-text);
    }

    .ac-history-timeline__meta {
        margin: 0;
        font-size: 0.86rem;
        font-weight: 600;
        line-height: 1.45;
        color: var(--ac-text);
    }

    .ac-history-timeline__description {
        font-size: 0.92rem;
        line-height: 1.55;
        color: var(--ac-text-muted);
    }

    .ac-history-timeline__comment-body {
        margin: 0;
        font-size: 0.92rem;
        line-height: 1.55;
        color: var(--ac-text);
        white-space: pre-wrap;
        word-break: break-word;
    }

    .ac-diagnostics-payload {
        display: grid;
        gap: 0.65rem;
    }

    .ac-diagnostics-payload__label {
        margin: 0;
        font-size: 0.9rem;
        font-weight: 700;
        line-height: 1.35;
        color: var(--ac-text);
    }

    .ac-diagnostics-payload__pre {
        margin: 0;
        max-height: 26rem;
        overflow: auto;
        padding: 1rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 84%, transparent);
        border-radius: 1rem;
        background: color-mix(in srgb, var(--ac-surface-muted) 82%, var(--ac-surface-strong));
        color: var(--ac-text);
        font-size: 0.8rem;
        line-height: 1.55;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .ac-diagnostics-payload {
        display: grid;
        gap: 0.65rem;
    }

    .ac-diagnostics-payload__label {
        margin: 0;
        font-size: 0.9rem;
        font-weight: 700;
        line-height: 1.35;
        color: var(--ac-text);
    }

    .ac-diagnostics-payload__pre {
        margin: 0;
        max-height: 26rem;
        overflow: auto;
        padding: 1rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 84%, transparent);
        border-radius: 1rem;
        background: color-mix(in srgb, var(--ac-surface-muted) 82%, var(--ac-surface-strong));
        color: var(--ac-text);
        font-size: 0.8rem;
        line-height: 1.55;
        white-space: pre-wrap;
        word-break: break-word;
    }

    @media (max-width: 1140px) {
        .ac-contact-page__layout,
        .ac-contact-form-grid,
        .ac-dialog-overview,
        .ac-dialog-workspace {
            grid-template-columns: minmax(0, 1fr);
        }

        .ac-dialog-summary__sections {
            grid-template-columns: minmax(0, 1fr);
        }

        .ac-composer {
            position: static;
        }
    }

    @media (max-width: 1280px) {
        .fi-main.fi-admin-content-wide {
            width: 100%;
        }
    }

    @media (max-width: 960px) {
        .ac-message__bubble {
            max-width: 100%;
        }
    }

    @media (max-width: 768px) {
        .ac-dialog-summary__identity,
        .ac-dialog-summary__actions {
            align-items: flex-start;
            justify-content: flex-start;
        }

        .ac-dialog-summary__identity {
            width: 100%;
        }

        .ac-dialog-stage-step {
            flex-basis: 8.5rem;
            min-width: 8.5rem;
            padding-inline: 0.85rem;
            font-size: 0.78rem;
        }

        .ac-form-grid {
            grid-template-columns: minmax(0, 1fr);
        }

        .ac-modal-backdrop {
            padding: 1rem;
        }

        .ac-modal,
        .ac-modal--sm,
        .ac-modal--md {
            width: 100%;
        }

        .ac-modal--drawer {
            width: min(100%, 32rem);
            border-radius: 0;
        }

        .ac-actions--between {
            align-items: stretch;
        }

        .ac-actions__hint {
            flex-basis: 100%;
        }

        .ac-right-aligned {
            text-align: left;
        }
    }

    .dark .fi-main-sidebar {
        border-inline-end-color: rgba(71, 85, 105, 0.5);
        background:
            linear-gradient(180deg, rgba(15, 23, 42, 0.98) 0%, rgba(17, 24, 39, 0.98) 100%);
        box-shadow: 16px 0 36px -30px rgba(2, 6, 23, 0.8);
    }
</style>
