<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/modal_header.php';

header('Content-Type: application/json');

// Two kinds of row live in this list:
//
//   - every clock running right now, whoever it belongs to, so a ticket somebody
//     else is already working on is visible rather than discovered afterwards
//   - the user's OWN recently clocked out tickets, kept for
//     config_ticket_timer_clocked_out_minutes so clocking back in is one press
//     rather than a search
//
// Other people's clocked out tickets are not listed - they are not resumable from
// here and would only make the list longer.
//
// Scoped on the ticket's own client column so a restricted technician does not
// learn which other clients are being worked on.
$clocked_out_minutes = intval($config_ticket_timer_clocked_out_minutes);

$sql = mysqli_query(
    $mysqli,
    "SELECT timer_id, timer_started_at, timer_stopped_at, timer_user_id,
        ticket_id, ticket_number, ticket_prefix, ticket_subject, client_name, user_name,
        (SELECT SUM(TIMESTAMPDIFF(SECOND, banked.timer_started_at, banked.timer_stopped_at))
            FROM ticket_timers banked
            WHERE banked.timer_ticket_id = ticket_timers.timer_ticket_id
            AND banked.timer_user_id = ticket_timers.timer_user_id
            AND banked.timer_stopped_at IS NOT NULL
            AND banked.timer_applied_at IS NULL) AS banked_seconds,
        TIMESTAMPDIFF(SECOND, timer_started_at, NOW()) AS running_seconds
    FROM ticket_timers
    LEFT JOIN tickets ON timer_ticket_id = ticket_id
    LEFT JOIN clients ON ticket_client_id = client_id
    LEFT JOIN users ON timer_user_id = user_id
    WHERE (timer_stopped_at IS NULL
        OR (timer_user_id = $session_user_id
            AND timer_applied_at IS NULL
            AND ticket_closed_at IS NULL
            AND timer_stopped_at > NOW() - INTERVAL $clocked_out_minutes MINUTE))
    " . clientScopeSql('ticket_client_id') . "
    ORDER BY timer_stopped_at IS NULL DESC, timer_user_id = $session_user_id DESC, timer_started_at ASC"
);

// One row per ticket per person - a technician who clocks in and out of the same
// ticket three times owns three segments, but only one line in a list of what is
// being worked on. The ORDER BY above puts the running segment first, so the row
// that survives is the one describing the ticket's current state.
$timers = [];
$num_running = 0;

while ($row = mysqli_fetch_assoc($sql)) {

    $key = intval($row['ticket_id']) . '-' . intval($row['timer_user_id']);

    if (isset($timers[$key])) {
        continue;
    }

    $timers[$key] = $row;

    if (empty($row['timer_stopped_at'])) {
        $num_running++;
    }
}

// Generate the HTML form content using output buffering.
ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class='fas fa-hourglass-half me-2'></i><?= $num_running ?> Running Ticket<?php if ($num_running != 1) { echo "s"; } ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
    <?php if (!empty($timers)) { ?>
    <table class="table table-sm table-hover table-borderless align-middle mb-0">

        <?php foreach ($timers as $row) {

            $timer_id = intval($row['timer_id']);
            $timer_user_id = intval($row['timer_user_id']);
            $ticket_id = intval($row['ticket_id']);
            $ticket_prefix = escapeHtml($row['ticket_prefix']);
            $ticket_number = intval($row['ticket_number']);
            $ticket_subject = escapeHtml($row['ticket_subject']);
            $user_name = escapeHtml($row['user_name']);
            $client_name = escapeHtml($row['client_name']);
            $banked_seconds = intval($row['banked_seconds']);

            $is_running = empty($row['timer_stopped_at']);
            $is_mine = $timer_user_id == $session_user_id;

            // Total time on this ticket, so the figure carries across a clock out and
            // back in rather than restarting. A running row adds the segment in
            // progress; the script below keeps counting from the same base.
            $elapsed_seconds = $banked_seconds;

            if ($is_running) {
                $elapsed_seconds += intval($row['running_seconds']);
            }

            if ($client_name) {
                $client_name_display = "Client: $client_name";
            } else {
                $client_name_display = "No client";
            }

            $elapsed_display = sprintf("%02d:%02d:%02d", intdiv($elapsed_seconds, 3600), intdiv($elapsed_seconds % 3600, 60), $elapsed_seconds % 60);

            ?>

        <tr>
            <th class="fw-normal">
                <a class="text-dark text-decoration-none" href="/agent/ticket.php?ticket_id=<?= $ticket_id ?>" title="<?= $ticket_subject ?>">
                    <span class="fw-bold"><?= $ticket_prefix ?><?= $ticket_number ?></span>
                    <span class="text-secondary mx-1">|</span>
                    <span class="fw-bold font-monospace<?php if ($is_running) { echo " ticket-timer-elapsed"; } ?>" <?php if ($is_running) { ?>data-elapsed-seconds="<?= $elapsed_seconds ?>"<?php } ?>><?= $elapsed_display ?></span>
                    <br>
                    <small class="text-secondary"><?= $client_name_display ?><?php if (!$is_mine) { ?> &middot; <?= $user_name ?><?php } ?></small>
                </a>
            </th>
            <th class="text-end" style="width: 1%;">
                <?php if ($is_running && $is_mine) { ?>
                    <a href="/agent/post.php?stop_ticket_timer=<?= $timer_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-danger" title="Clock out">
                        <i class="fas fa-fw fa-stop"></i>
                    </a>
                <?php } elseif ($is_running) { ?>
                    <span class="btn btn-danger disabled" title="<?= $user_name ?> is clocked in">
                        <i class="fas fa-fw fa-stop"></i>
                    </span>
                <?php } else { ?>
                    <a href="/agent/post.php?start_ticket_timer=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-secondary" title="Clock back in">
                        <i class="fas fa-fw fa-play"></i>
                    </a>
                <?php } ?>
            </th>
        </tr>

        <?php
        }
        ?>
        </table>
    <?php } else { ?>
    <div class="text-center text-secondary pt-3">
        <i class='far fa-6x fa-hourglass'></i>
        <h3 class="mt-3">No Running Timers</h3>
    </div>
    <?php } ?>
</div>
<div class="modal-footer">
    <a href="/agent/tickets.php" class="btn btn-secondary">
        <span class="text-white">Go to Tickets</span>
    </a>
    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
        <i class="fas fa-times me-2"></i>Close
    </button>
</div>

<script>
itflowReady(function () {

    // The clocks run on the server; this only animates the labels of the ones still
    // running. Each row carries a total the server worked out, so the browser only
    // ever adds seconds since page load and never has to agree about the time of day.
    var pageLoaded = Date.now();

    var rows = Array.from(document.querySelectorAll('.ticket-timer-elapsed')).map(function (el) {
        return { el: el, base: parseInt(el.dataset.elapsedSeconds, 10) || 0 };
    });

    function pad(val) {
        return val < 10 ? '0' + val : val;
    }

    function tick() {
        var elapsedSincePageLoad = Math.floor((Date.now() - pageLoaded) / 1000);
        rows.forEach(function (row) {
            var secs = row.base + elapsedSincePageLoad;
            row.el.textContent = pad(Math.floor(secs / 3600)) + ':' + pad(Math.floor((secs % 3600) / 60)) + ':' + pad(secs % 60);
        });
    }

    tick();
    setInterval(tick, 1000);
});
</script>

<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/modal_footer.php';
