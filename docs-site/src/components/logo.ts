/**
 * The site's logo, drawn once for every place it appears: the header and the
 * docs layout (Logo.astro) and the social cards (src/pages/og/_card.ts).
 * Replace the drawing here to rebrand them all. Keep it a single-colour
 * mark; each place fills it with its own text colour.
 */
export const logo = {
  /** The drawing's box; callers size it by height and keep the ratio. */
  width: 311,
  height: 184,
  paths: ["M35.0 0H135.0A35.0 35.0 0 0 1 135.0 70H35.0A35.0 35.0 0 0 1 35.0 0Z", "M225.0 0H276.0A35.0 35.0 0 0 1 276.0 70H225.0A35.0 35.0 0 0 1 225.0 0Z", "M35.0 114H75.0A35.0 35.0 0 0 1 75.0 184H35.0A35.0 35.0 0 0 1 35.0 114Z", "M165.0 114H276.0A35.0 35.0 0 0 1 276.0 184H165.0A35.0 35.0 0 0 1 165.0 114Z"],
};
