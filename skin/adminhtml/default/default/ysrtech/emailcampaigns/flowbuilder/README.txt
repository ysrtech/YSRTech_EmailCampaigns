flowBuilder.js / flowBuilder.css
================================

A built bundle: React 18.3.1 and React Flow, compiled from
frontend/src/flowBuilder/*.jsx by frontend/vite.flow.config.js.

Vendored rather than fetched from a CDN, for the same reasons as the GrapesJS
bundle beside it: the admin must work on a box with no outbound access, and an
editor that silently stops loading because someone else's CDN moved is not a
thing to discover while scheduling a campaign.

It is driven entirely by window.YsrFlowEditorConfig, which
app/design/adminhtml/.../automation_canvas.phtml sets - see
Model/Automation/Graph.php for the translation between the graph this speaks
and the ordered steps the engine runs. The bundle itself is unmodified from
the branch it came from, so rebuilding it from frontend/ stays possible.
