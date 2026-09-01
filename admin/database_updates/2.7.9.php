<?php

/*
 * ITFlow - Database update to version 2.7.9 (from 2.7.8)
 * Included by admin/database_updates.php - do not access directly
 */

defined('FROM_DB_UPDATER') || die("Direct file access is not allowed");

    // The ticket timer has always been a stopwatch held in the browser's
    // localStorage. That is fine until the technician changes machine, closes the
    // tab, or wants a colleague to see that a ticket is already being worked on -
    // none of which localStorage can answer, because nothing about the running
    // timer ever leaves that browser.
    //
    // ticket_timers records the same work as clock in / clock out segments on the
    // server instead. A row with timer_stopped_at NULL is a clock that is running
    // now, which is what makes the count in the navbar and the list of who is on
    // what possible at all.
    //
    // timer_applied_at is what stops a segment being billed twice: the reply form
    // is prefilled from the segments that have not yet been written into a reply,
    // and posting that reply stamps them. Without it every reply on a ticket would
    // be prefilled with the same running total for the life of the ticket.
    mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `ticket_timers` (
        `timer_id` int(11) NOT NULL AUTO_INCREMENT,
        `timer_started_at` datetime NOT NULL DEFAULT current_timestamp(),
        `timer_stopped_at` datetime DEFAULT NULL,
        `timer_applied_at` datetime DEFAULT NULL,
        `timer_ticket_id` int(11) NOT NULL,
        `timer_user_id` int(11) NOT NULL,
        PRIMARY KEY (`timer_id`),
        KEY `timer_user_id` (`timer_user_id`,`timer_stopped_at`),
        KEY `timer_ticket_id` (`timer_ticket_id`,`timer_user_id`,`timer_applied_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // 0 keeps the existing stopwatch, so an upgrade changes nothing until an admin
    // chooses otherwise in Settings > Ticket.
    $itflow_column_exists = mysqli_query($mysqli, "SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'settings'
        AND COLUMN_NAME = 'config_ticket_timer_mode'");

    if (!mysqli_num_rows($itflow_column_exists)) {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ticket_timer_mode` tinyint(1) NOT NULL DEFAULT 0 AFTER `config_ticket_timer_autostart`");
    }
