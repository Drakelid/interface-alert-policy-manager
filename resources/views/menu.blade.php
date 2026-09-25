{{--
    LibreNMS renders MenuEntryHook views inside Overview > Plugins and does not
    currently provide plugins with a server-side slot in the Alerts dropdown.
    Keep this ordinary link as a no-JavaScript fallback, then add a clone to
    the Alerts dropdown once its markup is available.
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

    function addIapmAlertsNavigation() {
        if (document.getElementById('iapm-alerts-navigation')) {
            return;
        }

        var source = document.getElementById('iapm-plugin-menu-fallback');
        // The Notifications link is the stable entry in LibreNMS's Alerts menu.
        // Its icon and navbar classes vary between LibreNMS versions.
        var notificationsLink = document.querySelector('#navHeaderCollapse a[href$="/alerts"]');
        var alertsMenu = notificationsLink && notificationsLink.closest('ul.dropdown-menu');
        if (!source || !alertsMenu) {
            return;
        }

        var item = document.createElement('li');
        item.id = 'iapm-alerts-navigation';

        var link = source.cloneNode(true);
        link.id = 'iapm-alerts-navigation-link';
        link.setAttribute('aria-label', 'Interface Alert Policy Manager');
        link.querySelector('i').className = 'fa fa-bell-o fa-fw fa-lg';
        link.querySelector('[data-iapm-menu-label]').textContent = 'Dispatch';

        item.appendChild(link);
        alertsMenu.appendChild(item);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', addIapmAlertsNavigation, {once: true});
    } else {
        addIapmAlertsNavigation();
    }
})();
</script>
