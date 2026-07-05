<style>
    :root {
        --ac-page-bg: #f7f8fa;
        --ac-bg: #f7f8fa;
        --ac-page-bg-alt: #ffffff;
        --ac-surface: #ffffff;
        --ac-surface-strong: #ffffff;
        --ac-surface-muted: #f1f3f6;
        --ac-surface-2: #f1f3f6;
        --ac-surface-emphasis: #eef0ff;
        --ac-input-surface: #f1f3f6;
        --ac-input-surface-alt: #e8ebef;
        --ac-surface-3: #e8ebef;
        --ac-border: #e4e7ec;
        --ac-border-strong: #cfd3da;
        --ac-border-input: #d6d9df;
        --ac-text: #0f172a;
        --ac-text-muted: #475569;
        --ac-text-2: #475569;
        --ac-text-soft: #94a3b8;
        --ac-text-3: #94a3b8;
        --ac-text-inverse: #ffffff;
        --ac-primary: #4f46e5;
        --ac-accent: #4f46e5;
        --ac-primary-hover: #4338ca;
        --ac-accent-hover: #4338ca;
        --ac-primary-active: #3730a3;
        --ac-accent-active: #3730a3;
        --ac-primary-soft: #eef0ff;
        --ac-accent-soft: #eef0ff;
        --ac-primary-ring: rgba(79, 70, 229, 0.22);
        --ac-accent-ring: rgba(79, 70, 229, 0.22);
        --ac-success: #15803d;
        --ac-success-soft: #e7f5ec;
        --ac-warning: #b45309;
        --ac-warning-soft: #fef3e2;
        --ac-danger: #b42318;
        --ac-danger-hover: #912018;
        --ac-danger-soft: #feeae7;
        --ac-info: #1d4ed8;
        --ac-info-soft: #e6eefd;
        --ac-neutral-soft: #eef1f4;
        --ac-channel-telegram: #229ed9;
        --ac-channel-telegram-soft: #e5f3fb;
        --ac-channel-max: #7b4dff;
        --ac-channel-max-soft: #efe8ff;
        --ac-channel-telegram-account: #5b7c99;
        --ac-channel-telegram-account-soft: #ecf1f6;
        --ac-channel-bitrix24: #2fc6f6;
        --ac-channel-bitrix24-soft: #e4f7fe;
        --ac-tag-gray: #64748b;
        --ac-tag-gray-soft: #eef1f4;
        --ac-tag-blue: #2563eb;
        --ac-tag-blue-soft: #e6eefd;
        --ac-tag-green: #16a34a;
        --ac-tag-green-soft: #e6f4ea;
        --ac-tag-yellow: #ca8a04;
        --ac-tag-yellow-soft: #fbf1d9;
        --ac-tag-red: #dc2626;
        --ac-tag-red-soft: #fce9e7;
        --ac-tag-purple: #7c3aed;
        --ac-tag-purple-soft: #f0e8fe;
        --ac-shadow-sm: 0 1px 2px rgba(15, 23, 42, 0.05);
        --ac-shadow-lg: 0 4px 12px rgba(15, 23, 42, 0.08);
        --ac-shadow-pop: 0 8px 24px rgba(15, 23, 42, 0.10), 0 0 0 1px rgba(15, 23, 42, 0.06);
        --ac-radius-xl: 10px;
        --ac-radius-lg: 8px;
        --ac-radius-md: 6px;
        --ac-radius-sm: 4px;
        --ac-radius-1: 4px;
        --ac-radius-2: 6px;
        --ac-radius-3: 8px;
        --ac-radius-4: 10px;
        --ac-radius-pill: 999px;
        --ac-font: -apple-system, BlinkMacSystemFont, "Segoe UI", "SF Pro Text", "Inter", system-ui, Roboto, "Helvetica Neue", Arial, sans-serif;
        --ac-font-mono: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        --ac-fs-10: 10px;
        --ac-fs-11: 11px;
        --ac-fs-12: 12px;
        --ac-fs-13: 13px;
        --ac-fs-15: 15px;
        --ac-fs-22: 22px;
        --ac-fw-medium: 500;
        --ac-fw-semi: 600;
        --ac-fw-bold: 700;
        --ac-sp-1: 4px;
        --ac-sp-2: 8px;
        --ac-sp-3: 12px;
        --ac-sp-4: 16px;
        --ac-sp-5: 20px;
        --ac-sp-6: 24px;
        --ac-row-h-table: 40px;
        --ac-topbar-h: 48px;
        --ac-content-max: 1280px;
        --ac-z-popover: 40;
    }

    .dark {
        --ac-page-bg: #0b0d12;
        --ac-bg: #0b0d12;
        --ac-page-bg-alt: #14171f;
        --ac-surface: #14171f;
        --ac-surface-strong: #14171f;
        --ac-surface-muted: #1b1f29;
        --ac-surface-2: #1b1f29;
        --ac-surface-emphasis: rgba(129, 140, 248, 0.14);
        --ac-input-surface: #1b1f29;
        --ac-input-surface-alt: #232834;
        --ac-surface-3: #232834;
        --ac-border: #262b36;
        --ac-border-strong: #353b49;
        --ac-border-input: #313747;
        --ac-text: #e5e7eb;
        --ac-text-muted: #9ca3af;
        --ac-text-2: #9ca3af;
        --ac-text-soft: #6b7280;
        --ac-text-3: #6b7280;
        --ac-text-inverse: #0f172a;
        --ac-primary: #818cf8;
        --ac-accent: #818cf8;
        --ac-primary-hover: #a5b4fc;
        --ac-accent-hover: #a5b4fc;
        --ac-primary-active: #c7d2fe;
        --ac-accent-active: #c7d2fe;
        --ac-primary-soft: rgba(129, 140, 248, 0.14);
        --ac-accent-soft: rgba(129, 140, 248, 0.14);
        --ac-primary-ring: rgba(129, 140, 248, 0.32);
        --ac-accent-ring: rgba(129, 140, 248, 0.32);
        --ac-success: #4ade80;
        --ac-success-soft: rgba(74, 222, 128, 0.12);
        --ac-warning: #fbbf24;
        --ac-warning-soft: rgba(251, 191, 36, 0.12);
        --ac-danger: #f87171;
        --ac-danger-hover: #fca5a5;
        --ac-danger-soft: rgba(248, 113, 113, 0.14);
        --ac-info: #60a5fa;
        --ac-info-soft: rgba(96, 165, 250, 0.12);
        --ac-neutral-soft: rgba(148, 163, 184, 0.14);
        --ac-channel-telegram-soft: rgba(34, 158, 217, 0.16);
        --ac-channel-max-soft: rgba(123, 77, 255, 0.18);
        --ac-channel-telegram-account-soft: rgba(91, 124, 153, 0.18);
        --ac-channel-bitrix24-soft: rgba(47, 198, 246, 0.14);
        --ac-tag-gray-soft: rgba(148, 163, 184, 0.14);
        --ac-tag-blue-soft: rgba(96, 165, 250, 0.14);
        --ac-tag-green-soft: rgba(74, 222, 128, 0.14);
        --ac-tag-yellow-soft: rgba(251, 191, 36, 0.14);
        --ac-tag-red-soft: rgba(248, 113, 113, 0.14);
        --ac-tag-purple-soft: rgba(167, 139, 250, 0.14);
        --ac-shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.40);
        --ac-shadow-lg: 0 4px 12px rgba(0, 0, 0, 0.45);
        --ac-shadow-pop: 0 8px 24px rgba(0, 0, 0, 0.55), 0 0 0 1px rgba(255, 255, 255, 0.06);
    }

    input[type="number"] {
        appearance: textfield;
        -moz-appearance: textfield;
    }

    input[type="number"]::-webkit-inner-spin-button,
    input[type="number"]::-webkit-outer-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }

    .ac-tabs {
        display: flex;
        align-items: center;
        gap: 2px;
        border-bottom: 1px solid var(--ac-border);
        margin-top: var(--ac-sp-1);
        margin-bottom: var(--ac-sp-5);
        overflow-x: auto;
        scrollbar-width: none;
    }

    .ac-tabs::-webkit-scrollbar {
        display: none;
    }

    .ac-tab {
        appearance: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        position: relative;
        border: 0;
        border-radius: var(--ac-radius-2) var(--ac-radius-2) 0 0;
        background: transparent;
        padding: 8px 12px 10px;
        color: var(--ac-text-2);
        font-size: var(--ac-fs-13);
        font-weight: var(--ac-fw-medium);
        white-space: nowrap;
        cursor: pointer;
        transition: color 100ms ease, background 100ms ease;
    }

    .ac-tab:hover {
        background: var(--ac-surface-2);
        color: var(--ac-text);
    }

    .ac-tab.is-active {
        color: var(--ac-text);
        font-weight: var(--ac-fw-semi);
    }

    .ac-tab.is-active::after {
        content: "";
        position: absolute;
        right: 0;
        bottom: -1px;
        left: 0;
        height: 2px;
        border-radius: 2px 2px 0 0;
        background: var(--ac-accent);
    }

    .ac-btn {
        appearance: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        height: 30px;
        border: 1px solid var(--ac-border-input);
        border-radius: var(--ac-radius-2);
        background: var(--ac-surface);
        padding: 0 12px;
        color: var(--ac-text);
        font-size: var(--ac-fs-13);
        font-weight: var(--ac-fw-medium);
        line-height: 1;
        text-decoration: none;
        white-space: nowrap;
        cursor: pointer;
        transition: background 100ms ease, border-color 100ms ease, color 100ms ease;
    }

    .ac-btn:hover {
        border-color: var(--ac-border-strong);
        background: var(--ac-surface-2);
        color: var(--ac-text);
        text-decoration: none;
    }

    .ac-btn:focus-visible {
        outline: none;
        box-shadow: 0 0 0 3px var(--ac-accent-ring);
    }

    .ac-btn:active {
        background: var(--ac-surface-3);
    }

    .ac-btn[disabled] {
        pointer-events: none;
        cursor: not-allowed;
        opacity: .5;
    }

    .ac-btn--sm {
        height: 26px;
        padding: 0 10px;
        font-size: var(--ac-fs-12);
    }

    .ac-btn--lg {
        height: 36px;
        padding: 0 16px;
    }

    .ac-btn--primary {
        border-color: var(--ac-accent);
        background: var(--ac-accent);
        color: var(--ac-text-inverse);
    }

    .ac-btn--primary:hover,
    .ac-btn--primary:focus-visible {
        border-color: var(--ac-accent-hover);
        background: var(--ac-accent-hover);
        color: var(--ac-text-inverse);
    }

    .ac-btn--primary:active {
        border-color: var(--ac-accent-active);
        background: var(--ac-accent-active);
    }

    .ac-btn--danger {
        border-color: var(--ac-border-input);
        background: var(--ac-surface);
        color: var(--ac-danger);
    }

    .ac-btn--danger:hover,
    .ac-btn--danger:focus-visible {
        border-color: var(--ac-danger);
        background: var(--ac-danger-soft);
        color: var(--ac-danger-hover);
    }

    .ac-btn--success {
        border-color: var(--ac-success);
        background: var(--ac-success);
        color: var(--ac-text-inverse);
    }

    .ac-btn--success:hover,
    .ac-btn--success:focus-visible {
        border-color: var(--ac-success);
        background: var(--ac-success);
        color: var(--ac-text-inverse);
        filter: brightness(1.08);
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
        background: color-mix(in srgb, var(--ac-surface) 92%, transparent);
        backdrop-filter: blur(16px);
    }

    .dark .fi-topbar {
        background: color-mix(in srgb, var(--ac-surface) 90%, transparent);
    }

    .fi-topbar nav {
        min-height: 3.75rem;
        gap: 0.75rem;
    }

    .fi-topbar .fi-topbar-start .fi-logo {
        display: none;
    }

    .fi-topbar .fi-topbar-start {
        gap: 0.65rem;
    }

    .fi-topbar .fi-topbar-collapse-sidebar-btn-ctn {
        order: 2;
        flex: 0 0 2rem;
        width: 2rem;
        margin-inline: 0;
    }

    .fi-main-sidebar {
        width: 15rem;
        background: var(--ac-surface);
        box-shadow: none;
    }

    @media (min-width: 1024px) {
        html,
        .fi-body {
            overflow: hidden;
        }

        .fi-body {
            --ac-admin-shell-topbar-height: 4rem;
        }

        .fi-layout {
            min-height: calc(100dvh - var(--ac-admin-shell-topbar-height));
            max-height: calc(100dvh - var(--ac-admin-shell-topbar-height));
            overflow: hidden;
        }

        .fi-body-has-topbar .fi-main-sidebar,
        .fi-main-sidebar {
            position: static;
            align-self: stretch;
            height: calc(100dvh - var(--ac-admin-shell-topbar-height));
            min-height: calc(100dvh - var(--ac-admin-shell-topbar-height));
            max-height: calc(100dvh - var(--ac-admin-shell-topbar-height));
            overflow: hidden;
            overscroll-behavior: contain;
        }

        .fi-main-sidebar.fi-sidebar-open,
        .fi-body-has-sidebar-collapsible-on-desktop .fi-main-sidebar:not(.fi-sidebar-open) {
            position: static;
        }

        .fi-sidebar-nav {
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
        }

        .fi-main-ctn {
            height: calc(100dvh - var(--ac-admin-shell-topbar-height));
            max-height: calc(100dvh - var(--ac-admin-shell-topbar-height));
            overflow-y: auto;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
        }
    }

    .fi-body-has-sidebar-collapsible-on-desktop .fi-main-sidebar:not(.fi-sidebar-open) {
        width: 3.75rem;
    }

    .fi-body-has-sidebar-collapsible-on-desktop .fi-main-sidebar:not(.fi-sidebar-open) .fi-sidebar-header {
        padding-inline: 0.5rem;
    }

    .fi-body-has-sidebar-collapsible-on-desktop .fi-main-sidebar:not(.fi-sidebar-open) .fi-sidebar-nav {
        padding-inline: 0.45rem;
    }

    .fi-body-has-sidebar-collapsible-on-desktop .fi-main-sidebar:not(.fi-sidebar-open) .fi-sidebar-nav-groups {
        margin-inline: 0;
        align-items: center;
    }

    .fi-body-has-sidebar-collapsible-on-desktop .fi-main-sidebar:not(.fi-sidebar-open) .fi-sidebar-item-btn,
    .fi-body-has-sidebar-collapsible-on-desktop .fi-main-sidebar:not(.fi-sidebar-open) .fi-sidebar-group-btn {
        width: 2.5rem;
        justify-content: center;
        padding-inline: 0;
    }

    .fi-body-has-sidebar-collapsible-on-desktop .fi-main-sidebar:not(.fi-sidebar-open) .fi-sidebar-footer .ac-env-indicator {
        display: none;
    }

    .dark .fi-main-sidebar {
        background: var(--ac-surface);
    }

    .fi-sidebar-header {
        min-height: 4rem;
        border-bottom: 1px solid var(--ac-border);
    }

    .fi-sidebar-nav {
        padding: 0.85rem 0.7rem;
    }

    .fi-sidebar-group-label {
        color: var(--ac-text-soft);
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        text-transform: none;
    }

    .fi-sidebar-item a,
    .fi-sidebar-group-button {
        border-radius: 8px;
    }

    .fi-sidebar-item a {
        min-height: 2.2rem;
        color: var(--ac-text-muted);
        transition: background 140ms ease, color 140ms ease;
    }

    .fi-sidebar-item a:hover {
        background: var(--ac-surface-muted);
        color: var(--ac-text);
    }

    .fi-sidebar-item.fi-active a,
    .fi-sidebar-item-active a,
    .fi-sidebar-item[aria-current="page"] a {
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
    }

    .fi-sidebar-footer {
        margin-top: auto;
        padding: 0.75rem 0.9rem;
        border-top: 1px solid var(--ac-border);
    }

    .fi-sidebar-footer .ac-env-indicator {
        display: inline-flex;
        align-items: center;
        max-width: 100%;
        color: var(--ac-text-soft);
        font-family: var(--ac-font-mono);
        font-size: 0.62rem;
        line-height: 1.2;
    }

    .fi-sidebar-footer .ac-env-indicator span {
        border: 0;
        background: transparent;
        box-shadow: none;
        color: inherit;
        padding: 0;
        letter-spacing: 0;
        text-transform: none;
    }

    .ac-admin-topbar-start {
        display: contents;
    }

    .ac-admin-brand {
        display: inline-flex;
        order: 1;
        flex: 0 0 auto;
        align-items: center;
        color: var(--ac-text);
        font-size: 1rem;
        font-weight: 800;
        line-height: 1;
        text-decoration: none;
        white-space: nowrap;
    }

    .ac-admin-brand:hover,
    .ac-admin-brand:focus-visible {
        color: var(--ac-text);
        text-decoration: none;
    }

    .ac-admin-breadcrumbs {
        display: inline-flex;
        order: 3;
        align-items: center;
        min-width: 0;
        gap: 0.35rem;
        color: var(--ac-text-muted);
        font-size: 0.86rem;
        font-weight: 600;
        line-height: 1.2;
    }

    .ac-admin-breadcrumbs__item {
        min-width: 0;
        max-width: 15rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    a.ac-admin-breadcrumbs__item {
        color: inherit;
        text-decoration: none;
        transition: color 0.15s ease;
    }

    a.ac-admin-breadcrumbs__item:hover,
    a.ac-admin-breadcrumbs__item:focus-visible {
        color: var(--ac-text);
        text-decoration: none;
    }

    .ac-admin-breadcrumbs__item--current {
        color: var(--ac-text);
        font-weight: 750;
    }

    .ac-admin-breadcrumbs__separator {
        color: var(--ac-text-soft);
    }

    .ac-admin-topbar-end {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .fi-topbar-end {
        gap: 0.5rem;
    }

    .fi-topbar-end .fi-user-menu {
        margin-inline-start: 0.25rem;
        padding-inline-start: 0.65rem;
        border-inline-start: 1px solid var(--ac-border);
    }

    .ac-admin-topbar-end .ac-env-indicator {
        display: inline-flex;
        align-items: center;
        max-width: 11rem;
        margin-inline-end: 0.1rem;
        font-family: var(--ac-font-mono);
        white-space: nowrap;
    }

    .ac-admin-topbar-end .ac-env-indicator span {
        min-height: 1.55rem;
        border-color: var(--ac-border);
        border-radius: 8px;
        background: var(--ac-surface);
        color: var(--ac-text-muted);
        box-shadow: none;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        text-transform: none;
    }

    .ac-admin-search {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        width: min(17.5rem, 26vw);
        min-height: 2.15rem;
        padding: 0 0.65rem;
        border: 1px solid var(--ac-border);
        border-radius: 8px;
        background: var(--ac-surface-muted);
        color: var(--ac-text-muted);
    }

    .ac-admin-search__icon {
        width: 1rem;
        height: 1rem;
        flex: 0 0 auto;
        color: var(--ac-text-soft);
    }

    .ac-admin-search__input {
        min-width: 0;
        width: 100%;
        border: 0;
        background: transparent;
        color: var(--ac-text);
        font-size: 0.82rem;
        font-weight: 500;
        outline: none;
    }

    .ac-admin-search__input::placeholder {
        color: var(--ac-text-soft);
    }

    .ac-admin-icon-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.15rem;
        height: 2.15rem;
        border: 1px solid var(--ac-border);
        border-radius: 8px;
        background: var(--ac-surface);
        color: var(--ac-text-muted);
        transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
    }

    .ac-admin-icon-button:hover {
        border-color: color-mix(in srgb, var(--ac-primary) 26%, var(--ac-border));
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
    }

    .ac-admin-icon-button.is-active {
        border-color: color-mix(in srgb, var(--ac-primary) 38%, var(--ac-border));
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
    }

    .ac-admin-notifications {
        position: relative;
        display: inline-flex;
    }

    .ac-admin-notifications__toggle {
        position: relative;
    }

    .ac-admin-notifications__badge {
        position: absolute;
        top: -0.3rem;
        right: -0.3rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.15rem;
        height: 1.15rem;
        padding: 0 0.25rem;
        border-radius: 999px;
        border: 2px solid var(--ac-surface);
        background: var(--ac-danger);
        color: var(--ac-text-inverse);
        font-size: 0.62rem;
        font-weight: 800;
        line-height: 1;
    }

    .ac-admin-notifications__badge[hidden],
    .ac-admin-notifications__popover[hidden],
    .ac-admin-notifications__empty[hidden] {
        display: none;
    }

    .ac-admin-notifications__popover {
        position: absolute;
        top: calc(100% + 0.55rem);
        right: 0;
        z-index: 60;
        display: grid;
        width: min(23.5rem, calc(100vw - 1.5rem));
        gap: 0.75rem;
        padding: 0.85rem;
        border: 1px solid var(--ac-border);
        border-radius: 10px;
        background: var(--ac-surface);
        box-shadow: var(--ac-shadow-pop);
        color: var(--ac-text);
    }

    .ac-admin-notifications__head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
    }

    .ac-admin-notifications__title {
        margin: 0;
        color: var(--ac-text);
        font-size: 0.88rem;
        font-weight: 700;
        line-height: 1.25;
    }

    .ac-admin-notifications__status {
        margin: 0.12rem 0 0;
        color: var(--ac-text-muted);
        font-size: 0.75rem;
        font-weight: 500;
        line-height: 1.35;
    }

    .ac-admin-notifications__scope,
    .ac-admin-notifications__volume,
    .ac-admin-notifications__sound {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        min-width: 0;
    }

    .ac-admin-notifications__scope,
    .ac-admin-notifications__volume {
        padding: 0.2rem;
        border: 1px solid var(--ac-border);
        border-radius: 8px;
        background: var(--ac-surface-muted);
    }

    .ac-admin-notifications__scope button,
    .ac-admin-notifications__volume button,
    .ac-admin-notifications__sound-toggle,
    .ac-admin-notifications__link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 1.85rem;
        border: 0;
        border-radius: 6px;
        background: transparent;
        color: var(--ac-text-muted);
        font-size: 0.75rem;
        font-weight: 700;
        line-height: 1;
        white-space: nowrap;
        cursor: pointer;
    }

    .ac-admin-notifications__scope button,
    .ac-admin-notifications__volume button {
        flex: 1 1 0;
        padding: 0 0.45rem;
    }

    .ac-admin-notifications__scope button:hover,
    .ac-admin-notifications__volume button:hover,
    .ac-admin-notifications__sound-toggle:hover,
    .ac-admin-notifications__link:hover {
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
    }

    .ac-admin-notifications__scope button.is-active,
    .ac-admin-notifications__volume button.is-active,
    .ac-admin-notifications__sound-toggle {
        background: var(--ac-surface);
        color: var(--ac-text);
        box-shadow: var(--ac-shadow-sm);
    }

    .ac-admin-notifications.is-sound-enabled .ac-admin-notifications__sound-toggle {
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
    }

    .ac-admin-notifications__sound {
        justify-content: space-between;
    }

    .ac-admin-notifications__field {
        display: grid;
        gap: 0.3rem;
        min-width: 0;
    }

    .ac-admin-notifications__field span {
        color: var(--ac-text-muted);
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 1.2;
    }

    .ac-admin-notifications__field select {
        width: 100%;
        min-height: 2.1rem;
        min-width: 0;
        border: 1px solid var(--ac-border);
        border-radius: 8px;
        background: var(--ac-surface);
        color: var(--ac-text);
        font-size: 0.78rem;
        font-weight: 650;
        line-height: 1.2;
        outline: none;
    }

    .ac-admin-notifications__field select:focus {
        border-color: var(--ac-primary);
        box-shadow: 0 0 0 3px var(--ac-primary-soft);
    }

    .ac-admin-notifications__sound-toggle {
        padding: 0 0.65rem;
        border: 1px solid var(--ac-border);
    }

    .ac-admin-notifications__link {
        padding: 0 0.2rem;
        color: var(--ac-primary);
    }

    .ac-admin-notifications__list {
        display: grid;
        gap: 0.4rem;
        max-height: 18rem;
        overflow: auto;
    }

    .ac-admin-notifications__item {
        display: grid;
        gap: 0.25rem;
        padding: 0.6rem 0.65rem;
        border: 1px solid var(--ac-border);
        border-radius: 8px;
        background: var(--ac-surface);
        color: inherit;
        text-decoration: none;
    }

    .ac-admin-notifications__item:hover {
        border-color: color-mix(in srgb, var(--ac-primary) 24%, var(--ac-border));
        background: var(--ac-primary-soft);
    }

    .ac-admin-notifications__item-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        min-width: 0;
    }

    .ac-admin-notifications__contact {
        min-width: 0;
        overflow: hidden;
        color: var(--ac-text);
        font-size: 0.82rem;
        font-weight: 700;
        line-height: 1.25;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ac-admin-notifications__time,
    .ac-admin-notifications__channel,
    .ac-admin-notifications__empty {
        color: var(--ac-text-muted);
        font-size: 0.72rem;
        font-weight: 600;
        line-height: 1.35;
    }

    .ac-admin-notifications__text {
        display: -webkit-box;
        overflow: hidden;
        color: var(--ac-text);
        font-size: 0.78rem;
        font-weight: 500;
        line-height: 1.35;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }

    .ac-admin-notifications__empty {
        margin: 0;
        padding: 0.6rem 0.65rem;
        border: 1px dashed var(--ac-border);
        border-radius: 8px;
        text-align: center;
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

    .fi-header .fi-breadcrumbs {
        display: none;
    }

    .fi-breadcrumbs-item-label {
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--ac-text-soft);
    }

    .fi-page:has([data-role="dialog-page"]) .fi-header.fi-header-has-breadcrumbs {
        display: none;
    }

    :is(.fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-page-header-main-ctn {
        gap: 0;
    }

    .fi-resource-contacts .fi-page-header-main-ctn > .fi-header {
        display: none;
    }

    :is(.fi-resource-tags, .fi-resource-channels) .fi-page-header-main-ctn > .fi-header {
        align-items: center;
        justify-content: flex-end;
        min-height: 0;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
        padding: 0 0 0.75rem;
    }

    :is(.fi-resource-tags, .fi-resource-channels) .fi-header-heading {
        display: none;
    }

    :is(.fi-resource-tags, .fi-resource-channels) .fi-header-actions-ctn {
        margin: 0 0 0 auto;
    }

    :is(.fi-resource-tags, .fi-resource-channels) .fi-header-actions-ctn .fi-btn {
        min-height: 2.25rem;
        border-radius: 8px;
        box-shadow: none;
    }

    :is(.fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-header-ctn {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        align-items: center;
        gap: 0.7rem;
        padding: 0.75rem 0.85rem;
    }

    :is(.fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-header-toolbar {
        min-width: 0;
        margin-top: 0;
        padding: 0;
        border-top: 0;
        border-bottom: 0;
        justify-content: flex-start;
    }

    :is(.fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-header-toolbar > :first-child:empty {
        display: none;
    }

    :is(.fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-header-toolbar > :last-child {
        width: 100%;
        min-width: 0;
        margin-inline-start: 0;
        justify-content: flex-start;
        gap: 0.5rem;
        flex-wrap: nowrap;
    }

    :is(.fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-header-toolbar .fi-btn,
    :is(.fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-header-toolbar .fi-icon-btn {
        min-height: 2.25rem;
        border-radius: 8px;
        box-shadow: none;
    }

    .fi-resource-contacts .fi-ta-header-toolbar .ac-contacts-filter-trigger.fi-btn,
    .fi-resource-tags .fi-ta-header-toolbar .ac-tags-filter-trigger.fi-btn,
    .fi-resource-channels .fi-ta-header-toolbar .ac-channels-filter-trigger.fi-btn {
        min-height: 2.25rem;
        border-color: var(--ac-border);
        border-radius: 8px;
        background: var(--ac-surface) !important;
        color: var(--ac-text) !important;
        font-size: 0.84rem;
        font-weight: 700;
        box-shadow: none;
    }

    .fi-resource-contacts .fi-ta-header-toolbar .ac-contacts-filter-trigger.fi-btn:hover,
    .fi-resource-tags .fi-ta-header-toolbar .ac-tags-filter-trigger.fi-btn:hover,
    .fi-resource-channels .fi-ta-header-toolbar .ac-channels-filter-trigger.fi-btn:hover {
        border-color: color-mix(in srgb, var(--ac-primary) 28%, var(--ac-border));
        background: var(--ac-primary-soft) !important;
        color: var(--ac-primary) !important;
    }

    :is(.fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-header-toolbar .fi-ta-col-manager-dropdown {
        margin-inline-start: auto;
        flex-shrink: 0;
    }

    .fi-resource-data-dictionary-entries .fi-header {
        align-items: center;
        flex-wrap: nowrap;
        padding: 0.8rem 1rem;
        border-radius: 18px;
    }

    .fi-resource-data-dictionary-entries .fi-breadcrumbs {
        display: none;
    }

    .fi-resource-data-dictionary-entries .fi-header-heading {
        font-size: 1.55rem;
        line-height: 1.1;
    }

    .fi-resource-data-dictionary-entries .fi-header-actions-ctn {
        margin-left: auto;
        margin-top: 0;
        flex-wrap: nowrap;
    }

    :is(
        .fi-resource-geo-countries,
        .fi-resource-geo-regions,
        .fi-resource-geo-cities,
        .fi-resource-geo-aliases
    ) .fi-page-header-main-ctn {
        gap: 0.75rem;
    }

    :is(
        .fi-resource-geo-countries,
        .fi-resource-geo-regions,
        .fi-resource-geo-cities,
        .fi-resource-geo-aliases
    ) .fi-header {
        align-items: center;
        gap: 0.75rem;
        padding: 0;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
    }

    :is(
        .fi-resource-geo-countries,
        .fi-resource-geo-regions,
        .fi-resource-geo-cities,
        .fi-resource-geo-aliases
    ) .fi-header-heading {
        font-size: 1.5rem;
        line-height: 1.1;
        letter-spacing: 0;
    }

    :is(
        .fi-resource-geo-countries,
        .fi-resource-geo-regions,
        .fi-resource-geo-cities,
        .fi-resource-geo-aliases
    ) .fi-header-actions-ctn {
        align-items: center;
        gap: 0.45rem;
        margin-left: auto;
    }

    :is(
        .fi-resource-geo-countries,
        .fi-resource-geo-regions,
        .fi-resource-geo-cities,
        .fi-resource-geo-aliases
    ) .fi-header-actions-ctn .fi-btn {
        min-height: 2rem;
        height: 2rem;
        padding-block: 0;
        padding-inline: 0.7rem;
        border-radius: var(--ac-radius-2);
        font-size: var(--ac-fs-13);
        font-weight: var(--ac-fw-semi);
        box-shadow: none !important;
    }

    .ac-sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    .ac-dialog-top-crumbs {
        position: fixed;
        inset-block-start: 0;
        inset-inline-start: 13.1rem;
        inset-inline-end: 15.5rem;
        z-index: 70;
        display: flex;
        align-items: center;
        min-width: 0;
        height: 4rem;
        gap: 0.5rem;
        color: #9a9893;
        font-size: 0.78rem;
        pointer-events: none;
    }

    .ac-dialog-top-crumbs__back,
    .ac-dialog-top-crumbs__link {
        pointer-events: auto;
    }

    .ac-dialog-top-crumbs__back {
        display: inline-flex;
        flex: 0 0 auto;
        align-items: center;
        justify-content: center;
        width: 1.65rem;
        height: 1.65rem;
        border: 1px solid #e7e5e0;
        border-radius: 0.45rem;
        background: #ffffff;
        color: #6b6a66;
        text-decoration: none;
        transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
    }

    .ac-dialog-top-crumbs__back:hover {
        border-color: #d6d3cc;
        background: #f5f5f3;
        color: #18181a;
    }

    .ac-dialog-top-crumbs__list {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        min-width: 0;
        margin: 0;
        padding: 0;
        overflow: hidden;
        list-style: none;
    }

    .ac-dialog-top-crumbs__item {
        display: inline-flex;
        align-items: center;
        min-width: 0;
        gap: 0.35rem;
        flex: 0 1 auto;
    }

    .ac-dialog-top-crumbs__separator {
        flex: none;
        color: #9a9893;
        opacity: 0.65;
    }

    .ac-dialog-top-crumbs__link {
        min-width: 0;
        max-width: 14rem;
        overflow: hidden;
        border-radius: 0.3rem;
        color: #9a9893;
        text-decoration: none;
        text-overflow: ellipsis;
        white-space: nowrap;
        transition: background 140ms ease, color 140ms ease;
    }

    .ac-dialog-top-crumbs__link:not(.is-current) {
        padding: 0.15rem 0.25rem;
    }

    .ac-dialog-top-crumbs__link:not(.is-current):hover {
        background: #f5f5f3;
        color: #18181a;
    }

    .ac-dialog-top-crumbs__link.is-current {
        color: #18181a;
        font-weight: 600;
    }

    body [data-role="dialog-top-breadcrumbs"].ac-dialog-top-crumbs {
        display: none;
    }

    .ac-env-indicator--topbar {
        position: fixed;
        inset-block-start: 2rem;
        inset-inline-end: 4.75rem;
        z-index: 70;
        transform: translateY(-50%);
        pointer-events: none;
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
    .fi-pagination-records-per-page-select .fi-select-input,
    .fi-pagination-records-per-page-select .fi-select-input-btn {
        min-height: 2.9rem;
    }

    .fi-global-search-field .fi-input-wrp-prefix,
    .fi-pagination-records-per-page-select .fi-input-wrp-prefix,
    .fi-global-search-field .fi-input-wrp-suffix,
    .fi-pagination-records-per-page-select .fi-input-wrp-suffix {
        color: var(--ac-text-soft);
        border-color: color-mix(in srgb, var(--ac-warning) 18%, var(--ac-border));
        background: color-mix(in srgb, var(--ac-surface-emphasis) 42%, transparent);
    }

    .fi-global-search-field .fi-input::placeholder,
    .fi-pagination-records-per-page-select .fi-input-wrp-label {
        color: var(--ac-text-soft);
    }

    .fi-ta-search-field .fi-input-wrp {
        display: flex;
        align-items: center;
        min-height: 1.9rem;
        height: 1.9rem;
        border-color: var(--ac-border-input);
        border-radius: var(--ac-radius-2);
        background: var(--ac-surface);
        box-shadow: none;
    }

    .fi-ta-search-field .fi-input-wrp-content-ctn {
        order: 2;
        min-width: 0;
    }

    .fi-ta-search-field .fi-input-wrp-prefix {
        order: 1;
        min-width: 1.9rem;
        color: var(--ac-text-3);
        border-inline-start: none;
        border-inline-end: none;
        background: transparent;
    }

    .fi-ta-search-field .fi-input-wrp-suffix {
        order: 3;
        min-width: 1.9rem;
        border-inline-start: none;
        background: transparent;
    }

    .fi-ta-search-field .fi-input {
        min-height: 1.9rem;
        height: 1.9rem;
        font-size: var(--ac-fs-13);
        font-weight: var(--ac-fw-medium);
    }

    .fi-ta-search-field .fi-input::placeholder {
        color: var(--ac-text-3);
    }

    .fi-ta-header-toolbar .fi-ta-search-field {
        min-width: min(100%, 20rem);
    }

    .fi-ta-header-toolbar .fi-btn,
    .fi-ta-header-toolbar .fi-icon-btn {
        min-height: 1.9rem;
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

    :is(
        .fi-resource-geo-countries,
        .fi-resource-geo-regions,
        .fi-resource-geo-cities,
        .fi-resource-geo-aliases
    ) .fi-ta-ctn {
        border-radius: var(--ac-radius-3);
    }

    :is(
        .fi-resource-geo-countries,
        .fi-resource-geo-regions,
        .fi-resource-geo-cities,
        .fi-resource-geo-aliases
    ) .fi-ta-header-ctn {
        padding: 0.75rem 0.9rem 0;
    }

    :is(
        .fi-resource-geo-countries,
        .fi-resource-geo-regions,
        .fi-resource-geo-cities,
        .fi-resource-geo-aliases
    ) .fi-ta-header-toolbar {
        margin-top: 0;
        padding: 0.6rem 0;
    }

    :is(
        .fi-resource-geo-countries,
        .fi-resource-geo-regions,
        .fi-resource-geo-cities,
        .fi-resource-geo-aliases
    ) .fi-ta-header-toolbar > :last-child > .fi-ta-search-field {
        flex: 0 0 20rem;
        min-width: 20rem;
    }

    :is(
        .fi-resource-geo-countries,
        .fi-resource-geo-regions,
        .fi-resource-geo-cities,
        .fi-resource-geo-aliases
    ) .fi-ta-header-toolbar .fi-btn,
    :is(
        .fi-resource-geo-countries,
        .fi-resource-geo-regions,
        .fi-resource-geo-cities,
        .fi-resource-geo-aliases
    ) .fi-ta-header-toolbar .fi-icon-btn {
        min-height: 1.9rem;
        border-radius: var(--ac-radius-2);
        box-shadow: none;
    }

    :is(
        .fi-resource-geo-countries,
        .fi-resource-geo-regions,
        .fi-resource-geo-cities,
        .fi-resource-geo-aliases
    ) .ac-table-toolbar-trigger {
        min-height: 1.9rem;
        border-radius: var(--ac-radius-2);
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

    .ac-geo-form-modal {
        border: 1px solid var(--ac-border);
        border-radius: var(--ac-radius-4);
        background: var(--ac-surface);
        box-shadow: var(--ac-shadow-lg);
    }

    .ac-geo-form-modal .fi-modal-header {
        padding: 1rem 1.1rem 0.75rem;
        border-bottom: 1px solid var(--ac-border);
    }

    .ac-geo-form-modal .fi-modal-heading {
        font-size: var(--ac-fs-15);
        font-weight: var(--ac-fw-semi);
        line-height: 1.25;
        letter-spacing: 0;
    }

    .ac-geo-form-modal .fi-modal-content {
        display: grid;
        gap: 0.8rem;
        padding: 0.9rem 1.1rem;
    }

    .ac-geo-form-modal .fi-modal-footer {
        padding: 0.75rem 1.1rem 1rem;
        border-top: 1px solid var(--ac-border);
        background: var(--ac-surface);
    }

    .ac-geo-form-modal .fi-modal-footer-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
        width: 100%;
    }

    .ac-geo-form-modal .fi-modal-footer .fi-btn {
        min-height: 1.9rem;
        border-radius: var(--ac-radius-2);
        padding-inline: 0.8rem;
        font-size: var(--ac-fs-13);
        font-weight: var(--ac-fw-semi);
        box-shadow: none;
    }

    .ac-geo-form-modal .fi-section {
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
    }

    .ac-geo-form-modal .fi-section-header {
        padding: 0 0 0.6rem;
        border-bottom: 1px solid var(--ac-border);
    }

    .ac-geo-form-modal .fi-section-heading,
    .ac-geo-form-modal .fi-section-header-heading {
        font-size: var(--ac-fs-14);
        font-weight: var(--ac-fw-semi);
        letter-spacing: 0;
    }

    .ac-geo-form-modal .fi-section-content-ctn,
    .ac-geo-form-modal .fi-section-content {
        border: 0;
        background: transparent;
        box-shadow: none;
    }

    .ac-geo-form-modal .fi-section-content {
        display: grid;
        gap: 0.8rem;
        padding-top: 0.8rem;
    }

    .ac-geo-form-modal .fi-fo-field {
        gap: 0.35rem;
    }

    .ac-geo-form-modal .fi-fo-field-label {
        color: var(--ac-text);
        font-size: var(--ac-fs-13);
        font-weight: var(--ac-fw-semi);
    }

    .ac-geo-form-modal .fi-fo-field-wrp-helper-text,
    .ac-geo-form-modal .fi-fo-field-wrp-hint {
        color: var(--ac-text-3);
        font-size: var(--ac-fs-12);
        line-height: 1.35;
    }

    .ac-geo-form-modal .fi-input-wrp {
        min-height: 2rem;
        border-color: var(--ac-border-input);
        border-radius: var(--ac-radius-2);
        background: var(--ac-surface);
        box-shadow: none;
        transition: border-color 140ms ease, box-shadow 140ms ease;
    }

    .ac-geo-form-modal .fi-input,
    .ac-geo-form-modal .fi-select-input,
    .ac-geo-form-modal .fi-select-input-btn {
        min-height: 2rem;
        font-size: var(--ac-fs-13);
        font-weight: var(--ac-fw-medium);
    }

    .ac-geo-form-modal .fi-input-wrp:hover {
        border-color: color-mix(in srgb, var(--ac-border-strong) 72%, var(--ac-primary));
        box-shadow: none;
    }

    .ac-geo-form-modal .fi-input-wrp:focus-within {
        border-color: var(--ac-primary);
        background: var(--ac-surface);
        box-shadow: 0 0 0 3px var(--ac-accent-ring);
    }

    .ac-geo-form-modal .fi-input-wrp:has(textarea.fi-input) {
        background: var(--ac-surface);
        box-shadow: none;
    }

    .ac-geo-form-modal textarea.fi-input {
        min-height: 4.5rem;
        line-height: 1.45;
    }

    .ac-geo-form-modal .fi-fo-toggle {
        align-items: center;
        gap: 0.55rem;
    }

    .ac-geo-form-modal .fi-toggle {
        box-shadow: none;
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
        fill: none;
        stroke: transparent;
        stroke-width: 18;
        pointer-events: stroke;
        cursor: pointer;
    }

    .ac-bot-constructor-arrow__line {
        fill: none;
        stroke: #8aa0bd;
        stroke-width: 2.5;
        stroke-linecap: round;
        stroke-linejoin: round;
        pointer-events: none;
    }

    .ac-bot-constructor-arrow.is-self-loop .ac-bot-constructor-arrow__line {
        stroke-width: 3;
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

    .fi-resource-channels .fi-header {
        align-items: center;
        flex-wrap: nowrap;
    }

    .fi-resource-channels .fi-header-heading {
        white-space: nowrap;
    }

    .fi-resource-channels .fi-header-actions-ctn {
        flex: 0 0 auto;
        flex-wrap: nowrap;
        margin-left: auto;
        margin-top: 0;
    }

    @media (max-width: 720px) {
        .fi-resource-channels .fi-header {
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .fi-resource-channels .fi-header-heading {
            white-space: normal;
        }

        .fi-resource-channels .fi-header-actions-ctn {
            width: 100%;
            margin-left: 0;
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

    .ac-channel-check-health {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: var(--ac-sp-4);
        margin-bottom: var(--ac-sp-3);
        border: 1px solid color-mix(in srgb, var(--ac-warning) 30%, var(--ac-border));
        border-radius: var(--ac-radius-lg);
        background: var(--ac-warning-soft);
        padding: var(--ac-sp-3) var(--ac-sp-4);
        color: var(--ac-warning);
    }

    .ac-channel-check-health[data-tone="danger"] {
        border-color: color-mix(in srgb, var(--ac-danger) 30%, var(--ac-border));
        background: var(--ac-danger-soft);
        color: var(--ac-danger);
    }

    .ac-channel-check-health[data-tone="success"] {
        border-color: color-mix(in srgb, var(--ac-success) 30%, var(--ac-border));
        background: var(--ac-success-soft);
        color: var(--ac-success);
    }

    .ac-channel-check-health__main {
        display: grid;
        gap: var(--ac-sp-1);
        min-width: 0;
    }

    .ac-channel-check-health__badge {
        font-size: var(--ac-fs-13);
        font-weight: var(--ac-fw-bold);
        line-height: 1.25;
    }

    .ac-channel-check-health__description {
        color: var(--ac-text-muted);
        font-size: var(--ac-fs-12);
        font-weight: var(--ac-fw-medium);
        line-height: 1.35;
    }

    .ac-channel-check-health__meta {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: var(--ac-sp-3);
        margin: 0;
        flex-wrap: wrap;
        text-align: right;
    }

    .ac-channel-check-health__meta div {
        display: grid;
        gap: 2px;
    }

    .ac-channel-check-health__meta dt {
        color: var(--ac-text-soft);
        font-size: var(--ac-fs-10);
        font-weight: var(--ac-fw-bold);
        line-height: 1;
        text-transform: uppercase;
    }

    .ac-channel-check-health__meta dd {
        margin: 0;
        color: var(--ac-text);
        font-size: var(--ac-fs-12);
        font-weight: var(--ac-fw-semi);
        line-height: 1.2;
        white-space: nowrap;
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

        .ac-channel-check-health {
            display: grid;
        }

        .ac-channel-check-health__meta {
            justify-content: flex-start;
            text-align: left;
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
        width: 100%;
        cursor: pointer;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        list-style: none;
        text-align: left;
    }

    .ac-channel-view-panel__summary::-webkit-details-marker {
        display: none;
    }

    .ac-channel-view-panel__summary-state {
        color: var(--ac-text-soft);
        font-size: 0.72rem;
        font-weight: 700;
    }

    .ac-channel-view-panel[data-open="false"] .ac-channel-view-panel__summary {
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

    body:has([data-role="dialog-page"]) .fi-main {
        background: #f5f5f3;
    }

    html.dark body:has([data-role="dialog-page"]) .fi-main {
        background: var(--ac-page-bg);
    }

    body:has([data-role="dialog-page"]) .fi-page {
        gap: 0.5rem;
    }

    body:has([data-role="dialog-page"]) .fi-page-header-main-ctn {
        padding-top: 1.1rem;
    }

    [data-role="dialog-page"].ac-panel-stack--relaxed {
        gap: 0.55rem;
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
        grid-template-columns: minmax(0, 1fr) minmax(20rem, 24rem);
        align-items: stretch;
        gap: 1rem;
    }

    .ac-dialog-main-column,
    .ac-dialog-side-column {
        display: grid;
        min-width: 0;
        gap: 0.9rem;
    }

    .ac-dialog-main-column {
        align-content: stretch;
    }

    .ac-dialog-side-column {
        align-content: start;
    }

    .ac-dialog-side-stack {
        display: grid;
        gap: 0.9rem;
        min-width: 0;
    }

    .ac-dialog-side-tabs {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.4rem;
        padding: 0.55rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 88%, transparent);
        border-radius: var(--ac-radius-lg);
        background: var(--ac-surface);
        box-shadow: var(--ac-shadow-sm);
    }

    .ac-dialog-side-tabs__link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 2rem;
        padding: 0.4rem 0.55rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 86%, transparent);
        border-radius: var(--ac-radius-pill);
        background: var(--ac-surface-muted);
        color: var(--ac-text-muted);
        font-size: 0.78rem;
        font-weight: 700;
        line-height: 1;
        text-align: center;
        text-decoration: none;
        transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
    }

    .ac-dialog-side-tabs__link:hover {
        border-color: color-mix(in srgb, var(--ac-primary) 28%, var(--ac-border));
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
    }

    .ac-dialog-side-tabs__link.is-active {
        border-color: color-mix(in srgb, var(--ac-primary) 44%, transparent);
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
        box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--ac-primary) 14%, transparent);
    }

    .ac-dialog-chat-panel .ac-thread {
        flex: 1 1 auto;
        height: calc(var(--ac-dialog-chat-panel-height, 44rem) - 12.25rem);
        min-height: 0;
        max-height: none;
        border: 0;
        border-radius: 0;
        background: color-mix(in srgb, var(--ac-surface-muted) 46%, var(--ac-surface));
        box-shadow: none;
    }

    .ac-dialog-chat-panel > .ac-surface__header {
        flex: 0 0 auto;
        padding: 0.62rem 0.85rem;
        border-bottom: 1px solid #eadfb4;
        background: #fff8df;
    }

    .ac-dialog-chat-panel > .ac-surface__header .ac-surface__title {
        font-size: 1rem;
        line-height: 1.2;
    }

    html.dark .ac-dialog-chat-panel > .ac-surface__header {
        border-bottom-color: var(--ac-border-strong);
        background: color-mix(in srgb, var(--ac-surface-muted) 72%, var(--ac-surface));
    }

    html.dark .ac-dialog-chat-panel > .ac-surface__header .ac-surface__title {
        color: var(--ac-text);
    }

    .ac-dialog-side-list {
        display: grid;
        gap: 0;
    }

    .ac-dialog-side-list .ac-meta {
        display: grid;
        grid-template-columns: minmax(6.8rem, 0.72fr) minmax(0, 1fr);
        align-items: baseline;
        gap: 0.7rem;
        padding: 0.45rem 0;
        border-bottom: 1px dashed var(--ac-border);
    }

    .ac-dialog-side-list .ac-meta.is-copyable {
        cursor: pointer;
        border-radius: 0.65rem;
        transition:
            background-color 160ms ease,
            border-color 160ms ease;
    }

    .ac-dialog-side-list .ac-meta.is-copyable:hover,
    .ac-dialog-side-list .ac-meta.is-copyable:focus-visible {
        background: color-mix(in srgb, var(--ac-accent) 8%, transparent);
        outline: none;
    }

    .ac-dialog-side-list .ac-meta.is-copied {
        background: color-mix(in srgb, var(--ac-success) 13%, transparent);
    }

    .ac-dialog-side-list .ac-meta:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .ac-dialog-side-list .ac-meta:first-child {
        padding-top: 0;
    }

    .ac-dialog-side-list .ac-meta__label {
        margin: 0;
        line-height: 1.35;
    }

    .ac-dialog-side-list .ac-meta__value {
        text-align: right;
        overflow-wrap: anywhere;
    }

    .ac-dialog-side-list .ac-meta__link {
        color: var(--ac-accent);
        font-weight: 650;
        text-decoration: none;
    }

    .ac-dialog-side-list .ac-meta__link:hover {
        text-decoration: underline;
    }

    html.dark .ac-dialog-side-card .ac-dialog-summary__section-title,
    html.dark .ac-dialog-side-list .ac-meta__label {
        color: #8b95a7;
    }

    .ac-dialog-side-list .ac-meta[data-field-key="current_block_id"] .ac-meta__value,
    .ac-dialog-side-list .ac-meta[data-field-key="last_message_at"] .ac-meta__value,
    .ac-dialog-side-list .ac-meta[data-field-key="last_inbound_message_at"] .ac-meta__value,
    .ac-dialog-side-list .ac-meta[data-field-key="last_outbound_message_at"] .ac-meta__value {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ac-dialog-side-list .ac-meta[data-field-key="current_block_id"] .ac-meta__value--muted,
    .ac-dialog-side-list .ac-meta[data-field-key="last_message_at"] .ac-meta__value--muted,
    .ac-dialog-side-list .ac-meta[data-field-key="last_inbound_message_at"] .ac-meta__value--muted,
    .ac-dialog-side-list .ac-meta[data-field-key="last_outbound_message_at"] .ac-meta__value--muted {
        grid-column: 2;
        min-width: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ac-dialog-side-list .ac-select {
        justify-self: end;
        max-width: 100%;
        min-height: 2.5rem;
        padding: 0.55rem 0.75rem;
    }

    .ac-dialog-side-list .ac-meta--assignee-editing {
        grid-template-columns: minmax(6.8rem, 0.72fr) minmax(0, 1fr);
        align-items: center;
        gap: 0.7rem;
    }

    .ac-dialog-status-toggle {
        justify-self: end;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        max-width: 100%;
        min-width: 0;
        overflow: hidden;
        border: 1px solid var(--ac-border);
        border-radius: 999px;
        background: var(--ac-accent-soft);
        padding: 0.42rem 0.72rem;
        color: var(--ac-accent-active);
        font-size: 0.78rem;
        font-weight: 700;
        line-height: 1.15;
        white-space: nowrap;
        text-overflow: ellipsis;
        cursor: pointer;
        transition: background 140ms ease, color 140ms ease, box-shadow 140ms ease;
        box-shadow: var(--ac-shadow-sm);
    }

    .ac-dialog-status-toggle:hover:not(:disabled) {
        background: var(--ac-surface-strong);
        color: var(--ac-text);
    }

    .ac-dialog-status-toggle:disabled {
        cursor: not-allowed;
        opacity: 0.55;
    }

    .ac-dialog-status-toggle span {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ac-dialog-assignee-editor {
        justify-self: end;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.3rem;
        min-width: 0;
        width: fit-content;
        max-width: 100%;
    }

    .ac-dialog-assignee-editor .ac-select {
        flex: 0 1 auto;
        width: auto;
        min-width: 8.25rem;
        max-width: min(11.75rem, 100%);
        min-height: 1.72rem;
        padding: 0.28rem 0.52rem;
        font-size: 0.74rem;
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

    [data-role="dialog-page"] .ac-surface--hero.ac-dialog-summary {
        border-color: var(--ac-border);
        border-radius: 0.85rem;
        background: var(--ac-surface);
        box-shadow: none;
        padding: 0.62rem 0.85rem;
    }

    [data-role="dialog-page"] .ac-surface__title--hero {
        font-size: 1.34rem;
    }

    .ac-surface.ac-dialog-chat-panel {
        --ac-dialog-chat-panel-height: clamp(34rem, calc(100dvh - 17rem), 62rem);
        display: flex;
        flex-direction: column;
        min-height: var(--ac-dialog-chat-panel-height);
        height: auto;
        gap: 0;
        overflow: hidden;
        padding: 0;
        background: var(--ac-surface);
        box-shadow: none;
    }

    .ac-surface.ac-dialog-side-card {
        border-radius: 0.85rem;
        background: var(--ac-surface);
        box-shadow: none;
        padding: 0.95rem 1rem;
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
        width: 2.75rem;
        height: 2.75rem;
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
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .ac-dialog-summary__identity {
        display: flex;
        min-width: min(100%, 28rem);
        align-items: center;
        gap: 0.75rem;
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
        margin-top: 0.55rem;
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
        gap: 0.65rem;
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
        min-height: 2.2rem;
        align-items: center;
        justify-content: center;
        isolation: isolate;
        margin: 0;
        border: 0;
        border-radius: 0;
        padding: 0.34rem 0.74rem 0.34rem 0.9rem;
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

    html.dark .ac-dialog-stage-step {
        --stage-fill-top: #222938;
        --stage-fill-bottom: #1a202d;
        --stage-outline: #3a4455;
        color: #cbd5e1;
    }

    html.dark .ac-dialog-stage-step[data-state="available"] {
        --stage-fill-top: #273142;
        --stage-fill-bottom: #202838;
        --stage-outline: #4b5b72;
        color: #d7dee9;
    }

    html.dark .ac-dialog-stage-step[data-state="available"]:hover,
    html.dark .ac-dialog-stage-step[data-state="available"]:focus-visible {
        --stage-fill-top: #203848;
        --stage-fill-bottom: #182c3a;
        --stage-outline: #56cfe1;
        color: #f8fafc;
    }

    html.dark .ac-dialog-stage-step[data-state="locked"] {
        --stage-fill-top: #202636;
        --stage-fill-bottom: #191f2c;
        --stage-outline: #343d4f;
        color: #94a3b8;
    }

    html.dark .ac-dialog-stage-step[data-state="completed"],
    html.dark .ac-dialog-stage-step[data-state="current"] {
        --stage-fill-top: #1ea9bd;
        --stage-fill-bottom: #0e7490;
        --stage-outline: #38bdf8;
        color: #ecfeff;
    }

    html.dark .ac-dialog-stage-step[data-state="current"] {
        filter: drop-shadow(0 0.7rem 1rem rgba(14, 116, 144, 0.28));
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

    .ac-dialog-fields-list {
        display: grid;
        gap: 0.55rem;
    }

    .ac-dialog-field-row {
        display: block;
        justify-self: end;
        width: 100%;
        min-width: 0;
    }

    .ac-dialog-field-row__input {
        width: 100%;
        min-width: 0;
        border: 1px solid var(--ac-border);
        border-radius: var(--ac-radius-md);
        background: var(--ac-surface);
        color: var(--ac-text);
        font-size: 0.82rem;
        font-weight: 650;
        line-height: 1.25;
        min-height: 1.5rem;
        padding: 0.14rem 0.35rem;
        text-align: right;
    }

    .ac-dialog-field-row__input:focus {
        border-color: var(--ac-primary);
        outline: 2px solid color-mix(in oklch, var(--ac-primary) 20%, transparent);
        outline-offset: 1px;
    }

    .ac-dialog-field-row__input:disabled {
        cursor: wait;
        opacity: 0.7;
    }

    .ac-card-grid {
        display: grid;
        gap: 0.85rem;
        grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
    }

    .ac-card-grid--kanban-filters {
        align-items: end;
        grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
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

    body:has([data-role="dialog-kanban-page"]) .fi-header {
        display: none;
    }

    body:has([data-role="dialog-kanban-page"]) .fi-page {
        gap: 0;
    }

    body:has([data-role="dialog-kanban-page"]) .fi-main {
        background: #f5f5f3;
    }

    .ac-kanban-hero {
        display: block;
        padding: 0.25rem 0 0.1rem;
    }

    .ac-kanban-hero__top {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 1.25rem;
        flex-wrap: wrap;
    }

    .ac-kanban-hero__actions {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 0.5rem;
        flex-wrap: wrap;
        width: 100%;
    }

    .ac-kanban-hero__actions > .ac-kanban-view-switch {
        margin-left: auto;
    }

    .ac-kanban-hero__actions > .ac-button,
    .ac-kanban-sort-wrap > .ac-button {
        min-height: 2.3rem;
        border-color: #e5e3df;
        border-radius: 0.6rem;
        background: #ffffff;
        color: #343434;
        font-size: 0.84rem;
        font-weight: 700;
        line-height: 1;
        padding: 0.44rem 0.75rem;
        box-shadow: none;
    }

    .ac-kanban-hero__actions > .ac-button:hover:not(:disabled),
    .ac-kanban-sort-wrap > .ac-button:hover:not(:disabled) {
        border-color: #d6d2cb;
        background: #ffffff;
    }

    .ac-kanban-hero__actions > .ac-button:disabled,
    .ac-kanban-sort-wrap > .ac-button:disabled {
        cursor: not-allowed;
        opacity: 0.58;
    }

    .ac-kanban-hero__badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.25rem;
        min-height: 1.25rem;
        margin-left: 0.35rem;
        border-radius: 999px;
        background: color-mix(in srgb, var(--ac-warning) 18%, var(--ac-surface-strong));
        color: var(--ac-warning);
        font-size: 0.72rem;
        font-weight: 800;
        line-height: 1;
    }

    .ac-kanban-toolbar {
        display: grid;
        grid-template-columns: minmax(18rem, 1fr);
        gap: 0.85rem;
        align-items: end;
    }

    .ac-kanban-toolbar__search,
    .ac-kanban-toolbar__summary {
        min-width: 0;
    }

    .ac-kanban-toolbar__search .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
        padding: 0;
    }

    .ac-kanban-hero__actions .ac-kanban-toolbar__search {
        width: min(18rem, 32vw);
    }

    .ac-kanban-hero__actions .ac-kanban-search-control .ac-input {
        min-height: 2.3rem;
        border-color: #e5e3df;
        border-radius: 0.6rem;
        background: #ffffff;
        padding: 0.44rem 0.75rem;
        font-size: 0.84rem;
        line-height: 1.2;
        box-shadow: none;
    }

    .ac-kanban-hero__actions .ac-kanban-search-control .ac-input:hover,
    .ac-kanban-hero__actions .ac-kanban-search-control .ac-input:focus {
        border-color: #d6d2cb;
        box-shadow: none;
    }

    .ac-kanban-toolbar__summary {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.45rem;
        flex-wrap: wrap;
        margin-top: 0.65rem;
        padding-bottom: 0.1rem;
    }

    .ac-kanban-toolbar__summary--start {
        justify-content: flex-start;
    }

    .ac-kanban-view-switch {
        display: inline-flex;
        align-items: center;
        gap: 0.15rem;
        min-height: 2.3rem;
        border: 1px solid #e5e3df;
        border-radius: 0.6rem;
        background: #ffffff;
        padding: 0.18rem;
        box-shadow: none;
    }

    .ac-kanban-view-switch__item {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 1.82rem;
        border-radius: 0.45rem;
        padding: 0.28rem 0.62rem;
        color: #7a7a7a;
        font-size: 0.82rem;
        font-weight: 700;
        line-height: 1;
        text-decoration: none;
        white-space: nowrap;
    }

    .ac-kanban-view-switch__item.is-active {
        background: #edf3ff;
        color: #4f6fdc;
        box-shadow: none;
    }

    .ac-kanban-view-switch.is-loading {
        pointer-events: none;
    }

    .ac-kanban-view-switch__item[data-ac-dialogs-view-link] {
        position: relative;
    }

    .ac-kanban-view-switch__item[data-ac-dialogs-view-link].is-loading {
        opacity: 0.72;
    }

    .ac-kanban-view-switch__item[data-ac-dialogs-view-link].is-loading::after {
        content: '';
        width: 0.68rem;
        height: 0.68rem;
        margin-left: 0.38rem;
        border: 2px solid currentColor;
        border-right-color: transparent;
        border-radius: 999px;
        animation: ac-dialogs-view-switch-spin 760ms linear infinite;
    }

    @keyframes ac-dialogs-view-switch-spin {
        to {
            transform: rotate(360deg);
        }
    }

    .ac-kanban-sort-wrap {
        position: relative;
        display: inline-flex;
    }

    .ac-kanban-sort-popover {
        position: absolute;
        top: calc(100% + 0.45rem);
        right: 0;
        z-index: 45;
        width: 15.5rem;
        overflow: hidden;
        border: 1px solid #e5e3df;
        border-radius: 0.7rem;
        background: #ffffff;
        box-shadow: 0 18px 40px rgba(15, 18, 25, 0.12);
    }

    .ac-kanban-sort-popover__head {
        border-bottom: 1px solid #eceae6;
        padding: 0.72rem 0.85rem;
        color: #2d2d2d;
        font-size: 0.82rem;
        font-weight: 800;
    }

    .ac-kanban-sort-popover__list {
        display: grid;
        gap: 0.05rem;
        padding: 0.35rem;
    }

    .ac-kanban-sort-option {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        width: 100%;
        border: 0;
        border-radius: 0.45rem;
        background: transparent;
        color: #343434;
        cursor: pointer;
        font: inherit;
        font-size: 0.82rem;
        font-weight: 700;
        line-height: 1.2;
        padding: 0.58rem 0.62rem;
        text-align: left;
    }

    .ac-kanban-sort-option:hover,
    .ac-kanban-sort-option.is-active {
        background: #fff7cc;
        color: #111111;
    }

    .ac-kanban-sort-option__mark {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.1rem;
        height: 1.1rem;
        flex: none;
        border-radius: 999px;
        background: #facc15;
        color: #111111;
        font-size: 0.72rem;
        font-weight: 900;
        line-height: 1;
    }

    .ac-kanban-gear-wrap {
        position: relative;
        display: inline-flex;
    }

    .ac-kanban-gear-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.3rem;
        height: 2.3rem;
        border: 1px solid #e5e3df;
        border-radius: 0.6rem;
        background: #ffffff;
        color: #777;
        cursor: pointer;
        opacity: 1;
        transition: background 120ms ease, border-color 120ms ease, color 120ms ease;
    }

    .ac-kanban-gear-button:hover,
    .ac-kanban-gear-button.is-open {
        border-color: #b9c7f8;
        background: #edf3ff;
        color: #4f6fdc;
    }

    [data-role="dialog-kanban-page"] [x-cloak],
    .ac-kanban-fields-popover[hidden] {
        display: none !important;
    }

    .ac-kanban-fields-popover {
        position: absolute;
        top: calc(100% + 0.45rem);
        right: 0;
        z-index: 40;
        width: 17rem;
        overflow: hidden;
        border: 1px solid #e5e3df;
        border-radius: 0.7rem;
        background: #ffffff;
        box-shadow: 0 18px 40px rgba(15, 18, 25, 0.12);
    }

    .ac-kanban-fields-popover__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        border-bottom: 1px solid #eceae6;
        padding: 0.72rem 0.85rem;
        color: #2d2d2d;
        font-size: 0.82rem;
        font-weight: 800;
    }

    .ac-kanban-fields-popover__head button {
        border: 0;
        background: transparent;
        color: #4f6fdc;
        cursor: pointer;
        font: inherit;
        font-size: 0.73rem;
        font-weight: 800;
        padding: 0;
    }

    .ac-kanban-fields-popover__head button:hover {
        text-decoration: underline;
        text-underline-offset: 0.16rem;
    }

    .ac-kanban-fields-popover__list {
        display: grid;
        gap: 0.05rem;
        padding: 0.35rem;
    }

    .ac-kanban-fields-popover__row {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        border-radius: 0.45rem;
        color: #343434;
        cursor: pointer;
        font-size: 0.82rem;
        font-weight: 700;
        line-height: 1.2;
        padding: 0.52rem 0.55rem;
        user-select: none;
    }

    .ac-kanban-fields-popover__row:hover {
        background: #f6f6f4;
    }

    .ac-kanban-fields-popover__row input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .ac-kanban-fields-popover__box {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1rem;
        height: 1rem;
        flex: none;
        border: 1.5px solid #c9c4bc;
        border-radius: 0.25rem;
        background: #ffffff;
    }

    .ac-kanban-fields-popover__row input:checked + .ac-kanban-fields-popover__box {
        border-color: #4f6fdc;
        background: #4f6fdc;
    }

    .ac-kanban-fields-popover__row input:checked + .ac-kanban-fields-popover__box::after {
        content: "";
        width: 0.42rem;
        height: 0.24rem;
        border-bottom: 2px solid #ffffff;
        border-left: 2px solid #ffffff;
        transform: rotate(-45deg) translate(0.02rem, -0.02rem);
    }

    .ac-kanban-filters-panel {
        display: grid;
        gap: 0.85rem;
        border-radius: 0.8rem;
    }

    .ac-kanban-search-control {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.6rem;
        align-items: center;
    }

    .ac-kanban-board {
        display: flex;
        gap: 0.9rem;
        align-items: start;
        overflow-x: auto;
        padding: 0.35rem 0 0.6rem;
        scrollbar-width: thin;
    }

    .ac-kanban-column {
        --ac-kanban-column-width: min(18.75rem, calc(100vw - 2rem));
        flex: 0 0 var(--ac-kanban-column-width);
        width: var(--ac-kanban-column-width);
        display: flex;
        flex-direction: column;
        min-height: 20rem;
        min-width: 0;
        gap: 0.65rem;
        border: 1px solid #e7e5e0;
        border-radius: 0.8rem;
        background: #ffffff;
        padding: 0.75rem;
        box-shadow: none;
        transition:
            opacity 150ms ease,
            box-shadow 150ms ease,
            border-color 150ms ease,
            flex-basis 180ms ease,
            width 180ms ease;
    }

    .ac-kanban-column--empty {
        --ac-kanban-column-width: min(12rem, calc(100vw - 2rem));
    }

    .ac-kanban-column--empty:hover,
    .ac-kanban-column--empty.ac-kanban-column--drop-target {
        --ac-kanban-column-width: min(18.75rem, calc(100vw - 2rem));
    }

    .ac-kanban-column__header {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        min-width: 0;
        padding-bottom: 0.6rem;
        border-bottom: 1px solid #eceae6;
    }

    .ac-kanban-column__bullet {
        width: 0.5rem;
        height: 0.5rem;
        border-radius: 999px;
        flex: none;
        background: #9ca3af;
    }

    .ac-kanban-column__bullet[data-tone="info"],
    .ac-kanban-column__bullet[data-tone="primary"] {
        background: #60a5fa;
    }

    .ac-kanban-column__bullet[data-tone="success"] {
        background: #61b36b;
    }

    .ac-kanban-column__bullet[data-tone="warning"] {
        background: #c7a052;
    }

    .ac-kanban-column__bullet[data-tone="danger"] {
        background: #f87171;
    }

    .ac-kanban-column__title {
        min-width: 0;
        flex: 1;
        margin: 0;
        overflow: hidden;
        color: #2d2d2d;
        font-size: 0.88rem;
        font-weight: 800;
        letter-spacing: -0.01em;
        line-height: 1.25;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ac-kanban-column__count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.9rem;
        height: 1.1rem;
        border-radius: 999px;
        background: #f3f2ef;
        color: #9a9690;
        font-size: 0.75rem;
        font-weight: 800;
        font-variant-numeric: tabular-nums;
    }

    .ac-kanban-column--drop-target {
        border-color: color-mix(in srgb, var(--ac-warning) 48%, var(--ac-border));
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--ac-warning) 45%, transparent);
    }

    .ac-kanban-column--inactive {
        opacity: 0.7;
    }

    .ac-kanban-column__cards {
        display: grid;
        gap: 0.55rem;
        align-content: start;
        align-items: start;
        flex: 1;
    }

    .ac-kanban-column__footer {
        margin-top: 1rem;
    }

    .ac-kanban-card {
        border: 1px solid #ebe8e3;
        border-radius: 0.65rem;
        background: #ffffff;
        padding: 0.72rem 0.8rem;
        min-width: 0;
        overflow: hidden;
        box-shadow: none;
        transition: border-color 150ms ease, transform 150ms ease, box-shadow 150ms ease;
    }

    .ac-kanban-card:hover {
        border-color: #d8d4cd;
        transform: translateY(-1px);
        box-shadow: 0 5px 14px rgba(15, 18, 25, 0.06);
    }

    .ac-kanban-card__header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.65rem;
    }

    .ac-kanban-card__header .ac-pill {
        min-height: 1.35rem;
        max-width: 7.4rem;
        border-radius: 0.45rem;
        padding: 0.18rem 0.44rem;
        font-size: 0.7rem;
        font-weight: 800;
        line-height: 1.05;
        text-align: center;
        white-space: normal;
    }

    .ac-kanban-card__title-group {
        min-width: 0;
        display: grid;
        gap: 0.22rem;
    }

    .ac-kanban-card__title-row {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        min-width: 0;
    }

    .ac-kanban-card__id {
        flex: none;
        color: #99958f;
        font-family: ui-monospace, "SF Mono", Menlo, Monaco, Consolas, monospace;
        font-size: 0.73rem;
        font-weight: 800;
        line-height: 1;
    }

    .ac-kanban-card__title {
        display: block;
        min-width: 0;
        font-size: 0.92rem;
        font-weight: 800;
        color: #262626;
        text-decoration: none;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ac-kanban-card__title:hover {
        color: #4f6fdc;
    }

    .ac-kanban-card__channel {
        margin: 0;
        font-size: 0.78rem;
        line-height: 1.25;
        color: #8b8780;
        overflow-wrap: anywhere;
    }

    .ac-kanban-card__body {
        margin-top: 0.52rem;
        display: grid;
        gap: 0.5rem;
    }

    .ac-kanban-card__preview {
        margin: 0;
        border: 0;
        border-radius: 0;
        background: transparent;
        padding: 0;
        font-size: 0.82rem;
        line-height: 1.38;
        color: #6f6b65;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .ac-kanban-card__meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.28rem;
    }

    .ac-kanban-card__chip {
        display: inline-flex;
        align-items: center;
        max-width: 100%;
        min-height: 1.2rem;
        border-radius: 0.3rem;
        background: #f4f3f1;
        color: #74706a;
        padding: 0.13rem 0.38rem;
        font-size: 0.7rem;
        font-weight: 700;
        line-height: 1.2;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ac-kanban-card__chip--route[data-tone="success"] {
        color: var(--ac-success);
    }

    .ac-kanban-card__chip--route[data-tone="warning"] {
        color: var(--ac-warning);
    }

    .ac-kanban-card__chip--route[data-tone="danger"] {
        color: var(--ac-danger);
    }

    .ac-kanban-card__chip--route[data-tone="info"],
    .ac-kanban-card__chip--route[data-tone="primary"] {
        color: var(--ac-primary);
    }

    .ac-kanban-card__footer {
        display: flex;
        justify-content: flex-end;
        margin-top: 0.1rem;
    }

    .ac-kanban-card__open-link {
        display: inline-flex;
        align-items: center;
        gap: 0.32rem;
        color: #5b72d6;
        font-size: 0.78rem;
        font-weight: 800;
        line-height: 1.2;
        text-decoration: none;
    }

    .ac-kanban-card__open-link:hover {
        color: #4059b8;
        text-decoration: underline;
        text-underline-offset: 0.16rem;
    }

    .ac-kanban-hide-id .ac-kanban-card__id,
    .ac-kanban-hide-channel .ac-kanban-card__channel,
    .ac-kanban-hide-status .ac-kanban-card__header > .ac-pill,
    .ac-kanban-hide-preview .ac-kanban-card__preview,
    .ac-kanban-hide-route .ac-kanban-card__chip--route,
    .ac-kanban-hide-responsible .ac-kanban-card__chip--responsible,
    .ac-kanban-hide-activity .ac-kanban-card__chip--activity,
    .ac-kanban-hide-open-link .ac-kanban-card__footer {
        display: none !important;
    }

    .ac-kanban-hide-route.ac-kanban-hide-responsible.ac-kanban-hide-activity .ac-kanban-card__meta {
        display: none !important;
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
        min-height: 4.8rem;
        border-radius: 0.55rem;
        border: 1px dashed #e7e2da;
        background: #ffffff;
        padding: 1rem;
        font-size: 0.83rem;
        line-height: 1.6;
        text-align: center;
        color: #9a958e;
        overflow-wrap: anywhere;
        font-style: italic;
    }

    .ac-kanban-column--empty .ac-kanban-empty-column {
        min-height: 7.5rem;
        padding: 0.85rem;
        font-size: 0.82rem;
        line-height: 1.45;
        text-wrap: balance;
    }

    @media (max-width: 760px) {
        .ac-kanban-toolbar {
            grid-template-columns: minmax(0, 1fr);
        }

        .ac-kanban-toolbar__summary {
            justify-content: flex-start;
        }

        .ac-kanban-search-control {
            grid-template-columns: minmax(0, 1fr);
        }

        .ac-kanban-search-control__clear {
            width: 100%;
        }
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

    .ac-kanban-hero__actions > .ac-button.ac-button--secondary,
    .ac-kanban-sort-wrap > .ac-button.ac-button--secondary,
    .ac-kanban-hero__actions > .ac-button.ac-button--secondary:disabled,
    .ac-kanban-sort-wrap > .ac-button.ac-button--secondary:disabled,
    .ac-kanban-hero__actions > .ac-button.ac-button--secondary[disabled],
    .ac-kanban-sort-wrap > .ac-button.ac-button--secondary[disabled] {
        border-color: #e5e3df !important;
        background: #ffffff !important;
        color: #343434 !important;
        box-shadow: none !important;
        opacity: 1 !important;
        transform: none !important;
    }

    .ac-kanban-hero__actions > .ac-button.ac-button--secondary:hover:not(:disabled),
    .ac-kanban-sort-wrap > .ac-button.ac-button--secondary:hover:not(:disabled),
    .ac-kanban-hero__actions > .ac-button.ac-button--secondary:focus-visible:not(:disabled),
    .ac-kanban-sort-wrap > .ac-button.ac-button--secondary:focus-visible:not(:disabled),
    .ac-kanban-hero__actions > .ac-button.ac-button--secondary:active:not(:disabled),
    .ac-kanban-sort-wrap > .ac-button.ac-button--secondary:active:not(:disabled) {
        border-color: #eab308 !important;
        background: #fff7cc !important;
        color: #111111 !important;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.18) !important;
        transform: none !important;
    }

    .ac-kanban-hero__actions > .ac-button.ac-button--secondary:disabled,
    .ac-kanban-sort-wrap > .ac-button.ac-button--secondary:disabled,
    .ac-kanban-hero__actions > .ac-button.ac-button--secondary[disabled],
    .ac-kanban-sort-wrap > .ac-button.ac-button--secondary[disabled] {
        color: #64748b !important;
        cursor: not-allowed !important;
    }

    .ac-kanban-hero__actions > .ac-button.ac-button--secondary:disabled:hover,
    .ac-kanban-sort-wrap > .ac-button.ac-button--secondary:disabled:hover,
    .ac-kanban-hero__actions > .ac-button.ac-button--secondary[disabled]:hover,
    .ac-kanban-sort-wrap > .ac-button.ac-button--secondary[disabled]:hover {
        border-color: #facc15 !important;
        background: #fff7cc !important;
        color: #334155 !important;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.14) !important;
        transform: none !important;
    }

    .ac-kanban-hero__actions > .ac-button.ac-button--warning-soft,
    .ac-kanban-sort-wrap > .ac-button.ac-button--warning-soft {
        border-color: #facc15 !important;
        background: #fff3b0 !important;
        color: #111111 !important;
        box-shadow: none !important;
        opacity: 1 !important;
        transform: none !important;
    }

    .ac-kanban-hero__actions > .ac-button.ac-button--warning-soft:hover,
    .ac-kanban-sort-wrap > .ac-button.ac-button--warning-soft:hover,
    .ac-kanban-hero__actions > .ac-button.ac-button--warning-soft:focus-visible,
    .ac-kanban-sort-wrap > .ac-button.ac-button--warning-soft:focus-visible,
    .ac-kanban-hero__actions > .ac-button.ac-button--warning-soft:active,
    .ac-kanban-sort-wrap > .ac-button.ac-button--warning-soft:active {
        border-color: #eab308 !important;
        background: #fde68a !important;
        color: #111111 !important;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.22) !important;
        transform: none !important;
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

    html.dark .ac-button--success {
        border-color: color-mix(in srgb, var(--ac-success) 58%, transparent);
        background: linear-gradient(
            180deg,
            color-mix(in srgb, var(--ac-success) 90%, #bbf7d0 10%) 0%,
            color-mix(in srgb, var(--ac-success) 82%, #22c55e 18%) 100%
        );
        color: #052e16;
    }

    html.dark .ac-button--success:disabled,
    html.dark .ac-button--success[disabled] {
        color: #0b3b22;
        opacity: 0.78;
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

    .ac-message__forwarded {
        margin: -0.1rem 0 0.45rem;
        color: var(--ac-text-soft);
        font-size: 0.78rem;
        font-weight: 700;
        line-height: 1.25;
        word-break: break-word;
    }

    .ac-message__forwarded-summary {
        display: inline-flex;
        gap: 0.18rem;
        align-items: baseline;
        padding: 0;
        border: 0;
        background: transparent;
        color: inherit;
        font: inherit;
        text-align: left;
        cursor: pointer;
        outline: none;
    }

    .ac-message__forwarded-summary-icon {
        color: color-mix(in srgb, var(--ac-text-soft) 78%, transparent);
    }

    .ac-message__forwarded-summary:focus-visible {
        border-radius: 6px;
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--ac-primary) 34%, transparent);
    }

    .ac-message__forwarded-details {
        display: grid;
        gap: 0.22rem;
        margin: 0.38rem 0 0;
        padding: 0.48rem 0.56rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 76%, transparent);
        border-radius: 8px;
        background: color-mix(in srgb, var(--ac-surface-muted) 58%, transparent);
        font-size: 0.75rem;
        font-weight: 650;
    }

    .ac-message__forwarded-row {
        display: grid;
        grid-template-columns: max-content minmax(0, 1fr);
        gap: 0.45rem;
        align-items: baseline;
    }

    .ac-message__forwarded-row dt,
    .ac-message__forwarded-row dd {
        margin: 0;
    }

    .ac-message__forwarded-row dt {
        color: var(--ac-text-soft);
    }

    .ac-message__forwarded-value {
        color: var(--ac-text);
        overflow-wrap: anywhere;
    }

    .ac-message__forwarded-value--success {
        color: var(--ac-success);
    }

    .ac-message__forwarded-value--warning {
        color: var(--ac-warning);
    }

    .ac-message__forwarded-link {
        color: inherit;
        text-decoration: underline;
        text-underline-offset: 0.12em;
    }

    .ac-message__forwarded-link:hover {
        color: var(--ac-primary);
    }

    .ac-message__edit-history,
    .ac-message__edited-label,
    .ac-message__removed-label {
        margin: 0.45rem 0 0.08rem;
        color: var(--ac-text-soft);
        font-size: 0.74rem;
        font-weight: 700;
        line-height: 1.25;
    }

    .ac-message__removed-label {
        color: #b45309;
    }

    html.dark .ac-message__removed-label {
        color: #fbbf24;
    }

    .ac-message__bubble--removed .ac-message__text {
        color: color-mix(in srgb, var(--ac-text) 66%, var(--ac-text-soft));
    }

    .ac-message__edit-summary {
        display: inline-flex;
        align-items: center;
        gap: 0.24rem;
        padding: 0;
        border: 0;
        background: transparent;
        color: inherit;
        cursor: pointer;
        font: inherit;
        outline: none;
    }

    .ac-message__edit-summary-icon {
        display: inline-grid;
        width: 0.6rem;
        place-items: center;
        color: color-mix(in srgb, var(--ac-text-soft) 84%, transparent);
        font-size: 0.68rem;
        line-height: 1;
    }

    .ac-message__edit-summary:focus-visible {
        border-radius: 6px;
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--ac-primary) 34%, transparent);
    }

    .ac-message__edit-details {
        display: grid;
        gap: 0.48rem;
        margin-top: 0.42rem;
        padding: 0.52rem 0.6rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 76%, transparent);
        border-radius: 8px;
        background: color-mix(in srgb, var(--ac-surface-muted) 58%, transparent);
    }

    .ac-message__edit-row {
        display: grid;
        gap: 0.34rem;
    }

    .ac-message__edit-time {
        color: var(--ac-text-soft);
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0;
        text-transform: uppercase;
    }

    .ac-message__edit-diff {
        display: grid;
        gap: 0.4rem;
        margin: 0;
    }

    .ac-message__edit-diff div {
        display: grid;
        gap: 0.12rem;
    }

    .ac-message__edit-diff dt,
    .ac-message__edit-diff dd {
        margin: 0;
    }

    .ac-message__edit-diff dt {
        color: var(--ac-text-soft);
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
    }

    .ac-message__edit-diff dd {
        color: var(--ac-text);
        font-size: 0.76rem;
        font-weight: 650;
        overflow-wrap: anywhere;
        white-space: pre-wrap;
    }

    .ac-message__contact-share {
        display: grid;
        gap: 0.34rem;
        width: min(20rem, 100%);
        margin: -0.04rem 0 0.15rem;
        padding: 0.62rem 0.68rem;
        border: 1px solid color-mix(in srgb, var(--ac-primary) 22%, var(--ac-border));
        border-radius: 12px;
        background: color-mix(in srgb, var(--ac-primary-soft) 34%, var(--ac-surface-muted));
    }

    .ac-message__contact-share-heading {
        color: var(--ac-text-soft);
        font-size: 0.74rem;
        font-weight: 800;
        line-height: 1.15;
    }

    .ac-message__contact-share-name {
        color: var(--ac-text);
        font-size: 0.98rem;
        font-weight: 850;
        line-height: 1.25;
        overflow-wrap: anywhere;
    }

    .ac-message__contact-share-details {
        display: grid;
        gap: 0.22rem;
        margin: 0;
        font-size: 0.78rem;
        line-height: 1.25;
    }

    .ac-message__contact-share-row {
        display: grid;
        grid-template-columns: max-content minmax(0, 1fr);
        gap: 0.45rem;
        align-items: baseline;
    }

    .ac-message__contact-share-row dt,
    .ac-message__contact-share-row dd {
        margin: 0;
    }

    .ac-message__contact-share-row dt {
        color: var(--ac-text-soft);
        font-weight: 750;
    }

    .ac-message__contact-share-value {
        color: var(--ac-text);
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .ac-message__contact-share-value--success {
        color: var(--ac-success);
    }

    .ac-message__contact-share-value--warning {
        color: var(--ac-warning);
    }

    .ac-message__text {
        white-space: pre-wrap;
        word-break: break-word;
        font-size: 0.95rem;
        line-height: 1.45;
        color: var(--ac-text);
        text-align: left;
    }

    .ac-message__bubble--has-gallery .ac-message__text {
        margin-top: 0.45rem;
    }

    .ac-message--system .ac-message__text {
        color: #374151;
        font-size: 0.9rem;
        line-height: 1.36;
        text-align: left;
    }

    html.dark .ac-message--system .ac-message__text {
        color: #cbd5e1;
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

    .ac-message__text--html blockquote {
        margin: 0.55rem 0;
        border-left: 3px solid color-mix(in srgb, var(--ac-primary) 72%, transparent);
        border-radius: 10px;
        background: color-mix(in srgb, var(--ac-primary) 11%, transparent);
        padding: 0.38rem 0.62rem;
    }

    .ac-message__text--html .ac-rich-text-heading {
        font-weight: 800;
    }

    .ac-message__text--html .ac-rich-text-highlight {
        border-radius: 6px;
        background: color-mix(in srgb, var(--ac-warning) 26%, transparent);
        color: inherit;
        padding: 0 0.12rem;
    }

    .ac-message__text--html .ac-rich-text-mention {
        border-radius: 6px;
        background: color-mix(in srgb, var(--ac-primary) 13%, transparent);
        color: var(--ac-primary);
        padding: 0 0.2rem;
        font-weight: 600;
    }

    .ac-message__text--html .ac-rich-text-list {
        display: block;
        padding-left: 1.1em;
        position: relative;
    }

    .ac-message__text--html .ac-rich-text-list::before {
        content: '\2022';
        position: absolute;
        left: 0.15em;
        color: var(--ac-text-soft);
    }

    /* Спойлер: отправитель скрыл текст — показываем размытым, раскрытие по hover. */
    .ac-message__text--html .ac-rich-text-spoiler {
        filter: blur(4px);
        border-radius: 4px;
        cursor: pointer;
        transition: filter 0.15s ease;
    }

    .ac-message__text--html .ac-rich-text-spoiler:hover {
        filter: none;
    }

    .ac-message__button-preview {
        display: grid;
        width: 100%;
        min-width: min(16rem, 100%);
        gap: 0.34rem;
        margin-top: 0.55rem;
        border-top: 1px solid color-mix(in srgb, var(--ac-border) 66%, transparent);
        padding-top: 0.5rem;
    }

    .ac-message__button-preview-label {
        color: var(--ac-text-soft);
        font-size: 0.68rem;
        font-weight: 800;
        line-height: 1.2;
    }

    .ac-message__button-preview-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.36rem;
        min-width: 0;
    }

    .ac-message__button-preview-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        max-width: 100%;
        min-height: 2rem;
        gap: 0.36rem;
        border: 1px solid color-mix(in srgb, var(--ac-primary) 34%, var(--ac-border));
        border-radius: 10px;
        background: color-mix(in srgb, var(--ac-primary-soft) 44%, var(--ac-surface-strong));
        padding: 0.38rem 0.56rem;
        color: var(--ac-text);
        font-size: 0.78rem;
        font-weight: 760;
        line-height: 1.18;
        text-align: center;
        cursor: default;
        opacity: 1;
    }

    .ac-message__button-preview-button:disabled {
        cursor: default;
        opacity: 1;
    }

    .ac-message__button-preview-text {
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .ac-message__button-preview-kind {
        flex: 0 0 auto;
        border-radius: 999px;
        background: color-mix(in srgb, var(--ac-surface-strong) 78%, transparent);
        padding: 0.12rem 0.34rem;
        color: var(--ac-text-soft);
        font-size: 0.62rem;
        font-weight: 800;
        line-height: 1.1;
        white-space: nowrap;
    }

    .ac-message__timestamp {
        flex: 0 0 auto;
        font-size: 0.72rem;
        line-height: 1.2;
        color: var(--ac-text-soft);
    }

    .ac-message__attachments {
        display: grid;
        width: 100%;
        min-width: min(18rem, 100%);
        gap: 0.38rem;
        margin-top: 0.55rem;
        border-top: 1px solid color-mix(in srgb, var(--ac-border) 72%, transparent);
        padding-top: 0.48rem;
    }

    .ac-message__attachments--preview-only {
        margin-top: 0.35rem;
        border-top: 0;
        padding-top: 0;
    }

    .ac-message__attachments--after-gallery {
        margin-top: 0.45rem;
    }

    .ac-message__attachments--inline-video {
        width: min(25rem, calc(100vw - 8rem));
        min-width: 0;
        max-width: 100%;
    }

    .ac-message-gallery {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        width: min(24rem, 100%);
        max-width: 100%;
        gap: 0.18rem;
        overflow: hidden;
        border-radius: 14px;
        background: color-mix(in srgb, var(--ac-surface-muted) 82%, #000 18%);
    }

    .ac-message-gallery[data-count="1"] {
        display: block;
        width: min(22rem, 100%);
        background: transparent;
    }

    .ac-message-gallery--stickers[data-count="1"] {
        width: min(10rem, 100%);
    }

    .ac-message-gallery[data-count="3"] .ac-message-gallery__item:first-child {
        grid-row: span 2;
    }

    .ac-message-gallery__item {
        position: relative;
        display: block;
        min-width: 0;
        overflow: hidden;
        background: color-mix(in srgb, var(--ac-surface-muted) 82%, #000 18%);
        cursor: zoom-in;
        line-height: 0;
    }

    .ac-message-gallery[data-count="1"] .ac-message-gallery__item {
        border: 1px solid color-mix(in srgb, var(--ac-border) 80%, transparent);
        border-radius: 14px;
    }

    .ac-message-gallery[data-count="1"] .ac-message-gallery__item--sticker {
        border-color: transparent;
        background: transparent;
    }

    .ac-message-gallery:not([data-count="1"]) .ac-message-gallery__item {
        aspect-ratio: 1 / 1;
    }

    .ac-message-gallery__item img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .ac-message-gallery[data-count="1"] .ac-message-gallery__item img {
        height: auto;
        max-height: 24rem;
        object-fit: contain;
        background: color-mix(in srgb, var(--ac-surface-muted) 82%, #000 18%);
    }

    .ac-message-gallery[data-count="1"] .ac-message-gallery__item--sticker img {
        max-height: 10rem;
        background: transparent;
    }

    body.ac-media-viewer-open {
        overflow: hidden;
    }

    .ac-media-viewer {
        position: fixed;
        inset: 0;
        z-index: 120;
        display: grid;
        place-items: center;
        padding: clamp(0.75rem, 2vw, 1.5rem);
    }

    .ac-media-viewer[hidden] {
        display: none !important;
    }

    .ac-media-viewer__backdrop {
        position: absolute;
        inset: 0;
        background: rgba(2, 6, 23, 0.86);
        backdrop-filter: blur(12px);
    }

    .ac-media-viewer__dialog {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-rows: auto minmax(0, 1fr);
        width: min(100%, 72rem);
        height: var(--ac-media-viewer-frame-height);
        max-height: var(--ac-media-viewer-frame-height);
        overflow: hidden;
        border: 1px solid rgba(226, 232, 240, 0.16);
        border-radius: 18px;
        background: rgba(15, 23, 42, 0.94);
        box-shadow: 0 32px 90px -44px rgba(0, 0, 0, 0.88);
        color: #f8fafc;
    }

    .ac-media-viewer__toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        min-height: 3.25rem;
        border-bottom: 1px solid rgba(226, 232, 240, 0.12);
        padding: 0.58rem 0.7rem 0.58rem 1rem;
    }

    .ac-media-viewer__summary {
        display: grid;
        min-width: 0;
        gap: 0.18rem;
    }

    .ac-media-viewer__title {
        overflow: hidden;
        font-size: 0.88rem;
        font-weight: 700;
        line-height: 1.25;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ac-media-viewer__counter {
        font-size: 0.76rem;
        line-height: 1.2;
        color: rgba(226, 232, 240, 0.72);
    }

    .ac-media-viewer__counter[hidden] {
        display: none;
    }

    .ac-media-viewer__actions {
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.5rem;
        flex: 0 0 auto;
    }

    .ac-media-viewer__button,
    .ac-media-viewer__nav {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(226, 232, 240, 0.22);
        background: rgba(30, 41, 59, 0.86);
        color: #f8fafc;
        text-decoration: none;
        cursor: pointer;
        transition: background 140ms ease, border-color 140ms ease, transform 140ms ease, opacity 140ms ease;
    }

    .ac-media-viewer__button {
        min-height: 2.1rem;
        border-radius: 10px;
        padding: 0.45rem 0.7rem;
        font-size: 0.78rem;
        font-weight: 700;
        --ac-media-viewer-frame-height: min(46rem, calc(100dvh - clamp(1.5rem, 4vw, 3rem)));
        --ac-media-viewer-figure-padding: clamp(0.75rem, 2vw, 1.35rem);
        --ac-media-viewer-toolbar-space: 3.75rem;
        line-height: 1.1;
    }

        box-sizing: border-box;
    .ac-media-viewer__button--icon {
        width: 2.1rem;
        min-height: 100dvh;
        padding: 0;
        font-size: 1.35rem;
        line-height: 1;
    }

    .ac-media-viewer__button[hidden] {
        display: none;
    }

    .ac-media-viewer__button:disabled {
        opacity: 0.64;
        cursor: wait;
    }

    .ac-media-viewer__copy-panel {
        position: absolute;
        top: 3.75rem;
        right: 1rem;
        left: 1rem;
        z-index: 3;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.65rem;
        align-items: center;
        border: 1px solid rgba(250, 204, 21, 0.42);
        border-radius: 12px;
        background: rgba(15, 23, 42, 0.96);
        padding: 0.6rem;
        box-shadow: 0 18px 48px -28px rgba(0, 0, 0, 0.8);
    }

    .ac-media-viewer__copy-panel[hidden] {
        display: none;
    }

    .ac-media-viewer__copy-input {
        min-width: 0;
        border: 1px solid rgba(226, 232, 240, 0.22);
        border-radius: 9px;
        background: rgba(2, 6, 23, 0.82);
        padding: 0.45rem 0.55rem;
        color: #f8fafc;
        font-size: 0.78rem;
    }

    .ac-media-viewer__copy-hint {
        font-size: 0.76rem;
        font-weight: 700;
        color: rgba(250, 204, 21, 0.9);
        white-space: nowrap;
    }

    .ac-media-viewer__button:hover,
    .ac-media-viewer__button:focus-visible,
    .ac-media-viewer__nav:hover:not(:disabled),
    .ac-media-viewer__nav:focus-visible:not(:disabled) {
        border-color: rgba(250, 204, 21, 0.78);
        background: rgba(51, 65, 85, 0.96);
        outline: none;
    }

    .ac-media-viewer__figure {
        display: grid;
        place-items: center;
        min-width: 0;
        min-height: 0;
        margin: 0;
        overflow: hidden;
        padding: var(--ac-media-viewer-figure-padding);
    }

    .ac-media-viewer__figure[data-media-viewer-kind="pdf"],
    .ac-media-viewer__figure[data-media-viewer-kind="video"] {
        align-items: stretch;
        justify-items: stretch;
    }

    .ac-media-viewer__figure[data-media-viewer-kind="audio"] {
        align-items: center;
        justify-items: center;
    }

    .ac-media-viewer__figure img,
    .ac-media-viewer__figure video,
    .ac-media-viewer__figure audio {
        display: block;
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
        border-radius: 10px;
        user-select: auto;
    }

    .ac-media-viewer__figure video {
        width: 100%;
        height: 100%;
        background: #020617;
    }

    .ac-media-viewer__figure audio {
        width: min(100%, 34rem);
        max-height: 4rem;
    }

    .ac-media-viewer__audio-panel {
        display: grid;
        place-items: center;
        width: min(100%, 38rem);
        border: 1px solid rgba(226, 232, 240, 0.2);
        border-radius: 18px;
        background:
            linear-gradient(135deg, rgba(30, 41, 59, 0.96), rgba(15, 23, 42, 0.96)),
            radial-gradient(circle at 18% 35%, rgba(250, 204, 21, 0.18), transparent 32%);
        padding: 1rem;
        box-shadow: 0 22px 60px -34px rgba(0, 0, 0, 0.82);
    }

    .ac-media-viewer__audio-panel audio {
        width: 100%;
        height: 2.9rem;
        min-height: 2.9rem;
        max-height: 4rem;
        border-radius: 999px;
    }

    .ac-media-viewer__figure iframe {
        display: block;
        width: 100%;
        height: 100%;
        min-height: min(70vh, 39rem);
        border: 0;
        border-radius: 10px;
        background: #0f172a;
    }

    .ac-media-viewer__figure img[hidden],
    .ac-media-viewer__figure video[hidden],
    .ac-media-viewer__figure audio[hidden],
    .ac-media-viewer__figure iframe[hidden],
    .ac-media-viewer__audio-panel[hidden] {
        display: none;
    }

    .ac-media-viewer__nav {
        position: absolute;
        top: 50%;
        z-index: 2;
        width: 2.75rem;
        height: 3.4rem;
        border-radius: 14px;
        font-size: 2.2rem;
        line-height: 1;
        transform: translateY(-50%);
    }

    .ac-media-viewer__nav--prev {
        left: 0.85rem;
    }

    .ac-media-viewer__nav--next {
        width: 100%;
        height: 100%;
        right: 0.85rem;
    }

    .ac-media-viewer__nav[hidden] {
        display: none;
    }

    .ac-media-viewer__nav:disabled {
        opacity: 0.36;
        cursor: not-allowed;
    }

    .ac-message-attachment {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: start;
        gap: 0.65rem;
    }

    .ac-message-attachment--image {
        grid-template-columns: minmax(0, 1fr);
        gap: 0.42rem;
    }

    .ac-message-attachment--audio {
        grid-template-columns: minmax(0, 1fr);
        gap: 0.42rem;
    }

    .ac-message-attachment--video-note {
    .ac-media-viewer__figure img {
        width: auto;
        height: auto;
        max-height: max(
            8rem,
            calc(
                var(--ac-media-viewer-frame-height)
                - var(--ac-media-viewer-toolbar-space)
                - var(--ac-media-viewer-figure-padding)
                - var(--ac-media-viewer-figure-padding)
            )
        );
    }

        grid-template-columns: minmax(0, 1fr);
        gap: 0.42rem;
    }

    .ac-message-attachment--video {
        grid-template-columns: minmax(0, 1fr);
        gap: 0.42rem;
    }

    .ac-message-attachment__preview {
        display: block;
        width: min(22rem, 100%);
        max-width: 100%;
        overflow: hidden;
        border: 1px solid color-mix(in srgb, var(--ac-border) 80%, transparent);
        border-radius: 14px;
        background: color-mix(in srgb, var(--ac-surface-muted) 82%, transparent);
        cursor: zoom-in;
        line-height: 0;
    }

    .ac-message-attachment__preview img {
        display: block;
        width: 100%;
        max-height: 24rem;
        object-fit: contain;
        background: color-mix(in srgb, var(--ac-surface-muted) 82%, #000 18%);
    }

    .ac-message-attachment__main {
        min-width: 0;
        display: grid;
        gap: 0.18rem;
    }

    .ac-message-attachment__title {
        min-width: 0;
        color: var(--ac-text);
        font-size: 0.86rem;
        font-weight: 760;
        line-height: 1.25;
        overflow-wrap: anywhere;
    }

    .ac-message-attachment__kind {
        margin-right: 0.32rem;
        color: var(--ac-text-soft);
        font-weight: 820;
    }

    .ac-message-attachment__meta,
    .ac-message-attachment__error {
        color: var(--ac-text-soft);
        font-size: 0.76rem;
        line-height: 1.25;
        overflow-wrap: anywhere;
    }

    .ac-message-attachment__error {
        color: var(--ac-danger);
    }

    .ac-message-attachment__side {
        display: inline-flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-items: center;
        gap: 0.34rem;
        max-width: 12rem;
    }

    .ac-message-attachment--image .ac-message-attachment__side {
        justify-content: flex-start;
        max-width: 100%;
    }

    .ac-message-attachment--audio .ac-message-attachment__side {
        justify-content: flex-start;
        max-width: 100%;
    }

    .ac-message-attachment--video-note .ac-message-attachment__side {
        justify-content: flex-start;
        max-width: 100%;
    }

    .ac-message-attachment--video .ac-message-attachment__side {
        justify-content: flex-start;
        max-width: 100%;
    }

    .ac-message-attachment__side .ac-pill {
        min-height: auto;
        padding: 0.14rem 0.44rem;
        font-size: 0.72rem;
        line-height: 1.15;
    }

    .ac-message-attachment__download {
        color: var(--ac-primary);
        font-size: 0.76rem;
        font-weight: 800;
        line-height: 1.15;
        text-decoration: underline;
        text-underline-offset: 2px;
    }

    .ac-message-attachment__download:hover {
        color: color-mix(in srgb, var(--ac-primary) 78%, #0f172a);
    }

    .ac-voice-player {
        --ac-voice-progress: 0%;
        display: grid;
        grid-template-columns: auto minmax(8rem, 1fr) auto;
        align-items: center;
        gap: 0.72rem;
        width: min(24rem, 100%);
        min-width: min(18rem, 100%);
        padding: 0.44rem 0.56rem 0.46rem;
        border-radius: 18px;
        background: color-mix(in srgb, var(--ac-primary) 10%, var(--ac-surface) 90%);
        box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--ac-primary) 18%, transparent);
    }

    .ac-message--outbound .ac-voice-player {
        background: color-mix(in srgb, #59c36a 16%, var(--ac-surface) 84%);
        box-shadow: inset 0 0 0 1px color-mix(in srgb, #37a451 22%, transparent);
    }

    .ac-voice-player__audio {
        display: none;
    }

    .ac-voice-player__toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.78rem;
        height: 2.78rem;
        border: 0;
        border-radius: 999px;
        color: #fff;
        background: linear-gradient(180deg, #62c76d 0%, #4bb65c 100%);
        box-shadow: 0 7px 16px color-mix(in srgb, #3fae50 28%, transparent);
        transition: transform 120ms ease, box-shadow 120ms ease, filter 120ms ease;
    }

    .ac-voice-player__toggle:hover,
    .ac-voice-player__toggle:focus-visible {
        transform: translateY(-1px);
        filter: saturate(1.05);
        box-shadow: 0 9px 19px color-mix(in srgb, #3fae50 34%, transparent);
        outline: none;
    }

    .ac-voice-player__toggle:active {
        transform: translateY(0);
    }

    .ac-voice-player__icon,
    .ac-voice-player__icon svg {
        display: block;
        width: 1.32rem;
        height: 1.32rem;
    }

    .ac-voice-player__icon[data-role="conversation-voice-play-icon"] {
        transform: translateX(1px);
    }

    .ac-voice-player__body {
        min-width: 0;
        display: grid;
        gap: 0.32rem;
    }

    .ac-voice-player__waveform {
        position: relative;
        display: block;
        width: 100%;
        height: 1.18rem;
        padding: 0;
        border: 0;
        background: transparent;
        cursor: pointer;
    }

    .ac-voice-player__waveform::before,
    .ac-voice-player__waveform::after {
        content: "";
        position: absolute;
        left: 0;
        top: 50%;
        height: 4px;
        border-radius: 999px;
        transform: translateY(-50%);
    }

    .ac-voice-player__waveform::before {
        right: 0;
        background: color-mix(in srgb, #4fb866 22%, var(--ac-text-soft) 78%);
        opacity: 0.56;
    }

    .ac-voice-player__waveform::after {
        width: var(--ac-voice-progress, 0%);
        background: #4bb65c;
        opacity: 0.92;
        transition: width 100ms linear;
    }

    .ac-voice-player__waveform span {
        display: none;
    }

    .ac-voice-player[data-waveform-hydrated="true"] .ac-voice-player__waveform {
        display: grid;
        grid-template-columns: repeat(46, minmax(2px, 1fr));
        align-items: center;
        gap: 2px;
    }

    .ac-voice-player[data-waveform-hydrated="true"] .ac-voice-player__waveform::before,
    .ac-voice-player[data-waveform-hydrated="true"] .ac-voice-player__waveform::after {
        display: none;
    }

    .ac-voice-player[data-waveform-hydrated="true"] .ac-voice-player__waveform span {
        display: block;
        height: max(3px, var(--ac-voice-bar, 18%));
        min-height: 3px;
        border-radius: 999px;
        background: color-mix(in srgb, #4fb866 34%, var(--ac-text-soft) 66%);
        opacity: 0.62;
        transition: height 180ms ease, background-color 120ms ease, opacity 120ms ease;
    }

    .ac-voice-player[data-waveform-hydrated="true"] .ac-voice-player__waveform span[data-active="true"] {
        background: #4bb65c;
        opacity: 1;
    }

    .ac-voice-player__waveform:hover::before,
    .ac-voice-player__waveform:focus-visible::before {
        opacity: 0.78;
    }

    .ac-voice-player[data-waveform-hydrated="true"] .ac-voice-player__waveform:hover span,
    .ac-voice-player[data-waveform-hydrated="true"] .ac-voice-player__waveform:focus-visible span {
        opacity: 0.9;
    }

    .ac-voice-player__waveform:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--ac-primary) 62%, transparent);
        outline-offset: 4px;
        border-radius: 999px;
    }

    .ac-voice-player__meta {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        color: color-mix(in srgb, #3fae50 76%, var(--ac-text) 24%);
        font-size: 0.84rem;
        font-weight: 780;
        line-height: 1.1;
        white-space: nowrap;
    }

    .ac-voice-player__download {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.7rem;
        height: 1.7rem;
        border-radius: 999px;
        color: color-mix(in srgb, #3fae50 80%, var(--ac-text) 20%);
        background: color-mix(in srgb, #59c36a 12%, transparent);
        transition: color 120ms ease, background-color 120ms ease, transform 120ms ease;
    }

    .ac-voice-player__download:hover,
    .ac-voice-player__download:focus-visible {
        color: #2f9742;
        background: color-mix(in srgb, #59c36a 22%, transparent);
        transform: translateY(-1px);
        outline: none;
    }

    .ac-voice-player__download svg {
        width: 1rem;
        height: 1rem;
    }

    .ac-video-note-player {
        position: relative;
        width: min(15rem, 64vw);
        aspect-ratio: 1;
        border-radius: 999px;
        overflow: hidden;
        background: color-mix(in srgb, var(--ac-surface-muted) 82%, #0f172a 18%);
        box-shadow:
            inset 0 0 0 1px color-mix(in srgb, var(--ac-border) 78%, transparent),
            0 10px 26px color-mix(in srgb, #0f172a 14%, transparent);
    }

    .ac-video-note-player__video {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
        background: color-mix(in srgb, var(--ac-surface-muted) 70%, #0f172a 30%);
        cursor: pointer;
    }

    .ac-video-note-player__toggle {
        position: absolute;
        inset: 50% auto auto 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 3.1rem;
        height: 3.1rem;
        border: 0;
        border-radius: 999px;
        color: #fff;
        background: color-mix(in srgb, #0f172a 56%, transparent);
        box-shadow: 0 8px 22px color-mix(in srgb, #0f172a 28%, transparent);
        transform: translate(-50%, -50%);
        transition: opacity 140ms ease, transform 140ms ease, background-color 140ms ease;
    }

    .ac-video-note-player[data-playing="true"] .ac-video-note-player__toggle {
        opacity: 0;
    }

    .ac-video-note-player:hover .ac-video-note-player__toggle,
    .ac-video-note-player:focus-within .ac-video-note-player__toggle,
    .ac-video-note-player[data-playing="false"] .ac-video-note-player__toggle {
        opacity: 1;
    }

    .ac-video-note-player__toggle:hover,
    .ac-video-note-player__toggle:focus-visible {
        background: color-mix(in srgb, #0f172a 68%, transparent);
        transform: translate(-50%, -50%) scale(1.04);
        outline: none;
    }

    .ac-video-note-player__icon,
    .ac-video-note-player__icon svg {
        display: block;
        width: 1.55rem;
        height: 1.55rem;
    }

    .ac-video-note-player__icon[data-role="conversation-video-note-play-icon"] {
        transform: translateX(1px);
    }

    .ac-video-note-player__meta {
        position: absolute;
        left: 50%;
        bottom: 0.62rem;
        max-width: calc(100% - 2rem);
        padding: 0.16rem 0.52rem;
        border-radius: 999px;
        color: #fff;
        background: color-mix(in srgb, #0f172a 58%, transparent);
        font-size: 0.76rem;
        font-weight: 780;
        line-height: 1.2;
        text-align: center;
        white-space: nowrap;
        transform: translateX(-50%);
    }

    .ac-video-note-player__download {
        position: absolute;
        right: 0.62rem;
        bottom: 0.62rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.9rem;
        height: 1.9rem;
        border-radius: 999px;
        color: #fff;
        background: color-mix(in srgb, #0f172a 58%, transparent);
        transition: background-color 120ms ease, transform 120ms ease;
    }

    .ac-video-note-player__download:hover,
    .ac-video-note-player__download:focus-visible {
        background: color-mix(in srgb, #0f172a 70%, transparent);
        transform: translateY(-1px);
        outline: none;
    }

    .ac-video-note-player__download svg {
        width: 1rem;
        height: 1rem;
    }

    .ac-video-player {
        display: grid;
        gap: 0.42rem;
        width: min(25rem, 100%);
    }

    .ac-video-player__video {
        display: block;
        width: 100%;
        max-height: 24rem;
        aspect-ratio: 16 / 9;
        border: 1px solid color-mix(in srgb, var(--ac-border) 78%, transparent);
        border-radius: 14px;
        background: #020617;
        object-fit: contain;
        box-shadow: 0 10px 26px color-mix(in srgb, #0f172a 12%, transparent);
    }

    .ac-video-player__footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.65rem;
        min-width: 0;
    }

    .ac-video-player__meta {
        min-width: 0;
        color: var(--ac-text-soft);
        font-size: 0.78rem;
        font-weight: 720;
        line-height: 1.2;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ac-video-player__actions {
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.34rem;
        flex: 0 0 auto;
    }

    .ac-video-player__button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.85rem;
        height: 1.85rem;
        border-radius: 999px;
        color: var(--ac-primary);
        background: color-mix(in srgb, var(--ac-primary) 10%, transparent);
        transition: color 120ms ease, background-color 120ms ease, transform 120ms ease;
    }

    .ac-video-player__button:hover,
    .ac-video-player__button:focus-visible {
        color: color-mix(in srgb, var(--ac-primary) 76%, #0f172a);
        background: color-mix(in srgb, var(--ac-primary) 18%, transparent);
        transform: translateY(-1px);
        outline: none;
    }

    .ac-video-player__button svg {
        width: 1rem;
        height: 1rem;
    }

    :where(.dark, [data-theme="dark"]) .ac-voice-player {
        background: color-mix(in srgb, #59c36a 14%, var(--ac-surface-strong) 86%);
        box-shadow: inset 0 0 0 1px color-mix(in srgb, #59c36a 22%, transparent);
    }

    :where(.dark, [data-theme="dark"]) .ac-voice-player__waveform::before {
        background: color-mix(in srgb, #70d47c 24%, var(--ac-text-soft) 76%);
    }

    :where(.dark, [data-theme="dark"]) .ac-voice-player__waveform::after {
        background: #70d47c;
    }

    :where(.dark, [data-theme="dark"]) .ac-voice-player[data-waveform-hydrated="true"] .ac-voice-player__waveform span {
        background: color-mix(in srgb, #70d47c 42%, var(--ac-text-soft) 58%);
    }

    :where(.dark, [data-theme="dark"]) .ac-voice-player[data-waveform-hydrated="true"] .ac-voice-player__waveform span[data-active="true"] {
        background: #70d47c;
    }

    @media (max-width: 640px) {
        .ac-voice-player {
            grid-template-columns: auto minmax(0, 1fr) auto;
            min-width: 0;
            width: 100%;
            gap: 0.58rem;
        }

        .ac-voice-player[data-waveform-hydrated="true"] .ac-voice-player__waveform {
            grid-template-columns: repeat(34, minmax(2px, 1fr));
        }

        .ac-voice-player[data-waveform-hydrated="true"] .ac-voice-player__waveform span:nth-child(n + 35) {
            display: none;
        }

        .ac-video-note-player {
            width: min(13.5rem, 72vw);
        }

        .ac-video-player {
            width: 100%;
        }

        .ac-message__attachments--inline-video {
            width: 100%;
        }

        .ac-video-player__video {
            max-height: 20rem;
        }
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

    .ac-composer .ac-inline-split {
        align-items: center;
    }

    .ac-composer__format-toggle {
        flex: 1 1 12rem;
        justify-content: center;
    }

    .ac-composer--dialog-inline {
        position: static;
        top: auto;
        border: 0;
        border-top: 1px solid color-mix(in srgb, var(--ac-warning) 24%, var(--ac-border));
        border-radius: 0;
        background: #fff8df;
        box-shadow: none;
        padding: 0.55rem 0.85rem 0.62rem;
    }

    html.dark .ac-composer--dialog-inline {
        border-top-color: var(--ac-border-strong);
        background: color-mix(in srgb, var(--ac-surface-muted) 72%, var(--ac-surface));
    }

    html.dark .ac-composer--dialog-inline .ac-surface__title {
        color: var(--ac-text);
    }

    .ac-composer--dialog-inline .ac-inline-split {
        gap: 0.65rem;
    }

    .ac-composer--dialog-inline .ac-surface__title {
        font-size: 1rem;
        line-height: 1.2;
    }

    .ac-composer--dialog-inline .ac-note {
        font-size: 0.78rem;
        line-height: 1.35;
    }

    .ac-composer--dialog-inline .ac-composer__format-toggle {
        flex: 0 0 auto;
        gap: 0.35rem;
    }

    .ac-composer--dialog-inline .ac-composer__format-toggle .ac-button {
        min-height: 1.8rem;
        border-radius: 10px;
        padding: 0.34rem 0.68rem;
        font-size: 0.76rem;
        line-height: 1.1;
    }

    .ac-composer--dialog-inline .ac-surface__divider {
        margin-top: 0.45rem;
        padding-top: 0.45rem;
    }

    .ac-composer--dialog-inline .ac-note-stack {
        gap: 0.42rem;
    }

    .ac-composer--dialog-inline .ac-actions {
        margin-top: 0.36rem;
    }

    .ac-composer--dialog-inline .ac-actions .ac-button {
        min-height: 2rem;
        border-radius: 10px;
        padding: 0.38rem 0.76rem;
        font-size: 0.78rem;
        line-height: 1.1;
    }

    .ac-composer--dialog-inline .ac-textarea--composer {
        box-sizing: border-box;
        height: 2.5rem;
        min-height: 2.5rem;
        max-height: none;
        padding-top: 0.52rem;
        padding-bottom: 0.52rem;
        line-height: 1.35;
        overflow-y: auto;
        resize: vertical !important;
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

    .ac-contact-dialogs {
        padding: 0;
        min-width: 0;
        width: 100%;
        max-width: 100%;
        overflow: visible;
    }

    .ac-contact-dialogs__toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        min-height: 2.5rem;
        padding: 0.62rem 0.75rem;
        border-bottom: 1px solid var(--ac-border);
    }

    .ac-contact-dialogs__summary {
        margin: 0;
        font-size: 0.82rem;
        line-height: 1.25;
        color: var(--ac-text-muted);
    }

    .ac-contact-dialogs__summary b {
        color: var(--ac-text);
        font-weight: 700;
    }

    .ac-contact-dialogs__columns {
        position: relative;
        flex: 0 0 auto;
    }

    .ac-contact-dialogs__tools {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        flex: 0 0 auto;
    }

    .ac-contact-dialogs__scroll-buttons {
        display: inline-flex;
        align-items: center;
        overflow: hidden;
        border: 1px solid var(--ac-border);
        border-radius: 10px;
        background: var(--ac-surface-strong);
    }

    .ac-contact-dialogs__scroll-buttons button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        min-height: 2rem;
        border: 0;
        border-right: 1px solid var(--ac-border);
        background: transparent;
        color: var(--ac-text);
        font-size: 1.05rem;
        font-weight: 800;
        line-height: 1;
        cursor: pointer;
    }

    .ac-contact-dialogs__scroll-buttons button:last-child {
        border-right: 0;
    }

    .ac-contact-dialogs__scroll-buttons button:hover:not(:disabled) {
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
    }

    .ac-contact-dialogs__scroll-buttons button:disabled {
        cursor: default;
        opacity: 0.38;
    }

    .ac-contact-dialogs__columns > summary {
        list-style: none;
    }

    .ac-contact-dialogs__columns > summary::-webkit-details-marker {
        display: none;
    }

    .ac-contact-dialogs__columns-button {
        display: inline-flex;
        align-items: center;
        gap: 0.42rem;
        min-height: 2rem;
        border: 1px solid var(--ac-border);
        border-radius: 10px;
        background: var(--ac-surface-strong);
        padding: 0.34rem 0.62rem;
        color: var(--ac-text);
        font-size: 0.78rem;
        font-weight: 600;
        line-height: 1;
        cursor: pointer;
        user-select: none;
    }

    .ac-contact-dialogs__columns-button [data-role="contact-dialogs-visible-columns"] {
        color: var(--ac-text-muted);
        font-weight: 600;
    }

    .ac-contact-dialogs__columns-popover {
        position: absolute;
        z-index: 40;
        top: calc(100% + 0.45rem);
        right: 0;
        min-width: 13.5rem;
        border: 1px solid var(--ac-border);
        border-radius: 12px;
        background: var(--ac-surface);
        box-shadow: 0 22px 46px -32px rgba(15, 23, 42, 0.55);
        padding: 0.48rem;
    }

    .ac-contact-dialogs__columns-title {
        margin: 0 0 0.28rem;
        padding: 0.2rem 0.28rem;
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 1.2;
        color: var(--ac-text-muted);
    }

    .ac-contact-dialogs__columns-list {
        display: grid;
        gap: 0.12rem;
    }

    .ac-contact-dialogs__columns-row {
        display: flex;
        align-items: center;
        gap: 0.38rem;
        border-radius: 8px;
        padding: 0.28rem 0.34rem;
        font-size: 0.8rem;
        line-height: 1.2;
        color: var(--ac-text);
        cursor: grab;
    }

    .ac-contact-dialogs__columns-row:hover {
        background: var(--ac-surface-muted);
    }

    .ac-contact-dialogs__columns-row.is-dragging {
        opacity: 0.56;
        outline: 1px dashed var(--ac-primary);
        background: var(--ac-primary-soft);
    }

    .ac-contact-dialogs__drag-handle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        width: 1rem;
        color: var(--ac-text-soft);
        font-size: 0.82rem;
        letter-spacing: -0.2em;
        cursor: grab;
        user-select: none;
    }

    .ac-contact-dialogs__columns-check {
        display: inline-flex;
        align-items: center;
        gap: 0.48rem;
        min-width: 0;
        flex: 1 1 auto;
        cursor: pointer;
    }

    .ac-contact-dialogs__order-buttons {
        display: inline-flex;
        align-items: center;
        gap: 0.12rem;
        flex: 0 0 auto;
    }

    .ac-contact-dialogs__order-buttons button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.35rem;
        height: 1.35rem;
        border: 1px solid var(--ac-border);
        border-radius: 7px;
        background: var(--ac-surface-strong);
        color: var(--ac-text-muted);
        font-size: 0.68rem;
        font-weight: 800;
        line-height: 1;
        cursor: pointer;
    }

    .ac-contact-dialogs__order-buttons button:hover {
        border-color: color-mix(in srgb, var(--ac-primary) 36%, var(--ac-border));
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
    }

    .ac-contact-dialogs__columns-row input {
        width: 0.9rem;
        height: 0.9rem;
        accent-color: var(--ac-primary);
    }

    .ac-contact-dialogs__columns-actions {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        margin-top: 0.28rem;
        border-top: 1px solid var(--ac-border);
        padding-top: 0.42rem;
    }

    .ac-contact-dialogs__columns-action,
    .ac-contact-dialogs__columns-apply {
        border: 0;
        border-radius: 8px;
        background: transparent;
        width: 100%;
        min-height: 1.92rem;
        padding: 0.28rem 0.5rem;
        text-align: center;
        color: var(--ac-primary);
        font-size: 0.78rem;
        font-weight: 700;
        cursor: pointer;
    }

    .ac-contact-dialogs__columns-action:hover {
        background: var(--ac-primary-soft);
    }

    .ac-contact-dialogs__columns-apply {
        background: var(--ac-primary);
        color: var(--ac-text-inverse);
    }

    .ac-contact-dialogs__columns-apply:hover {
        background: var(--ac-primary-hover);
    }

    .ac-contact-dialogs__table-wrap {
        position: relative;
        display: block;
        width: 100%;
        max-width: 100%;
        min-height: 8.5rem;
        overflow-x: scroll;
        overflow-y: visible;
        padding-bottom: 0.42rem;
        scrollbar-gutter: stable;
        overscroll-behavior-x: contain;
        -webkit-overflow-scrolling: touch;
        box-shadow: inset -2px 0 0 var(--ac-border-strong);
    }

    .ac-contact-dialogs__table-wrap::-webkit-scrollbar {
        height: 0.72rem;
    }

    .ac-contact-dialogs__table-wrap::-webkit-scrollbar-track {
        border-radius: 999px;
        background: var(--ac-surface-muted);
    }

    .ac-contact-dialogs__table-wrap::-webkit-scrollbar-thumb {
        border: 2px solid var(--ac-surface-muted);
        border-radius: 999px;
        background: var(--ac-border-strong);
    }

    .ac-contact-dialogs__table-wrap::-webkit-scrollbar-thumb:hover {
        background: var(--ac-text-soft);
    }

    .ac-contact-dialogs__table {
        width: 108.5rem;
        min-width: 100%;
        max-width: none;
        border-collapse: separate;
        border-spacing: 0;
        table-layout: fixed;
        font-size: 0.8rem;
        line-height: 1.35;
        color: var(--ac-text);
    }

    .ac-contact-dialogs__table th,
    .ac-contact-dialogs__table td {
        border-right: 1px solid var(--ac-border);
        border-bottom: 1px solid var(--ac-border);
        padding: 0.52rem 0.65rem;
        vertical-align: top;
    }

    .ac-contact-dialogs__table th[hidden],
    .ac-contact-dialogs__table td[hidden] {
        display: none !important;
    }

    .ac-contact-dialogs__table th:last-child,
    .ac-contact-dialogs__table td:last-child,
    .ac-contact-dialogs__table th[data-is-last-visible="1"],
    .ac-contact-dialogs__table td[data-is-last-visible="1"] {
        border-right: 2px solid var(--ac-border-strong);
    }

    .ac-contact-dialogs__table thead th {
        position: relative;
        background: var(--ac-surface-muted);
        color: var(--ac-text-muted);
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 1.2;
        text-align: left;
        white-space: nowrap;
        overflow: visible;
        user-select: none;
    }

    .ac-contact-dialogs__th-label {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        padding-right: 0.48rem;
    }

    .ac-contact-dialogs__resize {
        position: absolute;
        z-index: 2;
        top: 0;
        right: 0;
        bottom: 0;
        width: 0.68rem;
        border: 0;
        border-radius: 999px;
        background: transparent;
        cursor: col-resize;
    }

    .ac-contact-dialogs__resize::after {
        content: "";
        position: absolute;
        top: 0.32rem;
        right: 0.28rem;
        bottom: 0.32rem;
        width: 2px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--ac-border-strong) 74%, transparent);
        transition: background 120ms ease, width 120ms ease;
    }

    .ac-contact-dialogs__table th:hover .ac-contact-dialogs__resize::after,
    .ac-contact-dialogs__resize:hover::after,
    .ac-contact-dialogs-is-resizing .ac-contact-dialogs__resize::after {
        width: 3px;
        background: var(--ac-primary);
    }

    .ac-contact-dialogs-is-resizing,
    .ac-contact-dialogs-is-resizing * {
        cursor: col-resize !important;
        user-select: none !important;
    }

    .ac-contact-dialogs__table tbody tr {
        background: var(--ac-surface);
        transition: background 120ms ease;
        cursor: pointer;
    }

    .ac-contact-dialogs__table tbody tr:hover {
        background: color-mix(in srgb, var(--ac-primary-soft) 36%, var(--ac-surface));
    }

    .ac-contact-dialogs__table th[data-column="id"],
    .ac-contact-dialogs__table td[data-column="id"] {
        width: 4.5rem;
    }

    .ac-contact-dialogs__table th[data-column="channel"],
    .ac-contact-dialogs__table td[data-column="channel"] {
        width: 19rem;
    }

    .ac-contact-dialogs__table th[data-column="name"],
    .ac-contact-dialogs__table td[data-column="name"],
    .ac-contact-dialogs__table th[data-column="phone"],
    .ac-contact-dialogs__table td[data-column="phone"] {
        width: 12rem;
    }

    .ac-contact-dialogs__table th[data-column="stage"],
    .ac-contact-dialogs__table td[data-column="stage"],
    .ac-contact-dialogs__table th[data-column="status"],
    .ac-contact-dialogs__table td[data-column="status"] {
        width: 10rem;
    }

    .ac-contact-dialogs__table th[data-column="message"],
    .ac-contact-dialogs__table td[data-column="message"] {
        width: 25rem;
    }

    .ac-contact-dialogs__table th[data-column="date"],
    .ac-contact-dialogs__table td[data-column="date"] {
        width: 11rem;
    }

    .ac-contact-dialogs__id {
        color: var(--ac-primary);
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        font-weight: 700;
        text-decoration: none;
    }

    .ac-contact-dialogs__channel {
        display: flex;
        align-items: flex-start;
        gap: 0.52rem;
        color: inherit;
        text-decoration: none;
        min-width: 0;
    }

    .ac-contact-dialogs__channel-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        width: 1.32rem;
        height: 1.32rem;
        border-radius: 999px;
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
        font-size: 0.68rem;
        font-weight: 800;
    }

    .ac-contact-dialogs__channel-body {
        display: grid;
        min-width: 0;
        gap: 0.16rem;
    }

    .ac-contact-dialogs__main-line,
    .ac-contact-dialogs__mono,
    .ac-contact-dialogs__muted-line {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 100%;
    }

    .ac-contact-dialogs__main-line {
        color: var(--ac-text);
        font-weight: 700;
    }

    .ac-contact-dialogs__mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        color: var(--ac-text);
    }

    .ac-contact-dialogs__muted-line {
        color: var(--ac-text-muted);
        font-size: 0.74rem;
        font-weight: 500;
    }

    .ac-contact-dialogs__preview {
        display: grid;
        gap: 0.28rem;
        min-width: 0;
    }

    .ac-contact-dialogs__preview-meta,
    .ac-contact-dialogs__badges {
        display: flex;
        align-items: center;
        gap: 0.38rem;
        min-width: 0;
        flex-wrap: wrap;
    }

    .ac-contact-dialogs__preview-text {
        margin: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: var(--ac-text);
        font-weight: 600;
    }

    .ac-contact-dialogs .ac-pill {
        min-height: 1.35rem;
        padding: 0.16rem 0.48rem;
        font-size: 0.68rem;
        line-height: 1.05;
        white-space: nowrap;
    }

    @media (max-width: 900px) {
        .ac-contact-dialogs__toolbar {
            align-items: stretch;
            flex-direction: column;
        }

        .ac-contact-dialogs__columns {
            width: 100%;
        }

        .ac-contact-dialogs__tools {
            width: 100%;
            justify-content: space-between;
        }

        .ac-contact-dialogs__columns-button {
            width: 100%;
            justify-content: center;
        }

        .ac-contact-dialogs__columns-popover {
            left: 0;
            right: auto;
        }
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
        gap: 0.85rem;
        color: var(--ac-text);
    }

    [data-role="contact-page-header"].ac-surface {
        padding: 0.9rem 1rem;
        border-radius: 12px;
        background:
            linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-strong) 94%, var(--ac-primary-soft)) 0%, var(--ac-surface-strong) 100%);
        box-shadow: none;
    }

    [data-role="contact-page-header"] .ac-surface__header {
        gap: 0.75rem;
    }

    [data-role="contact-page-header"] .ac-surface__eyebrow {
        font-size: 0.68rem;
        letter-spacing: 0.08em;
    }

    [data-role="contact-page-header"] .ac-surface__title-group {
        gap: 0.18rem;
    }

    [data-role="contact-page-header"] .ac-surface__title--hero {
        font-size: clamp(1.35rem, 1rem + 1vw, 1.85rem);
        line-height: 1.1;
    }

    .ac-contact-page__headline {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.55rem;
    }

    .ac-contact-page__id {
        display: inline-flex;
        align-items: center;
        min-height: 1.45rem;
        padding: 0.1rem 0.5rem;
        border: 1px solid var(--ac-border);
        border-radius: var(--ac-radius-pill);
        background: var(--ac-surface-muted);
        color: var(--ac-text-muted);
        font-family: var(--ac-font-mono);
        font-size: 0.72rem;
        font-weight: 700;
    }

    .ac-contact-page__tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
    }

    .ac-contact-page__tab {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 2.15rem;
        padding: 0.45rem 0.78rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 88%, transparent);
        border-radius: var(--ac-radius-pill);
        background: var(--ac-surface);
        color: var(--ac-text-muted);
        font-size: 0.86rem;
        font-weight: 600;
        line-height: 1;
        text-decoration: none;
        transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
    }

    .ac-contact-page__tab:hover {
        border-color: color-mix(in srgb, var(--ac-primary) 22%, var(--ac-border));
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
    }

    .ac-contact-page__tab--active {
        border-color: color-mix(in srgb, var(--ac-primary) 44%, transparent);
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
        box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--ac-primary) 16%, transparent);
    }

    .ac-contact-page__layout {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        align-items: start;
        min-width: 0;
    }

    .ac-contact-page__column,
    .ac-contact-page__stack,
    .ac-contact-page__full-width {
        display: grid;
        gap: 0.75rem;
        align-content: start;
        min-width: 0;
        width: 100%;
        max-width: 100%;
    }

    .ac-contact-page__column {
        gap: 0.75rem;
    }

    .ac-contact-page__column > * + * {
        padding-top: 0;
        border-top: 0;
    }

    .ac-contact-form-section {
        display: grid;
        gap: 0;
        margin: 0;
        overflow: hidden;
        border: 1px solid var(--ac-border);
        border-radius: 12px;
        background: var(--ac-surface);
        box-shadow: var(--ac-shadow-sm);
    }

    details.ac-contact-form-section {
        display: block;
    }

    .ac-contact-form-section > summary {
        list-style: none;
        cursor: default;
    }

    .ac-contact-form-section > summary::-webkit-details-marker {
        display: none;
    }

    .ac-contact-form-section__header {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        min-height: 2rem;
        padding: 0.38rem 0.55rem;
        border-bottom: 1px solid var(--ac-border);
        background: color-mix(in srgb, var(--ac-surface-muted) 76%, var(--ac-surface));
    }

    .ac-contact-form-section__title {
        margin: 0;
        display: inline-flex;
        align-items: center;
        min-height: 1.4rem;
        padding: 0;
        background: transparent;
        color: var(--ac-text);
        font-size: 0.75rem;
        font-weight: 700;
        line-height: 1.2;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .ac-contact-form-section__empty-summary {
        margin-inline-start: auto;
        color: var(--ac-text-soft);
        font-size: 0.78rem;
        font-weight: 600;
        line-height: 1.2;
    }

    .ac-contact-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0;
        align-items: start;
    }

    .ac-contact-form-row {
        display: grid;
        grid-template-rows: auto minmax(1.25rem, auto);
        gap: 0.1rem;
        min-height: 2.55rem;
        padding: 0.28rem 0.5rem;
        border-bottom: 1px solid color-mix(in srgb, var(--ac-border) 82%, transparent);
        transition: background 140ms ease;
    }

    .ac-contact-form-row:nth-child(odd) {
        border-right: 1px solid color-mix(in srgb, var(--ac-border) 82%, transparent);
    }

    .ac-contact-form-row:hover {
        background: color-mix(in srgb, var(--ac-primary-soft) 32%, transparent);
    }

    .ac-contact-form-row__label {
        margin: 0;
        font-size: 0.64rem;
        font-weight: 600;
        line-height: 1.25;
        color: var(--ac-text-muted);
    }

    .ac-contact-form-row__value-shell {
        position: relative;
        display: flex;
        align-items: stretch;
    }

    .ac-contact-form-row__value {
        margin: 0;
        min-height: 1.25rem;
        width: 100%;
        padding: 0.1rem 0.32rem;
        border: 1px solid transparent;
        border-radius: 5px;
        background: transparent;
        color: var(--ac-text);
        font-size: 0.78rem;
        font-weight: 600;
        line-height: 1.35;
        word-break: break-word;
    }

    .ac-contact-form-row__value--empty {
        color: var(--ac-text-soft);
        font-weight: 500;
    }

    .ac-contact-form-row__value--with-action {
        padding-right: 2.1rem;
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
        grid-column: 1 / -1;
        gap: 0.45rem;
    }

    .ac-contact-form-item {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.55rem;
        padding: 0.28rem 0.42rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 82%, transparent);
        border-radius: 8px;
        background: color-mix(in srgb, var(--ac-surface-muted) 62%, var(--ac-surface));
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

    .ac-muted {
        color: var(--ac-text-muted);
    }

    .ac-icon-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.75rem;
        height: 1.75rem;
        border: 1px solid color-mix(in srgb, var(--ac-border) 88%, transparent);
        border-radius: 7px;
        background: var(--ac-surface-strong);
        color: var(--ac-text-muted);
        transition: opacity 140ms ease, background 140ms ease, border-color 140ms ease, color 140ms ease;
    }

    .ac-icon-button:hover {
        border-color: color-mix(in srgb, var(--ac-primary) 28%, var(--ac-border));
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
        box-shadow: none;
    }

    .ac-icon-button--field {
        position: absolute;
        inset-inline-end: 0.12rem;
        top: 50%;
        opacity: 0;
        transform: translateY(-50%);
    }

    .ac-contact-danger-zone {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.75rem;
        min-height: 2.25rem;
        padding: 0.42rem 0.55rem;
        border: 1px solid color-mix(in srgb, var(--ac-danger) 18%, var(--ac-border));
        border-radius: 10px;
        background: color-mix(in srgb, var(--ac-danger-soft) 34%, var(--ac-surface));
    }

    .ac-contact-danger-zone__text {
        color: var(--ac-text-muted);
        font-size: 0.76rem;
        font-weight: 700;
        line-height: 1.2;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .ac-contact-page .ac-bitrix-rescue-sync--compact {
        width: min(100%, 48rem);
        padding: 0.55rem 0.75rem;
        border-radius: var(--ac-radius-lg);
    }

    .ac-contact-page .ac-bitrix-rescue-sync--compact .ac-surface__header {
        align-items: center;
        gap: 0.65rem;
    }

    .ac-contact-page .ac-bitrix-rescue-sync--compact .ac-surface__title-group {
        display: flex;
        align-items: baseline;
        flex-wrap: wrap;
        gap: 0.32rem 0.55rem;
        min-width: 0;
    }

    .ac-contact-page .ac-bitrix-rescue-sync--compact .ac-surface__eyebrow,
    .ac-contact-page .ac-bitrix-rescue-sync--compact .ac-surface__title,
    .ac-contact-page .ac-bitrix-rescue-sync--compact .ac-surface__subtitle {
        line-height: 1.2;
    }

    .ac-contact-page .ac-field-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0;
        margin-top: 0.65rem;
        padding-top: 0;
        border-top: 1px solid var(--ac-border);
        border-left: 1px solid var(--ac-border);
        border-radius: 8px;
        overflow: hidden;
        background: var(--ac-surface);
    }

    .ac-contact-page .ac-field-card {
        display: grid;
        gap: 0.12rem;
        min-height: 2.55rem;
        padding: 0.32rem 0.5rem;
        border-right: 1px solid var(--ac-border);
        border-bottom: 1px solid var(--ac-border);
        background: var(--ac-surface);
    }

    .ac-contact-page .ac-field-card__label,
    .ac-contact-page .ac-field-card__value,
    .ac-contact-page .ac-field-card__key {
        margin: 0;
    }

    .ac-contact-page .ac-field-card__label {
        color: var(--ac-text-muted);
        font-size: 0.64rem;
        font-weight: 600;
        line-height: 1.25;
    }

    .ac-contact-page .ac-field-card__value {
        display: flex;
        align-items: baseline;
        flex-wrap: wrap;
        gap: 0.32rem;
        color: var(--ac-text);
        font-size: 0.78rem;
        font-weight: 600;
        line-height: 1.35;
        word-break: break-word;
    }

    .ac-contact-page .ac-field-card__external-link {
        flex-basis: 100%;
        color: #2563eb;
        font-size: 0.72rem;
        font-weight: 700;
        text-decoration: none;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .ac-contact-page .ac-field-card__external-link:hover,
    .ac-contact-page .ac-field-card__external-link:focus-visible {
        text-decoration: underline;
    }

    .ac-contact-page .ac-field-card__key {
        color: var(--ac-text-soft);
        font-family: var(--ac-font-mono);
        font-size: 0.68rem;
        line-height: 1.25;
        word-break: break-all;
    }

    .ac-contact-form-row:hover .ac-icon-button--field,
    .ac-icon-button--field:focus-visible {
        opacity: 1;
    }

    .ac-icon-button--field:hover {
        transform: translateY(-50%);
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
        gap: 0;
        border: 1px solid color-mix(in srgb, var(--ac-border) 84%, transparent);
        border-radius: 12px;
        background: color-mix(in srgb, var(--ac-surface-muted) 58%, var(--ac-surface));
        overflow: hidden;
    }

    .ac-diagnostics-payload__summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        min-height: 2.75rem;
        padding: 0.65rem 0.8rem;
        cursor: pointer;
        list-style: none;
    }

    .ac-diagnostics-payload__summary::-webkit-details-marker {
        display: none;
    }

    .ac-diagnostics-payload__summary::after {
        content: 'Показать';
        flex: 0 0 auto;
        color: var(--ac-primary);
        font-size: 0.76rem;
        font-weight: 700;
        line-height: 1;
    }

    .ac-diagnostics-payload[open] .ac-diagnostics-payload__summary {
        border-bottom: 1px solid color-mix(in srgb, var(--ac-border) 84%, transparent);
    }

    .ac-diagnostics-payload[open] .ac-diagnostics-payload__summary::after {
        content: 'Скрыть';
    }

    .ac-diagnostics-payload__label,
    .ac-diagnostics-payload__hint {
        display: block;
    }

    .ac-diagnostics-payload__label {
        margin: 0;
        font-size: 0.86rem;
        font-weight: 700;
        line-height: 1.35;
        color: var(--ac-text);
    }

    .ac-diagnostics-payload__hint {
        margin: 0;
        color: var(--ac-text-muted);
        font-size: 0.76rem;
        font-weight: 500;
        line-height: 1.35;
    }

    .ac-diagnostics-payload__pre {
        margin: 0;
        max-height: min(22rem, 48vh);
        overflow: auto;
        padding: 0.85rem;
        background: color-mix(in srgb, var(--ac-surface-strong) 92%, var(--ac-surface-muted));
        color: var(--ac-text);
        font-size: 0.76rem;
        line-height: 1.5;
        white-space: pre;
        word-break: normal;
    }

    .ac-contact-hero {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.65rem 0;
        background: transparent;
    }

    .ac-contact-hero__identity {
        display: flex;
        align-items: center;
        min-width: 0;
        gap: 0.85rem;
    }

    .ac-contact-hero__avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        width: 3.5rem;
        height: 3.5rem;
        border-radius: 50%;
        background: color-mix(in srgb, var(--ac-primary) 18%, var(--ac-surface-muted));
        color: var(--ac-primary);
        font-size: 1.15rem;
        font-weight: 800;
        line-height: 1;
    }

    .ac-contact-hero__title-group {
        display: grid;
        min-width: 0;
        gap: 0.12rem;
    }

    .ac-contact-hero__eyebrow,
    .ac-contact-hero__title,
    .ac-contact-hero__meta {
        margin: 0;
    }

    .ac-contact-hero__eyebrow {
        color: var(--ac-text-soft);
        font-size: 0.7rem;
        font-weight: 700;
        line-height: 1.2;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .ac-contact-hero__title {
        color: var(--ac-text);
        font-size: 1.375rem;
        font-weight: 800;
        line-height: 1.16;
    }

    .ac-contact-hero__meta {
        color: var(--ac-text-muted);
        font-size: 0.76rem;
        font-weight: 500;
        line-height: 1.35;
    }

    .ac-contact-hero__actions {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }

    .ac-contact-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        overflow: hidden;
        border: 1px solid var(--ac-border);
        border-radius: 12px;
        background: var(--ac-surface);
    }

    .ac-contact-stats__item {
        display: flex;
        align-items: center;
        min-height: 2.5rem;
        gap: 0.4rem;
        padding: 0.45rem 0.85rem;
        overflow: hidden;
        white-space: nowrap;
    }

    .ac-contact-stats__item + .ac-contact-stats__item {
        border-inline-start: 1px solid var(--ac-border);
    }

    .ac-contact-stats__label,
    .ac-contact-stats__value,
    .ac-contact-stats__meta {
        margin: 0;
    }

    .ac-contact-stats__label {
        color: var(--ac-text-soft);
        font-size: 0.75rem;
        font-weight: 600;
        line-height: 1.2;
    }

    .ac-contact-stats__value {
        color: var(--ac-text);
        font-size: 0.82rem;
        font-weight: 700;
        line-height: 1.25;
    }

    .ac-contact-stats__value::after {
        content: '·';
        margin-inline-start: 0.4rem;
        color: var(--ac-text-soft);
        font-weight: 500;
    }

    .ac-contact-stats__meta {
        color: var(--ac-text-muted);
        font-size: 0.74rem;
        font-weight: 500;
        line-height: 1.3;
    }

    .ac-contact-form-section {
        border-radius: 10px;
        box-shadow: none;
    }

    .ac-contact-form-section__header {
        min-height: 2.5rem;
        padding: 0.45rem 0.85rem;
        background: var(--ac-surface);
    }

    .ac-contact-form-section--empty-collapsed:not([open]) .ac-contact-form-section__header {
        border-bottom: 0;
        cursor: pointer;
    }

    .ac-contact-form-section--empty-collapsed:not([open]) .ac-contact-form-section__header:hover {
        background: var(--ac-surface-muted);
    }

    .ac-contact-form-section__title {
        color: var(--ac-text);
        font-size: 0.84rem;
        font-weight: 700;
        letter-spacing: 0;
        text-transform: none;
    }

    .ac-contact-form-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    [data-role="contact-section-client-data"] .ac-contact-form-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    [data-role="contact-section-client-data"] .ac-contact-form-row:nth-child(odd) {
        border-right: 1px solid color-mix(in srgb, var(--ac-border) 82%, transparent);
    }

    [data-role="contact-section-client-data"] .ac-contact-form-row {
        grid-template-columns: minmax(7.25rem, 42%) minmax(0, 1fr);
        gap: 0.45rem;
    }

    [data-role="contact-section-client-data"] .ac-contact-form-row--wide {
        grid-column: 1 / -1;
        grid-template-columns: minmax(8.5rem, 32%) minmax(0, 1fr);
        border-right: 0;
    }

    [data-role="contact-section-client-data"] .ac-contact-form-row__label {
        white-space: nowrap;
    }

    .ac-contact-form-row {
        grid-template-columns: minmax(8.5rem, 32%) minmax(0, 1fr);
        grid-template-rows: auto;
        align-items: center;
        gap: 0.75rem;
        min-height: 2rem;
        padding: 0.25rem 0.85rem;
    }

    .ac-contact-form-row:nth-child(odd) {
        border-right: 0;
    }

    .ac-contact-form-row--wide {
        grid-column: 1 / -1;
    }

    .ac-contact-form-row:hover {
        background: var(--ac-surface-muted);
    }

    .ac-contact-form-row:hover .ac-contact-form-row__value {
        border-color: var(--ac-border);
        background: var(--ac-surface);
    }

    .ac-contact-form-row__label {
        color: var(--ac-text-muted);
        font-size: 0.76rem;
        font-weight: 600;
    }

    .ac-contact-form-row__value-shell {
        min-width: 0;
        align-items: center;
    }

    .ac-contact-form-row__value-shell--with-actions {
        gap: 0.5rem;
    }

    .ac-contact-form-row__value {
        min-height: 1.5rem;
        padding: 0.14rem 0.35rem;
        font-size: 0.82rem;
        font-weight: 650;
    }

    .ac-contact-form-row__value--with-action {
        padding-right: 0.35rem;
    }

    .ac-contact-form-row__key,
    .ac-contact-form-row__items,
    .ac-icon-button--field {
        display: none;
    }

    .ac-contact-form-row__inline-actions {
        display: inline-flex;
        flex: 0 0 auto;
        align-items: center;
        gap: 0.55rem;
    }

    .ac-inline-profile-field {
        appearance: none;
        width: 100%;
        outline: none;
        cursor: text;
    }

    .ac-inline-profile-field--select {
        cursor: pointer;
        background-image:
            linear-gradient(45deg, transparent 50%, var(--ac-text-soft) 50%),
            linear-gradient(135deg, var(--ac-text-soft) 50%, transparent 50%);
        background-position:
            calc(100% - 0.8rem) 50%,
            calc(100% - 0.55rem) 50%;
        background-size: 0.28rem 0.28rem, 0.28rem 0.28rem;
        background-repeat: no-repeat;
        padding-inline-end: 1.35rem;
    }

    .ac-inline-profile-field:focus,
    .ac-inline-profile-field--select:focus {
        border-color: var(--ac-primary);
        background-color: var(--ac-surface);
        box-shadow: 0 0 0 3px var(--ac-primary-ring);
    }

    .ac-contact-form-row__error {
        grid-column: 1 / -1;
        margin: -0.15rem 0 0;
        color: var(--ac-danger);
        font-size: 0.74rem;
        font-weight: 600;
        line-height: 1.3;
    }

    .ac-savebar {
        position: fixed;
        left: 50%;
        bottom: 1.25rem;
        z-index: 80;
        display: none;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        min-width: min(35rem, calc(100vw - 2rem));
        padding: 0.65rem 0.75rem 0.65rem 0.9rem;
        border: 1px solid var(--ac-border);
        border-radius: 12px;
        background: var(--ac-surface);
        box-shadow: var(--ac-shadow-pop);
        transform: translateX(-50%);
    }

    .ac-savebar.is-visible {
        display: flex;
    }

    .ac-savebar__status {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        color: var(--ac-text);
        font-size: 0.86rem;
        font-weight: 700;
        line-height: 1.2;
    }

    .ac-savebar__status::before {
        content: '';
        width: 0.58rem;
        height: 0.58rem;
        border-radius: 999px;
        background: var(--ac-warning);
    }

    .ac-savebar__actions {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .ac-contact-empty-line {
        min-height: 2.25rem;
        padding: 0.55rem 0.85rem;
        color: var(--ac-text-soft);
        font-size: 0.82rem;
        font-weight: 500;
    }

    .ac-phone-list,
    .ac-tag-list {
        display: grid;
    }

    .ac-phone-row,
    .ac-tag-row {
        display: flex;
        align-items: center;
        min-width: 0;
        min-height: 2.75rem;
        gap: 0.45rem;
        padding: 0.35rem 0.85rem;
        border-bottom: 1px solid color-mix(in srgb, var(--ac-border) 82%, transparent);
        transition: background 140ms ease;
    }

    .ac-phone-row:last-child,
    .ac-tag-row:last-child {
        border-bottom: 0;
    }

    .ac-phone-row:hover,
    .ac-tag-row:hover {
        background: var(--ac-surface-muted);
    }

    .ac-phone-row__number {
        flex: 1 1 auto;
        min-width: 0;
        overflow: hidden;
        color: var(--ac-text);
        font-size: 0.84rem;
        font-weight: 700;
        line-height: 1.25;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ac-phone-row__primary {
        display: inline-flex;
        align-items: center;
        flex: 0 0 auto;
        min-height: 1.15rem;
        padding: 0.08rem 0.34rem;
        border-radius: var(--ac-radius-pill);
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
        font-size: 0.62rem;
        font-weight: 800;
        line-height: 1;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }

    .ac-phone-row__meta,
    .ac-tag-row__meta {
        min-width: 0;
        color: var(--ac-text-muted);
        font-size: 0.74rem;
        font-weight: 500;
        line-height: 1.25;
    }

    .ac-phone-row__actions {
        display: inline-flex;
        align-items: center;
        flex: 0 0 auto;
        gap: 0.5rem;
        margin-inline-start: auto;
        opacity: 0.72;
        transition: opacity 140ms ease;
    }

    .ac-phone-row:hover .ac-phone-row__actions,
    .ac-phone-row__actions:focus-within {
        opacity: 1;
    }

    .ac-tag-row {
        justify-content: space-between;
    }

    .ac-tag-row__body {
        display: inline-flex;
        align-items: center;
        min-width: 0;
        gap: 0.45rem;
    }

    .ac-contact-danger-zone {
        justify-content: flex-end;
        padding: 0.75rem 0 0;
        border: 0;
        border-top: 1px dashed var(--ac-border);
        border-radius: 0;
        background: transparent;
    }

    .ac-contact-danger-zone__text {
        display: none;
    }

    .fi-resource-dialogs {
        --ac-dialogs-row-h: 3.25rem;
    }

    .fi-resource-dialogs .fi-page-header-main-ctn > .fi-header {
        display: none;
    }

    .fi-resource-dialogs .fi-header {
        align-items: center;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
        padding: 0 0 0.65rem;
    }

    .fi-resource-dialogs .fi-header-heading {
        font-size: 1.55rem;
        font-weight: 760;
        letter-spacing: -0.035em;
    }

    .fi-resource-dialogs .fi-breadcrumbs-list {
        margin-bottom: 0.25rem;
    }

    .fi-resource-dialogs .fi-header-actions-ctn .fi-btn {
        min-height: 2.15rem;
        border: 1px solid var(--ac-border);
        border-radius: 8px;
        background: var(--ac-surface) !important;
        color: var(--ac-text) !important;
        box-shadow: none;
    }

    .fi-resource-dialogs .fi-header-actions-ctn .fi-btn :is(.fi-btn-label, .fi-icon) {
        color: inherit !important;
    }

    .fi-resource-dialogs .fi-header-actions-ctn .fi-btn:hover {
        border-color: color-mix(in srgb, var(--ac-primary) 26%, var(--ac-border));
        background: var(--ac-primary-soft) !important;
        color: var(--ac-primary) !important;
    }

    .fi-resource-dialogs .fi-ta-ctn {
        overflow: visible;
        border-radius: 12px;
        background: var(--ac-surface);
        box-shadow: none;
    }

    .fi-resource-dialogs .fi-ta-header-ctn {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        align-items: center;
        gap: 0.7rem;
        padding: 0.75rem 0.85rem;
    }

    .fi-resource-dialogs .fi-ta-header {
        align-items: center;
        gap: 0.7rem;
        min-width: 0;
    }

    .fi-resource-dialogs .fi-ta-header-heading {
        margin: 0;
        font-size: 1.55rem;
        font-weight: 760;
        line-height: 1;
        letter-spacing: -0.035em;
        color: var(--ac-text);
    }

    .fi-resource-dialogs .fi-ta-header .fi-ta-actions {
        gap: 0.45rem;
        flex-wrap: nowrap;
    }

    .fi-resource-dialogs .fi-ta-header .fi-btn {
        min-height: 2.15rem;
        border: 1px solid var(--ac-border);
        border-radius: 8px;
        background: var(--ac-surface) !important;
        color: var(--ac-text) !important;
        box-shadow: none;
    }

    .fi-resource-dialogs .fi-ta-header-toolbar {
        min-width: 0;
        margin-top: 0;
        padding: 0;
        border-top: 0;
        border-bottom: 0;
        justify-content: flex-start;
    }

    .fi-resource-dialogs .fi-ta-header-toolbar > :first-child:empty {
        display: none;
    }

    .fi-resource-dialogs .fi-ta-header-toolbar > :last-child {
        width: 100%;
        min-width: 0;
        margin-inline-start: 0;
        justify-content: flex-start;
        gap: 0.5rem;
        flex-wrap: nowrap;
    }

    :is(.fi-resource-dialogs, .fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-header-toolbar > :last-child > .fi-ta-search-field {
        flex: 0 1 17.5rem;
        min-width: 17.5rem;
    }

    :is(.fi-resource-dialogs, .fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-search-field .fi-input-wrp {
        display: flex;
        align-items: center;
        min-height: 2.15rem;
        overflow: hidden;
        border-color: var(--ac-border);
        border-radius: 8px;
        background: var(--ac-surface-muted);
        box-shadow: none;
    }

    :is(.fi-resource-dialogs, .fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-search-field .fi-input-wrp-content-ctn {
        order: 2;
        min-width: 0;
    }

    :is(.fi-resource-dialogs, .fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-search-field .fi-input-wrp-prefix,
    :is(.fi-resource-dialogs, .fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-search-field .fi-input-wrp-suffix {
        order: 1;
        min-height: 2.15rem;
        border: 0;
        background: transparent;
        color: var(--ac-text-soft);
    }

    :is(.fi-resource-dialogs, .fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-search-field .fi-input {
        min-height: 2.15rem;
        color: var(--ac-text);
        font-size: 0.82rem;
        font-weight: 500;
    }

    :is(.fi-resource-dialogs, .fi-resource-contacts, .fi-resource-tags, .fi-resource-channels) .fi-ta-search-field .fi-input::placeholder {
        color: var(--ac-text-soft);
    }

    .fi-resource-dialogs .fi-pagination-records-per-page-select .fi-input-wrp {
        min-height: 2.25rem;
        border-color: var(--ac-border);
        background: var(--ac-surface-muted);
        box-shadow: none;
    }

    .fi-resource-dialogs .fi-pagination-records-per-page-select .fi-select-input,
    .fi-resource-dialogs .fi-pagination-records-per-page-select .fi-select-input-btn {
        min-height: 2.25rem;
        font-size: 0.82rem;
    }

    .fi-resource-dialogs .fi-ta-header-toolbar .fi-btn,
    .fi-resource-dialogs .fi-ta-header-toolbar .fi-icon-btn {
        min-height: 2.25rem;
        border-radius: 8px;
        box-shadow: none;
    }

    .fi-resource-dialogs .fi-ta-header-toolbar .ac-dialogs-filter-trigger.fi-btn {
        min-height: 2.25rem;
        border-color: var(--ac-border);
        border-radius: 8px;
        background: var(--ac-surface) !important;
        color: var(--ac-text) !important;
        font-size: 0.84rem;
        font-weight: 700;
        box-shadow: none;
    }

    .fi-resource-dialogs .fi-ta-header-toolbar .ac-dialogs-filter-trigger.fi-btn:hover {
        border-color: color-mix(in srgb, var(--ac-primary) 28%, var(--ac-border));
        background: var(--ac-primary-soft) !important;
        color: var(--ac-primary) !important;
    }

    .fi-resource-dialogs .fi-ta-filters-dropdown > .fi-dropdown-panel {
        width: min(22rem, calc(100vw - 2rem)) !important;
        max-height: min(72vh, calc(100dvh - 8.5rem));
        overflow: hidden;
        border: 1px solid var(--ac-border);
        border-radius: 12px;
        background: var(--ac-surface) !important;
        box-shadow: var(--ac-shadow-pop);
        z-index: 80;
    }

    .fi-resource-dialogs .fi-ta-filters-dropdown > .fi-dropdown-panel .fi-ta-filters {
        max-height: min(68vh, calc(100dvh - 10rem));
        overflow-y: auto;
        overscroll-behavior: contain;
        background: var(--ac-surface);
        scrollbar-gutter: stable;
    }

    .fi-resource-dialogs .fi-ta-filters-dropdown > .fi-dropdown-panel .fi-ta-filters::-webkit-scrollbar {
        width: 0.55rem;
    }

    .fi-resource-dialogs .fi-ta-filters-dropdown > .fi-dropdown-panel .fi-ta-filters::-webkit-scrollbar-track {
        background: transparent;
    }

    .fi-resource-dialogs .fi-ta-filters-dropdown > .fi-dropdown-panel .fi-ta-filters::-webkit-scrollbar-thumb {
        border: 2px solid var(--ac-surface);
        border-radius: 999px;
        background: color-mix(in srgb, var(--ac-text-soft) 54%, transparent);
    }

    .fi-resource-dialogs .fi-ta-filter-indicators {
        margin: 0;
        padding: 0.55rem 0.85rem;
        border-top: 1px solid var(--ac-border);
        background: var(--ac-surface);
    }

    .fi-resource-dialogs .fi-ta-filter-indicators-label {
        font-size: 0.7rem;
        letter-spacing: 0.06em;
    }

    .fi-resource-dialogs .fi-ta-selection-indicator {
        margin: 0;
        padding: 0.55rem 0.85rem;
        border-top: 1px solid var(--ac-border);
        background: var(--ac-surface);
        color: var(--ac-text);
        font-size: 0.82rem;
    }

    .fi-resource-dialogs .fi-ta-selection-indicator-actions-ctn {
        gap: 0.75rem;
    }

    .fi-resource-dialogs .fi-ta-content-ctn {
        overflow-x: auto;
        border-top: 1px solid var(--ac-border);
        border-inline-end: 1px solid var(--ac-border);
    }

    .fi-resource-dialogs .fi-ta-content-ctn::-webkit-scrollbar {
        height: 0.7rem;
    }

    .fi-resource-dialogs .fi-ta-content-ctn::-webkit-scrollbar-track {
        background: var(--ac-surface-muted);
    }

    .fi-resource-dialogs .fi-ta-content-ctn::-webkit-scrollbar-thumb {
        border: 2px solid var(--ac-surface-muted);
        border-radius: 999px;
        background: color-mix(in srgb, var(--ac-text-soft) 56%, transparent);
    }

    .fi-resource-dialogs .fi-ta-table {
        min-width: max(100%, 88rem);
        table-layout: fixed;
        border-collapse: separate;
        border-spacing: 0;
    }

    .fi-resource-dialogs .fi-ta-table thead {
        background: var(--ac-surface-muted);
    }

    .fi-resource-dialogs .fi-ta-table th,
    .fi-resource-dialogs .fi-ta-table td {
        height: var(--ac-dialogs-row-h);
        border-color: var(--ac-border);
        vertical-align: middle;
    }

    .fi-resource-dialogs .fi-ta-table th {
        padding: 0.45rem 0.7rem;
    }

    .fi-resource-dialogs .fi-ta-selection-cell,
    .fi-resource-dialogs .fi-ta-table th:first-child:has(.fi-checkbox-input) {
        position: relative;
        width: 3rem;
        min-width: 3rem;
        max-width: 3rem;
        text-align: center;
    }

    .fi-resource-dialogs .fi-ta-selection-cell .fi-ta-col {
        justify-content: center;
        min-height: var(--ac-dialogs-row-h);
        padding-inline: 0.45rem;
    }

    .fi-resource-dialogs .fi-ta-table .fi-checkbox-input {
        position: relative;
        z-index: 2;
        width: 1rem;
        height: 1rem;
        border-radius: 4px;
        accent-color: var(--ac-primary);
    }

    .fi-resource-dialogs .fi-ta-selection-cell .ac-dialogs-selection-hitbox {
        position: absolute;
        inset: 0;
        z-index: 1;
        cursor: pointer;
    }

    .fi-resource-dialogs .fi-ta-header-cell-contact-label,
    .fi-resource-dialogs .fi-ta-cell-contact-label {
        width: 8rem;
        min-width: 8rem;
    }

    .fi-resource-dialogs .fi-ta-header-cell-inbox-status,
    .fi-resource-dialogs .fi-ta-cell-inbox-status {
        width: 7.5rem;
        min-width: 7.5rem;
    }

    .fi-resource-dialogs .fi-ta-header-cell-stage,
    .fi-resource-dialogs .fi-ta-cell-stage {
        width: 8.5rem;
        min-width: 8.5rem;
    }

    .fi-resource-dialogs .fi-ta-header-cell-assigned-user,
    .fi-resource-dialogs .fi-ta-cell-assigned-user {
        width: 7rem;
        min-width: 7rem;
    }

    .fi-resource-dialogs .fi-ta-header-cell-channel-label,
    .fi-resource-dialogs .fi-ta-cell-channel-label {
        width: 13rem;
        min-width: 13rem;
    }

    .fi-resource-dialogs .fi-ta-header-cell-route-status,
    .fi-resource-dialogs .fi-ta-cell-route-status {
        width: 9.5rem;
        min-width: 9.5rem;
    }

    .fi-resource-dialogs .fi-ta-header-cell-preview-sender-label,
    .fi-resource-dialogs .fi-ta-cell-preview-sender-label {
        width: 6.5rem;
        min-width: 6.5rem;
    }

    .fi-resource-dialogs .fi-ta-header-cell-preview-text,
    .fi-resource-dialogs .fi-ta-cell-preview-text {
        width: 22rem;
        min-width: 22rem;
    }

    .fi-resource-dialogs .fi-ta-header-cell-last-message-at,
    .fi-resource-dialogs .fi-ta-cell-last-message-at {
        width: 8.5rem;
        min-width: 8.5rem;
    }

    .fi-resource-dialogs .fi-ta-header-cell-id,
    .fi-resource-dialogs .fi-ta-cell-id {
        width: 4.5rem;
        min-width: 4.5rem;
    }

    .fi-resource-dialogs .fi-ta-header-cell-external-user-id,
    .fi-resource-dialogs .fi-ta-cell-external-user-id,
    .fi-resource-dialogs .fi-ta-header-cell-external-chat-id,
    .fi-resource-dialogs .fi-ta-cell-external-chat-id {
        width: 8rem;
        min-width: 8rem;
    }

    .fi-resource-dialogs .fi-ta-header-cell-external-username,
    .fi-resource-dialogs .fi-ta-cell-external-username,
    .fi-resource-dialogs .fi-ta-header-cell-phone-label,
    .fi-resource-dialogs .fi-ta-cell-phone-label,
    .fi-resource-dialogs .fi-ta-header-cell-route-source,
    .fi-resource-dialogs .fi-ta-cell-route-source {
        width: 10rem;
        min-width: 10rem;
    }

    .fi-resource-dialogs .fi-ta-table :is(th, td):last-child {
        border-inline-end: 1px solid var(--ac-border);
    }

    .fi-resource-dialogs .fi-ta-table th {
        color: var(--ac-text-muted);
        font-size: 0.72rem;
        font-weight: 760;
        line-height: 1.15;
    }

    .fi-resource-dialogs .fi-ta-table td {
        color: var(--ac-text);
        font-size: 0.82rem;
        line-height: 1.3;
        padding: 0 !important;
    }

    .fi-resource-dialogs .fi-ta-table .fi-ta-text,
    .fi-resource-dialogs .fi-ta-table .fi-ta-text-item,
    .fi-resource-dialogs .fi-ta-table .fi-ta-text-description {
        min-width: 0;
        max-width: 100%;
    }

    .fi-resource-dialogs .fi-ta-table .fi-ta-col {
        box-sizing: border-box;
        min-height: 3.875rem;
        width: 100%;
        padding: 0.45rem 0.7rem;
        align-items: center;
    }

    .fi-resource-dialogs .fi-ta-table .fi-ta-text {
        gap: 0.12rem;
        padding-block: 0 !important;
    }

    .fi-resource-dialogs .fi-ta-table .fi-ta-text-item,
    .fi-resource-dialogs .fi-ta-table .fi-ta-text-description {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .fi-resource-dialogs .fi-ta-cell-preview-text .fi-ta-text-item {
        display: -webkit-box;
        overflow: hidden;
        white-space: normal;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }

    .fi-resource-dialogs .fi-ta-cell-preview-text .fi-ta-text-description {
        margin-top: 0.12rem;
    }

    .fi-resource-dialogs .fi-ta-table td a {
        color: inherit;
        text-decoration: none;
    }

    .fi-resource-dialogs .fi-ta-table td a:hover {
        color: var(--ac-primary);
    }

    .fi-resource-dialogs .fi-ta-table tbody tr {
        transition: background 140ms ease;
    }

    .fi-resource-dialogs .fi-ta-table tbody tr:hover {
        background: var(--ac-surface-muted);
    }

    .fi-resource-dialogs .fi-ta-table .fi-badge {
        min-height: 1.25rem;
        padding: 0.14rem 0.42rem;
        font-size: 0.68rem;
        font-weight: 760;
        line-height: 1;
    }

    .fi-resource-dialogs .fi-pagination {
        gap: 0.75rem;
        border-top: 1px solid var(--ac-border);
        padding: 0.65rem 0.85rem;
        background: var(--ac-surface);
    }

    .fi-resource-dialogs .fi-pagination-overview {
        color: var(--ac-text-muted);
        font-size: 0.8rem;
    }

    .fi-resource-dialogs .fi-ta-col-manager-dropdown {
        display: none !important;
    }

    .fi-resource-dialogs .fi-ta-header-toolbar {
        align-items: center;
        gap: 0.5rem;
    }

    .fi-resource-dialogs .ac-dialogs-table-tools {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        flex: 0 0 auto;
        margin-inline-start: auto;
    }

    .fi-resource-dialogs .ac-dialogs-view-switch {
        flex: 0 0 auto;
    }

    @media (max-width: 980px) {
        .fi-resource-dialogs .fi-ta-header-toolbar > :last-child {
            flex-wrap: wrap;
        }

        .fi-resource-dialogs .ac-dialogs-table-tools {
            margin-inline-start: 0;
        }
    }

    @media (max-width: 820px) {
        .fi-resource-dialogs .fi-ta-header-toolbar > :last-child > .fi-ta-search-field {
            flex: 1 1 100%;
            min-width: 0;
        }
    }

    .fi-resource-dialogs .ac-dialogs-table-scroll {
        position: relative;
        scrollbar-gutter: stable;
        overscroll-behavior-x: contain;
        box-shadow: inset -2px 0 0 var(--ac-border-strong);
    }

    .fi-resource-dialogs .ac-dialogs-table-scroll.has-more-right {
        box-shadow:
            inset -2px 0 0 var(--ac-border-strong),
            inset -22px 0 20px -24px rgba(15, 23, 42, 0.45);
    }

    .fi-resource-dialogs .ac-dialogs-table-scroll.has-more-left {
        box-shadow:
            inset 22px 0 20px -24px rgba(15, 23, 42, 0.36),
            inset -2px 0 0 var(--ac-border-strong);
    }

    .fi-resource-dialogs .ac-dialogs-table-scroll.has-more-left.has-more-right {
        box-shadow:
            inset 22px 0 20px -24px rgba(15, 23, 42, 0.36),
            inset -2px 0 0 var(--ac-border-strong),
            inset -22px 0 20px -24px rgba(15, 23, 42, 0.45);
    }

    .fi-resource-dialogs .ac-dialogs-table-scroll .fi-ta-table {
        max-width: none;
        width: auto;
    }

    .fi-resource-dialogs .fi-ta-table th[data-ac-dialogs-last-visible="1"],
    .fi-resource-dialogs .fi-ta-table td[data-ac-dialogs-last-visible="1"] {
        border-inline-end: 2px solid var(--ac-border-strong) !important;
    }

    .fi-resource-dialogs .fi-ta-header-cell {
        position: relative;
        overflow: visible;
    }

    .fi-resource-dialogs .fi-ta-header-cell-sort-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        min-width: 0;
        position: relative;
        z-index: 3;
        color: inherit;
        text-decoration: none;
        cursor: pointer;
    }

    .fi-resource-dialogs .ac-dialogs-sort-link:hover {
        color: var(--ac-primary);
    }

    .fi-resource-dialogs .ac-dialogs-resize-handle {
        position: absolute;
        z-index: 4;
        top: 0;
        right: -0.18rem;
        bottom: 0;
        width: 0.45rem;
        border: 0;
        border-radius: 999px;
        background: transparent;
        cursor: col-resize;
    }

    .fi-resource-dialogs .ac-dialogs-resize-handle::after {
        content: "";
        position: absolute;
        top: 0.34rem;
        right: 0.18rem;
        bottom: 0.34rem;
        width: 2px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--ac-border-strong) 74%, transparent);
        transition: background 120ms ease, width 120ms ease;
    }

    .fi-resource-dialogs .fi-ta-header-cell:hover .ac-dialogs-resize-handle::after,
    .fi-resource-dialogs .ac-dialogs-resize-handle:hover::after,
    .ac-dialogs-table-is-resizing .ac-dialogs-resize-handle::after {
        width: 3px;
        background: var(--ac-primary);
    }

    .ac-dialogs-table-is-resizing,
    .ac-dialogs-table-is-resizing * {
        cursor: col-resize !important;
        user-select: none !important;
    }

    .fi-resource-dialogs .ac-dialogs-scroll-buttons {
        display: inline-flex;
        align-items: center;
        overflow: hidden;
        border: 1px solid var(--ac-border);
        border-radius: 8px;
        background: var(--ac-surface);
    }

    .fi-resource-dialogs .ac-dialogs-scroll-buttons button,
    .fi-resource-dialogs .ac-dialogs-columns-button {
        min-height: 2.15rem;
        border: 0;
        background: transparent;
        color: var(--ac-text);
        font-size: 0.82rem;
        font-weight: 700;
        line-height: 1;
        cursor: pointer;
    }

    .fi-resource-dialogs .ac-dialogs-scroll-buttons button {
        width: 2rem;
        border-inline-end: 1px solid var(--ac-border);
    }

    .fi-resource-dialogs .ac-dialogs-scroll-buttons button:last-child {
        border-inline-end: 0;
    }

    .fi-resource-dialogs .ac-dialogs-scroll-buttons button:hover:not(:disabled),
    .fi-resource-dialogs .ac-dialogs-columns-button:hover {
        background: var(--ac-primary-soft);
        color: var(--ac-primary);
    }

    .fi-resource-dialogs .ac-dialogs-scroll-buttons button:disabled {
        cursor: default;
        opacity: 0.38;
    }

    .fi-resource-dialogs .ac-dialogs-columns {
        position: relative;
    }

    .fi-resource-dialogs .ac-dialogs-columns[open] {
        z-index: 50;
    }

    .fi-resource-dialogs .ac-dialogs-columns > summary {
        list-style: none;
    }

    .fi-resource-dialogs .ac-dialogs-columns > summary::-webkit-details-marker {
        display: none;
    }

    .fi-resource-dialogs .ac-dialogs-columns-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.42rem;
        border: 1px solid var(--ac-border);
        border-radius: 8px;
        padding: 0.3rem 0.62rem;
        background: var(--ac-surface);
    }

    .fi-resource-dialogs .ac-dialogs-columns-gear {
        position: relative;
        width: 2.3rem;
        height: 2.3rem;
        min-height: 2.3rem;
        border-color: #e5e3df;
        border-radius: 0.6rem;
        color: #777;
    }

    .fi-resource-dialogs .ac-dialogs-columns-button-count {
        color: var(--ac-text-muted);
        font-weight: 600;
    }

    .fi-resource-dialogs .ac-dialogs-columns-gear .ac-dialogs-columns-button-count {
        position: absolute;
        top: -0.38rem;
        right: -0.38rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.25rem;
        min-height: 1.25rem;
        border-radius: 999px;
        background: color-mix(in srgb, var(--ac-warning) 18%, var(--ac-surface-strong));
        color: var(--ac-warning);
        font-size: 0.64rem;
        font-weight: 800;
        line-height: 1;
    }

    .fi-resource-dialogs .ac-dialogs-columns-popover {
        position: absolute;
        top: calc(100% + 0.45rem);
        right: 0;
        width: 17.5rem;
        max-width: calc(100vw - 2rem);
        border: 1px solid var(--ac-border);
        border-radius: 12px;
        background: var(--ac-surface);
        box-shadow: 0 22px 46px -32px rgba(15, 23, 42, 0.55);
        padding: 0.55rem;
    }

    .fi-resource-dialogs .ac-dialogs-columns-popover-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.12rem 0.22rem 0.45rem;
    }

    .fi-resource-dialogs .ac-dialogs-columns-title {
        color: var(--ac-text);
        font-size: 0.8rem;
        font-weight: 750;
    }

    .fi-resource-dialogs .ac-dialogs-columns-message {
        min-height: 1rem;
        margin: 0 0 0.3rem;
        padding-inline: 0.22rem;
        color: var(--ac-danger);
        font-size: 0.72rem;
        font-weight: 650;
    }

    .fi-resource-dialogs .ac-dialogs-columns-list {
        display: grid;
        gap: 0.14rem;
        max-height: 21rem;
        overflow-y: auto;
        padding-right: 0.12rem;
    }

    .fi-resource-dialogs .ac-dialogs-columns-row {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        border-radius: 8px;
        padding: 0.32rem 0.36rem;
        color: var(--ac-text);
        font-size: 0.8rem;
        line-height: 1.2;
        cursor: grab;
    }

    .fi-resource-dialogs .ac-dialogs-columns-row:hover,
    .fi-resource-dialogs .ac-dialogs-columns-row.is-dragging {
        background: var(--ac-primary-soft);
    }

    .fi-resource-dialogs .ac-dialogs-columns-row.is-dragging {
        opacity: 0.6;
        outline: 1px dashed var(--ac-primary);
    }

    .fi-resource-dialogs .ac-dialogs-columns-row.is-fixed {
        cursor: default;
    }

    .fi-resource-dialogs .ac-dialogs-columns-row.is-fixed .ac-dialogs-columns-drag {
        visibility: hidden;
        cursor: default;
    }

    .fi-resource-dialogs .ac-dialogs-columns-drag {
        width: 1rem;
        color: var(--ac-text-soft);
        font-size: 0.82rem;
        letter-spacing: -0.2em;
        cursor: grab;
        user-select: none;
    }

    .fi-resource-dialogs .ac-dialogs-columns-check {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        min-width: 0;
        flex: 1 1 auto;
        cursor: pointer;
    }

    .fi-resource-dialogs .ac-dialogs-columns-check input {
        width: 0.92rem;
        height: 0.92rem;
        accent-color: var(--ac-primary);
    }

    .fi-resource-dialogs .ac-dialogs-columns-actions {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.38rem;
        margin-top: 0.5rem;
        border-top: 1px solid var(--ac-border);
        padding-top: 0.5rem;
    }

    .fi-resource-dialogs .ac-dialogs-columns-action {
        min-height: 1.95rem;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: var(--ac-primary);
        font-size: 0.76rem;
        font-weight: 750;
        cursor: pointer;
    }

    .fi-resource-dialogs .ac-dialogs-columns-action:hover {
        background: var(--ac-primary-soft);
    }

    .fi-resource-dialogs .ac-dialogs-columns-apply {
        background: var(--ac-primary);
        color: var(--ac-text-inverse);
    }

    .fi-resource-dialogs .ac-dialogs-columns-apply:hover {
        background: var(--ac-primary-hover);
    }

    @media (max-width: 1140px) {
        .ac-contact-page__layout,
        .ac-contact-stats,
        .ac-contact-form-grid,
        .ac-contact-page .ac-field-grid,
        .ac-dialog-overview,
        .ac-dialog-workspace {
            grid-template-columns: minmax(0, 1fr);
        }

        .ac-dialog-summary__sections {
            grid-template-columns: minmax(0, 1fr);
        }

        .ac-surface.ac-dialog-chat-panel {
            --ac-dialog-chat-panel-height: auto;
            min-height: 0;
            height: auto;
        }

        .ac-dialog-chat-panel .ac-thread {
            height: auto;
            min-height: 18rem;
            max-height: 32rem;
        }

        .ac-dialog-side-list .ac-meta {
            grid-template-columns: minmax(0, 1fr);
            gap: 0.25rem;
        }

        .ac-dialog-side-list .ac-meta__value {
            text-align: left;
        }

        .ac-dialog-side-list .ac-select {
            justify-self: stretch;
        }

        .ac-dialog-status-toggle {
            justify-self: stretch;
        }

        .ac-dialog-assignee-editor {
            justify-self: stretch;
            flex-wrap: wrap;
        }

        .ac-dialog-assignee-editor .ac-select {
            flex: 1 1 11rem;
            width: auto;
            max-width: 100%;
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

    .ac-analytics,
    .ac-analytics * {
        box-sizing: border-box;
    }

    .ac-analytics {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        width: min(100%, 1180px);
        color: var(--ac-text);
    }

    .ac-analytics-panel,
    .ac-analytics-card {
        border: 1px solid var(--ac-border);
        border-radius: var(--ac-radius-xl);
        background: var(--ac-surface);
        box-shadow: var(--ac-shadow-sm);
    }

    .ac-analytics-hero {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem;
    }

    .ac-analytics-hero__copy,
    .ac-analytics-panel__header,
    .ac-analytics-section__header,
    .ac-analytics-metric p {
        margin: 0;
    }

    .ac-analytics-eyebrow {
        margin: 0 0 0.35rem;
        color: var(--ac-text-soft);
        font-size: 0.74rem;
        font-weight: 700;
        line-height: 1.2;
        text-transform: uppercase;
    }

    .ac-analytics-hero__title {
        margin: 0;
        color: var(--ac-text);
        font-size: 1.1rem;
        font-weight: 800;
        line-height: 1.18;
    }

    .ac-analytics-hero__period {
        margin: 0.35rem 0 0;
        color: var(--ac-text-muted);
        font-size: 0.84rem;
        font-weight: 500;
        line-height: 1.35;
    }

    .ac-analytics-period {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        justify-content: flex-end;
        gap: 0.65rem;
    }

    .ac-analytics-period__presets,
    .ac-analytics-period__dates {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.45rem;
    }

    .ac-analytics-period__button {
        min-height: 2rem;
        border: 1px solid var(--ac-border);
        border-radius: var(--ac-radius-md);
        background: var(--ac-surface-muted);
        color: var(--ac-text-muted);
        padding: 0.35rem 0.65rem;
        font-size: 0.8rem;
        font-weight: 700;
        line-height: 1.2;
        transition: border-color 0.15s ease, background-color 0.15s ease, color 0.15s ease;
    }

    .ac-analytics-period__button:hover,
    .ac-analytics-period__button:focus-visible {
        border-color: var(--ac-primary);
        color: var(--ac-primary);
        outline: none;
    }

    .ac-analytics-period__button--active {
        border-color: var(--ac-primary);
        background: var(--ac-primary);
        color: var(--ac-text-inverse);
    }

    .ac-analytics-period__button--active:hover,
    .ac-analytics-period__button--active:focus-visible {
        color: var(--ac-text-inverse);
    }

    .ac-analytics-period__date {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        min-height: 2rem;
        border: 1px solid var(--ac-border-input);
        border-radius: var(--ac-radius-md);
        background: var(--ac-input-surface);
        padding: 0.2rem 0.5rem;
    }

    .ac-analytics-period__date span {
        color: var(--ac-text-soft);
        font-size: 0.74rem;
        font-weight: 700;
        line-height: 1.2;
    }

    .ac-analytics-period__date input {
        width: 8.7rem;
        border: 0;
        background: transparent;
        color: var(--ac-text);
        font-size: 0.8rem;
        font-weight: 650;
        line-height: 1.2;
        outline: none;
        color-scheme: light dark;
    }

    .ac-analytics-section {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
    }

    .ac-analytics-section__header,
    .ac-analytics-panel__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
    }

    .ac-analytics-panel__header {
        padding: 0.9rem 1rem 0;
    }

    .ac-analytics-section__header h2,
    .ac-analytics-panel__header h2 {
        margin: 0;
        color: var(--ac-text);
        font-size: 0.92rem;
        font-weight: 800;
        line-height: 1.25;
    }

    .ac-analytics-section__header span,
    .ac-analytics-panel__header span {
        color: var(--ac-text-soft);
        font-size: 0.74rem;
        font-weight: 700;
        line-height: 1.2;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .ac-analytics-metric-grid {
        display: grid;
        gap: 0.75rem;
    }

    .ac-analytics-metric-grid--period {
        grid-template-columns: repeat(5, minmax(0, 1fr));
    }

    .ac-analytics-metric-grid--snapshot {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .ac-analytics-metric {
        position: relative;
        min-height: 7.1rem;
        overflow: hidden;
        padding: 0.85rem;
    }

    .ac-analytics-metric::before {
        content: '';
        position: absolute;
        inset: 0 auto auto 0;
        width: 100%;
        height: 3px;
        background: var(--ac-metric-color, var(--ac-primary));
    }

    .ac-analytics-metric:nth-child(1) {
        --ac-metric-color: var(--ac-primary);
    }

    .ac-analytics-metric:nth-child(2) {
        --ac-metric-color: var(--ac-info);
    }

    .ac-analytics-metric:nth-child(3) {
        --ac-metric-color: var(--ac-danger);
    }

    .ac-analytics-metric:nth-child(4) {
        --ac-metric-color: var(--ac-success);
    }

    .ac-analytics-metric:nth-child(5) {
        --ac-metric-color: var(--ac-warning);
    }

    .ac-analytics-metric__label {
        color: var(--ac-text-muted);
        font-size: 0.78rem;
        font-weight: 700;
        line-height: 1.25;
    }

    .ac-analytics-metric__value {
        margin-top: 0.55rem !important;
        color: var(--ac-text);
        font-size: 1.75rem;
        font-weight: 850;
        line-height: 1;
    }

    .ac-analytics-metric__caption {
        margin-top: 0.45rem !important;
        color: var(--ac-text-soft);
        font-family: var(--ac-font-mono);
        font-size: 0.7rem;
        font-weight: 600;
        line-height: 1.25;
        overflow-wrap: anywhere;
    }

    .ac-analytics-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(20rem, 0.72fr);
        gap: 1rem;
    }

    .ac-analytics-stage-list,
    .ac-analytics-list {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
        padding: 1rem;
    }

    .ac-analytics-stage__meta,
    .ac-analytics-list__row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
    }

    .ac-analytics-stage__meta span,
    .ac-analytics-list__row span {
        min-width: 0;
        overflow: hidden;
        color: var(--ac-text-muted);
        font-size: 0.84rem;
        font-weight: 650;
        line-height: 1.25;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ac-analytics-stage__meta strong,
    .ac-analytics-list__row strong {
        color: var(--ac-text);
        font-size: 0.84rem;
        font-weight: 800;
        line-height: 1.25;
    }

    .ac-analytics-stage__track {
        height: 0.45rem;
        margin-top: 0.35rem;
        overflow: hidden;
        border-radius: var(--ac-radius-pill);
        background: var(--ac-surface-muted);
    }

    .ac-analytics-stage__bar {
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, var(--ac-primary), var(--ac-info));
    }

    .ac-analytics-list__row,
    .ac-analytics-empty {
        min-height: 2.35rem;
        border: 1px solid var(--ac-border);
        border-radius: var(--ac-radius-lg);
        background: var(--ac-surface-muted);
        padding: 0.55rem 0.65rem;
    }

    .ac-analytics-empty,
    .ac-analytics-table__empty {
        color: var(--ac-text-muted);
        font-size: 0.82rem;
        font-weight: 550;
        line-height: 1.35;
        text-align: center;
    }

    .ac-analytics-table-wrap {
        width: 100%;
        margin-top: 0.5rem;
        overflow-x: auto;
        padding: 0 1rem 1rem;
    }

    .ac-analytics-table {
        width: 100%;
        min-width: 42rem;
        border-collapse: collapse;
        color: var(--ac-text);
        font-size: 0.82rem;
        line-height: 1.35;
    }

    .ac-analytics-table th {
        padding: 0.55rem 0.6rem;
        border-bottom: 1px solid var(--ac-border);
        color: var(--ac-text-soft);
        font-size: 0.72rem;
        font-weight: 800;
        line-height: 1.2;
        text-align: left;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .ac-analytics-table td {
        padding: 0.65rem 0.6rem;
        border-bottom: 1px solid var(--ac-border);
        color: var(--ac-text-muted);
        font-weight: 600;
        vertical-align: middle;
    }

    .ac-analytics-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .ac-analytics-table__num {
        text-align: right !important;
        white-space: nowrap;
    }

    .ac-analytics-link {
        color: var(--ac-primary);
        font-weight: 800;
        text-decoration: none;
    }

    .ac-analytics-link:hover,
    .ac-analytics-link:focus-visible {
        color: var(--ac-primary-hover);
        text-decoration: underline;
        outline: none;
    }

    .ac-analytics-badge-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
    }

    .ac-analytics-badge {
        display: inline-flex;
        align-items: center;
        min-height: 1.45rem;
        border-radius: var(--ac-radius-pill);
        background: var(--ac-neutral-soft);
        color: var(--ac-text-muted);
        padding: 0.2rem 0.5rem;
        font-size: 0.72rem;
        font-weight: 750;
        line-height: 1.15;
        white-space: nowrap;
    }

    @media (max-width: 1180px) {
        .ac-analytics-metric-grid--period,
        .ac-analytics-metric-grid--snapshot {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .ac-analytics-layout {
            grid-template-columns: minmax(0, 1fr);
        }
    }

    @media (max-width: 720px) {
        .ac-analytics-hero {
            align-items: stretch;
            flex-direction: column;
        }

        .ac-analytics-period {
            justify-content: flex-start;
        }

        .ac-analytics-period__date {
            flex: 1 1 9rem;
        }

        .ac-analytics-period__date input {
            width: 100%;
        }

        .ac-analytics-metric-grid--period,
        .ac-analytics-metric-grid--snapshot {
            grid-template-columns: minmax(0, 1fr);
        }

        .ac-analytics-section__header,
        .ac-analytics-panel__header {
            align-items: flex-start;
            flex-direction: column;
            gap: 0.25rem;
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

@php
    $acDialogsTableColumnLayout = \App\Filament\Resources\Dialogs\DialogResource::getDialogsTableColumnLayoutConfig();
    $acDialogsTableUserId = auth()->id();
@endphp

<script>
    (() => {
        const columnConfig = @json($acDialogsTableColumnLayout);
        const userId = @json($acDialogsTableUserId);
        const storageKey = `ab.dialogs.table.columns.v1.user.${userId || 'guest'}`;
        const tableSelector = '.fi-resource-dialogs .fi-ta-table';
        const selectionCellSelector = `${tableSelector} tbody td.fi-ta-selection-cell, ${tableSelector} tbody td.fi-ta-group-selection-cell`;
        const toolbarSelector = '.fi-resource-dialogs .fi-ta-header-toolbar';
        const scrollSelector = '.fi-resource-dialogs .fi-ta-content-ctn';
        const configByFilament = new Map(columnConfig.map((column) => [column.filament, column]));
        const selectionColumnConfig = columnConfig.find((column) => column.id === 'selection') || null;
        let rowNavigationFallbackReady = false;
        let selectionCellClickGuardReady = false;
        let selectionCellClickSuppressedUntil = 0;
        let dialogSideFieldCopyListenerReady = false;
        let viewSwitchLoadingListenerReady = false;
        let viewSwitchLoadingResetTimer = null;
        const voiceWaveformCache = new Map();

        window.__acConversationVoicePlayersReady = true;

        const isDialogsIndex = () => {
            const path = window.location.pathname.replace(/\/+$/, '');

            return !!document.querySelector('.fi-resource-dialogs') && path === '/admin/dialogs';
        };

        const findSelectionCellTarget = (target) => target?.closest?.(selectionCellSelector) || null;

        const shouldIgnoreRowNavigationTarget = (target) => {
            if (!target?.closest) {
                return false;
            }

            return Boolean(findSelectionCellTarget(target))
                || Boolean(target.closest('button, input, select, textarea, summary, label, [role="button"], [role="checkbox"], [data-ac-dialogs-resize], .ac-dialogs-columns'));
        };

        const resetViewSwitchLoading = () => {
            if (viewSwitchLoadingResetTimer !== null) {
                window.clearTimeout(viewSwitchLoadingResetTimer);
                viewSwitchLoadingResetTimer = null;
            }

            document.querySelectorAll('.ac-kanban-view-switch.is-loading').forEach((switcher) => {
                switcher.classList.remove('is-loading');
                switcher.removeAttribute('aria-busy');
            });

            document.querySelectorAll('[data-ac-dialogs-view-link].is-loading').forEach((link) => {
                link.classList.remove('is-loading');
                link.removeAttribute('aria-busy');
            });
        };

        const copyTextToClipboard = async (text) => {
            if (window.navigator?.clipboard?.writeText) {
                try {
                    await window.navigator.clipboard.writeText(text);

                    return true;
                } catch (error) {
                    // Some embedded browser shells expose the API but reject writes.
                }
            }

            const input = document.createElement('textarea');
            input.value = text;
            input.setAttribute('readonly', '');
            input.style.position = 'fixed';
            input.style.opacity = '0';
            input.style.pointerEvents = 'none';
            document.body.appendChild(input);
            input.focus();
            input.select();

            let copied = false;

            try {
                copied = document.execCommand('copy');
            } finally {
                input.remove();
            }

            return copied;
        };

        const markDialogSideFieldCopied = (row) => {
            row.classList.add('is-copied');
            row.dataset.copyState = 'copied';
            window.clearTimeout(Number(row.dataset.copyTimer || 0));
            row.dataset.copyTimer = String(window.setTimeout(() => {
                row.classList.remove('is-copied');
                delete row.dataset.copyState;
                delete row.dataset.copyTimer;
            }, 1200));
        };

        const copyDialogSideFieldValue = async (row) => {
            const value = String(row?.dataset?.copyValue || '').trim();

            if (value === '' || value === '—') {
                return;
            }

            if (await copyTextToClipboard(value)) {
                markDialogSideFieldCopied(row);
            }
        };

        const installDialogSideFieldCopyListener = () => {
            if (dialogSideFieldCopyListenerReady) {
                return;
            }

            dialogSideFieldCopyListenerReady = true;

            document.addEventListener('click', (event) => {
                const row = event.target?.closest?.('[data-role="dialog-side-field-row"].is-copyable');

                if (! row) {
                    return;
                }

                void copyDialogSideFieldValue(row);
            });

            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                const row = event.target?.closest?.('[data-role="dialog-side-field-row"].is-copyable');

                if (! row) {
                    return;
                }

                event.preventDefault();
                void copyDialogSideFieldValue(row);
            });
        };

        const installViewSwitchLoadingListener = () => {
            if (viewSwitchLoadingListenerReady) {
                return;
            }

            viewSwitchLoadingListenerReady = true;

            document.addEventListener('click', (event) => {
                const link = event.target?.closest?.('[data-ac-dialogs-view-link]');

                if (!link || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }

                const href = link.getAttribute('href');

                if (!href) {
                    return;
                }

                const targetUrl = new URL(href, window.location.href);

                if (targetUrl.href === window.location.href) {
                    return;
                }

                const switcher = link.closest('.ac-kanban-view-switch');
                switcher?.classList.add('is-loading');
                switcher?.setAttribute('aria-busy', 'true');
                link.classList.add('is-loading');
                link.setAttribute('aria-busy', 'true');

                viewSwitchLoadingResetTimer = window.setTimeout(resetViewSwitchLoading, 12000);
            }, true);

            document.addEventListener('livewire:navigated', resetViewSwitchLoading);
            window.addEventListener('pageshow', resetViewSwitchLoading);
        };

        const formatVoiceTime = (seconds) => {
            if (!Number.isFinite(seconds) || seconds < 0) {
                return '0:00';
            }

            const rounded = Math.floor(seconds);
            const minutes = Math.floor(rounded / 60);
            const rest = String(rounded % 60).padStart(2, '0');

            return `${minutes}:${rest}`;
        };

        const voiceNodes = (player) => ({
            audio: player.querySelector('[data-role="conversation-attachment-audio"]'),
            toggle: player.querySelector('[data-role="conversation-voice-toggle"]'),
            playIcon: player.querySelector('[data-role="conversation-voice-play-icon"]'),
            pauseIcon: player.querySelector('[data-role="conversation-voice-pause-icon"]'),
            waveform: player.querySelector('[data-role="conversation-voice-waveform"]'),
            bars: Array.from(player.querySelectorAll('[data-role="conversation-voice-waveform-bar"]')),
            time: player.querySelector('[data-role="conversation-voice-time"]'),
        });

        const updateVoicePlaybackState = (player) => {
            const nodes = voiceNodes(player);

            if (!nodes.audio) {
                return;
            }

            const decodedDuration = Number(player.dataset.voiceDuration || 0);
            const duration = Number.isFinite(nodes.audio.duration) && nodes.audio.duration > 0
                ? nodes.audio.duration
                : (Number.isFinite(decodedDuration) && decodedDuration > 0 ? decodedDuration : 0);
            const currentTime = Number.isFinite(nodes.audio.currentTime) ? nodes.audio.currentTime : 0;
            const progress = duration > 0 ? Math.min(1, Math.max(0, currentTime / duration)) : 0;
            const isPlaying = !nodes.audio.paused && !nodes.audio.ended;

            player.dataset.playing = isPlaying ? 'true' : 'false';
            player.style.setProperty('--ac-voice-progress', `${Math.round(progress * 100)}%`);
            nodes.toggle?.setAttribute('aria-label', isPlaying ? 'Поставить голосовое на паузу' : 'Воспроизвести голосовое');
            nodes.toggle?.setAttribute('title', isPlaying ? 'Пауза' : 'Воспроизвести голосовое');

            if (nodes.playIcon) {
                nodes.playIcon.hidden = isPlaying;
            }

            if (nodes.pauseIcon) {
                nodes.pauseIcon.hidden = !isPlaying;
            }

            if (nodes.time) {
                nodes.time.textContent = isPlaying || currentTime > 0
                    ? formatVoiceTime(currentTime)
                    : formatVoiceTime(duration);
            }

            const activeBars = Math.round(progress * nodes.bars.length);
            nodes.bars.forEach((bar, index) => {
                bar.dataset.active = index < activeBars ? 'true' : 'false';
            });
        };

        const pauseOtherVoicePlayers = (currentPlayer) => {
            document.querySelectorAll('[data-role="conversation-voice-player"]').forEach((player) => {
                if (player === currentPlayer) {
                    return;
                }

                const audio = player.querySelector('[data-role="conversation-attachment-audio"]');

                audio?.pause();
                updateVoicePlaybackState(player);
            });
        };

        const videoNoteNodes = (player) => ({
            video: player.querySelector('[data-role="conversation-video-note-video"]'),
            toggle: player.querySelector('[data-role="conversation-video-note-toggle"]'),
            playIcon: player.querySelector('[data-role="conversation-video-note-play-icon"]'),
            pauseIcon: player.querySelector('[data-role="conversation-video-note-pause-icon"]'),
        });

        const updateVideoNotePlaybackState = (player) => {
            const nodes = videoNoteNodes(player);

            if (!nodes.video) {
                return;
            }

            const isPlaying = !nodes.video.paused && !nodes.video.ended;

            player.dataset.playing = isPlaying ? 'true' : 'false';
            nodes.toggle?.setAttribute('aria-label', isPlaying ? 'Поставить кружок на паузу' : 'Воспроизвести кружок');
            nodes.toggle?.setAttribute('title', isPlaying ? 'Пауза' : 'Воспроизвести кружок');

            if (nodes.playIcon) {
                nodes.playIcon.hidden = isPlaying;
            }

            if (nodes.pauseIcon) {
                nodes.pauseIcon.hidden = !isPlaying;
            }
        };

        const pauseOtherVideoNotePlayers = (currentPlayer) => {
            document.querySelectorAll('[data-role="conversation-video-note-player"]').forEach((player) => {
                if (player === currentPlayer) {
                    return;
                }

                const video = player.querySelector('[data-role="conversation-video-note-video"]');

                video?.pause();
                updateVideoNotePlaybackState(player);
            });
        };

        const decodeVoiceWaveform = async (audio, barCount) => {
            const src = audio.currentSrc || audio.src;

            if (!src) {
                return null;
            }

            if (voiceWaveformCache.has(src)) {
                return voiceWaveformCache.get(src);
            }

            if (!window.AudioContext && !window.webkitAudioContext) {
                return null;
            }

            const context = window.__acVoiceAudioContext ??= new (window.AudioContext || window.webkitAudioContext)();
            const response = await window.fetch(src, { credentials: 'same-origin' });

            if (!response.ok) {
                return null;
            }

            const buffer = await response.arrayBuffer();
            const decoded = await context.decodeAudioData(buffer.slice(0));
            const channel = decoded.getChannelData(0);
            const samplesPerBar = Math.max(1, Math.floor(channel.length / barCount));
            const values = [];

            for (let index = 0; index < barCount; index += 1) {
                const start = index * samplesPerBar;
                const end = Math.min(channel.length, start + samplesPerBar);
                let sum = 0;

                for (let cursor = start; cursor < end; cursor += 1) {
                    sum += Math.abs(channel[cursor]);
                }

                values.push(sum / Math.max(1, end - start));
            }

            const max = Math.max(...values, 0.01);
            const normalized = values.map((value) => Math.max(18, Math.round((value / max) * 100)));
            const result = {
                bars: normalized,
                duration: decoded.duration,
            };

            voiceWaveformCache.set(src, result);

            return result;
        };

        const hydrateVoiceWaveform = async (player) => {
            if (player.dataset.waveformHydrated === 'true' || player.dataset.waveformHydrating === 'true') {
                return;
            }

            const nodes = voiceNodes(player);

            if (!nodes.audio || nodes.bars.length === 0) {
                return;
            }

            player.dataset.waveformHydrating = 'true';

            try {
                const waveform = await decodeVoiceWaveform(nodes.audio, nodes.bars.length);
                const values = Array.isArray(waveform) ? waveform : waveform?.bars;

                if (!Array.isArray(values) || values.length !== nodes.bars.length) {
                    player.dataset.waveformHydrated = 'fallback';

                    return;
                }

                nodes.bars.forEach((bar, index) => {
                    bar.style.setProperty('--ac-voice-bar', `${values[index]}%`);
                });

                if (Number.isFinite(waveform?.duration) && waveform.duration > 0) {
                    player.dataset.voiceDuration = String(waveform.duration);
                    updateVoicePlaybackState(player);
                }

                player.dataset.waveformHydrated = 'true';
            } catch (error) {
                player.dataset.waveformHydrated = 'fallback';
            } finally {
                delete player.dataset.waveformHydrating;
            }
        };

        const hydrateVoiceWaveformWhenIdle = (player) => {
            if (player.dataset.waveformHydrated === 'true' || player.dataset.waveformHydrating === 'true') {
                return;
            }

            const hydrate = () => void hydrateVoiceWaveform(player);

            if (typeof window.requestIdleCallback === 'function') {
                window.requestIdleCallback(hydrate, { timeout: 1800 });

                return;
            }

            window.setTimeout(hydrate, 150);
        };

        const toggleVoicePlayer = async (player) => {
            const nodes = voiceNodes(player);

            if (!nodes.audio) {
                return;
            }

            if (nodes.audio.paused || nodes.audio.ended) {
                pauseOtherVoicePlayers(player);
                pauseOtherVideoNotePlayers(null);
                void hydrateVoiceWaveform(player);

                try {
                    await nodes.audio.play();
                } catch (error) {
                    return;
                }
            } else {
                nodes.audio.pause();
            }

            updateVoicePlaybackState(player);
        };

        const seekVoicePlayer = (player, event) => {
            const nodes = voiceNodes(player);

            if (!nodes.audio || !nodes.waveform || !Number.isFinite(nodes.audio.duration) || nodes.audio.duration <= 0) {
                return;
            }

            const rect = nodes.waveform.getBoundingClientRect();
            const ratio = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));

            nodes.audio.currentTime = nodes.audio.duration * ratio;
            updateVoicePlaybackState(player);
        };

        const initVoicePlayer = (player) => {
            if (player.dataset.voicePlayerReady === 'true') {
                return;
            }

            player.dataset.voicePlayerReady = 'true';

            const nodes = voiceNodes(player);

            const syncVoiceMetadata = () => {
                updateVoicePlaybackState(player);
                hydrateVoiceWaveformWhenIdle(player);
            };

            nodes.audio?.addEventListener('loadedmetadata', syncVoiceMetadata);
            nodes.audio?.addEventListener('timeupdate', () => updateVoicePlaybackState(player));
            nodes.audio?.addEventListener('play', () => updateVoicePlaybackState(player));
            nodes.audio?.addEventListener('pause', () => updateVoicePlaybackState(player));
            nodes.audio?.addEventListener('ended', () => updateVoicePlaybackState(player));
            nodes.toggle?.addEventListener('click', () => void toggleVoicePlayer(player));
            nodes.waveform?.addEventListener('click', (event) => seekVoicePlayer(player, event));

            if (nodes.audio && nodes.audio.readyState >= 1) {
                syncVoiceMetadata();
            }

            updateVoicePlaybackState(player);
        };

        const initVoicePlayers = () => {
            document.querySelectorAll('[data-role="conversation-voice-player"]').forEach(initVoicePlayer);
        };

        const toggleVideoNotePlayer = async (player) => {
            const nodes = videoNoteNodes(player);

            if (!nodes.video) {
                return;
            }

            if (nodes.video.paused || nodes.video.ended) {
                pauseOtherVideoNotePlayers(player);
                pauseOtherVoicePlayers(null);

                try {
                    await nodes.video.play();
                } catch (error) {
                    return;
                }
            } else {
                nodes.video.pause();
            }

            updateVideoNotePlaybackState(player);
        };

        const initVideoNotePlayer = (player) => {
            if (player.dataset.videoNotePlayerReady === 'true') {
                return;
            }

            player.dataset.videoNotePlayerReady = 'true';

            const nodes = videoNoteNodes(player);

            nodes.video?.addEventListener('loadedmetadata', () => updateVideoNotePlaybackState(player));
            nodes.video?.addEventListener('play', () => updateVideoNotePlaybackState(player));
            nodes.video?.addEventListener('pause', () => updateVideoNotePlaybackState(player));
            nodes.video?.addEventListener('ended', () => updateVideoNotePlaybackState(player));
            nodes.video?.addEventListener('click', () => void toggleVideoNotePlayer(player));
            nodes.toggle?.addEventListener('click', () => void toggleVideoNotePlayer(player));

            updateVideoNotePlaybackState(player);
        };

        const initVideoNotePlayers = () => {
            document.querySelectorAll('[data-role="conversation-video-note-player"]').forEach(initVideoNotePlayer);
        };

        const readSettings = () => {
            try {
                const parsed = JSON.parse(window.localStorage.getItem(storageKey) || '{}');

                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch (error) {
                return {};
            }
        };

        const writeSettings = (settings) => {
            window.localStorage.setItem(storageKey, JSON.stringify(settings));
        };

        const resetSettings = () => {
            window.localStorage.removeItem(storageKey);
        };

        const dialogsKanbanUrl = () => {
            const url = new URL(window.location.href);
            url.pathname = '/admin/dialogs/kanban';
            url.search = '';

            return url.toString();
        };

        const getColumnSuffixFromHeader = (header) => {
            const className = Array.from(header.classList)
                .find((name) => name.startsWith('fi-ta-header-cell-') && name !== 'fi-ta-header-cell');

            return className ? className.replace('fi-ta-header-cell-', '') : null;
        };

        const textLabel = (node, fallback) => {
            const text = (node?.textContent || '').replace(/\s+/g, ' ').trim();

            return text || fallback;
        };

        const normalizeSelectionColumn = (table) => {
            const header = Array.from(table.querySelectorAll('thead tr:first-child > th'))
                .find((node) => node.querySelector('.fi-checkbox-input'));

            if (!header) {
                return null;
            }

            return {
                id: selectionColumnConfig?.id || 'selection',
                suffix: '__selection',
                label: selectionColumnConfig?.label || 'Выбор строк',
                defaultWidth: selectionColumnConfig?.defaultWidth || 48,
                minWidth: selectionColumnConfig?.minWidth || 48,
                defaultVisible: selectionColumnConfig?.defaultVisible ?? true,
                defaultOrder: selectionColumnConfig?.defaultOrder ?? 0,
                header,
                isSelection: true,
                resizable: false,
                reorderable: false,
            };
        };

        const normalizeColumns = (table) => {
            const headers = Array.from(table.querySelectorAll('thead tr:first-child > th'));
            const selectionColumn = normalizeSelectionColumn(table);

            const dataColumns = headers
                .map((header, index) => {
                    const suffix = getColumnSuffixFromHeader(header);

                    if (!suffix) {
                        return null;
                    }

                    const configured = configByFilament.get(suffix);
                    const fallbackId = suffix.replaceAll('-', '_');
                    const defaultWidth = configured?.defaultWidth || 140;
                    const minWidth = configured?.minWidth || 90;

                    return {
                        id: configured?.id || fallbackId,
                        suffix,
                        label: configured?.label || textLabel(header, fallbackId),
                        defaultWidth,
                        minWidth,
                        defaultVisible: configured?.defaultVisible ?? false,
                        defaultOrder: configured?.defaultOrder ?? (1000 + index),
                        header,
                    };
                })
                .filter(Boolean);

            return selectionColumn ? [selectionColumn, ...dataColumns] : dataColumns;
        };

        const getCells = (table, column) => {
            if (column.isSelection) {
                return Array.from(table.querySelectorAll('tbody td.fi-ta-selection-cell, tbody td.fi-ta-group-selection-cell'));
            }

            return Array.from(table.querySelectorAll(`.fi-ta-cell-${column.suffix}`));
        };

        const getColumnElements = (table, column) => {
            return [column.header, ...getCells(table, column)].filter(Boolean);
        };

        const defaultStateFor = (columns) => {
            return {
                order: columns
                    .slice()
                    .sort((a, b) => a.defaultOrder - b.defaultOrder)
                    .map((column) => column.id),
                visible: Object.fromEntries(columns.map((column) => [column.id, column.defaultVisible])),
                widths: Object.fromEntries(columns.map((column) => [column.id, column.defaultWidth])),
            };
        };

        const hasVisibleDataColumn = (columns, visible) => {
            return columns.some((column) => !column.isSelection && visible[column.id]);
        };

        const normalizeOrder = (columns, order) => {
            const fixedIds = columns
                .filter((column) => column.reorderable === false)
                .sort((a, b) => a.defaultOrder - b.defaultOrder)
                .map((column) => column.id);
            const fixed = new Set(fixedIds);

            return [
                ...fixedIds,
                ...order.filter((id) => !fixed.has(id)),
            ];
        };

        const normalizeState = (columns, settings = {}) => {
            const defaults = defaultStateFor(columns);
            const known = new Set(columns.map((column) => column.id));
            const order = Array.isArray(settings.order)
                ? settings.order.filter((id) => known.has(id))
                : [];

            defaults.order.forEach((id) => {
                if (!order.includes(id)) {
                    order.push(id);
                }
            });

            const normalizedOrder = normalizeOrder(columns, order);

            const visible = { ...defaults.visible };
            Object.entries(settings.visible || {}).forEach(([id, value]) => {
                if (known.has(id)) {
                    visible[id] = Boolean(value);
                }
            });

            if (!hasVisibleDataColumn(columns, visible) && normalizedOrder.length > 0) {
                const firstDataColumn = columns.find((column) => !column.isSelection);
                visible[firstDataColumn?.id || normalizedOrder[0]] = true;
            }

            const widths = { ...defaults.widths };
            Object.entries(settings.widths || {}).forEach(([id, value]) => {
                const column = columns.find((item) => item.id === id);
                const width = Number(value);

                if (column && Number.isFinite(width)) {
                    widths[id] = Math.max(column.minWidth, Math.round(width));
                }
            });

            return { order: normalizedOrder, visible, widths };
        };

        const settingsFromState = (state) => ({
            order: state.order,
            visible: state.visible,
            widths: state.widths,
        });

        const ensureColgroup = (table) => {
            let colgroup = table.querySelector(':scope > colgroup');

            if (!colgroup) {
                colgroup = document.createElement('colgroup');
                table.insertBefore(colgroup, table.firstChild);
            }

            return colgroup;
        };

        const buildColgroup = (table, columns, state) => {
            const colgroup = ensureColgroup(table);
            colgroup.innerHTML = '';

            state.order.forEach((id) => {
                const column = columns.find((item) => item.id === id);

                if (!column || !state.visible[column.id]) {
                    return;
                }

                const col = document.createElement('col');
                col.dataset.acDialogsCol = column.id;
                col.style.width = `${state.widths[column.id]}px`;
                colgroup.appendChild(col);
            });
        };

        const reorderDom = (table, columns, state) => {
            const headRow = table.querySelector('thead tr:first-child');
            const rows = Array.from(table.querySelectorAll('tbody tr'));

            state.order.forEach((id) => {
                const column = columns.find((item) => item.id === id);

                if (!column) {
                    return;
                }

                if (headRow && column.header) {
                    headRow.appendChild(column.header);
                }

                rows.forEach((row) => {
                    const cell = column.isSelection
                        ? row.querySelector('td.fi-ta-selection-cell, td.fi-ta-group-selection-cell')
                        : row.querySelector(`.fi-ta-cell-${column.suffix}`);

                    if (cell) {
                        row.appendChild(cell);
                    }
                });
            });
        };

        const setLastVisibleBoundary = (table, columns, state) => {
            columns.forEach((column) => {
                getColumnElements(table, column).forEach((node) => {
                    node.dataset.acDialogsLastVisible = '0';
                });
            });

            const lastVisibleId = state.order.filter((id) => state.visible[id]).at(-1);
            const lastVisible = columns.find((column) => column.id === lastVisibleId);

            if (!lastVisible) {
                return;
            }

            getColumnElements(table, lastVisible).forEach((node) => {
                node.dataset.acDialogsLastVisible = '1';
            });
        };

        const syncSelectionIndicatorVisibility = (table, selectionVisible = null) => {
            const tableContainer = table?.closest('.fi-ta-ctn');

            if (!tableContainer) {
                return;
            }

            const isSelectionVisible = selectionVisible ?? !tableContainer.classList.contains('ac-dialogs-selection-hidden');
            const recordCheckboxes = Array.from(table.querySelectorAll('.fi-ta-record-checkbox'));
            const shouldShowSelectionIndicator = isSelectionVisible
                && recordCheckboxes.length > 0
                && recordCheckboxes.some((checkbox) => checkbox.checked);

            tableContainer.querySelectorAll('.fi-ta-selection-indicator').forEach((node) => {
                node.hidden = !shouldShowSelectionIndicator;
                node.style.display = shouldShowSelectionIndicator ? '' : 'none';
            });

            syncPageSelectionCheckbox(table);
        };

        const syncPageSelectionCheckbox = (table) => {
            const pageCheckbox = table?.querySelector('thead .fi-ta-page-checkbox');

            if (!pageCheckbox) {
                return;
            }

            const recordCheckboxes = Array.from(table.querySelectorAll('tbody .fi-ta-record-checkbox'));
            const checkedCount = recordCheckboxes.filter((checkbox) => checkbox.checked).length;

            pageCheckbox.checked = recordCheckboxes.length > 0 && checkedCount === recordCheckboxes.length;
            pageCheckbox.indeterminate = checkedCount > 0 && checkedCount < recordCheckboxes.length;
        };

        const setSelectionCheckboxChecked = (checkbox, checked) => {
            checkbox.checked = checked;
            checkbox.closest('tr')?.classList.toggle('fi-selected', checked);
            checkbox.dispatchEvent(new Event('input', { bubbles: true }));
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        };

        const applyColumnLayout = (table, columns, state) => {
            reorderDom(table, columns, state);
            buildColgroup(table, columns, state);

            let visibleWidth = 0;

            columns.forEach((column) => {
                const visible = Boolean(state.visible[column.id]);

                if (visible) {
                    visibleWidth += Number(state.widths[column.id] || column.defaultWidth);
                }

                getColumnElements(table, column).forEach((node) => {
                    node.hidden = !visible;
                    node.style.display = visible ? '' : 'none';
                    node.dataset.acDialogsColumnId = column.id;
                });
            });

            const scroll = table.closest(scrollSelector);
            const minWidth = scroll?.clientWidth || 0;
            table.style.width = `${Math.max(visibleWidth, minWidth)}px`;
            table.style.minWidth = `${Math.max(visibleWidth, minWidth)}px`;
            const selectionColumn = columns.find((column) => column.isSelection);
            const selectionVisible = selectionColumn ? Boolean(state.visible[selectionColumn.id]) : true;
            const tableContainer = table.closest('.fi-ta-ctn');

            tableContainer?.classList.toggle('ac-dialogs-selection-hidden', !selectionVisible);
            syncSelectionIndicatorVisibility(table, selectionVisible);
            setLastVisibleBoundary(table, columns, state);
            updateScrollState(scroll);
        };

        const updateScrollState = (scroll) => {
            if (!scroll) {
                return;
            }

            const maxScroll = Math.max(0, scroll.scrollWidth - scroll.clientWidth - 1);
            const hasLeft = scroll.scrollLeft > 0;
            const hasRight = scroll.scrollLeft < maxScroll;

            scroll.classList.toggle('has-more-left', hasLeft);
            scroll.classList.toggle('has-more-right', hasRight);

            const tools = scroll.closest('.fi-ta-ctn')?.querySelector('[data-ac-dialogs-tools]');
            const left = tools?.querySelector('[data-ac-dialogs-scroll-left]');
            const right = tools?.querySelector('[data-ac-dialogs-scroll-right]');

            if (left) {
                left.disabled = !hasLeft;
            }

            if (right) {
                right.disabled = !hasRight;
            }
        };

        const ensureResizeHandles = (table, columns, state) => {
            columns.forEach((column) => {
                if (column.resizable === false || !column.header || column.header.querySelector('[data-ac-dialogs-resize]')) {
                    return;
                }

                const handle = document.createElement('button');
                handle.type = 'button';
                handle.className = 'ac-dialogs-resize-handle';
                handle.dataset.acDialogsResize = column.id;
                handle.setAttribute('aria-label', `Изменить ширину: ${column.label}`);
                column.header.appendChild(handle);

                handle.addEventListener('dblclick', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    state.widths[column.id] = column.defaultWidth;
                    writeSettings(settingsFromState(state));
                    applyColumnLayout(table, columns, state);
                });

                handle.addEventListener('pointerdown', (event) => {
                    if (event.button !== 0) {
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();

                    const startX = event.clientX;
                    const startWidth = Number(state.widths[column.id] || column.defaultWidth);
                    let nextWidth = startWidth;

                    document.body.classList.add('ac-dialogs-table-is-resizing');
                    handle.setPointerCapture?.(event.pointerId);

                    const onMove = (moveEvent) => {
                        nextWidth = Math.max(column.minWidth, Math.round(startWidth + moveEvent.clientX - startX));
                        state.widths[column.id] = nextWidth;
                        applyColumnLayout(table, columns, state);
                    };

                    const onUp = () => {
                        document.removeEventListener('pointermove', onMove);
                        document.removeEventListener('pointerup', onUp);
                        document.body.classList.remove('ac-dialogs-table-is-resizing');
                        state.widths[column.id] = nextWidth;
                        writeSettings(settingsFromState(state));
                        applyColumnLayout(table, columns, state);
                    };

                    document.addEventListener('pointermove', onMove);
            document.addEventListener('pointerup', onUp, { once: true });
                });
            });
        };

        const installRowNavigationFallbackListener = () => {
            if (rowNavigationFallbackReady) {
                return;
            }

            rowNavigationFallbackReady = true;

            const resolveRowNavigationUrl = (target) => {
                const directLink = target?.closest?.(`${tableSelector} tbody a.fi-ta-col[href]`);

                if (directLink) {
                    return directLink.href;
                }

                const row = target?.closest?.(`${tableSelector} tbody tr[data-ac-dialogs-row-url]`);

                if (!row) {
                    return null;
                }

                if (shouldIgnoreRowNavigationTarget(target)) {
                    return null;
                }

                return row.dataset.acDialogsRowUrl || null;
            };

            const openRowLink = (event) => {
                const isPrimaryPointer = event.button === undefined
                    || event.button === 0
                    || (event.type === 'pointerup' && event.button === -1);

                if (!isDialogsIndex() || !isPrimaryPointer || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }

                const url = resolveRowNavigationUrl(event.target);

                if (!url) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation?.();
                window.location.href = url;
            };

            document.addEventListener('pointerup', openRowLink, true);
            document.addEventListener('click', openRowLink, true);
        };

        const ensureSelectionCheckboxId = (checkbox, index) => {
            if (checkbox.id) {
                return checkbox.id;
            }

            const row = checkbox.closest('tr');
            const rawKey = row?.getAttribute('wire:key')
                || row?.dataset.acDialogsRowUrl
                || checkbox.value
                || index;
            const normalizedKey = String(rawKey).replace(/[^A-Za-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80) || index;
            const baseId = `ac-dialogs-selection-${normalizedKey}`;
            let nextId = baseId;
            let suffix = 1;

            while (document.getElementById(nextId)) {
                nextId = `${baseId}-${suffix}`;
                suffix += 1;
            }

            checkbox.id = nextId;

            return nextId;
        };

        const toggleSelectionCheckbox = (checkbox) => {
            setSelectionCheckboxChecked(checkbox, !checkbox.checked);
            syncSelectionIndicatorVisibility(checkbox.closest(tableSelector));
        };

        const togglePageSelectionCheckbox = (table) => {
            const recordCheckboxes = Array.from(table.querySelectorAll('tbody .fi-ta-record-checkbox:not(:disabled)'));
            const shouldSelect = recordCheckboxes.some((checkbox) => !checkbox.checked);

            recordCheckboxes.forEach((checkbox) => {
                setSelectionCheckboxChecked(checkbox, shouldSelect);
            });

            syncSelectionIndicatorVisibility(table);
        };

        const resolvePageSelectionClickTarget = (event) => {
            if (!isDialogsIndex() || !event.target?.closest) {
                return null;
            }

            const pageCheckbox = event.target.closest(`${tableSelector} thead .fi-ta-page-checkbox`);
            const cell = pageCheckbox?.closest('th.fi-ta-selection-cell')
                || event.target.closest(`${tableSelector} thead th.fi-ta-selection-cell`);
            const table = cell?.closest(tableSelector);

            if (!cell || !table) {
                return null;
            }

            return { cell, table };
        };

        const resolveSelectionCellClickTarget = (event) => {
            if (!isDialogsIndex()) {
                return null;
            }

            const cell = findSelectionCellTarget(event.target);

            if (!cell) {
                return null;
            }

            const checkbox = cell.querySelector('.fi-ta-record-checkbox, input[type="checkbox"]');

            if (!checkbox || checkbox.disabled) {
                return null;
            }

            return { cell, checkbox };
        };

        const stopSelectionCellEvent = (event) => {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation?.();
        };

        const installSelectionCellClickGuard = () => {
            if (selectionCellClickGuardReady) {
                return;
            }

            selectionCellClickGuardReady = true;

            document.addEventListener('pointerup', (event) => {
                const pageTarget = resolvePageSelectionClickTarget(event);

                if (pageTarget) {
                    const isPrimaryPointer = event.button === undefined
                        || event.button === 0
                        || event.button === -1;

                    if (!isPrimaryPointer || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                        return;
                    }

                    stopSelectionCellEvent(event);
                    togglePageSelectionCheckbox(pageTarget.table);
                    selectionCellClickSuppressedUntil = Date.now() + 750;
                    return;
                }

                const target = resolveSelectionCellClickTarget(event);

                if (!target) {
                    return;
                }

                const isPrimaryPointer = event.button === undefined
                    || event.button === 0
                    || event.button === -1;

                if (!isPrimaryPointer || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }

                stopSelectionCellEvent(event);
                toggleSelectionCheckbox(target.checkbox);
                selectionCellClickSuppressedUntil = Date.now() + 750;
            }, true);

            document.addEventListener('click', (event) => {
                const pageTarget = resolvePageSelectionClickTarget(event);

                if (pageTarget) {
                    const isPrimaryPointer = event.button === undefined || event.button === 0;

                    if (!isPrimaryPointer || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                        return;
                    }

                    stopSelectionCellEvent(event);

                    if (Date.now() <= selectionCellClickSuppressedUntil) {
                        return;
                    }

                    togglePageSelectionCheckbox(pageTarget.table);
                    return;
                }

                const target = resolveSelectionCellClickTarget(event);

                if (!target) {
                    return;
                }

                const isPrimaryPointer = event.button === undefined || event.button === 0;

                if (!isPrimaryPointer || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }

                stopSelectionCellEvent(event);

                if (Date.now() <= selectionCellClickSuppressedUntil) {
                    return;
                }

                toggleSelectionCheckbox(target.checkbox);
            }, true);
        };

        const bindSelectionCellClickGuards = (table) => {
            table.querySelectorAll('tbody td.fi-ta-selection-cell, tbody td.fi-ta-group-selection-cell').forEach((cell, index) => {
                const checkbox = cell.querySelector('.fi-ta-record-checkbox, input[type="checkbox"]');

                if (!checkbox) {
                    cell.querySelector('.ac-dialogs-selection-hitbox')?.remove();
                    cell.removeAttribute('data-ac-dialogs-selection-hitbox-ready');
                    return;
                }

                const checkboxId = ensureSelectionCheckboxId(checkbox, index);
                let hitbox = cell.querySelector('.ac-dialogs-selection-hitbox');

                if (!hitbox) {
                    hitbox = document.createElement('label');
                    hitbox.className = 'ac-dialogs-selection-hitbox';
                    hitbox.setAttribute('aria-hidden', 'true');
                    hitbox.setAttribute('tabindex', '-1');
                    cell.append(hitbox);
                }

                hitbox.htmlFor = checkboxId;
                cell.dataset.acDialogsSelectionHitboxReady = '1';
                cell.style.cursor = 'pointer';

                if (checkbox.dataset.acDialogsSelectionChangeBound !== '1') {
                    checkbox.dataset.acDialogsSelectionChangeBound = '1';
                    checkbox.addEventListener('change', () => syncSelectionIndicatorVisibility(table));
                }
            });
        };

        const bindRowNavigation = (table) => {
            table.querySelectorAll('tbody tr').forEach((row) => {
                const link = row.querySelector('a.fi-ta-col[href]');

                if (!link) {
                    row.removeAttribute('data-ac-dialogs-row-url');
                    row.removeAttribute('role');
                    row.removeAttribute('tabindex');
                    return;
                }

                row.dataset.acDialogsRowUrl = link.href;
                row.setAttribute('role', 'link');
                row.setAttribute('tabindex', '0');
            });
        };

        const buildColumnsList = (details, columns, draft) => {
            const list = details.querySelector('[data-ac-dialogs-columns-list]');
            const message = details.querySelector('[data-ac-dialogs-columns-message]');

            if (!list) {
                return;
            }

            list.innerHTML = '';

            draft.order.forEach((id) => {
                const column = columns.find((item) => item.id === id);

                if (!column) {
                    return;
                }

                const row = document.createElement('div');
                row.className = 'ac-dialogs-columns-row';
                row.draggable = column.reorderable !== false;
                row.classList.toggle('is-fixed', column.reorderable === false);
                row.dataset.acDialogsColumnsRow = column.id;
                row.innerHTML = `
                    <span class="ac-dialogs-columns-drag" aria-hidden="true">⋮⋮</span>
                    <label class="ac-dialogs-columns-check">
                        <input type="checkbox" value="${column.id}">
                        <span></span>
                    </label>
                `;

                const input = row.querySelector('input');
                const label = row.querySelector('.ac-dialogs-columns-check span');
                input.checked = Boolean(draft.visible[column.id]);
                label.textContent = column.label;

                input.addEventListener('change', () => {
                    const nextVisible = { ...draft.visible, [column.id]: input.checked };

                    if (!hasVisibleDataColumn(columns, nextVisible)) {
                        input.checked = true;
                        message.textContent = 'Оставьте хотя бы одну колонку данных.';

                        return;
                    }

                    draft.visible = nextVisible;
                    message.textContent = '';
                });

                    list.appendChild(row);

                    if (column.reorderable === false) {
                        return;
                    }

                    const dragHandle = row.querySelector('.ac-dialogs-columns-drag');
                    dragHandle?.addEventListener('pointerdown', (event) => {
                        if (event.button !== 0) {
                            return;
                        }

                        event.preventDefault();
                        event.stopPropagation();
                        row.classList.add('is-dragging');
                        dragHandle.setPointerCapture?.(event.pointerId);

                        const moveRow = (moveEvent) => {
                            const rows = Array.from(list.querySelectorAll('[data-ac-dialogs-columns-row]'))
                                .filter((candidate) => candidate !== row && !candidate.classList.contains('is-fixed'));
                            const target = rows.find((candidate) => {
                                const rect = candidate.getBoundingClientRect();

                                return moveEvent.clientY >= rect.top && moveEvent.clientY <= rect.bottom;
                            });

                            if (!target) {
                                return;
                            }

                            const rect = target.getBoundingClientRect();

                            if (moveEvent.clientY > rect.top + rect.height / 2) {
                                target.after(row);
                            } else {
                                target.before(row);
                            }
                        };

                        const stopMove = () => {
                            document.removeEventListener('pointermove', moveRow);
                            document.removeEventListener('pointerup', stopMove);
                            row.classList.remove('is-dragging');
                        };

                        document.addEventListener('pointermove', moveRow);
                        document.addEventListener('pointerup', stopMove, { once: true });
                    });
                });
        };

        const syncToolsCount = (tools, columns, state) => {
            const count = tools?.querySelector('[data-ac-dialogs-columns-count]');

            if (!count) {
                return;
            }

            const visible = columns.filter((column) => state.visible[column.id]).length;
            count.textContent = `${visible}/${columns.length}`;
        };

        const ensureTools = (table, columns, state) => {
            const tableContainer = table.closest('.fi-ta-ctn');
            const toolbar = document.querySelector(toolbarSelector);

            if (!tableContainer || !toolbar) {
                return null;
            }

            const controls = Array.from(toolbar.children)
                .find((child) => child.querySelector?.('.fi-ta-search-field'))
                || toolbar;

            let tools = toolbar.querySelector('[data-ac-dialogs-tools]');

            if (tools && (tools.__acDialogsTable !== table || tools.parentElement !== controls)) {
                tools.remove();
                tools = null;
            }

            if (!tools) {
                tools = document.createElement('div');
                tools.className = 'ac-dialogs-table-tools';
                tools.dataset.acDialogsTools = '1';
                tools.innerHTML = `
                    <span class="ac-kanban-view-switch ac-dialogs-view-switch" role="group" aria-label="Вид диалогов">
                        <a
                            href="${dialogsKanbanUrl()}"
                            class="ac-kanban-view-switch__item"
                            wire:navigate.hover
                            data-ac-dialogs-view-link
                        >
                            Канбан
                        </a>
                        <span class="ac-kanban-view-switch__item is-active">
                            Таблица
                        </span>
                    </span>
                    <details class="ac-dialogs-columns">
                        <summary>
                            <span class="ac-dialogs-columns-button ac-dialogs-columns-gear" title="Настроить колонки" aria-label="Настроить колонки">
                                <svg width="15" height="15" viewBox="0 0 16 16" aria-hidden="true" fill="none">
                                    <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M8 1.5v2M8 12.5v2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M1.5 8h2M12.5 8h2M3.4 12.6l1.4-1.4M11.2 4.8l1.4-1.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                </svg>
                                <span class="ac-dialogs-columns-button-count" data-ac-dialogs-columns-count></span>
                            </span>
                        </summary>
                        <div class="ac-dialogs-columns-popover">
                            <div class="ac-dialogs-columns-popover-head">
                                <div class="ac-dialogs-columns-title">Видимость и порядок</div>
                                <button type="button" class="ac-dialogs-columns-action" data-ac-dialogs-reset>Сбросить</button>
                            </div>
                            <div class="ac-dialogs-columns-message" data-ac-dialogs-columns-message></div>
                            <div class="ac-dialogs-columns-list" data-ac-dialogs-columns-list></div>
                            <div class="ac-dialogs-columns-actions">
                                <button type="button" class="ac-dialogs-columns-action" data-ac-dialogs-cancel>Отмена</button>
                                <button type="button" class="ac-dialogs-columns-action" data-ac-dialogs-show-all>Показать все</button>
                                <button type="button" class="ac-dialogs-columns-action ac-dialogs-columns-apply" data-ac-dialogs-apply>Применить</button>
                            </div>
                        </div>
                    </details>
                `;
                controls.appendChild(tools);

                tools.querySelector('[data-ac-dialogs-scroll-left]')?.addEventListener('click', () => {
                    table.closest(scrollSelector)?.scrollBy({ left: -420, behavior: 'smooth' });
                });
                tools.querySelector('[data-ac-dialogs-scroll-right]')?.addEventListener('click', () => {
                    table.closest(scrollSelector)?.scrollBy({ left: 420, behavior: 'smooth' });
                });
            }

            tools.__acDialogsTable = table;
            const details = tools.querySelector('.ac-dialogs-columns');

            if (details && details.dataset.acDialogsBound !== '1') {
                details.dataset.acDialogsBound = '1';

                details.addEventListener('toggle', () => {
                    if (!details.open) {
                        return;
                    }

                    const draft = structuredClone(settingsFromState(state));
                    details.__acDraft = draft;
                    buildColumnsList(details, columns, draft);
                });

                details.querySelector('[data-ac-dialogs-show-all]')?.addEventListener('click', () => {
                    const draft = details.__acDraft || structuredClone(settingsFromState(state));
                    draft.visible = Object.fromEntries(columns.map((column) => [column.id, true]));
                    details.__acDraft = draft;
                    details.querySelector('[data-ac-dialogs-columns-message]').textContent = '';
                    buildColumnsList(details, columns, draft);
                });

                details.querySelector('[data-ac-dialogs-reset]')?.addEventListener('click', () => {
                    resetSettings();
                    const defaults = defaultStateFor(columns);
                    state.order = defaults.order;
                    state.visible = defaults.visible;
                    state.widths = defaults.widths;
                    details.open = false;
                    applyColumnLayout(table, columns, state);
                    syncToolsCount(tools, columns, state);
                });

                details.querySelector('[data-ac-dialogs-cancel]')?.addEventListener('click', () => {
                    details.open = false;
                });

                details.querySelector('[data-ac-dialogs-apply]')?.addEventListener('click', () => {
                    const draft = details.__acDraft || structuredClone(settingsFromState(state));
                    const order = Array.from(details.querySelectorAll('[data-ac-dialogs-columns-row]'))
                        .map((row) => row.dataset.acDialogsColumnsRow)
                        .filter(Boolean);

                    draft.order = order.length ? normalizeOrder(columns, order) : draft.order;

                    if (!hasVisibleDataColumn(columns, draft.visible)) {
                        details.querySelector('[data-ac-dialogs-columns-message]').textContent = 'Оставьте хотя бы одну колонку данных.';

                        return;
                    }

                    state.order = draft.order;
                    state.visible = draft.visible;
                    state.widths = { ...state.widths, ...(draft.widths || {}) };
                    writeSettings(settingsFromState(state));
                    details.open = false;
                    applyColumnLayout(table, columns, state);
                    syncToolsCount(tools, columns, state);
                });

                const list = details.querySelector('[data-ac-dialogs-columns-list]');

                list?.addEventListener('dragstart', (event) => {
                    const row = event.target.closest('[data-ac-dialogs-columns-row]');

                    if (!row) {
                        return;
                    }

                    row.classList.add('is-dragging');
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', row.dataset.acDialogsColumnsRow || '');
                });

                list?.addEventListener('dragover', (event) => {
                    const dragging = list.querySelector('.is-dragging');
                    const target = event.target.closest('[data-ac-dialogs-columns-row]');

                    if (!dragging || !target || dragging === target || target.classList.contains('is-fixed')) {
                        return;
                    }

                    event.preventDefault();
                    const rect = target.getBoundingClientRect();

                    if (event.clientY > rect.top + rect.height / 2) {
                        target.after(dragging);
                    } else {
                        target.before(dragging);
                    }
                });

                list?.addEventListener('drop', (event) => {
                    event.preventDefault();
                });

                list?.addEventListener('dragend', () => {
                    list.querySelectorAll('.is-dragging').forEach((row) => row.classList.remove('is-dragging'));
                });
            }

            syncToolsCount(tools, columns, state);

            return tools;
        };

        const initDialogsTable = () => {
            if (!isDialogsIndex()) {
                return;
            }

            const table = document.querySelector(tableSelector);

            if (!table) {
                return;
            }

            bindRowNavigation(table);
            bindSelectionCellClickGuards(table);

            const scroll = table.closest(scrollSelector);
            scroll?.classList.add('ac-dialogs-table-scroll');

            const columns = normalizeColumns(table);

            if (columns.length === 0) {
                return;
            }

            const state = normalizeState(columns, readSettings());
            const tools = ensureTools(table, columns, state);

            ensureResizeHandles(table, columns, state);
            applyColumnLayout(table, columns, state);
            syncToolsCount(tools, columns, state);

            if (scroll && scroll.dataset.acDialogsScrollBound !== '1') {
                scroll.dataset.acDialogsScrollBound = '1';
                scroll.addEventListener('scroll', () => updateScrollState(scroll), { passive: true });
                scroll.addEventListener('wheel', (event) => {
                    const horizontalDelta = Math.abs(event.deltaX) >= Math.abs(event.deltaY)
                        ? event.deltaX
                        : (event.shiftKey ? event.deltaY : 0);

                    if (horizontalDelta === 0 || scroll.scrollWidth <= scroll.clientWidth) {
                        return;
                    }

                    event.preventDefault();
                    scroll.scrollLeft += horizontalDelta;
                    updateScrollState(scroll);
                }, { passive: false });
            }

            updateScrollState(scroll);
        };

        installSelectionCellClickGuard();
        installRowNavigationFallbackListener();
        installDialogSideFieldCopyListener();
        installViewSwitchLoadingListener();

        let initQueued = false;

        const scheduleInit = () => {
            if (initQueued) {
                return;
            }

            initQueued = true;
            const runOnNextFrame = window.requestAnimationFrame?.bind(window)
                || ((callback) => window.setTimeout(callback, 0));

            runOnNextFrame(() => {
                initQueued = false;
                initDialogsTable();
                initVoicePlayers();
                initVideoNotePlayers();
            });
        };

        const installObserver = () => {
            if (!document.body || document.body.dataset.acDialogsObserverReady === '1') {
                return;
            }

            document.body.dataset.acDialogsObserverReady = '1';
            new MutationObserver(() => scheduleInit()).observe(document.body, {
                childList: true,
                subtree: true,
            });
        };

        scheduleInit();
        document.addEventListener('DOMContentLoaded', scheduleInit);
        document.addEventListener('DOMContentLoaded', installObserver);
        document.addEventListener('livewire:navigated', scheduleInit);
        document.addEventListener('livewire:init', () => {
            if (window.Livewire?.hook) {
                window.Livewire.hook('morphed', scheduleInit);
            }
        });

        if (window.Livewire?.hook) {
            window.Livewire.hook('morphed', scheduleInit);
        }

        installObserver();
        window.addEventListener('resize', scheduleInit);
    })();
</script>
