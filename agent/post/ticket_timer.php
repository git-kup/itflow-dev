<?php

/*
 * ITFlow - GET/POST request handler for ticket clock in / clock out timers
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_GET['start_ticket_timer'])) {

    validateCSRFToken();

    // Permission check
    enforceUserPermission('module_support', 2);

    // GET Data
    $ticket_id = intval($_GET['start_ticket_timer']);

    // Get & verify ticket details
    $ticket_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id, ticket_number, ticket_prefix FROM tickets WHERE ticket_id = $ticket_id AND ticket_closed_at IS NULL"));

    $client_id = intval($ticket_row['ticket_client_id']);
    $ticket_prefix = escapeSql($ticket_row['ticket_prefix']);
    $ticket_number = intval($ticket_row['ticket_number']);

    if (!$ticket_number) {
        flashAlert("Invalid ticket!", 'error');
        redirect();
    }

    if ($client_id) {
        enforceClientAccess();
    }

    // One running clock per user per ticket - a second clock in is the user
    // pressing the button twice, not a request to bill the same minutes again
    $running = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT 1 AS running FROM ticket_timers WHERE timer_ticket_id = $ticket_id AND timer_user_id = $session_user_id AND timer_stopped_at IS NULL LIMIT 1"));

    if ($running) {
        flashAlert("You are already clocked in on <strong>$ticket_prefix$ticket_number</strong>", 'error');
        redirect();
    }

    // Clock in query
    mysqli_query($mysqli, "INSERT INTO ticket_timers SET timer_ticket_id = $ticket_id, timer_user_id = $session_user_id, timer_started_at = NOW()");

    logTicketHistory($ticket_id, "$session_name clocked in");

    flashAlert("Clocked in on <strong>$ticket_prefix$ticket_number</strong>");

    redirect();

}

if (isset($_GET['stop_ticket_timer'])) {

    validateCSRFToken();

    // Permission check
    enforceUserPermission('module_support', 2);

    // GET Data
    $timer_id = intval($_GET['stop_ticket_timer']);

    // Get & verify the running timer. A user stops their own clock - an id
    // belonging to somebody else is not theirs to close
    $timer_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT timer_ticket_id, ticket_client_id, ticket_number, ticket_prefix FROM ticket_timers
        LEFT JOIN tickets ON timer_ticket_id = ticket_id
        WHERE timer_id = $timer_id AND timer_user_id = $session_user_id AND timer_stopped_at IS NULL"));

    if (!$timer_row) {
        flashAlert("That timer is not running!", 'error');
        redirect();
    }

    $ticket_id = intval($timer_row['timer_ticket_id']);
    $client_id = intval($timer_row['ticket_client_id']);
    $ticket_prefix = escapeSql($timer_row['ticket_prefix']);
    $ticket_number = intval($timer_row['ticket_number']);

    if ($client_id) {
        enforceClientAccess();
    }

    // Clock out query
    mysqli_query($mysqli, "UPDATE ticket_timers SET timer_stopped_at = NOW() WHERE timer_id = $timer_id");

    $elapsed = escapeSql(secondsToTime(ticketTimerUnappliedSeconds($ticket_id, $session_user_id)));

    logTicketHistory($ticket_id, "$session_name clocked out");

    flashAlert("Clocked out of <strong>$ticket_prefix$ticket_number</strong> - <strong>$elapsed</strong> ready to log");

    redirect("ticket.php?ticket_id=$ticket_id");

}
