/**
 * Generate the hospitality PHP templates from the BD editorial source so the
 * markup stays byte-identical apart from the placeholders.
 *
 * Run: node scripts/build-hospitality-templates.js
 */
const fs = require('fs');
const path = require('path');

const SOURCE_DIR = '/Volumes/data/Work/EPIC/BD/hospitality-email';
const TARGET_DIR = path.resolve(__dirname, '..', 'templates');
const COPY_DATE = '2026-11-01';

const LOCALES = [
  { locale: 'vi', source: 'hotel-1.vi.html' },
  { locale: 'en', source: 'hotel-1.html' },
];

function transform(html, locale, source) {
  let out = html;

  // Order matters: [Your Name] must be replaced before [Name].
  out = out.split('[Your Name]').join('{{sender_name}}');
  out = out.split('[Name]').join('{{name}}');
  out = out.split('[Title]').join('{{sender_title}}');
  out = out.split('[EPIC_EMAIL]').join('{{epic_email}}');
  out = out.split('[Phone / WhatsApp]').join('{{phone}}');
  // Hotel name appears URL-encoded inside the CTA mailto: subject.
  out = out.split('%5BHotel%20Name%5D').join('{{hotel_name_url}}');

  const header = [
    '<?php',
    '/**',
    ` * COPY — editorial source is BD/hospitality-email/${source}`,
    ` * Copied ${COPY_DATE}. Keep the markup identical to the source; only the`,
    ' * {{placeholders}} differ. Regenerate with scripts/build-hospitality-templates.js.',
    ' *',
    ' * @package Epic_Mail_MCP',
    ' */',
    '',
    "if ( ! defined( 'ABSPATH' ) ) {",
    "\texit;",
    '}',
    '?>',
    '',
  ].join('\n');

  return header + out;
}

for (const { locale, source } of LOCALES) {
  const sourcePath = path.join(SOURCE_DIR, source);
  const targetPath = path.join(TARGET_DIR, `hospitality-hotel-intro.${locale}.php`);
  const html = fs.readFileSync(sourcePath, 'utf8');
  fs.writeFileSync(targetPath, transform(html, locale, source), 'utf8');
  console.log(`wrote ${targetPath}`);
}
