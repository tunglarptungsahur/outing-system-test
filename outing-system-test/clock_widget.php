<?php
// Shared live clock, shown on both staff and student pages as a plain
// time reference. Deliberately does NOT recompute curfew status here
// -- that logic lives once, server-side, in curfew.php. Duplicating
// "is it curfew right now" in client JS would risk it drifting out of
// sync with the server (different clock, different logic if curfew.php
// ever changes) and showing the wrong answer to the person who most
// needs the right one.
?>
<span id="live-clock" style="font-family: monospace;"></span>
<script>
    (function () {
        var el = document.getElementById('live-clock');
        function tick() {
            var d = new Date();
            var pad = function (n) { return String(n).padStart(2, '0'); };
            var days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            var time = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
            // Phones: time only, so the header keeps room for the menu/bell/avatar.
            if (window.matchMedia && window.matchMedia('(max-width: 600px)').matches) {
                el.textContent = time;
                return;
            }
            el.textContent = days[d.getDay()] + ' '
                + d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' '
                + time;
        }
        tick();
        setInterval(tick, 1000);
    })();
</script>
