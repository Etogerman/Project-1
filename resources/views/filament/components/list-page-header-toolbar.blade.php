<div
    x-data="{
        frame: null,
        schedule() {
            if (this.frame) {
                cancelAnimationFrame(this.frame)
            }

            this.frame = requestAnimationFrame(() => this.sync())
        },
        sync() {
            const page = this.$el.closest('.ac-inline-list-page')

            if (! page) {
                return
            }

            const toolbar = page.querySelector('.fi-ta-header-toolbar')

            if (! toolbar) {
                return
            }

            const source = [...toolbar.children].find((child) =>
                child.querySelector([
                    '.fi-ta-search-field',
                    '.fi-ta-filters-dropdown',
                    '.fi-ta-filters-modal',
                    '.fi-ta-filters-trigger-action-ctn',
                    '.fi-ta-column-manager-dropdown',
                    '.fi-ta-column-manager-modal',
                    '[wire\\:key$=\".table.column-manager\"]',
                ].join(', ')),
            )

            if (! source || this.$el.firstElementChild === source) {
                return
            }

            source.classList.add('ac-list-page-header-toolbar-items')
            this.$el.replaceChildren(source)
        },
    }"
    x-init="
        schedule();

        const page = $el.closest('.ac-inline-list-page');

        if (! page) {
            return;
        }

        const observer = new MutationObserver(() => schedule());

        observer.observe(page, {
            childList: true,
            subtree: true,
        });
    "
    class="ac-list-page-header-toolbar"
></div>
