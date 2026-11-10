/**
 * UI strings come translated from the server, keyed by their English text -
 * Block/Adminhtml/Automation/Canvas.php passes every string through the
 * module's helper, so the canvas is translated by the same CSV as the rest of
 * the admin. A string missing from that map falls back to the English here,
 * which is also what makes the bundle usable before the map knows about it.
 */
const dictionary = (window.YsrFlowEditorConfig && window.YsrFlowEditorConfig.i18n) || {};

export function t(text, ...args) {
  let out = Object.prototype.hasOwnProperty.call(dictionary, text) ? dictionary[text] : text;
  args.forEach((arg) => {
    out = out.replace(/%[sd]/, String(arg));
  });
  return out;
}
