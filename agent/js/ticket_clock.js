(function() {
    document.addEventListener("DOMContentLoaded", function() {

        // The clock itself lives on the server - this only animates the label so a
        // running clock looks alive. Nothing here decides how long anything ran;
        // the elapsed time that gets logged is worked out from the stored
        // timestamps when the user clocks out.
        var elapsedEl = document.getElementById("ticketClockElapsed");

        if (!elapsedEl) {
            return;
        }

        // MySQL datetimes have no zone. The server renders them in the install's
        // timezone and the browser may well be somewhere else, so the difference
        // is measured against the page load rather than against the wall clock.
        var startedAt = elapsedEl.dataset.startedAt;
        var pageLoaded = Date.now();
        var offsetSecs = 0;

        var parsed = Date.parse(startedAt.replace(' ', 'T'));
        var serverNow = Date.parse(elapsedEl.dataset.serverNow ? elapsedEl.dataset.serverNow.replace(' ', 'T') : '');

        if (!isNaN(parsed) && !isNaN(serverNow)) {
            offsetSecs = Math.max(0, Math.floor((serverNow - parsed) / 1000));
        }

        function pad(val) {
            return val < 10 ? "0" + val : val;
        }

        function tick() {
            var secs = offsetSecs + Math.floor((Date.now() - pageLoaded) / 1000);
            var hours = Math.floor(secs / 3600);
            var minutes = Math.floor((secs % 3600) / 60);
            var seconds = secs % 60;
            elapsedEl.textContent = pad(hours) + ":" + pad(minutes) + ":" + pad(seconds);
        }

        tick();
        setInterval(tick, 1000);
    });
})();
