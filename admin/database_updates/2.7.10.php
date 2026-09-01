<?php

/*
 * ITFlow - Database update to version 2.7.10 (from 2.7.9)
 * Included by admin/database_updates.php - do not access directly
 */

defined('FROM_DB_UPDATER') || die("Direct file access is not allowed");

    // How long a ticket stays in the navbar timer list after the technician clocks
    // out of it, in minutes. A clock out is very often followed by clocking back
    // into the same ticket - the phone rings, the reply needs a screenshot - so the
    // list keeps the ticket for a while with a resume button rather than dropping
    // it the instant the clock stops and making it something to go and find again.
    //
    // Only the technician's own clocked out tickets linger; other people's are
    // listed while their clock is actually running and not after. 0 drops a ticket
    // from the list as soon as it is clocked out.
    $itflow_column_exists = mysqli_query($mysqli, "SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'settings'
        AND COLUMN_NAME = 'config_ticket_timer_clocked_out_minutes'");

    if (!mysqli_num_rows($itflow_column_exists)) {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ticket_timer_clocked_out_minutes` int(11) NOT NULL DEFAULT 15 AFTER `config_ticket_timer_mode`");
    }
