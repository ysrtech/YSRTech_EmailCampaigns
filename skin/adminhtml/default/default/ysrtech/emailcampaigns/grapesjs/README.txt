Vendored third-party assets, not written here.

  grapes.min.js          grapesjs 0.23.6                  BSD-3-Clause
  grapes.min.css         grapesjs 0.23.6                  BSD-3-Clause
  preset-newsletter.js   grapesjs-preset-newsletter 1.0.2  BSD-3-Clause

Served from the store rather than a CDN on purpose: the admin keeps working
without outbound internet, and no third party is told which templates are
being edited or when.

sourceMappingURL comments are stripped because the .map files are not
shipped, and a 404 in the admin console is noise somebody will chase.

To update, take dist/ from the npm tarball for the version you want:
  https://registry.npmjs.org/grapesjs/-/grapesjs-<version>.tgz
  https://registry.npmjs.org/grapesjs-preset-newsletter/-/grapesjs-preset-newsletter-<version>.tgz
