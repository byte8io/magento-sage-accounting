/**
 * Force same-tab navigation for DocSearch hits that point at sibling
 * Docusaurus sites under docs.byte8.io.
 *
 * Without this:
 *   - With Docusaurus's `externalUrlRegex` set: DocSearch keeps hit URLs absolute,
 *     Docusaurus's <Link> sees a URL "with protocol" and adds target="_blank" +
 *     renders as a plain <a>. Customers click → new tab. SEO crawlers see the
 *     link as external (target=_blank, full URL).
 *   - Without `externalUrlRegex`: DocSearch makes hit URLs relative, <Link>
 *     treats them as internal → React Router fires history.push() → 404 because
 *     the destination route doesn't live in this site's bundle (it's a different
 *     Pages project under the docs-router Worker).
 *
 * This module hooks the click event in the capture phase, BEFORE React Router /
 * DocSearch handle it. For DocSearch hit links pointing at any docs.byte8.io
 * URL, it cancels both the React-Router SPA navigation and the new-tab default,
 * and does a same-tab full navigation via `window.location.href`. The Worker
 * then proxies to the correct Pages origin.
 *
 * Modifier-key + middle-click clicks (Cmd / Ctrl / Shift / Alt / non-primary
 * button) are deliberately NOT intercepted — those should keep the browser's
 * default "open in new tab" behaviour, which is what power users expect.
 *
 * SEO win: keeping `externalUrlRegex` unset means DocSearch emits relative
 * hrefs (`/sage/docs/...`) without `target="_blank"`. Crawlers see internal
 * same-site links, which is what we want for the unified docs.byte8.io domain.
 */

if (typeof document !== 'undefined') {
  document.addEventListener(
    'click',
    (event) => {
      const target = event.target as Element | null;
      const anchor = target?.closest?.('a');
      if (!anchor) return;

      // Only intercept clicks inside the DocSearch modal — leave everything
      // else (navbar links, in-page anchors, footer links, etc.) untouched.
      if (!anchor.closest('.DocSearch')) return;

      // Modifier keys + non-primary button → let the browser handle it
      // (cmd/ctrl-click and middle-click both open in a new tab; shift-click
      // opens in a new window; alt-click downloads in some browsers).
      const me = event as MouseEvent;
      if (me.metaKey || me.ctrlKey || me.shiftKey || me.altKey || me.button !== 0) {
        return;
      }

      // anchor.href is always the resolved absolute URL, even if the rendered
      // attribute is relative. Filter to docs.byte8.io so external links
      // (byte8.io, GitHub, etc. — DocSearch can occasionally surface these)
      // aren't hijacked.
      const fullUrl = anchor.href;
      if (!fullUrl || !/docs\.byte8\.io/.test(fullUrl)) return;

      event.preventDefault();
      event.stopImmediatePropagation();
      window.location.href = fullUrl;
    },
    true, // capture phase — runs before React Router's bubble-phase handlers
  );
}
