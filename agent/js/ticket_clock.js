(function() {
    document.addEventListener("DOMContentLoaded", function() {

        // The clock itself runs on the server - this only animates the label so a
        // running clock looks alive. Nothing here decides how long anything ran; the
        // elapsed time that gets logged is worked out from the stored timestamps when
        // the technician clocks out.
        //
        // Each element carries a total the server already worked out, so the browser
        // only ever adds seconds since page load and never has to agree with the
        // server about the time of day.
        var elements = Array.from(document.querySelectorAll(".ticket-timer-elapsed")).map(function(el) {
            return { el: el, base: parseInt(el.dataset.elapsedSeconds, 10) || 0 };
        });

        if (!elements.length) {
            return;
        }

        var pageLoaded = Date.now();

        function pad(val) {
            return val < 10 ? "0" + val : val;
        }

        function tick() {
            var elapsedSincePageLoad = Math.floor((Date.now() - pageLoaded) / 1000);
            elements.forEach(function(item) {
                var secs = item.base + elapsedSincePageLoad;
                item.el.textContent = pad(Math.floor(secs / 3600)) + ":" + pad(Math.floor((secs % 3600) / 60)) + ":" + pad(secs % 60);
            });
        }

        tick();
        setInterval(tick, 1000);
    });
})();
