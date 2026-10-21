const ENTITIES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

// Escapes a value for interpolation into an innerHTML template (text or quoted
// attribute). Every server-provided string (names, materials, error messages)
// must go through this: they are user-controlled.
export const esc = (value) => String(value ?? '').replace(/[&<>"']/g, ch => ENTITIES[ch]);
