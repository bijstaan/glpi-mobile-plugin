<?php

namespace GlpiPlugin\Glpimobile;

use CommonGLPI;
use Session;

/**
 * Setup-menu entry so the notification config page is reachable after install
 * (Setup → GLPI Mobile), not only via the plugin-list gear.
 */
class Menu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('GLPI Mobile', 'glpimobile');
    }

    public static function getIcon()
    {
        return 'ti ti-device-mobile';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight('config', READ);
    }

    public static function canCreate(): bool
    {
        return (bool) Session::haveRight('config', UPDATE);
    }

    /**
     * No getMenuContent().
     *
     * This class supplies the plugin's name and icon; it deliberately does not
     * register a Setup-menu entry. Setup > Plugins already links the settings
     * page, and a menu row pointing at the same page is a duplicate — with
     * several plugins installed, those duplicates are most of what is in the
     * Setup menu.
     */
}
