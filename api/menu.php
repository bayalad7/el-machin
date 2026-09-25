<?php
// GET api/menu.php → menú vigente (el que edita el admin, o menu-default.json)
require __DIR__ . '/comun.php';

exigir_metodo('GET');
responder(menu_vigente());
