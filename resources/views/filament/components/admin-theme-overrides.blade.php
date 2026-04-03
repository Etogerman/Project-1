<style>
    :root {
        --ac-page-bg: #eff5fb;
        --ac-page-bg-alt: #f8fbff;
        --ac-surface: rgba(255, 255, 255, 0.94);
        --ac-surface-strong: #ffffff;
        --ac-surface-muted: #f8fafc;
        --ac-surface-emphasis: #fff9e8;
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

    .fi-admin-content-wide {
        width: 100%;
        max-width: min(92vw, 1820px);
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
        overflow: hidden;
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

    .fi-btn:hover,
    .fi-icon-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 36px -26px rgba(15, 23, 42, 0.62);
    }

    .fi-input-wrp {
        border-color: var(--ac-border-strong);
        border-radius: 16px;
        background: var(--ac-surface-strong);
        box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .fi-input,
    .fi-select-input {
        color: var(--ac-text);
    }

    .fi-input::placeholder {
        color: var(--ac-text-soft);
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

    .fi-ta-actions {
        gap: 0.65rem;
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

    .fi-dropdown-list-item {
        border-radius: 12px;
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

    .ac-surface__header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.85rem;
        flex-wrap: wrap;
    }

    .ac-surface__title-group {
        display: grid;
        gap: 0.3rem;
    }

    .ac-surface__title {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
        line-height: 1.25;
        color: var(--ac-text);
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
        border: 1px solid var(--ac-border-strong);
        border-radius: 14px;
        background: var(--ac-surface-strong);
        padding: 0.7rem 1rem;
        font-size: 0.875rem;
        font-weight: 700;
        line-height: 1.2;
        color: var(--ac-text);
        text-decoration: none;
        box-shadow: 0 10px 24px -22px rgba(15, 23, 42, 0.55);
        cursor: pointer;
        transition: transform 140ms ease, box-shadow 140ms ease, border-color 140ms ease, background 140ms ease;
    }

    .ac-button:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 34px -26px rgba(15, 23, 42, 0.6);
    }

    .ac-button:disabled,
    .ac-button[disabled] {
        transform: none;
        box-shadow: none;
        cursor: not-allowed;
        opacity: 0.65;
    }

    .ac-button--secondary {
        background: var(--ac-surface-strong);
        color: var(--ac-text);
    }

    .ac-button--primary {
        border-color: color-mix(in srgb, var(--ac-primary) 45%, transparent);
        background: var(--ac-primary);
        color: #ffffff;
    }

    .ac-button--primary-soft {
        border-color: color-mix(in srgb, var(--ac-primary) 30%, transparent);
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
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
        background: var(--ac-surface-strong);
        color: var(--ac-danger);
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
    }

    .ac-list-card--warning {
        border-color: color-mix(in srgb, var(--ac-warning) 30%, transparent);
        background: var(--ac-warning-soft);
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

    .ac-thread {
        max-height: 36rem;
        overflow-y: auto;
        border: 1px solid var(--ac-border);
        border-radius: var(--ac-radius-lg);
        background: linear-gradient(180deg, var(--ac-surface-muted) 0%, var(--ac-surface) 100%);
        padding: 0.8rem;
    }

    .ac-thread__date {
        display: flex;
        justify-content: center;
        padding: 0.25rem 0 0.75rem;
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

    .ac-message__meta {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        flex-wrap: wrap;
        margin-bottom: 0.35rem;
    }

    .ac-message__text {
        white-space: pre-wrap;
        word-break: break-word;
        font-size: 0.95rem;
        line-height: 1.45;
        color: var(--ac-text);
        text-align: left;
    }

    .ac-message__time {
        display: flex;
        width: 100%;
        margin-top: 0.25rem;
        font-size: 0.7rem;
        line-height: 1;
        color: var(--ac-text-soft);
        font-style: italic;
    }

    .ac-message--outbound .ac-message__time {
        justify-content: flex-end;
    }

    .ac-message--inbound .ac-message__time {
        justify-content: flex-start;
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

    .ac-right-aligned {
        text-align: right;
    }

    .ac-title-with-gap {
        margin-bottom: 0.75rem;
    }

    @media (max-width: 960px) {
        .ac-message__bubble {
            max-width: 100%;
        }
    }

    @media (max-width: 768px) {
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
