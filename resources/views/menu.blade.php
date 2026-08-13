{{--
    LibreNMS renders MenuEntryHook views inside Overview > Plugins and does not
    currently provide plugins with a server-side slot in the primary navbar.
    Keep this ordinary link as a no-JavaScript fallback, then promote a clone to
    the primary navbar once its markup is available.
--}}
<a id="iapm-plugin-menu-fallback"
   href="{{ route('iapm.overview') }}"
   title="Interface Alert Policy Manager">
    <i class="fa fa-bell-o fa-fw fa-lg" aria-hidden="true"></i>
    <span data-iapm-menu-label>Interface Policies</span>
</a>
<script>
(function () {
    'use strict';

    function addIapmTopNavigation() {
        if (document.getElementById('iapm-top-navigation')) {
            return;
        }

        var source = document.getElementById('iapm-plugin-menu-fallback');
        var navbar = document.querySelector('#navHeaderCollapse > ul.navbar-nav');
        if (!source || !navbar) {
            return;
        }

        var item = document.createElement('li');
        item.id = 'iapm-top-navigation';

        var link = source.cloneNode(true);
        link.id = 'iapm-top-navigation-link';
        link.setAttribute('aria-label', 'Interface Alert Policy Manager');
        link.querySelector('i').className = 'fa fa-bell-o fa-fw fa-lg fa-nav-icons';
        link.querySelector('[data-iapm-menu-label]').textContent = 'Dispatch';

        item.appendChild(link);
        navbar.appendChild(item);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', addIapmTopNavigation, {once: true});
    } else {
        addIapmTopNavigation();
    }
})();
</script>
