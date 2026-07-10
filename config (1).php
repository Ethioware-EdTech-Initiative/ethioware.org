<?php
/**
 * EthioWare Research Scholars Program — Database Configuration
 *
 * Fill in the values below with the MySQL database credentials from your
 * cPanel "MySQL® Databases" page. cPanel database/user names are usually
 * prefixed with your cPanel username, e.g. "ethiowar_rsp" and "ethiowar_rspuser".
 *
 * IMPORTANT: Keep this file OUTSIDE any publicly browsable listing if possible,
 * and never commit real credentials to a public repository.
 */

// ---- EDIT THESE FOUR VALUES ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'ethiowzj_ethioware_rsp');          // your database name
define('DB_USER', 'ethiowzj_rsp_admin');      // your database user
define('DB_PASS', 'Ant0852.admin');
// ---------------------------------

function rsp_get_connection(): mysqli {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_errno) {
        http_response_code(500);
        die(json_encode([
            'success' => false,
            'message' => 'Could not connect to the database. Please try again later.',
        ]));
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}
