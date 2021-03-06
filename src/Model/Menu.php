<?php
/**
 * User: Paul Coudeville <paul@metabolism.fr>
 */

namespace Rocket\Model;


use Rocket\Application;

class Menu {

    public function __construct($name, $slug, $autodeclare = true)
    {
        if ($autodeclare)
        {
            register_nav_menu($slug, __($name, Application::$bo_domain_name));
        }
    }
}
