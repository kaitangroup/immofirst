<?php

/**
 * @file
 * Creates the legal/informational Basic pages with German placeholder text.
 *
 * Pages: Impressum, Datenschutz, AGB, Cookie-Hinweise, Widerrufsbelehrung
 * (Kontakt is a route of the immofirst_contact module, not a node).
 *
 * USAGE (from the project root, AFTER `drush cim` has created the
 * "Basic page" content type — see docs/deployment-legal-pages.md):
 *   drush scr scripts/create-legal-pages.php                 create missing pages
 *   drush scr scripts/create-legal-pages.php -- --dry-run    only report
 *   drush scr scripts/create-legal-pages.php -- --unpublished
 *                                     create missing pages as unpublished
 *
 * Safe to re-run — it only ever CREATES, never changes an existing page:
 * - Each page has a fixed UUID, so the same page is recognised on every
 *   environment and on every run (no duplicates).
 * - A page that already exists (same UUID) keeps its title, body and
 *   published status, so client edits are never overwritten; only a
 *   missing URL alias is added back.
 * - If the URL alias already belongs to another node (e.g. a page the
 *   client created by hand), that page is left alone and nothing is
 *   created for it. If it belongs to a non-node path, it is reported as
 *   a conflict and skipped.
 */

use Drupal\node\Entity\Node;

$dry_run = in_array('--dry-run', $extra ?? [], TRUE);
$published = !in_array('--unpublished', $extra ?? [], TRUE);

if (!\Drupal\node\Entity\NodeType::load('page')) {
  throw new \RuntimeException('Content type "page" does not exist. Run `drush cim -y` first.');
}

$notice = '<p><strong>Platzhaltertext:</strong> Dieser Inhalt ist ein Blindtext und dient nur als Platzhalter. Er muss vor der Veröffentlichung durch vom Kunden freigegebene und rechtlich geprüfte Inhalte ersetzt werden.</p>';
$p1 = '<p>Hinter den Wortbergen, fern der Länder Vokalien und Konsonantien leben die Blindtexte. Abgeschieden wohnen sie in Buchstabhausen an der Küste des Semantik, eines großen Sprachozeans. Ein kleines Bächlein namens Duden fließt durch ihren Ort und versorgt sie mit den nötigen Regelialien.</p>';
$p2 = '<p>Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est.</p>';
$p3 = '<p>Dies ist ein Blindtext. An ihm lässt sich vieles über die Schrift ablesen, in der er gesetzt ist. Auf den ersten Blick wird der Grauwert der Schriftfläche sichtbar. Dann kann man prüfen, wie gut die Schrift zu lesen ist und wie sie auf den Leser wirkt.</p>';

$build_body = function (array $headings) use ($notice, $p1, $p2, $p3) {
  $html = $notice . "\n" . $p1;
  foreach ($headings as $i => $heading) {
    $html .= "\n<h2>{$heading}</h2>\n" . ($i % 2 ? $p3 : $p2);
  }
  return $html;
};

$pages = [
  [
    'uuid' => 'd2bc047b-be27-490e-9665-624b507c5f83',
    'title' => 'Impressum',
    'alias' => '/impressum',
    'headings' => ['Anbieter', 'Kontakt', 'Vertretungsberechtigte Person', 'Registereintrag', 'Haftungshinweis'],
  ],
  [
    'uuid' => 'd3420a8e-f7f9-4ace-8232-7c4800cb1431',
    'title' => 'Datenschutz',
    'alias' => '/datenschutz',
    'headings' => ['Allgemeine Hinweise', 'Verantwortliche Stelle', 'Erhebung und Speicherung von Daten', 'Ihre Rechte', 'Kontakt zum Datenschutz'],
  ],
  [
    'uuid' => '42f96675-7876-4a02-9906-62382bebcdf2',
    'title' => 'Allgemeine Geschäftsbedingungen (AGB)',
    'alias' => '/agb',
    'headings' => ['§ 1 Geltungsbereich', '§ 2 Leistungsbeschreibung', '§ 3 Registrierung und Nutzung', '§ 4 Pflichten der Nutzer', '§ 5 Haftung', '§ 6 Schlussbestimmungen'],
  ],
  [
    'uuid' => 'd2ad2fc7-b917-4972-afbd-fefe9cc24dfa',
    'title' => 'Cookie-Hinweise',
    'alias' => '/cookie-hinweise',
    'headings' => ['Was sind Cookies?', 'Welche Cookies verwenden wir?', 'Cookie-Einstellungen verwalten', 'Weitere Informationen'],
  ],
  [
    'uuid' => 'a6107f77-30a7-4b58-8954-fe73e30e4f78',
    'title' => 'Widerrufsbelehrung',
    'alias' => '/widerrufsbelehrung',
    'headings' => ['Widerrufsrecht', 'Widerrufsfrist', 'Folgen des Widerrufs', 'Muster-Widerrufsformular'],
  ],
];

$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');
$langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
$has_pathauto = \Drupal::moduleHandler()->moduleExists('pathauto');
$prefix = $dry_run ? '[DRY-RUN] ' : '';

foreach ($pages as $page) {
  $alias = $page['alias'];
  $existing = $node_storage->loadByProperties(['uuid' => $page['uuid']]);
  $node = reset($existing) ?: NULL;
  $owners = array_values(array_map(fn($a) => $a->getPath(), $alias_storage->loadByProperties(['alias' => $alias])));

  if ($node) {
    $own_path = '/node/' . $node->id();
    if (in_array($own_path, $owners, TRUE)) {
      echo "[OK] {$page['title']}: exists (node {$node->id()}), {$alias} correct — unchanged.\n";
    }
    elseif ($owners) {
      echo "[CONFLICT] {$page['title']}: exists (node {$node->id()}), but {$alias} points to " . implode(', ', $owners) . " — alias not changed.\n";
    }
    else {
      echo "{$prefix}[ALIAS] {$page['title']}: exists (node {$node->id()}), content unchanged; adding missing alias {$alias}.\n";
      if (!$dry_run) {
        $alias_storage->create(['path' => $own_path, 'alias' => $alias, 'langcode' => $node->language()->getId()])->save();
      }
    }
    continue;
  }

  if ($owners) {
    $label = preg_match('#^/node/\d+$#', $owners[0]) ? 'SKIPPED' : 'CONFLICT';
    echo "[{$label}] {$page['title']}: {$alias} already points to " . implode(', ', $owners) . " — nothing created.\n";
    continue;
  }

  echo "{$prefix}[CREATE] {$page['title']} at {$alias} (" . ($published ? 'published' : 'unpublished') . ").\n";
  if ($dry_run) {
    continue;
  }
  $path = ['alias' => $alias];
  if ($has_pathauto) {
    // Keep the fixed alias; never let a Pathauto pattern replace it.
    $path['pathauto'] = 0;
  }
  Node::create([
    'type' => 'page',
    'uuid' => $page['uuid'],
    'langcode' => $langcode,
    'title' => $page['title'],
    'body' => ['value' => $build_body($page['headings']), 'format' => 'basic_html'],
    'path' => $path,
    'status' => $published ? 1 : 0,
    'uid' => 1,
  ])->save();
}
