<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/modal_header.php';

header('Content-Type: application/json');

// Every clock running right now, the user's own first. Scoped on the ticket's own
// client column so a restricted technician does not learn which other clients are
// being worked on.
$sql = mysqli_query(
    $mysqli,
    "SELECT timer_id, timer_started_at, timer_user_id, ticket_id, ticket_number, ticket_prefix,
        ticket_subject, client_name, user_name FROM ticket_timers
    LEFT JOIN tickets ON timer_ticket_id = ticket_id
    LEFT JOIN clients ON ticket_client_id = client_id
    LEFT JOIN users ON timer_user_id = user_id
    WHERE timer_stopped_at IS NULL
    " . clientScopeSql('ticket_client_id') . "
    ORDER BY timer_user_id = $session_user_id DESC, timer_started_at ASC"
);

$num_timers = mysqli_num_rows($sql);

// Generate the HTML form content using output buffering.
ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class='fas fa-hourglass-half me-2'></i>Running Timers<span class='badge bg-secondary rounded-pill px-3 ms-3'><?= $num_timers ?><span></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
    <?php if ($num_timers) { ?>
    <table class="table table-sm table-hover table-borderless">

        <?php while ($row = mysqli_fetch_assoc($sql)) {

            $timer_id = intval($row['timer_id']);
            $timer_user_id = intval($row['timer_user_id']);
            $timer_started_at = escapeHtml($row['timer_started_at']);
            $timer_started_at_ago = timeAgo($row['timer_started_at']);
            $ticket_id = intval($row['ticket_id']);
            $ticket_prefix = escapeHtml($row['ticket_prefix']);
            $ticket_number = intval($row['ticket_number']);
            $ticket_subject = escapeHtml($row['ticket_subject']);
            $user_name = escapeHtml($row['user_name']);
            $client_name = escapeHtml($row['client_name']);

            if ($client_name) {
                $client_name_display = $client_name;
            } else {
                $client_name_display = "-";
            }

            $is_mine = $timer_user_id == $session_user_id;

            ?>

        <tr class="timer-item">
            <th>
                <a class="text-dark" href="/agent/ticket.php?ticket_id=<?= $ticket_id ?>">
                    <i class="fas fa-hourglass-half me-2 text-success"></i><?= $ticket_prefix ?><?= $ticket_number ?> - <?= $ticket_subject ?>
                    <small class="text-muted float-end font-monospace timer-elapsed ms-3" data-started-at="<?= $timer_started_at ?>">
                        <?= $timer_started_at_ago ?>
                    </small>
                    <br>
                    <small class="text-secondary text-wrap">
                        <?php if ($is_mine) { ?>
                            <span class="badge bg-success me-1">You</span>
                        <?php } else { ?>
                            <?= $user_name ?> &middot;
                        <?php } ?>
                        <?= $client_name_display ?>
                    </small>
                </a>
            </th>
            <th class="text-end align-middle" style="width: 1%;">
                <?php if ($is_mine) { ?>
                    <a href="/agent/post.php?stop_ticket_timer=<?= $timer_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-outline-danger text-nowrap" title="Clock out">
                        <i class="fas fa-stop me-1"></i>Clock out
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

    // The clocks run on the server; this only animates the labels. Elapsed is
    // measured from the server's own clock at render time, so a browser in a
    // different timezone still shows the right figure.
    var serverNow = Date.parse('<?= date('Y-m-d\TH:i:s') ?>');
    var pageLoaded = Date.now();

    var rows = Array.from(document.querySelectorAll('.timer-elapsed')).map(function (el) {
        return { el: el, startedAt: Date.parse(el.dataset.startedAt.replace(' ', 'T')) };
    });

    function pad(val) {
        return val < 10 ? '0' + val : val;
    }

    function tick() {
        var drift = Date.now() - pageLoaded;
        rows.forEach(function (row) {
            if (isNaN(row.startedAt)) return;
            var secs = Math.max(0, Math.floor((serverNow - row.startedAt + drift) / 1000));
            row.el.textContent = pad(Math.floor(secs / 3600)) + ':' + pad(Math.floor((secs % 3600) / 60)) + ':' + pad(secs % 60);
        });
    }

    tick();
    setInterval(tick, 1000);
});
</script>

<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/modal_footer.php';
