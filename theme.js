(function () {
    var STORAGE_KEY = 'mtmo-theme';

    function storedTheme() {
        try {
            return localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
    }

    function isDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }

    function setTheme(dark) {
        if (dark) {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        try {
            localStorage.setItem(STORAGE_KEY, dark ? 'dark' : 'light');
        } catch (e) {}
        syncToggle();
    }

    function syncToggle() {
        var btn = document.getElementById('theme-toggle');
        if (!btn) {
            return;
        }
        var dark = isDark();
        btn.classList.toggle('theme-toggle--dark', dark);
        btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
        btn.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
        btn.setAttribute('title', dark ? 'Light mode' : 'Dark mode');
    }

    function injectToggle() {
        if (document.getElementById('theme-toggle')) {
            syncToggle();
            return;
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'theme-toggle';
        btn.className = 'theme-toggle theme-toggle-floating';
        btn.setAttribute('aria-pressed', isDark() ? 'true' : 'false');
        btn.innerHTML =
            '<span class="theme-toggle-icon theme-toggle-moon" aria-hidden="true">\u263E</span>' +
            '<span class="theme-toggle-icon theme-toggle-sun" aria-hidden="true">\u2600</span>';
        document.body.appendChild(btn);
        btn.addEventListener('click', function () {
            setTheme(!isDark());
        });
        syncToggle();
    }

    function injectSidebarToggle() {
        var toggleWrap = document.getElementById('sidebar-toggle-wrap');
        if (toggleWrap && toggleWrap.parentNode) {
            toggleWrap.parentNode.removeChild(toggleWrap);
        }
        var backdrop = document.querySelector('.sidebar-backdrop');
        if (backdrop && backdrop.parentNode) {
            backdrop.parentNode.removeChild(backdrop);
        }
        document.body.classList.remove('sidebar-open');
        document.body.classList.remove('sidebar-collapsed');
    }

    document.addEventListener('DOMContentLoaded', function () {
        var s = storedTheme();
        if (s === 'dark' || s === 'light') {
            setTheme(s === 'dark');
        }
        injectToggle();
        injectSidebarToggle();
    });
})();
