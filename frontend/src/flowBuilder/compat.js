/**
 * Puts back Array.prototype.entries, which the admin's prototype.js removes.
 *
 * Prototype copies its own `entries` (an alias of toArray) over the native
 * one and then, noticing the clash, deletes the property - which leaves no
 * entries at all rather than the native one. React Flow iterates arrays with
 * it when it reconciles nodes, so without this the canvas dies the first
 * time nodes are replaced wholesale: Tidy up, Restore.
 *
 * Only added when missing, with the standard behaviour, and not enumerable -
 * Prototype-era code walks arrays with for...in and must not start seeing it.
 * Imported first, before anything that might need it.
 */
if (typeof Array.prototype.entries !== 'function') {
  Object.defineProperty(Array.prototype, 'entries', {
    configurable: true,
    writable: true,
    enumerable: false,
    value: function* entries() {
      for (let i = 0; i < this.length; i++) {
        yield [i, this[i]];
      }
    },
  });
}
