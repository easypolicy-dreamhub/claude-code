# Toolkit page alternates

Upload one of these as `public_html/members/index.html` (overwrite) to change how tool cards open.

| File | Behaviour |
|---|---|
| `../public_html/members/index.html` (current) | Menu tab stays; each tool in its own named tab; same tool again goes back to its tab; the tool's "E3 Toolkit" link closes the tab. Needs `public_html/members/tool-tab.js` and the updated `gate.php`. |
| `index-same-tab.html` | Tools open in the same tab (back link / back button return to the menu). Outside links open a new tab. |
| `index-new-tab-each-time.html` | The original behaviour: every card opens a new tab (tabs pile up). |

All three show only the tools each person is ticked for.
