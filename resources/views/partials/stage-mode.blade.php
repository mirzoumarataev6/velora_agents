{{--
    STAGE MODE, applied before first paint.

    Stage mode is the presentation setting: it stamps `data-mode="stage"` on
    <html>, which raises the root font size to 112.5%, darkens muted text a full
    step, darkens borders and doubles them to 2px, and switches shadows and
    transparency off. The values all live in resources/css/tokens.css — this
    only decides whether the attribute is there.

    Why it runs inline in <head> rather than from app.js: everything it changes
    is layout-affecting (root font-size most of all), so applying it after first
    paint would reflow the entire page in front of the audience — on the one
    setting whose whole purpose is being used in front of an audience. The same
    reasoning as the theme script each layout already carries.

    Shared by all three heads (dashboard, auth, landing) rather than copied into
    each, because a presenter who switches it on at the landing and then signs in
    must not watch it switch itself off.

    localStorage only, never the account: this is a property of the ROOM, not of
    the person. The same owner wants it on for the projector and off at their
    desk twenty minutes later, and an account-level setting would follow them to
    the wrong one.
--}}
<script>
    (function () {
        try {
            if (localStorage.getItem('mode') === 'stage') {
                document.documentElement.setAttribute('data-mode', 'stage');
            }
        } catch (e) {
            /* private mode — stage mode just won't outlive the tab */
        }
    })();
</script>
