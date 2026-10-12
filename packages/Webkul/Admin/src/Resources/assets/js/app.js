/**
 * This will track all the images and fonts for publishing.
 */
import.meta.glob(["../images/**", "../fonts/**"]);

/**
 * Main vue bundler.
 */
import { createApp } from "vue/dist/vue.esm-bundler";

/**
 * Main root application registry.
 */
window.app = createApp({
    data() {
        return {
            isMenuActive: false,

            sidebarOpen: true,

            hoveringMenu: '',

            lastScrollY: 0,
        };
    },

    created() {
        window.addEventListener('click', this.handleFocusOut);
        document.body.classList.remove('is-scrolling-down');
    },

    mounted() {
        this.sidebarOpen = ! this.$refs.sidebar?.parentElement?.classList.contains('sidebar-collapsed');

        this.$nextTick(() => {
            this._scrollEl = document.getElementById('main-content');

            if (this._scrollEl) {
                this._scrollEl.addEventListener('scroll', this.handleScrollDirection, { passive: true });
            } else {
                this.lastScrollY = Math.max((window.scrollY ?? window.pageYOffset ?? 0), 0);
                window.addEventListener('scroll', this.handleScrollDirection, { passive: true });
            }

            this.handleScrollDirection();
        });
    },

    beforeDestroy() {
        window.removeEventListener('click', this.handleFocusOut);

        if (this._scrollEl) {
            this._scrollEl.removeEventListener('scroll', this.handleScrollDirection);
        } else {
            window.removeEventListener('scroll', this.handleScrollDirection);
        }

        document.body.classList.remove('is-scrolling-down');
    },

    beforeUnmount() {
        window.removeEventListener('click', this.handleFocusOut);

        if (this._scrollEl) {
            this._scrollEl.removeEventListener('scroll', this.handleScrollDirection);
        } else {
            window.removeEventListener('scroll', this.handleScrollDirection);
        }

        document.body.classList.remove('is-scrolling-down');
    },

    methods: {
        onSubmit() {},

        onInvalidSubmit({ values, errors, results }) {
            setTimeout(() => {
                const errorKeys = Object.entries(errors)
                    .map(([key, value]) => ({ key, value }))
                    .filter(error => error["value"].length);

                let firstErrorElement = document.querySelector('[name="' + errorKeys[0]["key"] + '"]');

                firstErrorElement.scrollIntoView({
                    behavior: "smooth",
                    block: "center"
                });
            }, 100);
        },

        /**
         * Opens or closes the side menu. The choice is kept in this browser, and the page
         * content follows the menu width through CSS (see the layout), so nothing is hidden.
         */
        toggleSidebar() {
            const container = this.$refs.sidebar?.parentElement;

            if (! container) {
                return;
            }

            const closed = container.classList.toggle('sidebar-collapsed');

            this.sidebarOpen = ! closed;
            this.isMenuActive = false;
            this.hoveringMenu = '';

            try {
                localStorage.setItem('krayin.sidebar', closed ? 'closed' : 'open');
            } catch (error) {
                // Private mode or blocked storage: the menu still toggles, it just is not remembered.
            }

            // Charts that only listen to the window size need to know the content width changed.
            const content = document.getElementById('main-content');

            if (content) {
                content.addEventListener('transitionend', () => window.dispatchEvent(new Event('resize')), { once: true });
            }
        },

        handleFocusOut(event) {
            const sidebar = this.$refs.sidebar;

            if (sidebar && ! sidebar.contains(event.target)) {
                this.isMenuActive = false;
            }
        },

        handleScrollDirection() {
            const topThreshold = 0;
            const el = this._scrollEl;
            const currentScrollY = el
                ? Math.max(el.scrollTop, 0)
                : Math.max((window.scrollY ?? window.pageYOffset ?? 0), 0);
            const scrollDelta = currentScrollY - this.lastScrollY;

            if (currentScrollY == topThreshold) {
                document.body.classList.remove('is-scrolling-down');
                this.lastScrollY = currentScrollY;

                return;
            }

            if (Math.abs(scrollDelta) < 2) {
                return;
            }

            if (scrollDelta > 0) {
                document.body.classList.add('is-scrolling-down');
            }

            this.lastScrollY = currentScrollY;
        },
    },
});

/**
 * Global plugins registration.
 */
import Admin from "./plugins/admin";
import Axios from "./plugins/axios";
import Emitter from "./plugins/emitter";
import Flatpickr from "./plugins/flatpickr";
import VeeValidate from "./plugins/vee-validate";
import CreateElement from "./plugins/createElement";
import Draggable from "./plugins/draggable";
import VueCal from "./plugins/vue-cal";

[
    Admin,
    Axios,
    Emitter,
    CreateElement,
    Draggable,
    Flatpickr,
    VeeValidate,
    VueCal,
].forEach((plugin) => app.use(plugin));

/**
 * Global directives.
 */
import Debounce from "./directives/debounce";
import DOMPurify from "./directives/dompurify";
import ToolTip from "./directives/tooltip";

app.directive("debounce", Debounce);
app.directive("safe-html", DOMPurify);
app.directive("tooltip", ToolTip);

export default app;

