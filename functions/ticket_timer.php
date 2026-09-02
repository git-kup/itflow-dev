<?php

// Ticket clock in / clock out timer helpers
//
// A row in ticket_timers is one clock segment. timer_stopped_at NULL means the
// clock is running now; timer_applied_at is stamped once the segment has been
// written into a ticket reply, so the same minutes are never offered twice.


/*
 * Seconds a user has clocked on a ticket that have not yet been written into a
 * reply. Closed segments only - a running clock is still being counted and is
 * displayed live in the browser instead.
 *
 * Used to prefill the time worked fields on the reply form, and to tell the
 * user what they have banked when they clock out.
 */
function ticketTimerUnappliedSeconds($ticket_id, $user_id) {
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $user_id = intval($user_id);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT SUM(TIMESTAMPDIFF(SECOND, timer_started_at, timer_stopped_at)) AS seconds
        FROM ticket_timers
        WHERE timer_ticket_id = $ticket_id
        AND timer_user_id = $user_id
        AND timer_stopped_at IS NOT NULL
        AND timer_applied_at IS NULL"));

    return intval($row['seconds']);
}

/*
 * Stamp a user's closed, unapplied segments on a ticket as applied. Called
 * after a reply records time worked, so the next reply starts from zero.
 *
 * A running clock is deliberately left alone: the technician is still working,
 * and that segment belongs to whatever they log next.
 */
function ticketTimerMarkApplied($ticket_id, $user_id) {
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $user_id = intval($user_id);

    mysqli_query($mysqli, "UPDATE ticket_timers SET timer_applied_at = NOW()
        WHERE timer_ticket_id = $ticket_id
        AND timer_user_id = $user_id
        AND timer_stopped_at IS NOT NULL
        AND timer_applied_at IS NULL");
}

/*
 * Stop any clock still running on a ticket. Called wherever a ticket is closed -
 * a closed ticket is immutable and its reply form is gone, so a clock left
 * running there can never be stopped from the ticket itself and would sit in the
 * navbar forever.
 *
 * The segment is kept rather than discarded: the technician did that work, and it
 * stays unapplied so it is still theirs to log against another ticket or to see
 * in the record.
 */
function ticketTimerStopRunning($ticket_id) {
    global $mysqli;

    $ticket_id = intval($ticket_id);

    mysqli_query($mysqli, "UPDATE ticket_timers SET timer_stopped_at = NOW()
        WHERE timer_ticket_id = $ticket_id
        AND timer_stopped_at IS NULL");
}

/*
 * One clock in / clock out entry on the ticket conversation timeline.
 *
 * Returns markup rather than echoing, matching slaPercentDisplay() and the other
 * display helpers. The event array is built by agent/ticket.php and its user name
 * is already escaped there, where the row is read.
 */
function ticketTimelineEvent($event) {

    $when = date('n/j/Y g:i A', strtotime($event['at']));

    if ($event['type'] == 'in') {
        $label = 'Clocked in';
        $state = 'is-in';
    } else {
        $label = 'Clocked out';
        $state = 'is-out';
    }

    return "<div class='ticket-timeline-event $state'>
        <span class='text-secondary'>$label</span> &mdash; $when
        <span class='text-secondary'>&middot; {$event['user']}</span>
    </div>";
}
