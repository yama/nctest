<?php
/*
 * Nucleus: PHP/MySQL Weblog CMS (http://nucleuscms.org/)
 * Copyright (C) 2002-2013 The Nucleus Group
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 * (see nucleus/documentation/index.html#license for more info)
 */

function upgrade_do300() {

    if (upgrade_checkinstall(300))
        return 'インストール済みです';

    // 2.5(beta/RC/...) -> 3.0
    // update database version  
    update_version('300');
    // nothing!
}

?>
