<?php
// Optional. db.php prefers a local mysql_config.php when one exists, and
// otherwise takes the same four variables from signcollect-lib, which reads
// them from /web/.env. A new host wants /web/.env and /web/lib, not a copy
// of this file; this stub is here for a host that is not on the library yet.
//
// Copy to mysql_config.php and fill in. The real file is gitignored.
$servername = "localhost";
$username   = "";
$password   = "";
$database   = "";
