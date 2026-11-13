/* Loaded into every tool page by members/gate.php. If the tool was opened from the toolkit menu (its own tab),
   a click on any link back to the toolkit closes this tab so the menu tab is shown again. If the menu tab is gone,
   or the browser will not close the tab, the link simply goes to the toolkit in this tab. */
(function () {
  'use strict';
  function isToolkitLink(a) {
    try {
      var u = new URL(a.href, location.href);
      return u.origin === location.origin && (u.pathname === '/members/' || u.pathname === '/members' || u.pathname === '/members/index.html') && !u.search && !u.hash;
    } catch (e) { return false; }
  }
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || !isToolkitLink(a)) return;
    if (String(window.name).indexOf('e3tool-') !== 0) return;     // not opened from the menu: normal link
    var op = window.opener;
    if (!op || op.closed) return;                                  // menu tab is gone: normal link
    e.preventDefault();
    try { op.focus(); } catch (err) {}
    window.close();
    setTimeout(function () { location.href = a.href; }, 250);     // still here = the browser refused to close
  }, true);
})();
